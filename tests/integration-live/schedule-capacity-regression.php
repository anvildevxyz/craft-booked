<?php

/**
 * Regression sweep around the issue #85 capacity fix.
 *
 * `schedule-capacity.php` proves the fixed behaviour. This script guards the
 * neighbouring behaviour the fix could plausibly have disturbed: overlapping
 * slot intervals, multi-day services, rescheduling, soft locks on group slots,
 * blackouts, breaks, cross-service isolation, booking statuses, concurrency,
 * timezones, and the employee-based paths that share the buffer helper.
 *
 * Runs the REAL services against the REAL database. Self-cleaning: seeded rows
 * are removed and every fixture attribute is restored.
 *
 * Usage (from the Craft project root, DDEV):
 *   ddev exec php plugins/craft-booked/tests/integration-live/schedule-capacity-regression.php
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\Booked;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Service;
use anvildev\booked\records\ReservationRecord;

$db = Craft::$app->getDb();
$plugin = Booked::getInstance();

$serviceId = (int)(getenv('SERVICE_ID') ?: 1236);      // employee-less, schedule-driven
$multiDayServiceId = (int)(getenv('MULTIDAY_SERVICE_ID') ?: 1219); // flexible_days, same schedule
$employeeServiceId = (int)(getenv('EMPLOYEE_SERVICE_ID') ?: 2098);
$date = getenv('DATE') ?: '2027-03-01';                 // a Monday, far from fixture data
$time = '09:00';
$marker = 'issue85reg-' . bin2hex(random_bytes(4));

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

$service = Service::find()->siteId('*')->id($serviceId)->status(null)->one();
if (!$service) {
    exit("Service {$serviceId} not found\n");
}

$scheduleId = (int)$db->createCommand(
    'SELECT scheduleId FROM {{%booked_service_schedule_assignments}} WHERE serviceId = :s ORDER BY sortOrder LIMIT 1',
    [':s' => $serviceId],
)->queryScalar();

// JSON column — hand Craft arrays, never pre-encoded strings.
$originalHours = json_decode((string)$db->createCommand(
    'SELECT workingHours FROM {{%booked_schedules}} WHERE id = :id',
    [':id' => $scheduleId],
)->queryScalar(), true) ?: [];

$originalService = $db->createCommand(
    'SELECT bufferBefore, bufferAfter, timeSlotLength FROM {{%booked_services}} WHERE id = :id',
    [':id' => $serviceId],
)->queryOne();

$setCapacity = function(?int $capacity) use ($db, $scheduleId, $originalHours, $plugin) {
    $hours = $originalHours;
    foreach (array_keys($hours) as $day) {
        $hours[$day]['capacity'] = $capacity;
    }
    $db->createCommand()->update('{{%booked_schedules}}', ['workingHours' => $hours], ['id' => $scheduleId])->execute();
    $plugin->getScheduleAssignment()->clearServiceScheduleCache();
    $plugin->getAvailability()->clearSlotCache();
};

$setService = function(array $attributes) use ($db, $serviceId) {
    $db->createCommand()->update('{{%booked_services}}', $attributes, ['id' => $serviceId])->execute();
    Craft::$app->getElements()->invalidateCachesForElementType(Service::class);
};

$seed = function(
    string $startTime,
    string $endTime,
    int $quantity = 1,
    string $status = ReservationRecord::STATUS_CONFIRMED,
    ?int $employeeId = null,
    ?int $forServiceId = null,
    ?string $onDate = null,
    ?string $endDate = null,
) use ($db, $date, $marker, $serviceId): int {
    $db->createCommand()->insert('{{%booked_reservations}}', [
        'userName' => 'Issue 85 regression',
        'userEmail' => $marker . '-' . bin2hex(random_bytes(3)) . '@example.test',
        'bookingDate' => $onDate ?? $date,
        'endDate' => $endDate,
        'startTime' => $startTime,
        'endTime' => $endTime,
        'status' => $status,
        'employeeId' => $employeeId,
        'serviceId' => $forServiceId ?? $serviceId,
        'quantity' => $quantity,
        'confirmationToken' => $marker . bin2hex(random_bytes(16)),
        'dateCreated' => gmdate('Y-m-d H:i:s'),
        'dateUpdated' => gmdate('Y-m-d H:i:s'),
        'uid' => \craft\helpers\StringHelper::UUID(),
    ])->execute();
    return (int)$db->getLastInsertID();
};

$clear = function() use ($db, $marker, $serviceId, $plugin) {
    $db->createCommand()->delete('{{%booked_reservations}}', ['like', 'userEmail', $marker . '%', false])->execute();
    $db->createCommand()->delete('{{%booked_soft_locks}}', ['like', 'token', $marker . '%', false])->execute();
    $plugin->getAvailability()->clearSlotCache();
    $plugin->getScheduleAssignment()->clearServiceScheduleCache();
};

$slots = function(?int $forServiceId = null, ?int $employeeId = null, int $quantity = 1, ?string $onDate = null, ?string $token = null) use ($plugin, $date, $serviceId): array {
    $plugin->getAvailability()->clearSlotCache();
    $plugin->getScheduleAssignment()->clearServiceScheduleCache();
    return $plugin->getAvailability()->getAvailableSlots(
        $onDate ?? $date, $employeeId, null, $forServiceId ?? $serviceId, $quantity, null, $token,
    );
};

$at = function(array $slots, string $t): ?array {
    foreach ($slots as $s) {
        if (($s['time'] ?? '') === $t) {
            return $s;
        }
    }
    return null;
};

$expect = function($actual, $expected, string $label) use ($ok, $bad) {
    $actual === $expected
        ? $ok("{$label} = " . var_export($expected, true))
        : $bad("{$label} = " . var_export($actual, true) . ", expected " . var_export($expected, true));
};

$duration = (int)($service->duration ?? 60);
$endTime = date('H:i:s', strtotime($time) + $duration * 60);

echo "Service #{$serviceId} ({$duration}min) | multi-day #{$multiDayServiceId} | employees #{$employeeServiceId} | {$date}\n";
$clear();

try {
    // -----------------------------------------------------------------------
    $section('J. Overlapping slots (interval shorter than duration)');
    $setCapacity(3);
    $setService(['timeSlotLength' => 30]);

    $all = $slots();
    $expect($at($all, '09:30') !== null, true, 'half-hour offsets are generated');

    $seed($time . ':00', $endTime);
    $all = $slots();
    $expect($at($all, '09:00')['availableCapacity'] ?? null, 2, '09:00 (the booked slot)');
    $expect($at($all, '09:30')['availableCapacity'] ?? null, 2, '09:30 (overlaps the booking)');
    $expect($at($all, '10:00')['availableCapacity'] ?? null, 3, '10:00 (clear of the booking)');

    // Saturate and confirm every overlapping start disappears together.
    $seed($time . ':00', $endTime);
    $seed($time . ':00', $endTime);
    $all = $slots();
    $expect($at($all, '09:00'), null, '09:00 once saturated');
    $expect($at($all, '09:30'), null, '09:30 once the overlap is saturated');
    $expect($at($all, '10:00')['availableCapacity'] ?? null, 3, '10:00 still untouched');

    $setService(['timeSlotLength' => $originalService['timeSlotLength']]);

    // -----------------------------------------------------------------------
    $section('K. Breaks and blackouts still win over capacity');
    $clear();
    $setCapacity(5);

    $all = $slots();
    $expect($at($all, '12:00'), null, 'the 12:00-13:00 break is excluded');
    $expect($at($all, '13:00') !== null, true, 'slots resume after the break');

    $blackoutDate = date('Y-m-d', strtotime($date . ' +1 day'));
    $blackout = new \anvildev\booked\elements\BlackoutDate();
    $blackout->title = 'Issue 85 regression blackout';
    $blackout->startDate = $blackoutDate;
    $blackout->endDate = $blackoutDate;
    $blackoutSaved = Craft::$app->getElements()->saveElement($blackout);

    if (!$blackoutSaved) {
        $bad('could not save the blackout fixture: ' . json_encode($blackout->getErrors()));
    } else {
        $expect($slots(null, null, 1, $blackoutDate), [], 'a blacked-out day yields no slots despite capacity 5');
        Craft::$app->getElements()->deleteElement($blackout, true);
    }

    // -----------------------------------------------------------------------
    $section('L. Booking status decides seat consumption');
    $clear();
    $setCapacity(4);

    foreach ([
        [ReservationRecord::STATUS_CONFIRMED, 3, 'confirmed holds a seat'],
        [ReservationRecord::STATUS_PENDING, 3, 'pending holds a seat'],
        [ReservationRecord::STATUS_NO_SHOW, 3, 'no-show keeps holding its seat'],
        [ReservationRecord::STATUS_CANCELLED, 4, 'cancelled releases its seat'],
    ] as [$status, $expected, $label]) {
        $clear();
        $seed($time . ':00', $endTime, 1, $status);
        $expect($at($slots(), $time)['availableCapacity'] ?? null, $expected, $label);
    }

    // -----------------------------------------------------------------------
    $section('M. Cross-service bookings do not consume this service capacity');
    $clear();
    $setCapacity(3);

    $seed($time . ':00', $endTime, 1, ReservationRecord::STATUS_CONFIRMED, null, $multiDayServiceId);
    $expect($at($slots(), $time)['availableCapacity'] ?? null, 3, 'another service booking leaves the seats alone');

    // -----------------------------------------------------------------------
    $section('N. Soft locks hold seats without blanking a group slot (issue #74)');
    $clear();
    $setCapacity(4);

    $token = $marker . '-lock';
    $db->createCommand()->insert('{{%booked_soft_locks}}', [
        'token' => $token,
        'sessionHash' => str_repeat('a', 64),
        'serviceId' => $serviceId,
        'employeeId' => null,
        'locationId' => null,
        'date' => $date,
        'endDate' => $date,
        'startTime' => $time . ':00',
        'endTime' => $endTime,
        'quantity' => 1,
        'expiresAt' => gmdate('Y-m-d H:i:s', time() + 3600),
        'dateCreated' => gmdate('Y-m-d H:i:s'),
        'dateUpdated' => gmdate('Y-m-d H:i:s'),
        'uid' => \craft\helpers\StringHelper::UUID(),
    ])->execute();

    $held = $at($slots(), $time);
    $expect($held !== null, true, 'a held group slot is still offered');
    $expect($held['availableCapacity'] ?? null, 3, 'the hold consumes exactly one seat');
    $expect($at($slots(null, null, 1, null, $token), $time)['availableCapacity'] ?? null, 4, 'the lock holder sees its own seat');

    // A hold plus bookings must saturate together.
    $seed($time . ':00', $endTime, 3);
    $expect($at($slots(), $time), null, '3 bookings + 1 hold fills capacity 4');

    // -----------------------------------------------------------------------
    $section('O. Rescheduling can land on the slot it already occupies');
    $clear();
    $setCapacity(1);

    $reservationId = $seed($time . ':00', $endTime);
    $expect($at($slots(), $time), null, 'the slot is closed to everyone else');

    $plugin->getAvailability()->clearSlotCache();
    $expect(
        $plugin->getAvailability()->isSlotAvailable($date, $time, substr($endTime, 0, 5), null, null, $serviceId, 1, null, 0, $reservationId),
        true,
        'excluding the booking being moved reopens its own slot',
    );

    // -----------------------------------------------------------------------
    $section('P. Quantity requests are bounded by capacity');
    $clear();
    $setCapacity(5);

    $endHm = substr($endTime, 0, 5);
    foreach ([[5, true, 'exactly the capacity'], [6, false, 'one past the capacity']] as [$qty, $expected, $label]) {
        $plugin->getAvailability()->clearSlotCache();
        $expect($plugin->getAvailability()->isSlotAvailable($date, $time, $endHm, null, null, $serviceId, $qty), $expected, "quantity {$qty} — {$label}");
    }

    $seed($time . ':00', $endTime, 2);
    $plugin->getAvailability()->clearSlotCache();
    $expect($plugin->getAvailability()->isSlotAvailable($date, $time, $endHm, null, null, $serviceId, 3), true, 'quantity 3 into 3 remaining');
    $plugin->getAvailability()->clearSlotCache();
    $expect($plugin->getAvailability()->isSlotAvailable($date, $time, $endHm, null, null, $serviceId, 4), false, 'quantity 4 into 3 remaining');

    // -----------------------------------------------------------------------
    $section('Q. Concurrency — the last seat is only sold once');
    $clear();
    $setCapacity(1);

    $results = [];
    for ($i = 0; $i < 2; $i++) {
        try {
            $plugin->getBooking()->createBooking([
                'customerName' => 'Issue 85 race',
                'customerEmail' => $marker . '-race' . $i . '@example.test',
                'date' => $date,
                'time' => $time,
                'serviceId' => $serviceId,
                'quantity' => 1,
            ]);
            $results[] = 'accepted';
        } catch (\Throwable $e) {
            $results[] = 'rejected';
        }
    }
    $expect($results, ['accepted', 'rejected'], 'two attempts at a single seat');

    // -----------------------------------------------------------------------
    $section('R. Multi-day services resolve capacity without fataling');
    $clear();
    $setCapacity(3);

    $rangeEnd = date('Y-m-d', strtotime($date . ' +2 days'));

    try {
        $expect(
            $plugin->getMultiDayAvailability()->getRemainingCapacityForRange($date, $rangeEnd, $multiDayServiceId),
            3,
            'an untouched range reports the full schedule capacity',
        );

        $seed('00:00:00', '00:00:00', 1, ReservationRecord::STATUS_CONFIRMED, null, $multiDayServiceId, $date, $rangeEnd);
        $expect(
            $plugin->getMultiDayAvailability()->getRemainingCapacityForRange($date, $rangeEnd, $multiDayServiceId),
            2,
            'a booked range reports one seat fewer',
        );
    } catch (\Throwable $e) {
        $bad('multi-day capacity threw: ' . $e->getMessage());
    }

    try {
        $starts = $plugin->getMultiDayAvailability()->getAvailableStartDates($date, date('Y-m-d', strtotime($date . ' +10 days')), $multiDayServiceId);
        $ok('getAvailableStartDates() returned ' . count($starts) . ' start date(s) without error');
    } catch (\Throwable $e) {
        $bad('getAvailableStartDates() threw: ' . $e->getMessage());
    }

    // -----------------------------------------------------------------------
    $section('S. Employee-based availability is untouched by the capacity change');
    $clear();
    $setCapacity(10);

    $employees = Employee::find()->siteId('*')->serviceId($employeeServiceId)->all();
    if (count($employees) < 2) {
        echo "  (skipped — service {$employeeServiceId} needs at least 2 employees)\n";
    } else {
        $empService = Service::find()->siteId('*')->id($employeeServiceId)->status(null)->one();
        $empDuration = (int)($empService->duration ?? 60);
        [$first, $second] = [$employees[0], $employees[1]];

        // Employees keep their own schedules, so probe a time both actually work —
        // otherwise "the other employee keeps the slot" fails for want of a shift.
        $firstTimes = array_column($slots($employeeServiceId, $first->id), 'time');
        $secondTimes = array_column($slots($employeeServiceId, $second->id), 'time');
        $probe = array_values(array_intersect($firstTimes, $secondTimes))[0] ?? null;

        if ($probe === null) {
            $bad("employees {$first->id} and {$second->id} share no slot to probe");
        } else {
            $probeEnd = date('H:i:s', strtotime($probe) + $empDuration * 60);
            $seed($probe . ':00', $probeEnd, 1, ReservationRecord::STATUS_CONFIRMED, $first->id, $employeeServiceId);

            $expect($at($slots($employeeServiceId, $first->id), $probe), null, "booked employee loses {$probe}");
            $expect($at($slots($employeeServiceId, $second->id), $probe) !== null, true, "a second employee keeps {$probe}");
            $expect($at($slots($employeeServiceId), $probe) !== null, true, "'any available' keeps {$probe} while someone is free");
            $expect($at($slots($employeeServiceId, $first->id), $probe) === null, true, 'per-employee capacity stays at one seat regardless of schedule capacity 10');
        }
    }

    // -----------------------------------------------------------------------
    $section('T. Midnight buffers no longer throw');
    $clear();
    $setCapacity(2);
    $setService(['bufferBefore' => 120, 'bufferAfter' => 120]);

    try {
        $seed('00:00:00', '00:30:00');
        $seed('23:30:00', '23:59:00');
        $slots();
        $ok('bookings at both ends of the day survive a 2-hour buffer');
    } catch (\Throwable $e) {
        $bad('midnight buffer threw: ' . $e->getMessage());
    }

    $setService(['bufferBefore' => $originalService['bufferBefore'], 'bufferAfter' => $originalService['bufferAfter']]);

    // -----------------------------------------------------------------------
    $section('U. Timezone shifting preserves capacity metadata');
    $clear();
    $setCapacity(6);

    $seed($time . ':00', $endTime, 2);
    $plugin->getAvailability()->clearSlotCache();
    $shifted = $plugin->getAvailability()->getAvailableSlots($date, null, null, $serviceId, 1, 'America/New_York');
    $carried = array_values(array_filter(array_map(fn($s) => $s['availableCapacity'] ?? null, $shifted)));

    $expect(count($shifted) > 0, true, 'slots survive the timezone shift');
    $expect(in_array(4, $carried, true), true, 'the partly booked slot still reports 4 seats after shifting');

    // -----------------------------------------------------------------------
    $section('V. Waitlist eligibility on a saturated day');
    $clear();
    $setCapacity(1);

    $dayOfWeek = (int)(new DateTime($date))->format('N');
    $resolver = new \anvildev\booked\services\ScheduleResolverService();

    $seed($time . ':00', $endTime);
    $expect($at($slots(), $time), null, 'the slot is full');
    $expect($resolver->hasScheduleForDay($serviceId, null, $date, $dayOfWeek), true, 'the day still counts as scheduled, so a waitlist can be offered');
    $expect($resolver->getCapacityForDay($serviceId, null, $date, $dayOfWeek), 1, 'day capacity still resolves');
} finally {
    $clear();
    $db->createCommand()->update('{{%booked_schedules}}', ['workingHours' => $originalHours], ['id' => $scheduleId])->execute();
    $db->createCommand()->update('{{%booked_services}}', $originalService, ['id' => $serviceId])->execute();
    Craft::$app->getElements()->invalidateCachesForElementType(Service::class);
    $plugin->getScheduleAssignment()->clearServiceScheduleCache();

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "passed: {$pass}   failed: {$fail}\n";
}

exit($fail > 0 ? 1 : 0);
