# Session Notes Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a post-appointment session notes field to reservations — a separate field from the existing customer `notes` — that is access-controlled so only the assigned employee and admins can view/edit it.

**Architecture:** Add a `sessionNotes` TEXT column to `booked_reservations` via migration. The existing `notes` field is customer-facing (filled during booking). `sessionNotes` is employee-facing (filled after appointment). Access control is enforced at the CP template and controller level: only users who are admins, or whose employee record matches the reservation's `employeeId`, can see and edit the field. Uses `PermissionService` to resolve the current user's employee link.

**Tech Stack:** PHP 8.2, Craft CMS 5, Yii 2 migrations, PHPUnit 9.5

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `src/migrations/m260314_000000_AddSessionNotesToReservations.php` | Add `sessionNotes` column |
| Modify | `src/records/ReservationRecord.php` | Add `sessionNotes` property and rules |
| Modify | `src/elements/Reservation.php` | Add `sessionNotes` property, save/load, CP edit field |
| Modify | `src/elements/db/ReservationQuery.php` | Select `sessionNotes` in `beforePrepare()` |
| Modify | `src/models/ReservationModel.php` | Add `sessionNotes` property |
| Modify | `src/contracts/ReservationInterface.php` | Add `@property` annotation and `getSessionNotes(): ?string` |
| Modify | `src/templates/bookings/edit.twig` | Show session notes field with access control |
| Modify | `src/controllers/cp/BookingsController.php` | Pass `canEditSessionNotes` to template, handle save |
| Modify | `src/translations/en/booked.php` | Add session notes translation keys |
| Modify | `src/translations/{de,es,fr,it,ja,nl,pt}/booked.php` | Add session notes translation keys |
| Create | `tests/Unit/Elements/ReservationSessionNotesTest.php` | Test the new field |

---

## Task 1: Create Migration

**Files:**
- Create: `src/migrations/m260314_000000_AddSessionNotesToReservations.php`

- [ ] **Step 1: Create the migration file**

Create `src/migrations/m260314_000000_AddSessionNotesToReservations.php`:

```php
<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

class m260314_000000_AddSessionNotesToReservations extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn('{{%booked_reservations}}', 'sessionNotes', $this->text()->after('notes'));
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%booked_reservations}}', 'sessionNotes');
        return true;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/migrations/m260314_000000_AddSessionNotesToReservations.php
git commit -m "feat(session-notes): add migration for sessionNotes column"
```

---

## Task 2: Update ReservationRecord

**Files:**
- Modify: `src/records/ReservationRecord.php`
- Modify: `tests/Unit/Records/ReservationRecordTest.php`

- [ ] **Step 1: Write failing test**

In `tests/Unit/Records/ReservationRecordTest.php`, add:

```php
public function testSessionNotesPropertyExists(): void
{
    $record = $this->makeRecord(['sessionNotes' => 'Patient reported improvement']);
    $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
    $ref->setAccessible(true);
    $attrs = $ref->getValue($record);
    $this->assertEquals('Patient reported improvement', $attrs['sessionNotes']);
}
```

- [ ] **Step 2: Update the record class**

In `src/records/ReservationRecord.php`, add to the docblock (after `@property string|null $notes`):

```php
 * @property string|null $sessionNotes
```

In `rules()`, add `sessionNotes` to the existing string validation (line 66):

```php
[['notes', 'sessionNotes', 'virtualMeetingUrl', 'virtualMeetingProvider', 'virtualMeetingId', 'googleEventId', 'outlookEventId'], 'string'],
```

- [ ] **Step 3: Run tests**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Records/ReservationRecordTest.php -v`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add src/records/ReservationRecord.php tests/Unit/Records/ReservationRecordTest.php
git commit -m "feat(session-notes): add sessionNotes to ReservationRecord"
```

---

## Task 3: Update Reservation Element

**Files:**
- Modify: `src/elements/Reservation.php`
- Modify: `src/elements/db/ReservationQuery.php`
- Create: `tests/Unit/Elements/ReservationSessionNotesTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Elements/ReservationSessionNotesTest.php`:

