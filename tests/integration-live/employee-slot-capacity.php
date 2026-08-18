<?php

/**
 * Live integration check for issue #109 — a Schedule's per-day capacity must
 * govern employee slots too, not only employee-less ones.
 *
 * Issue #85 taught the employee-less path to honour capacity. Employee slots
 * kept one seat each, so a capacity-3 schedule still closed its slot after the
 * first booking. Worse, the slot kept reporting three free seats while it
 * vanished from the calendar.
 *
 * Builds four services from one capacity-3 schedule and fills the 09:00 slot one
 * booking at a time:
 *
 *   A  no employees                     -> 3 bookings (the #85 behaviour)
 *   B  1 employee, schedule on employee -> 3 bookings
 *   C  1 employee, schedule on service  -> 3 bookings
 *   D  2 employees, schedule on service -> 6 bookings (seats are per employee)
 *
 * Then it pins the guard that keeps existing sites safe: a schedule with no
 * capacity set still takes exactly one booking per slot.
 *
 * Runs the REAL AvailabilityService and CapacityService against the REAL
 * database. Self-cleaning: every element and reservation it creates is removed
 * again, including after a failure.
 *
 * Usage (from the Craft project root, DDEV):
 *   ddev exec php plugins/craft-booked/tests/integration-live/employee-slot-capacity.php [YYYY-MM-DD]
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\Booked;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Schedule;
use anvildev\booked\elements\Service;
use anvildev\booked\factories\ReservationFactory;

$db = Craft::$app->getDb();
$elements = Craft::$app->getElements();
$plugin = Booked::getInstance();

/** A far-future date, so real bookings and seeded fixtures can never collide. */
$date = $argv[1] ?? '2027-04-07';
$time = '09:00';
$prefix = 'ISSUE109';
$marker = 'issue109-' . bin2hex(random_bytes(4));

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

/**
 * Remove every element this script names, plus its reservations.
 *
 * Bookings made through the booking service carry their own random token, so
 * they are cleared by service rather than by marker — the marker alone would
 * leave them behind.
 */
