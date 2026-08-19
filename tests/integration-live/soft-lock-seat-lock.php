<?php

/**
 * Live integration check for issue #117 — a soft lock on some seats of a group
 * slot must not refuse a new lock while seats remain.
 *
 * The lock endpoints used to run an all-or-nothing check: one customer mid-
 * checkout held the whole slot, and every other customer saw "This time slot
 * is temporarily reserved" even though the calendar still showed free seats.
 * The fix resolves the slot's remaining seats server-side and passes them as
 * the lock capacity, exactly as this script does.
 *
 * Builds employee-less services from per-day-capacity schedules and drives the
 * REAL SoftLockService and CapacityService against the REAL database:
 *
 *   A  capacity 3, empty slot      -> 3 locks grant, the 4th is refused
 *   B  capacity 3, 2 seats booked  -> 1 lock grants, the 2nd is refused
 *   C  no capacity set (1 seat)    -> 1 lock grants, the 2nd is refused
 *
 * Self-cleaning: every element, reservation and soft lock it creates is
 * removed again, including after a failure.
 *
 * Usage (from the Craft project root, DDEV):
 *   ddev exec php plugins/craft-booked/tests/integration-live/soft-lock-seat-lock.php [YYYY-MM-DD]
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\Booked;
use anvildev\booked\elements\Schedule;
use anvildev\booked\elements\Service;

$db = Craft::$app->getDb();
$elements = Craft::$app->getElements();
$plugin = Booked::getInstance();

/** A far-future date, so real bookings and seeded fixtures can never collide. */
$date = $argv[1] ?? '2027-04-14';
$time = '09:00';
$endTime = '10:00';
$prefix = 'ISSUE117';
$marker = 'issue117-' . bin2hex(random_bytes(4));

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

$dayOfWeek = (int)(new DateTime($date))->format('N');

/** Remove every element this script names, plus its reservations and locks. */
$purge = function() use ($elements, $db, $prefix, $marker): void {
    $db->createCommand()->delete('{{%booked_reservations}}', ['like', 'confirmationToken', $marker . '%', false])->execute();

    foreach ([Service::class, Schedule::class] as $cls) {
        foreach ($cls::find()->siteId('*')->status(null)->trashed(null)->all() as $el) {
            if (!str_starts_with((string)$el->title, $prefix)) {
                continue;
            }
            if ($el instanceof Service) {
                $db->createCommand()->delete('{{%booked_soft_locks}}', ['serviceId' => $el->id])->execute();
                $db->createCommand()->delete('{{%booked_reservations}}', ['serviceId' => $el->id])->execute();
            }
            $elements->deleteElement($el, true);
        }
    }
};

/** Seat one confirmed booking on the probe slot. */
$book = function(int $serviceId, string $tag) use ($db, $date, $marker): void {
    $db->createCommand()->insert('{{%booked_reservations}}', [
        'userName' => 'Issue117 ' . $tag,
        'userEmail' => 'issue117@example.test',
        'bookingDate' => $date,
        'startTime' => '09:00:00',
        'endTime' => '10:00:00',
        'status' => 'confirmed',
        'serviceId' => $serviceId,
        'employeeId' => null,
        'quantity' => 1,
        'confirmationToken' => $marker . '-' . $tag,
        'dateCreated' => date('Y-m-d H:i:s'),
        'dateUpdated' => date('Y-m-d H:i:s'),
        'uid' => \craft\helpers\StringHelper::UUID(),
    ])->execute();
};

/** Build an employee-less service from a schedule with the given per-day capacity. */
$fixture = function(string $label, ?int $capacity) use ($elements, $plugin, $prefix): Service {
    $day = [
        'enabled' => true,
        'start' => '09:00',
        'end' => '17:00',
        'breakStart' => null,
        'breakEnd' => null,
        'capacity' => $capacity,
    ];

    $schedule = new Schedule();
    $schedule->title = "{$prefix} Sched {$label}";
    $schedule->workingHours = array_fill_keys(range(1, 7), $day);
    if (!$elements->saveElement($schedule)) {
        throw new RuntimeException("schedule {$label}: " . implode(', ', $schedule->getErrorSummary(true)));
    }

    $service = new Service();
    $service->title = "{$prefix} Svc {$label}";
    $service->durationType = 'minutes';
    $service->duration = 60;
    $service->bufferBefore = 0;
    $service->bufferAfter = 0;
    if (!$elements->saveElement($service)) {
        throw new RuntimeException("service {$label}: " . implode(', ', $service->getErrorSummary(true)));
    }

    $plugin->getScheduleAssignment()->setSchedulesForService($service->id, [$schedule->id]);
    $plugin->getScheduleAssignment()->clearServiceScheduleCache();

    return $service;
};

/**
 * Acquire one lock exactly as SlotController::actionCreateLock() now does:
 * remaining seats resolved server-side and passed as the lock capacity.
 */
$acquire = function(int $serviceId) use ($plugin, $date, $time, $endTime): string|false {
    $capacity = $plugin->getCapacity()->getRemainingCapacityForSlot($date, $time, $endTime, null, $serviceId);

    return $plugin->getSoftLock()->createLock([
        'date' => $date,
        'startTime' => $time,
        'endTime' => $endTime,
        'serviceId' => $serviceId,
        'employeeId' => null,
        'locationId' => null,
        'quantity' => 1,
        'capacity' => $capacity,
    ], 5);
};

$purge();
echo "Issue #117 — seat-aware soft locks\n";
echo "date {$date} (ISO day {$dayOfWeek}), probe slot {$time}-{$endTime}\n";

try {
    $section('A. Group slot: holds grant while seats remain');
    $serviceA = $fixture('A', 3);
    $granted = 0;
    for ($n = 1; $n <= 3; $n++) {
        if ($acquire($serviceA->id) !== false) {
            $granted++;
        }
    }
    $granted === 3
        ? $ok('3 concurrent holds granted on a 3-seat slot')
        : $bad("only {$granted} of 3 holds granted on a 3-seat slot — #117 regressed");
    $acquire($serviceA->id) === false
        ? $ok('4th hold refused once every seat is held')
        : $bad('4th hold granted — the slot is oversold');

    $section('B. Booked seats reduce what holds may take');
    $serviceB = $fixture('B', 3);
    $book($serviceB->id, 'B1');
    $book($serviceB->id, 'B2');
    $acquire($serviceB->id) !== false
        ? $ok('1 hold granted on the last free seat')
        : $bad('hold refused although one seat is still free');
    $acquire($serviceB->id) === false
        ? $ok('2nd hold refused: 2 booked + 1 held = 3 seats')
        : $bad('2nd hold granted — booked seats were not counted');

    $section('C. Single-seat slot keeps the all-or-nothing hold');
    $serviceC = $fixture('C', null);
    $acquire($serviceC->id) !== false
        ? $ok('1st hold granted on a single-seat slot')
        : $bad('1st hold refused on an empty single-seat slot');
    $acquire($serviceC->id) === false
        ? $ok('2nd hold refused on a single-seat slot')
        : $bad('2nd hold granted on a single-seat slot — regression');
} finally {
    $purge();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
