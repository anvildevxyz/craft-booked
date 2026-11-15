<?php

/**
 * Exercises the native bookings index's filter-HUD condition rules against a
 * real query (#89).
 *
 * ReservationEmployeeConditionRule, ReservationServiceConditionRule,
 * ReservationLocationConditionRule and ReservationEventDateConditionRule all
 * call ReservationQuery::employeeId()/serviceId()/locationId()/eventDateId()
 * with getElementIds() — an array — but those methods were typed ?int only.
 * Selecting any single value in the filter HUD threw a TypeError and 500'd
 * the whole index; reading the condition rule's source gives no reason to
 * suspect this; Craft's own ElementCondition::modifyQuery() has to actually
 * call it with a real selection to reach the broken line.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/craft-booked/tests/integration-live/reservation-condition-rules.php
 *
 * Read-only against existing data; creates nothing, so there is nothing to
 * clean up. Exits non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\elements\Employee;
use anvildev\booked\elements\EventDate;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\Service;

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

/**
 * @param array<string, string> $methodsToFixtures method name => element class to pick an id from
 */
function exerciseFilter(string $method, string $elementClass): void
{
    $fixture = $elementClass::find()->siteId('*')->status(null)->one();
    if ($fixture === null) {
        echo "  skipping {$method}() — no {$elementClass} exists on this install\n";
        return;
    }

    // Mirrors exactly what a BaseElementSelectConditionRule passes: an array
    // of selected ids, even for a single selection.
    try {
        $count = Reservation::find()->siteId('*')->status(null)->$method([$fixture->id])->count();
        check("{$method}([id]) runs without a TypeError", true, "count={$count}");
    } catch (\TypeError $e) {
        check("{$method}([id]) runs without a TypeError", false, $e->getMessage());
    }
}

echo "Filtering by an array of ids, as the filter HUD's condition rules do:\n";
exerciseFilter('employeeId', Employee::class);
exerciseFilter('locationId', Location::class);
exerciseFilter('serviceId', Service::class);
exerciseFilter('eventDateId', EventDate::class);

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
