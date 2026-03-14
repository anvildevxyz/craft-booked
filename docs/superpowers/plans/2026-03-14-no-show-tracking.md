# No-Show Tracking Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `no_show` as a formal reservation status with auto-marking via console command and CP statistics.

**Architecture:** Extend the existing three-status system (pending/confirmed/cancelled) with a fourth `no_show` status. No-shows keep the `activeSlotKey` populated (the slot is consumed, unlike cancellations). A console command auto-marks confirmed bookings as no-show after a configurable grace period post-appointment. The CP element index gains a "No Show" source sidebar and element action.

**Tech Stack:** PHP 8.2, Craft CMS 5, PHPUnit 9.5

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Modify | `src/records/ReservationRecord.php` | Add `STATUS_NO_SHOW` constant, update `getStatuses()`, update validation rules, update `activeSlotKey` logic |
| Modify | `src/elements/Reservation.php` | Update `statuses()`, `defineSources()`, `defineActions()`, `defineRules()`, add `markAsNoShow()` |
| Modify | `src/elements/db/ReservationQuery.php` | Update `statusCondition()` |
| Modify | `src/models/ReservationModel.php` | Add `markAsNoShow()`, update `defineRules()` status validation |
| Modify | `src/contracts/ReservationInterface.php` | Add `markAsNoShow(): bool` to interface |
| Create | `src/elements/actions/MarkAsNoShow.php` | CP bulk action for marking reservations as no-show |
| Modify | `src/console/controllers/BookingsController.php` | Add `actionMarkNoShows` command, update `actionValidate()` and `actionList()` valid status arrays |
| Modify | `src/translations/en/booked.php` | Add no-show translation keys |
| Modify | `src/translations/{de,es,fr,it,ja,nl,pt}/booked.php` | Add no-show translation keys (English placeholders) |
| Modify | `src/gql/interfaces/elements/ReservationInterface.php` | Update status description strings |
| Modify | `src/gql/arguments/elements/ReservationArguments.php` | Update status description strings |
| Modify | `tests/Unit/Records/ReservationRecordTest.php` | Test new constant and updated statuses |
| Create | `tests/Unit/Elements/Actions/MarkAsNoShowTest.php` | Test the CP action |
| Modify | `tests/Unit/Elements/ReservationTest.php` | Test updated statuses and `markAsNoShow()` |

### Verified Touch Points (no changes needed)

These files reference statuses but correctly treat no-shows as "active" (slot-consuming) bookings:
- `src/services/AvailabilityService.php:825` — excludes only `STATUS_CANCELLED`, so no-shows still consume slots (correct)
- `src/services/BookingService.php:76` — `!= STATUS_CANCELLED` for upcoming bookings (no-shows count, correct)
- `src/services/BookingValidationService.php:38,152,215` — rate limiting counts no-shows (correct)

---

## Task 1: Add STATUS_NO_SHOW Constant and Update ReservationRecord

**Files:**
- Modify: `src/records/ReservationRecord.php:47-49` (constants), `:65` (validation), `:82-89` (activeSlotKey), `:94-109` (getStatuses)
- Test: `tests/Unit/Records/ReservationRecordTest.php`

- [ ] **Step 1: Write failing tests for the new status constant**

In `tests/Unit/Records/ReservationRecordTest.php`, add:

```php
public function testNoShowStatusConstant(): void
{
    $this->assertEquals('no_show', ReservationRecord::STATUS_NO_SHOW);
}

public function testGetStatusesReturnsFourStatuses(): void
{
    $statuses = ReservationRecord::getStatuses();
    $this->assertCount(4, $statuses);
    $this->assertArrayHasKey('no_show', $statuses);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Records/ReservationRecordTest.php -v`
Expected: FAIL — `STATUS_NO_SHOW` not defined, count is 3 not 4

- [ ] **Step 3: Add the constant and update methods**

In `src/records/ReservationRecord.php`:

```php
// Line 49, add after STATUS_CANCELLED:
public const STATUS_NO_SHOW = 'no_show';
```

Update `rules()` line 65 — add `self::STATUS_NO_SHOW` to the `'in'` range:
```php
[['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW]],
```

