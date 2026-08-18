<?php

/**
 * Live integration check: the memoized schedule lookups never serve stale data.
 *
 * One availability request resolves the same employees three times over — the
 * working-hours lookup, the slot subtraction and the capacity enrichment each
 * ask independently — so ScheduleAssignmentService memoizes them for the
 * request. A memo that outlives a change is worse than the queries it saves:
 * availability would advertise seats a schedule no longer grants.
 *
 * Every way a schedule can change mid-request is exercised here: editing the
 * element, reassigning it, unassigning it, and rewriting the row behind the
 * element layer (which only the explicit clear can catch).
 *
 * Usage (from the Craft project root, DDEV):
 *   ddev exec php plugins/craft-booked/tests/integration-live/schedule-cache-invalidation.php
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\Booked;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Schedule;
use anvildev\booked\elements\Service;

$db = Craft::$app->getDb();
$elements = Craft::$app->getElements();
$plugin = Booked::getInstance();
$prefix = 'CACHECHK';
$date = $argv[1] ?? '2027-08-04';

$pass = 0;
$fail = 0;
$ok = function(string $m) use (&$pass) {
    echo "  \u{2713} {$m}\n";
    $pass++;
};
$bad = function(string $m) use (&$fail) {
    echo "  \u{2717} {$m}\n";
    $fail++;
};
$section = fn(string $t) => print("\n=== {$t} ===\n");

$purge = function() use ($elements, $db, $prefix) {
    foreach ([Service::class, Schedule::class, Employee::class] as $cls) {
        foreach ($cls::find()->siteId('*')->status(null)->trashed(null)->all() as $el) {
            if (!str_starts_with((string)$el->title, $prefix)) {
                continue;
            }
            if ($el instanceof Service) {
                $db->createCommand()->delete('{{%booked_reservations}}', ['serviceId' => $el->id])->execute();
            }
            $elements->deleteElement($el, true);
        }
    }
};

/** Seats the calendar reports for 09:00, or null when the slot is gone. */
$seats = function(int $serviceId) use ($plugin, $date): ?int {
    // Only the slot cache is cleared: the schedule memo is what is under test,
    // and clearing it here would prove nothing.
    $plugin->getAvailability()->clearSlotCache();
    foreach ($plugin->getAvailability()->getAvailableSlots($date, null, null, $serviceId) as $slot) {
        if (substr($slot['time'], 0, 5) === '09:00') {
            return $slot['availableCapacity'] ?? null;
        }
    }
    return null;
};

$day = fn(?int $capacity) => [
    'enabled' => true, 'start' => '09:00', 'end' => '17:00',
    'breakStart' => null, 'breakEnd' => null, 'capacity' => $capacity,
];

$purge();
echo "Schedule cache invalidation\ndate {$date}\n";

try {
    $schedule = new Schedule();
    $schedule->title = "{$prefix} Sched";
    $schedule->workingHours = array_fill_keys(range(1, 7), $day(3));
    $elements->saveElement($schedule);

    $service = new Service();
    $service->title = "{$prefix} Svc";
    $service->duration = 60;
    $service->bufferBefore = 0;
    $service->bufferAfter = 0;
    $elements->saveElement($service);
    $plugin->getScheduleAssignment()->setSchedulesForService($service->id, [$schedule->id]);

    $employee = new Employee();
    $employee->title = "{$prefix} Emp";
    $employee->email = 'cachechk@example.test';
    $employee->serviceIds = [$service->id];
    $employee->workingHours = array_fill_keys(range(1, 7), ['enabled' => false]);
    $elements->saveElement($employee);
    $plugin->getScheduleAssignment()->setSchedulesForEmployee($employee->id, [$schedule->id]);

    $section('A. The memo answers the same question twice');
    $seats($service->id) === 3
        ? $ok('3 seats on the first read')
        : $bad('expected 3 seats, got ' . var_export($seats($service->id), true));
    $seats($service->id) === 3
        ? $ok('3 seats on the second read')
        : $bad('the second read disagreed with the first');

    $section('B. Editing the schedule element is seen immediately');
    $schedule->workingHours = array_fill_keys(range(1, 7), $day(5));
    $elements->saveElement($schedule);
    $seats($service->id) === 5
        ? $ok('a capacity raised to 5 is reflected without clearing anything')
        : $bad('stale after an element save: got ' . var_export($seats($service->id), true) . ', expected 5');

    $section('C. Reassigning the employee is seen immediately');
    $other = new Schedule();
    $other->title = "{$prefix} Sched B";
    $other->workingHours = array_fill_keys(range(1, 7), $day(2));
    $elements->saveElement($other);

    // Warm the memo AFTER creating the schedule: saving an element clears the
    // caches on its own, so without this read the assignment write would be
    // invalidating an already-empty memo and prove nothing.
    $seats($service->id);

    $plugin->getScheduleAssignment()->setSchedulesForEmployee($employee->id, [$other->id]);
    $seats($service->id) === 2
        ? $ok('the employee\'s new schedule of 2 is used at once')
        : $bad('stale after reassignment: got ' . var_export($seats($service->id), true) . ', expected 2');

    $seats($service->id); // warm again before the next write
    $plugin->getScheduleAssignment()->unassignScheduleFromEmployee($other->id, $employee->id);
    $seats($service->id) === 5
        ? $ok('unassigning falls back to the service schedule of 5')
        : $bad('stale after unassignment: got ' . var_export($seats($service->id), true) . ', expected 5');

    $section('D. A row rewritten behind the element layer needs the explicit clear');
    // This is how the regression suites mutate schedules. The memo cannot see it,
    // which is exactly why clearServiceScheduleCache() empties both caches.
    $db->createCommand()->update(
        '{{%booked_schedules}}',
        ['workingHours' => array_fill_keys(range(1, 7), $day(7))],
        ['id' => $schedule->id],
    )->execute();
    Craft::$app->getElements()->invalidateCachesForElementType(Schedule::class);
    $plugin->getScheduleAssignment()->clearServiceScheduleCache();

    $seats($service->id) === 7
        ? $ok('clearServiceScheduleCache() also clears the employee memo')
        : $bad('stale after a raw update + clear: got ' . var_export($seats($service->id), true) . ', expected 7');

    $section('E. Deleting the schedule is seen immediately');
    $elements->deleteElement($schedule, true);
    $seats($service->id) === null
        ? $ok('the slot disappears once its schedule is gone')
        : $bad('the slot survived its schedule: ' . var_export($seats($service->id), true));
} catch (Throwable $e) {
    $bad('threw: ' . $e->getMessage());
} finally {
    $purge();
}

echo "\n" . str_repeat('=', 40) . "\n";
echo "passed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
