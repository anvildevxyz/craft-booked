<?php

/**
 * Live integration check for issue #85 — a Schedule's per-day capacity must
 * govern how many bookings a time slot accepts before it closes.
 *
 * Runs the REAL AvailabilityService, CapacityService and BookingService against
 * the REAL database (there is no in-process Craft test harness, so the DB-bound
 * availability paths are otherwise only unit-tested through reflection). It
 * drives an employee-less service whose schedule grants N seats, fills those
 * seats one at a time, and asserts the slot stays listed and bookable until the
 * last seat is gone — then re-runs the capacity-1 and capacity-unset cases to
 * pin the single-booking behaviour, and the employee-based path to prove it is
 * untouched. Self-cleaning: seeded rows are removed and the schedule's original
 * working hours are restored.
 *
 * Usage (from the Craft project root, DDEV):
 *   ddev exec php plugins/craft-booked/tests/integration-live/schedule-capacity.php
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\Booked;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Service;

$db = Craft::$app->getDb();
$plugin = Booked::getInstance();

/** Employee-less service assigned to a schedule (its capacity is driven per group). */
$serviceId = (int)(getenv('SERVICE_ID') ?: 1236);
/** Service with employees — used to prove the per-employee path is unchanged. */
$employeeServiceId = (int)(getenv('EMPLOYEE_SERVICE_ID') ?: 2098);
/** A far-future Monday, so real bookings can never collide with fixture data. */
$date = getenv('DATE') ?: '2027-03-01';
$time = '09:00';
$marker = 'issue85-' . bin2hex(random_bytes(4));

$pass = 0;
$fail = 0;
$group = '';
$ok = function(string $m) use (&$pass) {
    echo "  \u{2713} {$m}\n";
    $pass++;
};
$bad = function(string $m) use (&$fail) {
    echo "  \u{2717} {$m}\n";
    $fail++;
};
$section = function(string $title) use (&$group) {
    $group = $title;
    echo "\n=== {$title} ===\n";
};

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

$service = Service::find()->siteId('*')->id($serviceId)->status(null)->one();
if (!$service) {
    exit("Service {$serviceId} not found — pass SERVICE_ID=<id>\n");
}

$scheduleId = (int)$db->createCommand(
    'SELECT scheduleId FROM {{%booked_service_schedule_assignments}} WHERE serviceId = :s ORDER BY sortOrder LIMIT 1',
    [':s' => $serviceId],
)->queryScalar();

if (!$scheduleId) {
    exit("Service {$serviceId} has no assigned schedule — issue #85 only applies to schedule-driven services\n");
}

// workingHours is a JSON column — hand Craft arrays, never pre-encoded strings,
// or the value lands in the database wrapped in a second layer of encoding.
$originalWorkingHours = json_decode((string)$db->createCommand(
    'SELECT workingHours FROM {{%booked_schedules}} WHERE id = :id',
    [':id' => $scheduleId],
)->queryScalar(), true) ?: [];

/** Rewrite every day's capacity on the schedule under test. */
$setCapacity = function(?int $capacity) use ($db, $scheduleId, $originalWorkingHours, $plugin) {
    $hours = $originalWorkingHours;
    foreach (array_keys($hours) as $day) {
        $hours[$day]['capacity'] = $capacity;
    }
    $db->createCommand()->update('{{%booked_schedules}}', ['workingHours' => $hours], ['id' => $scheduleId])->execute();
    $plugin->getScheduleAssignment()->clearServiceScheduleCache();
    $plugin->getAvailability()->clearSlotCache();
};