Update `beforeSave()` — no-shows keep the slot blocked (only cancelled NULLs the key):
```php
public function beforeSave($insert): bool
{
    $this->activeSlotKey = ($this->status !== self::STATUS_CANCELLED && $this->employeeId !== null)
        ? $this->bookingDate . '|' . $this->startTime . '|' . $this->employeeId
        : null;

    return parent::beforeSave($insert);
}
```
Note: `activeSlotKey` logic already handles this correctly — only `STATUS_CANCELLED` NULLs it. No change needed here.

Update `getStatuses()`:
```php
public static function getStatuses(): array
{
    if (class_exists(Craft::class)) {
        return [
            self::STATUS_PENDING => Craft::t('booked', 'status.pending'),
            self::STATUS_CONFIRMED => Craft::t('booked', 'status.confirmed'),
            self::STATUS_CANCELLED => Craft::t('booked', 'status.cancelled'),
            self::STATUS_NO_SHOW => Craft::t('booked', 'status.noShow'),
        ];
    }

    return [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_CONFIRMED => 'Confirmed',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_NO_SHOW => 'No Show',
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Records/ReservationRecordTest.php -v`
Expected: PASS

- [ ] **Step 5: Update existing test assertion counts**

The existing `testGetStatusesReturnsAllThreeStatuses` now needs to assert 4. Update the test name and count:

```php
public function testGetStatusesReturnsAllFourStatuses(): void
{
    $statuses = ReservationRecord::getStatuses();
    $this->assertCount(4, $statuses);
    $this->assertArrayHasKey('pending', $statuses);
    $this->assertArrayHasKey('confirmed', $statuses);
    $this->assertArrayHasKey('cancelled', $statuses);
    $this->assertArrayHasKey('no_show', $statuses);
}
```

- [ ] **Step 6: Run full test suite to check for regressions**

Run: `cd plugins/booked && composer test`
Expected: All tests pass (note any tests that hardcode status count = 3)

- [ ] **Step 7: Commit**

```bash
git add src/records/ReservationRecord.php tests/Unit/Records/ReservationRecordTest.php
git commit -m "feat(no-show): add STATUS_NO_SHOW constant and update ReservationRecord"
```

---

## Task 2: Update Reservation Element

**Files:**
- Modify: `src/elements/Reservation.php:260-263` (statuses), `:346-380` (defineSources), `:457-482` (defineRules), add `markAsNoShow()` method
- Modify: `src/contracts/ReservationInterface.php` — add `markAsNoShow(): bool`
- Modify: `src/models/ReservationModel.php` — add `markAsNoShow()` implementation
- Test: `tests/Unit/Elements/ReservationTest.php`

- [ ] **Step 1: Write failing tests**

