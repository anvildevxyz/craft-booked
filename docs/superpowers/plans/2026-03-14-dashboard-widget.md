# Dashboard Widget Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a Craft dashboard widget showing today's booking stats (count, pending, confirmed) and an upcoming reservations table with configurable lookahead and staff scoping.

**Architecture:** Create a `BookedWidget` extending `craft\base\Widget` with a settings model for lookahead days (1/3/7). The widget queries reservations using `ReservationFactory::find()` with date range filters, scoped by `PermissionService` for staff users. Registered in `Booked.php` via Craft's `EVENT_REGISTER_WIDGET_TYPES` event. This is separate from the plugin's existing CP dashboard page — it appears on Craft's main admin dashboard.

**Tech Stack:** PHP 8.2, Craft CMS 5, Twig, PHPUnit 9.5

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `src/widgets/BookedWidget.php` | Widget class with settings and body rendering |
| Create | `src/templates/widgets/booked.twig` | Widget body Twig template |
| Create | `src/templates/widgets/_settings.twig` | Widget settings Twig template |
| Modify | `src/Booked.php` | Register widget type via event |
| Modify | `src/translations/en/booked.php` | Widget translation keys |
| Modify | `src/translations/{de,es,fr,it,ja,nl,pt}/booked.php` | Widget translation keys |
| Create | `tests/Unit/Widgets/BookedWidgetTest.php` | Test widget settings and display name |

---

## Task 1: Create BookedWidget Class

**Files:**
- Create: `src/widgets/BookedWidget.php`
- Create: `tests/Unit/Widgets/BookedWidgetTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Widgets/BookedWidgetTest.php`:

```php
<?php

namespace anvildev\booked\tests\Unit\Widgets;

use anvildev\booked\tests\Support\TestCase;
use anvildev\booked\widgets\BookedWidget;

class BookedWidgetTest extends TestCase
{
    public function testDisplayName(): void
    {
        $this->requiresCraft();
        $name = BookedWidget::displayName();
        $this->assertNotEmpty($name);
    }

    public function testIconPath(): void
    {
        $widget = new BookedWidget();
        $icon = BookedWidget::icon();
        // icon() should return a path or null
        $this->assertTrue($icon === null || is_string($icon));
    }

    public function testDefaultLookaheadDays(): void
    {
        $widget = new BookedWidget();
        $this->assertEquals(1, $widget->lookaheadDays);
    }

    public function testSettingsValidation(): void
    {
        $widget = new BookedWidget();
        $widget->lookaheadDays = 3;
        $rules = $widget->rules();
        $this->assertNotEmpty($rules);
    }

    public function testValidLookaheadValues(): void
    {
        $this->requiresCraft();
        $widget = new BookedWidget();

        $widget->lookaheadDays = 1;
        $this->assertTrue($widget->validate());

        $widget->lookaheadDays = 3;
        $this->assertTrue($widget->validate());

        $widget->lookaheadDays = 7;
        $this->assertTrue($widget->validate());
    }

    public function testInvalidLookaheadValue(): void
    {
        $this->requiresCraft();
        $widget = new BookedWidget();
        $widget->lookaheadDays = 5;
        $this->assertFalse($widget->validate());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Widgets/BookedWidgetTest.php -v`
Expected: FAIL — class not found

- [ ] **Step 3: Create the widget class**

Create `src/widgets/BookedWidget.php`:

```php
<?php

namespace anvildev\booked\widgets;

use anvildev\booked\Booked;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\records\ReservationRecord;
use Craft;
use craft\base\Widget;

class BookedWidget extends Widget
{
    public int $lookaheadDays = 1;

    public static function displayName(): string
    {
        return Craft::t('booked', 'widget.todaysBookings');
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@booked/icon.svg');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['lookaheadDays'], 'required'];
        $rules[] = [['lookaheadDays'], 'in', 'range' => [1, 3, 7]];
        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('booked/widgets/_settings', [
            'widget' => $this,
        ]);
    }

    public function getBodyHtml(): ?string
    {
        $permissionService = Booked::getInstance()->getPermission();

        $timezone = new \DateTimeZone(Craft::$app->getTimeZone());
        $today = new \DateTime('now', $timezone);
        $today->setTime(0, 0, 0);

        $endDate = (clone $today)->modify('+' . ($this->lookaheadDays - 1) . ' days');

        // Use the return value of scopeReservationQuery() to match existing DashboardService patterns
        $baseQuery = $permissionService->scopeReservationQuery(
            ReservationFactory::find()
                ->bookingDate(['and', '>= ' . $today->format('Y-m-d'), '<= ' . $endDate->format('Y-m-d')])
        );

        // Stats
        $confirmedCount = (clone $baseQuery)->reservationStatus(ReservationRecord::STATUS_CONFIRMED)->count();
        $pendingCount = (clone $baseQuery)->reservationStatus(ReservationRecord::STATUS_PENDING)->count();
        $totalCount = (clone $baseQuery)->reservationStatus([
            ReservationRecord::STATUS_CONFIRMED,
            ReservationRecord::STATUS_PENDING,
        ])->count();

        // Upcoming reservations (next 10, ordered by date+time)
        $upcoming = (clone $baseQuery)
            ->reservationStatus([ReservationRecord::STATUS_CONFIRMED, ReservationRecord::STATUS_PENDING])
            ->orderBy(['booked_reservations.bookingDate' => SORT_ASC, 'booked_reservations.startTime' => SORT_ASC])
            ->limit(10)
            ->all();

        return Craft::$app->getView()->renderTemplate('booked/widgets/booked', [
            'totalCount' => $totalCount,
            'confirmedCount' => $confirmedCount,
            'pendingCount' => $pendingCount,
            'upcoming' => $upcoming,
            'lookaheadDays' => $this->lookaheadDays,
        ]);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Widgets/BookedWidgetTest.php -v`