```php
<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\Reservation;
use anvildev\booked\tests\Support\TestCase;

class ReservationSessionNotesTest extends TestCase
{
    public function testSessionNotesPropertyDefaultsToNull(): void
    {
        $reservation = new Reservation();
        $this->assertNull($reservation->sessionNotes);
    }

    public function testSessionNotesCanBeSet(): void
    {
        $reservation = new Reservation();
        $reservation->sessionNotes = 'Client completed all exercises.';
        $this->assertEquals('Client completed all exercises.', $reservation->sessionNotes);
    }

    public function testGetSessionNotesReturnsValue(): void
    {
        $reservation = new Reservation();
        $reservation->sessionNotes = 'Follow-up in 2 weeks';
        $this->assertEquals('Follow-up in 2 weeks', $reservation->getSessionNotes());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Elements/ReservationSessionNotesTest.php -v`
Expected: FAIL — property not defined

- [ ] **Step 3: Add property to Reservation element**

In `src/elements/Reservation.php`, after line 43 (`public ?string $notes = null;`):

```php
public ?string $sessionNotes = null;
```

- [ ] **Step 4: Add `getSessionNotes()` method**

After the existing `getNotes()` method:

```php
public function getSessionNotes(): ?string
{
    return $this->sessionNotes;
}
```

- [ ] **Step 5: Update `defineRules()`**

In `src/elements/Reservation.php`, line 471, add `sessionNotes` to the string validation:

```php
[['notes', 'sessionNotes', 'virtualMeetingUrl', 'virtualMeetingProvider', 'virtualMeetingId'], 'string'],
```

- [ ] **Step 6: Update `afterSave()` to persist sessionNotes**

In `src/elements/Reservation.php`, after line 683 (`$record->notes = $this->notes;`):

```php
$record->sessionNotes = $this->sessionNotes;
```

- [ ] **Step 7: Update ReservationQuery to select sessionNotes**

In `src/elements/db/ReservationQuery.php`, in `beforePrepare()` around line 176, add `"$t.sessionNotes"` to the `addSelect()` array:

```php
$this->query->addSelect([
    "$t.userName", "$t.userEmail", "$t.userPhone", "$t.userId",
    "$t.userTimezone", "$t.bookingDate", "$t.startTime", "$t.endTime",
    "$t.status", "$t.notes", "$t.sessionNotes", "$t.notificationSent", "$t.confirmationToken",
    // ... rest unchanged
]);
```

- [ ] **Step 8: Run tests**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Elements/ReservationSessionNotesTest.php -v`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add src/elements/Reservation.php src/elements/db/ReservationQuery.php tests/Unit/Elements/ReservationSessionNotesTest.php
git commit -m "feat(session-notes): add sessionNotes property to Reservation element and query"
```

---

## Task 4: Update ReservationModel and Interface

**Files:**
- Modify: `src/contracts/ReservationInterface.php`
- Modify: `src/models/ReservationModel.php`

- [ ] **Step 1: Add to ReservationInterface**

In `src/contracts/ReservationInterface.php`:

1. Add `@property string|null $sessionNotes` to the docblock (around line 28, after `@property string|null $notes`)
2. Add the getter method after `getNotes()`:

```php
public function getSessionNotes(): ?string;
```

**Note:** The `@property` annotation is needed for PHPStan compatibility and IDE support, matching the existing pattern in the interface docblock.

- [ ] **Step 2: Add to ReservationModel**

In `src/models/ReservationModel.php`, add property:

```php
public ?string $sessionNotes = null;
```

Add getter method:

```php
public function getSessionNotes(): ?string
{
    return $this->sessionNotes;
}
```

Update the `fromRecord()` method to include `sessionNotes`:

```php
$model->sessionNotes = $record->sessionNotes ?? null;
```

Update the `save()` method to include `sessionNotes` when writing to the record.

- [ ] **Step 3: Run tests**