$purge = function() use ($elements, $db, $prefix, $marker) {
    $db->createCommand()->delete('{{%booked_reservations}}', ['like', 'confirmationToken', $marker . '%', false])->execute();

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

/** Seat one booking on the probe slot. */
$book = function(int $serviceId, ?int $employeeId, string $tag) use ($db, $date, $marker): void {
    $db->createCommand()->insert('{{%booked_reservations}}', [
        'userName' => 'Issue109 ' . $tag,
        'userEmail' => 'issue109@example.test',
        'bookingDate' => $date,
        'startTime' => '09:00:00',
        'endTime' => '10:00:00',
        'status' => 'confirmed',
        'serviceId' => $serviceId,
        'employeeId' => $employeeId,
        'quantity' => 1,
        'confirmationToken' => $marker . '-' . $tag,
        'dateCreated' => date('Y-m-d H:i:s'),
        'dateUpdated' => date('Y-m-d H:i:s'),
        'uid' => \craft\helpers\StringHelper::UUID(),
    ])->execute();
};

/** The probe slot as the calendar would show it, or null when it is gone. */
$probeSlot = function(int $serviceId) use ($plugin, $date, $time): ?array {
    $plugin->getAvailability()->clearSlotCache();
    $plugin->getScheduleAssignment()->clearServiceScheduleCache();
    foreach ($plugin->getAvailability()->getAvailableSlots($date, null, null, $serviceId) as $slot) {
        if (substr($slot['time'], 0, 5) === $time) {
            return $slot;
        }
    }
    return null;
};

/**
 * Build a service from a schedule, optionally with employees.
 *
 * @return array{0: Service, 1: Schedule, 2: int[]}
 */
$fixture = function(string $label, ?int $capacity, int $employeeCount, bool $scheduleOnEmployee, string $durationType = 'minutes') use ($elements, $prefix): array {
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
    $service->durationType = $durationType;
    $service->duration = $durationType === 'days' ? 1 : 60;
    $service->bufferBefore = 0;
    $service->bufferAfter = 0;
    if (!$elements->saveElement($service)) {
        throw new RuntimeException("service {$label}: " . implode(', ', $service->getErrorSummary(true)));
    }

    $assign = Booked::getInstance()->getScheduleAssignment();
    $assign->setSchedulesForService($service->id, [$schedule->id]);

    $employeeIds = [];
    for ($i = 1; $i <= $employeeCount; $i++) {
        $employee = new Employee();
        $employee->title = "{$prefix} Emp {$label}{$i}";
        $employee->email = "issue109-{$label}{$i}@example.test";
        $employee->serviceIds = [$service->id];
        $employee->workingHours = array_fill_keys(range(1, 7), ['enabled' => false]);
        if (!$elements->saveElement($employee)) {
            throw new RuntimeException("employee {$label}{$i}: " . implode(', ', $employee->getErrorSummary(true)));
        }
        if ($scheduleOnEmployee) {
            $assign->setSchedulesForEmployee($employee->id, [$schedule->id]);
        }
        $employeeIds[] = $employee->id;
    }

    return [$service, $schedule, $employeeIds];
};

/**
 * Fill the probe slot one booking at a time and report how many it took.
 * Bookings are spread over the employees so no single employee is oversold.
 */
$fillUntilClosed = function(int $serviceId, array $employeeIds, int $limit, string $label) use ($book, $probeSlot): int {
    for ($n = 1; $n <= $limit; $n++) {
        if ($probeSlot($serviceId) === null) {
            return $n - 1;
        }
        $employeeId = empty($employeeIds) ? null : $employeeIds[($n - 1) % count($employeeIds)];
        $book($serviceId, $employeeId, "{$label}{$n}");
    }
    return $probeSlot($serviceId) === null ? $limit : $limit + 1;
};

$purge();
echo "Issue #109 — employee slot capacity\n";
echo "date {$date} (ISO day {$dayOfWeek}), probe slot {$time}\n";

try {
    $section('A. Employee-less service still honours capacity (issue #85)');
    [$service] = $fixture('A', 3, 0, false);
    $taken = $fillUntilClosed($service->id, [], 5, 'A');
    $taken === 3
        ? $ok("employee-less slot took 3 bookings then closed")
        : $bad("employee-less slot took {$taken} bookings, expected 3");

    $section('B. Capacity schedule assigned to the employee');
    [$service, , $employeeIds] = $fixture('B', 3, 1, true);
    $slot = $probeSlot($service->id);
    ($slot['maxCapacity'] ?? null) === 3
        ? $ok('employee slot reports the schedule capacity of 3')
        : $bad('employee slot reports maxCapacity=' . var_export($slot['maxCapacity'] ?? null, true) . ', expected 3');
    $taken = $fillUntilClosed($service->id, $employeeIds, 5, 'B');
    $taken === 3
        ? $ok('employee slot took 3 bookings then closed')
        : $bad("employee slot took {$taken} bookings, expected 3");

    $section('C. Capacity schedule assigned to the service, one employee');
    [$service, , $employeeIds] = $fixture('C', 3, 1, false);
    $taken = $fillUntilClosed($service->id, $employeeIds, 5, 'C');
    $taken === 3
        ? $ok('employee slot took 3 bookings then closed')
        : $bad("employee slot took {$taken} bookings, expected 3");

    $section('D. Seats are per employee, not a shared pool');
    [$service, , $employeeIds] = $fixture('D', 3, 2, false);

    // The merged "any available" slot advertises the best single employee, not
    // the total. A booking goes to one employee, and the wizard reads this same
    // number as the largest party it will offer — six is a party nobody here
    // could seat, and the booking service would refuse it.
    $slot = $probeSlot($service->id);
    ($slot['maxCapacity'] ?? null) === 3
        ? $ok('merged slot advertises one employee\'s 3 seats, not the 6-seat total')
        : $bad('merged slot reports maxCapacity=' . var_export($slot['maxCapacity'] ?? null, true) . ', expected 3');

    // Single bookings still drain both employees, so the pool really is 6.
    $taken = $fillUntilClosed($service->id, $employeeIds, 8, 'D');
    $taken === 6
        ? $ok('one-seat bookings still fill all 6 seats before it closes')
        : $bad("merged slot took {$taken} bookings, expected 6");

    $section('N. A party is only offered a slot one employee can seat');
    // Seats do not add up across staff: the booking service hands the whole
    // party to a single employee. Offering a slot on the sum and refusing the
    // booking afterwards is the same dead end this issue is about, pointing the
    // other way.
    [$service, , $employeeIds] = $fixture('N', 2, 2, true);

    $plugin->getAvailability()->clearSlotCache();
    $forPair = $plugin->getAvailability()->getAvailableSlots($date, null, null, $service->id, 2);
    $hasPair = false;
    foreach ($forPair as $s) {
        if (substr($s['time'], 0, 5) === $time) {
            $hasPair = true;
        }
    }
    $hasPair
        ? $ok('a party of 2 is offered — one employee holds 2 seats')
        : $bad('a party of 2 was withheld even though an employee can seat it');

    $plugin->getAvailability()->clearSlotCache();
    $forFour = $plugin->getAvailability()->getAvailableSlots($date, null, null, $service->id, 4);
    $hasFour = false;
    foreach ($forFour as $s) {
        if (substr($s['time'], 0, 5) === $time) {
            $hasFour = true;
        }
    }
    !$hasFour
        ? $ok('a party of 4 is withheld — 2 + 2 seats are not one bookable party')
        : $bad('a party of 4 was offered across two employees; the booking would be refused');

    $slot = $probeSlot($service->id);
    ($slot['availableCapacity'] ?? null) === 2
        ? $ok('the slot advertises 2 seats, so the quantity picker stops at 2')
        : $bad('the slot advertises ' . var_export($slot['availableCapacity'] ?? null, true) . ' seats, expected 2');

    $section('E. Guard: an unset capacity still means one booking per slot');
    [$service, , $employeeIds] = $fixture('E', null, 1, true);
    $taken = $fillUntilClosed($service->id, $employeeIds, 3, 'E');
    $taken === 1
        ? $ok('capacity-less employee slot took 1 booking then closed')
        : $bad("capacity-less employee slot took {$taken} bookings, expected 1");

    $section('F. Guard: capacity 1 still means one booking per slot');
    [$service, , $employeeIds] = $fixture('F', 1, 1, true);
    $taken = $fillUntilClosed($service->id, $employeeIds, 3, 'F');
    $taken === 1
        ? $ok('capacity-1 employee slot took 1 booking then closed')
        : $bad("capacity-1 employee slot took {$taken} bookings, expected 1");

    $section('G. A booking on another service still blocks the whole slot');
    [$service, , $employeeIds] = $fixture('G', 3, 1, true);
    [$otherService] = $fixture('H', 3, 0, false);
    $book($otherService->id, $employeeIds[0], 'G-cross');
    $probeSlot($service->id) === null
        ? $ok('slot withdrawn while the employee is busy on another service')
        : $bad('slot survived a booking the employee has on another service');

    $section('I. The booking service seats and then refuses, matching the calendar');
    [$service, , $employeeIds] = $fixture('I', 3, 1, true);

    // Distinct addresses per attempt so the per-email rate limiter never fires
    // and capacity is unambiguously the thing being measured.
    $create = fn(int $attempt) => $plugin->getBooking()->createBooking([
        'customerName' => 'Issue 109',
        'customerEmail' => $marker . '-i' . $attempt . '@example.test',
        'date' => $date,
        'time' => $time,
        'serviceId' => $service->id,
        'employeeId' => $employeeIds[0],
        'quantity' => 1,
    ]);

    for ($i = 1; $i <= 3; $i++) {
        try {
            $reservation = $create($i);
            $ok("booking {$i} of 3 accepted (#{$reservation->id})");
        } catch (Throwable $e) {
            $bad("booking {$i} of 3 rejected: " . $e->getMessage());
        }
    }

    try {
        $create(4);
        $bad('booking 4 was accepted past capacity 3 — the employee slot was oversold');
    } catch (\anvildev\booked\exceptions\BookingRateLimitException $e) {
        $bad('booking 4 hit the rate limiter, so capacity was never exercised');
    } catch (Throwable $e) {
        $ok('booking 4 rejected past capacity: ' . (new ReflectionClass($e))->getShortName());
    }

    $probeSlot($service->id) === null
        ? $ok('slot withdrawn once the booking service filled every seat')
        : $bad('slot still listed after the booking service filled every seat');

    $section('L. Whole-day bookings honour capacity for an employee too');
    // Whole-day and flexible-day bookings hold no time, so activeSlotKey used to
    // be built from an empty time segment ("2026-10-01||42") for every one of
    // them. The second booking of a date collided on the unique index whatever
    // the schedule granted, so an employee's multi-day service could never take
    // more than one booking. Found by driving the wizard, not by the suites.
    [$service, , $employeeIds] = $fixture('L', 3, 1, true, 'days');
    $seatKeys = [];
    for ($n = 1; $n <= 3; $n++) {
        $r = ReservationFactory::create();
        $r->userName = 'Issue109 L' . $n;
        $r->userEmail = $marker . "-l{$n}@example.test";
        $r->bookingDate = $date;
        $r->endDate = $date;
        $r->status = 'confirmed';
        $r->serviceId = $service->id;
        $r->employeeId = $employeeIds[0];
        $r->quantity = 1;
        $r->confirmationToken = $marker . '-l' . $n;
        $saved = $r->save();
        $seatKeys[] = $saved ? 'saved' : 'refused';
    }
    $seatKeys === ['saved', 'saved', 'saved']
        ? $ok('3 whole-day bookings fit a capacity of 3')
        : $bad('whole-day bookings gave ' . implode(', ', $seatKeys));

    $keyed = (int)$db->createCommand(
        'SELECT COUNT(*) FROM {{%booked_reservations}} WHERE serviceId = :s AND activeSlotKey IS NOT NULL',
        [':s' => $service->id],
    )->queryScalar();
    $keyed === 0
        ? $ok('none of them claims the single-seat key')
        : $bad("{$keyed} whole-day booking(s) still took the single-seat key");

    $section('M. Guard: a whole-day service without capacity still takes one booking');
    [$service, , $employeeIds] = $fixture('M', null, 1, true, 'days');
    $outcome = [];
    for ($n = 1; $n <= 2; $n++) {
        $r = ReservationFactory::create();
        $r->userName = 'Issue109 M' . $n;
        $r->userEmail = $marker . "-m{$n}@example.test";
        $r->bookingDate = $date;
        $r->endDate = $date;
        $r->status = 'confirmed';
        $r->serviceId = $service->id;
        $r->employeeId = $employeeIds[0];
        $r->quantity = 1;
        $r->confirmationToken = $marker . '-m' . $n;
        try {
            $outcome[] = $r->save() ? 'saved' : 'refused';
        } catch (Throwable $e) {
            $outcome[] = 'refused';
        }
    }
    $outcome === ['saved', 'refused']
        ? $ok('the unique slot key still stops a second whole-day booking')
        : $bad('capacity-less whole-day bookings gave ' . implode(', ', $outcome));

    $section('O. The Control Panel accepts the seats the wizard sells');
    // BookingsController::checkForBookingConflicts() treated any overlapping
    // booking for the employee as a conflict, so an admin adding the second
    // booking of a capacity-3 slot was refused while the wizard was still
    // selling the third — the CP contradicting the front end on one rule.
    [$service, , $employeeIds] = $fixture('O', 3, 1, true);

    $conflictCheck = new ReflectionMethod(\anvildev\booked\controllers\cp\BookingsController::class, 'slotHasRoomFor');
    $conflictCheck->setAccessible(true);
    $controller = new \anvildev\booked\controllers\cp\BookingsController('bookings', Craft::$app);

    $candidate = function() use ($service, $employeeIds, $date, $time) {
        $r = ReservationFactory::create();
        $r->userName = 'Issue109 O';
        $r->userEmail = 'issue109-o@example.test';
        $r->bookingDate = $date;
        $r->startTime = $time;
        $r->endTime = '10:00';
        $r->status = 'confirmed';
        $r->serviceId = $service->id;
        $r->employeeId = $employeeIds[0];
        $r->quantity = 1;
        return $r;
    };

    $outcomes = [];
    for ($n = 1; $n <= 4; $n++) {
        $r = $candidate();
        $refused = !$conflictCheck->invoke($controller, $r, null);
        if ($refused) {
            $outcomes[] = 'refused';
            break;
        }
        $outcomes[] = 'accepted';
        $r->confirmationToken = $marker . '-o' . $n;
        $r->save();
    }

    $outcomes === ['accepted', 'accepted', 'accepted', 'refused']
        ? $ok('the CP seats 3 bookings then refuses the 4th, matching the calendar')
        : $bad('the CP gave ' . implode(', ', $outcomes) . ', expected 3 accepted then refused');

    $section('P. An employee-less booking costs a seat, not the whole slot');
    // removeUnassignedBookingSlots() dropped one slot per employee-less booking.
    // After deduplication there is one slot per time, so a single such booking
    // erased every seat behind it — six on a two-employee capacity-3 service.
    // The seats are already charged by enrichSlotsWithCapacity(), so the filter
    // was subtracting the same booking a second time, far more bluntly.
    //
    // Both schedule placements are checked because only the service-schedule
    // path ran that filter, and the two disagreed.
    foreach ([['P-svc', false], ['P-emp', true]] as [$label, $onEmployee]) {
        [$service, , $employeeIds] = $fixture($label, 3, 2, $onEmployee);

        $before = $probeSlot($service->id);
        ($before['availableCapacity'] ?? null) === 3
            ? $ok("{$label}: 3 seats before any booking")
            : $bad("{$label}: expected 3 seats, got " . var_export($before['availableCapacity'] ?? null, true));

        $book($service->id, null, str_replace('-', '', $label) . 'u');

        $after = $probeSlot($service->id);
        $after !== null
            ? $ok("{$label}: the slot survives one employee-less booking")
            : $bad("{$label}: one employee-less booking erased the whole slot");
        ($after['availableCapacity'] ?? null) === 2
            ? $ok("{$label}: it costs exactly one seat")
            : $bad("{$label}: seats went to " . var_export($after['availableCapacity'] ?? null, true) . ', expected 2');
    }

    $section('Q. A party larger than the slot is withheld on every path');
    // filterByQuantity() was only reached on the employee-schedule path. The two
    // service-schedule branches never received the requested quantity at all, so
    // a capacity-3 slot was offered for a party of eight and the booking was then
    // refused. Found while verifying the capacity table in AVAILABILITY.md.
    foreach ([['Q-none', 0, false], ['Q-svc', 2, false], ['Q-emp', 1, true]] as [$label, $employeeCount, $onEmployee]) {
        [$service] = $fixture($label, 3, $employeeCount, $onEmployee);

        $offered = function(int $quantity) use ($plugin, $service, $date, $time): bool {
            $plugin->getAvailability()->clearSlotCache();
            foreach ($plugin->getAvailability()->getAvailableSlots($date, null, null, $service->id, $quantity) as $slot) {
                if (substr($slot['time'], 0, 5) === $time) {
                    return true;
                }
            }
            return false;
        };

        $offered(3)
            ? $ok("{$label}: a party of 3 is offered")
            : $bad("{$label}: a party of 3 was withheld from a capacity-3 slot");
        !$offered(8)
            ? $ok("{$label}: a party of 8 is withheld")
            : $bad("{$label}: a party of 8 was offered on a capacity-3 slot");
    }

    $section('K. A soft lock holds one seat of an employee group slot, not the employee');
    // filterSoftLockedSlots already takes the held seats off the slot. When
    // filterDeduplicatedSoftLocks tested the lock a second time it zeroed the
    // employee out entirely, closing a slot that still had a free seat — the
    // "any available" view lost a bookable slot the moment anyone opened the
    // booking form. Found by the browser smoke run, not by the unit suite.
    [$service, , $employeeIds] = $fixture('K', 3, 1, true);
    $book($service->id, $employeeIds[0], 'K1');

    $slot = $probeSlot($service->id);
    ($slot['availableCapacity'] ?? null) === 2
        ? $ok('2 seats left after 1 booking')
        : $bad('expected 2 seats before the lock, got ' . var_export($slot['availableCapacity'] ?? null, true));

    $token = $plugin->getSoftLock()->createLock([
        'date' => $date,
        'startTime' => $time,
        'endTime' => '10:00',
        'serviceId' => $service->id,
        'employeeId' => $employeeIds[0],
        'quantity' => 1,
    ]);
    $token !== false ? $ok('soft lock taken on the group slot') : $bad('could not take a soft lock');

    $slot = $probeSlot($service->id);
    $slot !== null
        ? $ok('slot still offered while one seat is held')
        : $bad('slot withdrawn by a lock that holds only one of its seats');
    ($slot['availableCapacity'] ?? null) === 1
        ? $ok('the held seat is the only one taken')
        : $bad('expected 1 seat under the lock, got ' . var_export($slot['availableCapacity'] ?? null, true));

    if ($token !== false) {
        $plugin->getSoftLock()->releaseLock($token, $plugin->getSoftLock()->getSessionHash());
    }

    $section('J. The last seat of an employee group slot is only sold once');
    // A multi-seat slot has to hold several active bookings for one employee, so
    // the unique activeSlotKey index no longer guards it. That leaves the mutex
    // and the in-mutex capacity re-check, exactly as for employee-less group
    // slots — this is the check that the remaining guard actually holds.
    [$service, , $employeeIds] = $fixture('J', 2, 1, true);

    $race = [];
    for ($i = 0; $i < 3; $i++) {
        try {
            $plugin->getBooking()->createBooking([
                'customerName' => 'Issue 109 race',
                'customerEmail' => $marker . '-race' . $i . '@example.test',
                'date' => $date,
                'time' => $time,
                'serviceId' => $service->id,
                'employeeId' => $employeeIds[0],
                'quantity' => 1,
            ]);
            $race[] = 'accepted';
        } catch (Throwable $e) {
            $race[] = 'rejected';
        }
    }
    $race === ['accepted', 'accepted', 'rejected']
        ? $ok('two seats sold, the third attempt refused')
        : $bad('three attempts at two seats gave ' . implode(', ', $race));

    $held = (int)$db->createCommand(
        'SELECT COUNT(*) FROM {{%booked_reservations}} WHERE serviceId = :s AND bookingDate = :d AND startTime = :t AND status != :c',
        [':s' => $service->id, ':d' => $date, ':t' => $time . ':00', ':c' => 'cancelled'],
    )->queryScalar();
    $held === 2
        ? $ok('exactly 2 bookings hold the slot')
        : $bad("{$held} bookings hold a slot with 2 seats");
} catch (Throwable $e) {
    $bad('threw: ' . $e->getMessage());
} finally {
    $purge();
}

echo "\n" . str_repeat('=', 40) . "\n";
echo "passed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