Expected: PASS (tests that don't require Craft should pass; Craft-dependent ones are skipped)

- [ ] **Step 5: Commit**

```bash
git add src/widgets/BookedWidget.php tests/Unit/Widgets/BookedWidgetTest.php
git commit -m "feat(widget): create BookedWidget class with lookahead settings"
```

---

## Task 2: Create Widget Templates

**Files:**
- Create: `src/templates/widgets/booked.twig`
- Create: `src/templates/widgets/_settings.twig`

- [ ] **Step 1: Create the widget body template**

Create `src/templates/widgets/booked.twig`:

```twig
{% set dayLabel = lookaheadDays == 1 ? 'widget.today'|t('booked') : 'widget.nextDays'|t('booked', { days: lookaheadDays }) %}

<div class="booked-widget">
    <div class="booked-widget-stats" style="display: flex; gap: 12px; margin-bottom: 16px;">
        <div style="flex: 1; text-align: center; padding: 8px; background: var(--ui-control-bg-color); border-radius: 6px;">
            <div style="font-size: 20px; font-weight: 600;">{{ totalCount }}</div>
            <div class="light" style="font-size: 11px;">{{ 'widget.total'|t('booked') }}</div>
        </div>
        <div style="flex: 1; text-align: center; padding: 8px; background: var(--ui-control-bg-color); border-radius: 6px;">
            <div style="font-size: 20px; font-weight: 600; color: var(--green-600);">{{ confirmedCount }}</div>
            <div class="light" style="font-size: 11px;">{{ 'status.confirmed'|t('booked') }}</div>
        </div>
        <div style="flex: 1; text-align: center; padding: 8px; background: var(--ui-control-bg-color); border-radius: 6px;">
            <div style="font-size: 20px; font-weight: 600; color: var(--orange-500);">{{ pendingCount }}</div>
            <div class="light" style="font-size: 11px;">{{ 'status.pending'|t('booked') }}</div>
        </div>
    </div>

    {% if upcoming | length %}
        <table class="data fullwidth">
            <thead>
                <tr>
                    <th>{{ 'reservation.name'|t('booked') }}</th>
                    <th>{{ 'reservation.dateTime'|t('booked') }}</th>
                    <th>{{ 'labels.status'|t('booked') }}</th>
                </tr>
            </thead>
            <tbody>
                {% for r in upcoming %}
                    <tr>
                        <td>
                            <a href="{{ r.getCpEditUrl() }}">{{ r.getUserName() }}</a>
                        </td>
                        <td class="light">
                            {{ r.getBookingDate()|date('short') }}
                            {{ r.getStartTime() }}
                        </td>
                        <td>
                            <span class="status {{ r.getStatus() }}"></span>
                            {{ r.getStatusLabel() }}
                        </td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    {% else %}
        <p class="light" style="text-align: center; padding: 16px 0;">
            {{ 'widget.noBookings'|t('booked') }}
        </p>
    {% endif %}
</div>
```

- [ ] **Step 2: Create the widget settings template**

Create `src/templates/widgets/_settings.twig`:

```twig
{% import '_includes/forms.twig' as forms %}

{{ forms.selectField({
    label: 'widget.lookahead'|t('booked'),
    id: 'lookaheadDays',
    name: 'lookaheadDays',
    value: widget.lookaheadDays,
    options: [
        { label: 'widget.lookahead1'|t('booked'), value: 1 },
        { label: 'widget.lookahead3'|t('booked'), value: 3 },
        { label: 'widget.lookahead7'|t('booked'), value: 7 },
    ],
}) }}
```

- [ ] **Step 3: Commit**

```bash
git add src/templates/widgets/
git commit -m "feat(widget): add widget body and settings Twig templates"
```

---

## Task 3: Register Widget in Booked.php

**Files:**
- Modify: `src/Booked.php`

- [ ] **Step 1: Add widget registration event**

In `src/Booked.php`, in the `init()` method or in an `_attachEventHandlers()` method (wherever other event listeners are registered), add:

```php
use craft\events\RegisterComponentTypesEvent;
use craft\services\Dashboard;

Event::on(
    Dashboard::class,
    Dashboard::EVENT_REGISTER_WIDGET_TYPES,
    function(RegisterComponentTypesEvent $event) {
        $event->types[] = \anvildev\booked\widgets\BookedWidget::class;
    }
);
```

Verify the `use` imports are present. Follow the existing pattern in `Booked.php` for registering component types (look at how element types are registered for the pattern).

- [ ] **Step 2: Run tests**

Run: `cd plugins/booked && composer test`

- [ ] **Step 3: Commit**

```bash
git add src/Booked.php
git commit -m "feat(widget): register BookedWidget on Craft dashboard"
```

---

## Task 4: Add Translations

**Files:**
- Modify: `src/translations/en/booked.php`
- Modify: `src/translations/{de,es,fr,it,ja,nl,pt}/booked.php`

- [ ] **Step 1: Add English translation keys**

```php
'widget.todaysBookings' => "Today's Bookings",
'widget.today' => 'Today',
'widget.nextDays' => 'Next {days} Days',
'widget.total' => 'Total',
'widget.noBookings' => 'No upcoming bookings',
'widget.lookahead' => 'Lookahead',
'widget.lookahead1' => 'Today',
'widget.lookahead3' => 'Next 3 Days',
'widget.lookahead7' => 'Next 7 Days',
```

- [ ] **Step 2: Add same keys to all other language files**

- [ ] **Step 3: Commit**

```bash
git add src/translations/
git commit -m "feat(widget): add translation keys for dashboard widget"
```

---

## Task 5: Code Quality and Final Verification

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
git commit -m "chore: fix code style for dashboard widget feature"
```