Run: `cd plugins/booked && composer test`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add src/contracts/ReservationInterface.php src/models/ReservationModel.php
git commit -m "feat(session-notes): add sessionNotes to ReservationInterface and ReservationModel"
```

---

## Task 5: Add Access-Controlled CP Template Field

**Files:**
- Modify: `src/templates/bookings/edit.twig`

- [ ] **Step 1: Add session notes field to the edit template**

In `src/templates/bookings/edit.twig`, after the existing customer notes field, add a session notes section. The field should only be visible to admins and the assigned employee:

```twig
{# Session Notes — only visible to admins and assigned employee #}
{% set canViewSessionNotes = currentUser.admin or (currentUser.id is defined and reservation.employeeId and craft.booked.isUserEmployee(currentUser.id, reservation.employeeId)) %}

{% if canViewSessionNotes %}
    <hr>
    <h2>{{ 'sessionNotes.heading'|t('booked') }}</h2>
    <p class="light">{{ 'sessionNotes.description'|t('booked') }}</p>

    {{ forms.textareaField({
        label: 'sessionNotes.label'|t('booked'),
        id: 'sessionNotes',
        name: 'sessionNotes',
        value: reservation.sessionNotes ?? '',
        rows: 6,
        instructions: 'sessionNotes.instructions'|t('booked'),
    }) }}
{% endif %}
```

**Important:** The exact implementation depends on how the edit template is structured. Check the existing `edit.twig` for the forms import pattern and how the reservation variable is named. Adapt accordingly.

If `craft.booked.isUserEmployee()` doesn't exist, the access check can be done in the controller instead — pass a `canEditSessionNotes` boolean to the template:

In the CP controller that renders the edit page, add:

```php
$canEditSessionNotes = $currentUser->admin;
if (!$canEditSessionNotes && $reservation->employeeId) {
    $employees = Booked::getInstance()->getPermission()->getEmployeesForCurrentUser();
    $canEditSessionNotes = collect($employees)->contains('id', $reservation->employeeId);
}
```

Pass `canEditSessionNotes` to the template. This is the preferred approach — keep logic in the controller, not the template.

- [ ] **Step 2: Handle sessionNotes in the controller save action**

In the CP controller that handles the save, ensure `sessionNotes` is read from the request and set on the reservation:

```php
$reservation->sessionNotes = $request->getBodyParam('sessionNotes', $reservation->sessionNotes);
```

Only allow setting if the user has permission (same check as template visibility).

- [ ] **Step 3: Commit**

```bash
git add src/templates/bookings/edit.twig src/controllers/cp/BookingsController.php
git commit -m "feat(session-notes): add access-controlled session notes to CP edit screen"
```

---

## Task 6: Update Install Migration (for fresh installs)

**Files:**
- Modify: `src/migrations/Install.php`

- [ ] **Step 1: Add `sessionNotes` column to Install migration**

In `src/migrations/Install.php`, in the `booked_reservations` table definition, after the `notes` column (line 320):

```php
'sessionNotes' => $this->text(),
```

This ensures fresh installations get the column without needing to run the incremental migration.

- [ ] **Step 2: Commit**

```bash
git add src/migrations/Install.php
git commit -m "feat(session-notes): add sessionNotes to Install migration for fresh installs"
```

---

## Task 7: Add Translations

**Files:**
- Modify: `src/translations/en/booked.php`
- Modify: `src/translations/{de,es,fr,it,ja,nl,pt}/booked.php`

- [ ] **Step 1: Add English translation keys**

```php
'sessionNotes.heading' => 'Session Notes',
'sessionNotes.label' => 'Session Notes',
'sessionNotes.description' => 'Post-appointment notes visible only to the assigned staff member and administrators.',
'sessionNotes.instructions' => 'Add notes about this appointment (only visible to assigned staff and admins).',
```

- [ ] **Step 2: Add same keys to all other language files**

- [ ] **Step 3: Commit**

```bash
git add src/translations/
git commit -m "feat(session-notes): add translation keys"
```

---

## Task 8: Code Quality and Final Verification

- [ ] **Step 1: Run ECS**

Run: `cd plugins/booked && composer ecs:check`
Fix: `composer ecs`

- [ ] **Step 2: Run PHPStan**

Run: `cd plugins/booked && composer phpstan`
Ensure no new errors.

- [ ] **Step 3: Run full test suite**

Run: `cd plugins/booked && composer test`
Expected: All tests pass.

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "chore: fix code style for session notes feature"
```