/** Insert a reservation straight into the table, bypassing availability checks. */
$seed = function(
    string $startTime,
    string $endTime,
    int $quantity = 1,
    string $status = 'confirmed',
    ?int $employeeId = null,
    ?int $forServiceId = null,
) use ($db, $date, $marker, $serviceId): int {
    $db->createCommand()->insert('{{%booked_reservations}}', [
        'userName' => 'Issue 85',
        'userEmail' => $marker . '@example.test',
        'bookingDate' => $date,
        'startTime' => $startTime,
        'endTime' => $endTime,
        'status' => $status,
        'employeeId' => $employeeId,
        'locationId' => null,
        'serviceId' => $forServiceId ?? $serviceId,
        'quantity' => $quantity,
        'confirmationToken' => $marker . bin2hex(random_bytes(16)),
        'dateCreated' => gmdate('Y-m-d H:i:s'),
        'dateUpdated' => gmdate('Y-m-d H:i:s'),
        'uid' => \craft\helpers\StringHelper::UUID(),
    ])->execute();

    return (int)$db->getLastInsertID();
};

// The wildcard has to sit in the value: escaping is off, so Yii adds none itself.
$clearBookings = function() use ($db, $marker) {
    $db->createCommand()->delete('{{%booked_reservations}}', ['like', 'userEmail', $marker . '%', false])->execute();
};

/** Ask the real availability service for the day's slots, with all caches cold. */
$slots = function(?int $forServiceId = null, ?int $employeeId = null, int $quantity = 1) use ($plugin, $date, $serviceId): array {
    $plugin->getAvailability()->clearSlotCache();
    $plugin->getScheduleAssignment()->clearServiceScheduleCache();
    return $plugin->getAvailability()->getAvailableSlots($date, $employeeId, null, $forServiceId ?? $serviceId, $quantity);
};

$slotAt = function(array $slots, string $at): ?array {
    foreach ($slots as $slot) {
        if (($slot['time'] ?? '') === $at) {
            return $slot;
        }
    }
    return null;
};

/** Assert the probe slot exists with the expected remaining capacity. */
$expectSlot = function(array $slots, string $at, ?int $expectedAvailable, string $label) use ($ok, $bad, $slotAt) {
    $slot = $slotAt($slots, $at);
    if ($slot === null) {
        $bad("{$label}: slot {$at} is missing (expected {$expectedAvailable} seats free)");
        return;
    }
    $actual = $slot['availableCapacity'] ?? null;
    $actual === $expectedAvailable
        ? $ok("{$label}: {$at} open with " . var_export($actual, true) . ' seats')
        : $bad("{$label}: {$at} reports " . var_export($actual, true) . " seats, expected " . var_export($expectedAvailable, true));
};

$expectNoSlot = function(array $slots, string $at, string $label) use ($ok, $bad, $slotAt) {
    $slotAt($slots, $at) === null
        ? $ok("{$label}: {$at} correctly withdrawn")
        : $bad("{$label}: {$at} still offered when it should be full");
};

$expectBookable = function(bool $expected, string $at, int $quantity, string $label) use ($plugin, $ok, $bad, $date, $serviceId, $service) {
    $plugin->getAvailability()->clearSlotCache();
    $end = date('H:i', strtotime($at) + (int)($service->duration ?? 60) * 60);
    $actual = $plugin->getAvailability()->isSlotAvailable($date, $at, $end, null, null, $serviceId, $quantity);
    $actual === $expected
        ? $ok("{$label}: isSlotAvailable(qty {$quantity}) = " . var_export($expected, true))
        : $bad("{$label}: isSlotAvailable(qty {$quantity}) returned " . var_export($actual, true) . ", expected " . var_export($expected, true));
};

$duration = (int)($service->duration ?? 60);
$endTime = date('H:i:s', strtotime($time) + $duration * 60);
$nextTime = date('H:i', strtotime($time) + $duration * 60);

echo "Service #{$serviceId} '{$service->title}' | duration {$duration}min | schedule #{$scheduleId} | date {$date}\n";

$clearBookings();