In `tests/Unit/Elements/ReservationTest.php` (or create if it doesn't exist), add tests for the updated statuses array and the no-show source:

```php
public function testStatusesIncludesNoShow(): void
{
    $statuses = Reservation::statuses();
    $this->assertArrayHasKey('no_show', $statuses);
    $this->assertCount(4, $statuses);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd plugins/booked && ./vendor/bin/phpunit --filter testStatusesIncludesNoShow -v`
Expected: FAIL

- [ ] **Step 3: Update `statuses()` method**

In `src/elements/Reservation.php` line 260-263:

```php
public static function statuses(): array
{
    return [
        'confirmed' => 'green',
        'pending' => 'orange',
        'cancelled' => null,
        'no_show' => 'red',
    ];
}
```

- [ ] **Step 4: Update `defineSources()` — add No Show sidebar**

After the 'cancelled' source block (line 378), add:

```php
[
    'key' => 'no_show',
    'label' => Craft::t('booked', 'status.noShow'),
    'criteria' => ['reservationStatus' => ReservationRecord::STATUS_NO_SHOW],
    'defaultSort' => ['bookingDate', 'desc'],
    'type' => 'native',
],
```

- [ ] **Step 5: Update `defineRules()` — add no_show to status validation**

In `src/elements/Reservation.php` line 466-470, add `ReservationRecord::STATUS_NO_SHOW`:

```php
[['status'], 'in', 'range' => [
    ReservationRecord::STATUS_PENDING,
    ReservationRecord::STATUS_CONFIRMED,
    ReservationRecord::STATUS_CANCELLED,
    ReservationRecord::STATUS_NO_SHOW,
]],
```

- [ ] **Step 6: Add `markAsNoShow()` method to Reservation**

After the `cancel()` method (line 771):

```php
public function markAsNoShow(): bool
{
    if ($this->status === ReservationRecord::STATUS_CANCELLED) {
        return false;
    }
    if ($this->status === ReservationRecord::STATUS_NO_SHOW) {
        return false;
    }

    $this->status = ReservationRecord::STATUS_NO_SHOW;
    return Craft::$app->elements->saveElement($this);
}
```

- [ ] **Step 7: Add `markAsNoShow()` to ReservationInterface**

In `src/contracts/ReservationInterface.php`, add:

```php
public function markAsNoShow(): bool;
```

- [ ] **Step 8: Update `ReservationModel::defineRules()` status validation**

In `src/models/ReservationModel.php`, find the status `'in'` range validation (around line 513-517) and add `STATUS_NO_SHOW`:

```php
[['status'], 'in', 'range' => [
    ReservationRecord::STATUS_PENDING,
    ReservationRecord::STATUS_CONFIRMED,
    ReservationRecord::STATUS_CANCELLED,
    ReservationRecord::STATUS_NO_SHOW,
]],
```

**Critical:** Without this, `markAsNoShow()` on ReservationModel will fail validation when `save()` is called.

- [ ] **Step 9: Add `markAsNoShow()` to ReservationModel**

In `src/models/ReservationModel.php`, add the method:

```php
public function markAsNoShow(): bool
{
    if ($this->status === ReservationRecord::STATUS_CANCELLED) {
        return false;
    }
    if ($this->status === ReservationRecord::STATUS_NO_SHOW) {
        return false;
    }

    $this->status = ReservationRecord::STATUS_NO_SHOW;
    return $this->save(false);
}
```

Note: Uses `$this->save(false)` (skip validation) to match the existing `cancel()` method pattern at line 297.

- [ ] **Step 10: Update `afterSave()` to detect no-show transitions for waitlist**

In `src/elements/Reservation.php`, around line 649-653, the existing code only triggers waitlist on cancellation. No-shows should NOT free waitlist slots (the appointment happened, the customer just didn't show). No changes needed here.

- [ ] **Step 11: Run tests**

Run: `cd plugins/booked && composer test`
Expected: PASS

- [ ] **Step 12: Commit**

```bash
git add src/elements/Reservation.php src/contracts/ReservationInterface.php src/models/ReservationModel.php
git commit -m "feat(no-show): update Reservation element with no_show status, sources, and markAsNoShow()"
```

---

## Task 3: Update ReservationQuery

**Files:**
- Modify: `src/elements/db/ReservationQuery.php:153-159`

- [ ] **Step 1: Update `statusCondition()` to handle no_show**

```php
protected function statusCondition(string $status): mixed
{
    return match ($status) {
        'confirmed', 'pending', 'cancelled', 'no_show' => ['booked_reservations.status' => $status],
        default => parent::statusCondition($status),
    };
}
```

- [ ] **Step 2: Run tests**

Run: `cd plugins/booked && composer test`

- [ ] **Step 3: Commit**

```bash
git add src/elements/db/ReservationQuery.php
git commit -m "feat(no-show): add no_show to ReservationQuery statusCondition"
```

---

## Task 4: Create MarkAsNoShow Element Action

**Files:**
- Create: `src/elements/actions/MarkAsNoShow.php`
- Modify: `src/elements/Reservation.php:332-335` (defineActions)
- Create: `tests/Unit/Elements/Actions/MarkAsNoShowTest.php`

- [ ] **Step 1: Write failing test for the action**

Create `tests/Unit/Elements/Actions/MarkAsNoShowTest.php`:

```php
<?php

namespace anvildev\booked\tests\Unit\Elements\Actions;

use anvildev\booked\elements\actions\MarkAsNoShow;
use anvildev\booked\tests\Support\TestCase;

class MarkAsNoShowTest extends TestCase
{
    public function testDisplayName(): void
    {
        $this->requiresCraft();
        $action = new MarkAsNoShow();
        $this->assertNotEmpty($action::displayName());
    }

    public function testConfirmationMessage(): void
    {
        $this->requiresCraft();
        $action = new MarkAsNoShow();
        $this->assertNotEmpty($action->getConfirmationMessage());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Elements/Actions/MarkAsNoShowTest.php -v`
Expected: FAIL — class not found

- [ ] **Step 3: Create the action class**

Create `src/elements/actions/MarkAsNoShow.php`:

```php
<?php

namespace anvildev\booked\elements\actions;

use anvildev\booked\records\ReservationRecord;
use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;

class MarkAsNoShow extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('booked', 'action.markAsNoShow');
    }

    public function getConfirmationMessage(): ?string
    {
        return Craft::t('booked', 'action.markAsNoShowConfirm');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $count = 0;
        foreach ($query->all() as $reservation) {
            if ($reservation->status === ReservationRecord::STATUS_CANCELLED
                || $reservation->status === ReservationRecord::STATUS_NO_SHOW) {
                continue;
            }
            $reservation->status = ReservationRecord::STATUS_NO_SHOW;
            if (Craft::$app->elements->saveElement($reservation)) {
                $count++;
            }
        }

        $this->setMessage(Craft::t('booked', 'action.markedAsNoShow', ['count' => $count]));
        return true;
    }
}
```

- [ ] **Step 4: Register action in `defineActions()`**

In `src/elements/Reservation.php`, update `defineActions()`:

```php
protected static function defineActions(?string $source = null): array
{
    return [
        actions\MarkAsNoShow::class,
        Delete::class,
    ];
}
```

- [ ] **Step 5: Run tests**

Run: `cd plugins/booked && composer test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/elements/actions/MarkAsNoShow.php src/elements/Reservation.php tests/Unit/Elements/Actions/MarkAsNoShowTest.php
git commit -m "feat(no-show): add MarkAsNoShow CP element action"
```

---

## Task 5: Add Console Command for Auto-Marking No-Shows

**Files:**
- Modify: `src/console/controllers/BookingsController.php`
- Create: `tests/Unit/Console/MarkNoShowsCommandTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Console/MarkNoShowsCommandTest.php`:

```php
<?php

namespace anvildev\booked\tests\Unit\Console;

use anvildev\booked\console\controllers\BookingsController;
use anvildev\booked\tests\Support\TestCase;

class MarkNoShowsCommandTest extends TestCase
{
    public function testCommandOptionsIncludeGracePeriod(): void
    {
        $this->requiresCraft();
        $controller = new BookingsController('bookings', null);
        $options = $controller->options('mark-no-shows');
        $this->assertContains('gracePeriod', $options);
        $this->assertContains('dryRun', $options);
    }
}
```

- [ ] **Step 2: Add properties and options to BookingsController**

In `src/console/controllers/BookingsController.php`, add properties:

```php
public int $gracePeriod = 30;
public bool $dryRun = false;
```

Update `options()`:

```php
'mark-no-shows' => [...parent::options($actionID), 'gracePeriod', 'dryRun'],
```

- [ ] **Step 3: Implement `actionMarkNoShows()`**

```php
/**
 * Auto-mark confirmed bookings as no-show when their appointment end time
 * plus a grace period has passed without being checked in.
 *
 * Usage: php craft booked/bookings/mark-no-shows --grace-period=30 --dry-run
 */
public function actionMarkNoShows(): int
{
    $this->stdout("\nNo-Show Auto-Detection\n", Console::BOLD);
    $this->stdout("═══════════════════════════════════\n\n");
    $this->stdout("Grace period: {$this->gracePeriod} minutes\n");

    $cutoff = new \DateTime();
    $cutoff->modify("-{$this->gracePeriod} minutes");

    // Broad date filter — precise end-time comparison happens in the loop below.
    // ReservationFactory::find() already sets siteId('*'), no need to repeat.
    $reservations = ReservationFactory::find()
        ->reservationStatus(ReservationRecord::STATUS_CONFIRMED)
        ->bookingDate(['<=', $cutoff->format('Y-m-d')])
        ->all();

    $marked = 0;
    $skipped = 0;

    foreach ($reservations as $reservation) {
        // Build full end datetime to compare against cutoff
        $endDateTime = \DateTime::createFromFormat(
            'Y-m-d H:i',
            $reservation->getBookingDate() . ' ' . $reservation->getEndTime()
        );

        if (!$endDateTime || $endDateTime > $cutoff) {
            $skipped++;
            continue;
        }

        if ($this->dryRun) {
            $this->stdout("  [DRY RUN] Would mark #{$reservation->getId()} ({$reservation->getUserName()}) as no-show\n");
            $marked++;
            continue;
        }

        if ($reservation->markAsNoShow()) {
            $this->stdout("  ✓ Marked #{$reservation->getId()} ({$reservation->getUserName()}) as no-show\n", Console::FG_GREEN);
            $marked++;
        } else {
            $this->stderr("  ✗ Failed to mark #{$reservation->getId()}\n", Console::FG_RED);
        }
    }

    $this->stdout("\n{$marked} booking(s) marked as no-show, {$skipped} skipped.\n\n");
    return ExitCode::OK;
}
```

- [ ] **Step 4: Run tests**

Run: `cd plugins/booked && composer test`

- [ ] **Step 5: Commit**

```bash
git add src/console/controllers/BookingsController.php tests/Unit/Console/MarkNoShowsCommandTest.php
git commit -m "feat(no-show): add mark-no-shows console command with grace period"
```

---

## Task 6: Update Hardcoded Status References

**Files:**
- Modify: `src/console/controllers/BookingsController.php` — `actionValidate()` line ~91, `actionList()` line ~142, `printReservationRow()` line ~389, `actionInfo()` line ~188
- Modify: `src/gql/interfaces/elements/ReservationInterface.php` — line ~90
- Modify: `src/gql/arguments/elements/ReservationArguments.php` — line ~14

- [ ] **Step 1: Update `actionValidate()` valid statuses**

In `src/console/controllers/BookingsController.php`, find the `$validStatuses` array (around line 91) and add `STATUS_NO_SHOW`:

```php
$validStatuses = [
    ReservationRecord::STATUS_PENDING,
    ReservationRecord::STATUS_CONFIRMED,
    ReservationRecord::STATUS_CANCELLED,
    ReservationRecord::STATUS_NO_SHOW,
];
```

- [ ] **Step 2: Update `actionList()` status filter**

Find the hardcoded valid status array in `actionList()` (around line 142) and add `STATUS_NO_SHOW`.

- [ ] **Step 3: Update `printReservationRow()` status color mapping**

In `printReservationRow()` (around line 389-394), add a match arm for no-show:

```php
'no_show' => Console::FG_RED,
```

- [ ] **Step 4: Update `actionInfo()` status color**

In `actionInfo()` (around line 188-193), add:

```php
'no_show' => Console::FG_RED,
```

- [ ] **Step 5: Update GraphQL status descriptions**

In `src/gql/interfaces/elements/ReservationInterface.php` (~line 90) and `src/gql/arguments/elements/ReservationArguments.php` (~line 14), update status description strings to include `no_show` and remove stale `completed` reference:

```php
'description' => 'Filter by reservation status (pending, confirmed, cancelled, no_show).'
```

- [ ] **Step 6: Run tests**

Run: `cd plugins/booked && composer test`

- [ ] **Step 7: Commit**

```bash
git add src/console/controllers/BookingsController.php src/gql/
git commit -m "feat(no-show): update hardcoded status references in console commands and GraphQL"
```

---

## Task 7: Add Translations

**Files:**
- Modify: `src/translations/en/booked.php`
- Modify: `src/translations/{de,es,fr,it,ja,nl,pt}/booked.php`

- [ ] **Step 1: Add English translation keys**

```php
'status.noShow' => 'No Show',
'action.markAsNoShow' => 'Mark as No Show',
'action.markAsNoShowConfirm' => 'Are you sure you want to mark the selected bookings as no-show?',
'action.markedAsNoShow' => '{count} booking(s) marked as no-show.',
```

- [ ] **Step 2: Add same keys to all other language files** (with English values as placeholders)

- [ ] **Step 3: Commit**

```bash
git add src/translations/
git commit -m "feat(no-show): add translation keys for no-show status"
```

---

## Task 8: Run Code Quality Checks

- [ ] **Step 1: Run ECS**

Run: `cd plugins/booked && composer ecs:check`
Fix any issues: `composer ecs`

- [ ] **Step 2: Run PHPStan**

Run: `cd plugins/booked && composer phpstan`
Ensure no NEW errors introduced.

- [ ] **Step 3: Run full test suite**

Run: `cd plugins/booked && composer test`
Expected: All tests pass.

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "chore: fix code style and static analysis for no-show feature"
```