try {
    // -----------------------------------------------------------------------
    $section('A. Capacity 10 — seats drain one at a time');
    $setCapacity(10);

    $expectSlot($slots(), $time, 10, 'empty day');
    $expectBookable(true, $time, 10, 'empty day');

    $seed($time . ':00', $endTime);
    $expectSlot($slots(), $time, 9, 'after 1 booking');
    $expectSlot($slots(), $nextTime, 10, 'neighbouring slot');
    $expectBookable(true, $time, 9, 'after 1 booking');
    $expectBookable(false, $time, 10, 'after 1 booking');

    for ($i = 0; $i < 8; $i++) {
        $seed($time . ':00', $endTime);
    }
    $expectSlot($slots(), $time, 1, 'after 9 bookings');
    $expectBookable(true, $time, 1, 'after 9 bookings');
    $expectBookable(false, $time, 2, 'after 9 bookings');

    $seed($time . ':00', $endTime);
    $expectNoSlot($slots(), $time, 'after 10 bookings');
    $expectSlot($slots(), $nextTime, 10, 'after 10 bookings, neighbour');
    $expectBookable(false, $time, 1, 'after 10 bookings');

    // -----------------------------------------------------------------------
    $section('B. Group bookings consume their full quantity');
    $clearBookings();

    $seed($time . ':00', $endTime, 4);
    $expectSlot($slots(), $time, 6, 'one party of 4');

    $seed($time . ':00', $endTime, 6);
    $expectNoSlot($slots(), $time, '4 + 6 fills capacity 10');

    // -----------------------------------------------------------------------
    $section('C. Booking status decides whether a seat is held');
    $clearBookings();

    $seed($time . ':00', $endTime, 3, 'cancelled');
    $expectSlot($slots(), $time, 10, 'cancelled booking');

    $seed($time . ':00', $endTime, 3, 'pending');
    $expectSlot($slots(), $time, 7, 'pending booking holds seats');

    // -----------------------------------------------------------------------
    $section('D. Capacity 1 — a single booking still closes the slot');
    $clearBookings();
    $setCapacity(1);

    $expectSlot($slots(), $time, 1, 'empty day');
    $seed($time . ':00', $endTime);
    $expectNoSlot($slots(), $time, 'after 1 booking');
    $expectSlot($slots(), $nextTime, 1, 'neighbouring slot');
    $expectBookable(false, $time, 1, 'after 1 booking');

    // -----------------------------------------------------------------------
    $section('E. Capacity unset — behaves as a single seat, not unlimited');
    $clearBookings();
    $setCapacity(null);

    $seed($time . ':00', $endTime);
    $expectNoSlot($slots(), $time, 'after 1 booking');
    $expectBookable(false, $time, 1, 'after 1 booking');

    // -----------------------------------------------------------------------
    $section('F. End-to-end booking creation respects capacity');
    $clearBookings();
    $setCapacity(3);

    // Distinct addresses per attempt so the per-email rate limiter never fires
    // and capacity is unambiguously the thing being measured.
    $book = fn(int $attempt) => $plugin->getBooking()->createBooking([
        'customerName' => 'Issue 85',
        'customerEmail' => $marker . '-f' . $attempt . '@example.test',
        'date' => $date,
        'time' => $time,
        'serviceId' => $serviceId,
        'quantity' => 1,
    ]);

    for ($i = 1; $i <= 3; $i++) {
        try {
            $reservation = $book($i);
            $ok("booking {$i} of 3 accepted (#{$reservation->id})");
        } catch (\Throwable $e) {
            $bad("booking {$i} of 3 rejected: " . $e->getMessage());
        }
    }

    try {
        $book(4);
        $bad('booking 4 was accepted past capacity 3 — overbooking');
    } catch (\anvildev\booked\exceptions\BookingRateLimitException $e) {
        $bad('booking 4 hit the rate limiter, so capacity was never exercised');
    } catch (\Throwable $e) {
        $ok('booking 4 rejected past capacity: ' . (new ReflectionClass($e))->getShortName());
    }

    $expectNoSlot($slots(), $time, 'after filling capacity via the booking service');

    // -----------------------------------------------------------------------
    $section('G. Employee-based services still allocate one seat per employee');
    $clearBookings();
    $setCapacity(10);

    $employees = Employee::find()->siteId('*')->serviceId($employeeServiceId)->all();
    if (empty($employees)) {
        echo "  (skipped — service {$employeeServiceId} has no employees)\n";
    } else {
        $employee = $employees[0];
        $empService = Service::find()->siteId('*')->id($employeeServiceId)->status(null)->one();
        $empDuration = (int)($empService->duration ?? 60);

        $before = $slots($employeeServiceId, $employee->id);
        $probe = $before[0]['time'] ?? null;

        if ($probe === null) {
            $bad("service {$employeeServiceId} returned no slots for employee {$employee->id}");
        } else {
            $seed(
                $probe . ':00',
                date('H:i:s', strtotime($probe) + $empDuration * 60),
                1,
                'confirmed',
                $employee->id,
                $employeeServiceId,
            );
            $after = $slots($employeeServiceId, $employee->id);
            $slotAt($after, $probe) === null
                ? $ok("employee slot {$probe} withdrawn after 1 booking")
                : $bad("employee slot {$probe} survived a booking — per-employee capacity leaked");
        }
    }

    // -----------------------------------------------------------------------
    $section('H. Buffers only bite once the slot is full');
    $clearBookings();
    $setCapacity(4);

    // Give the service a real buffer for the duration of this group, so the
    // interaction is exercised rather than skipped on buffer-less fixtures.
    $originalBuffers = $db->createCommand(
        'SELECT bufferBefore, bufferAfter FROM {{%booked_services}} WHERE id = :id',
        [':id' => $serviceId],
    )->queryOne();
    $db->createCommand()->update('{{%booked_services}}', ['bufferBefore' => 15, 'bufferAfter' => 15], ['id' => $serviceId])->execute();
    Craft::$app->getElements()->invalidateCachesForElementType(Service::class);

    try {
        $seed($time . ':00', $endTime);
        $slotAt($slots(), $nextTime) !== null
            ? $ok("neighbour {$nextTime} stays open while seats remain")
            : $bad("neighbour {$nextTime} blocked by a buffer on a partly filled slot");

        for ($i = 0; $i < 3; $i++) {
            $seed($time . ':00', $endTime);
        }
        $expectNoSlot($slots(), $time, 'saturated slot');

        // 15 minutes of buffer either side of a full 09:00-10:00 booking must
        // also take the 10:00 slot out, which would otherwise start inside it.
        $expectNoSlot($slots(), $nextTime, 'buffer after a saturated slot');
    } finally {
        $db->createCommand()->update('{{%booked_services}}', $originalBuffers, ['id' => $serviceId])->execute();
        Craft::$app->getElements()->invalidateCachesForElementType(Service::class);
    }

    // -----------------------------------------------------------------------
    $section('I. Waitlist eligibility survives a full slot');
    $clearBookings();
    $setCapacity(2);

    $dayOfWeek = (int)(new DateTime($date))->format('N');
    $plugin->getScheduleAssignment()->clearServiceScheduleCache();
    $resolver = new \anvildev\booked\services\ScheduleResolverService();

    $resolver->hasScheduleForDay($serviceId, null, $date, $dayOfWeek)
        ? $ok('hasScheduleForDay() true for a scheduled day')
        : $bad('hasScheduleForDay() false — waitlist would never be offered');

    $capacityForDay = $resolver->getCapacityForDay($serviceId, null, $date, $dayOfWeek);
    $capacityForDay === 2
        ? $ok('getCapacityForDay() reports the schedule capacity (2)')
        : $bad('getCapacityForDay() reported ' . var_export($capacityForDay, true) . ', expected 2');

    // Employee-less lookups must not trip over employees that have no active schedule.
    try {
        $resolver->getCapacityForDay($employeeServiceId, null, $date, $dayOfWeek);
        $resolver->hasScheduleForDay($employeeServiceId, null, $date, $dayOfWeek);
        $ok('schedule-less employees do not fatal the day-capacity lookup');
    } catch (\Throwable $e) {
        $bad('day-capacity lookup threw on schedule-less employees: ' . $e->getMessage());
    }
} finally {
    $clearBookings();
    $db->createCommand()->update('{{%booked_schedules}}', ['workingHours' => $originalWorkingHours], ['id' => $scheduleId])->execute();
    $plugin->getScheduleAssignment()->clearServiceScheduleCache();

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "passed: {$pass}   failed: {$fail}\n";
}

exit($fail > 0 ? 1 : 0);
