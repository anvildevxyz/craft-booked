<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Schedule;
use anvildev\booked\factories\ReservationFactory;
use Craft;
use craft\base\Component;
use DateTime;

/**
 * Manages booking capacity: checks slot availability against max capacity,
 * tracks booked quantities, and enriches slot arrays with capacity metadata.
 */
class CapacityService extends Component
{
    /**
     * Statuses that occupy a seat. Mirrors the "everything but cancelled" rule the
     * availability window subtraction uses — a no-show keeps its slot rather than
     * releasing it, so counting only confirmed and pending would over-report the
     * seats left on a group slot.
     */
    private const SEAT_HOLDING_STATUSES = ['confirmed', 'pending', 'no_show'];

    private ?TimeWindowService $timeWindowService = null;

    public function setTimeWindowService(TimeWindowService $service): void
    {
        $this->timeWindowService = $service;
    }

    private function getTimeWindowService(): TimeWindowService
    {
        return $this->timeWindowService ??= new TimeWindowService();
    }

    /**
     * Cheap sanity check on a requested quantity, before the slot is priced out.
     *
     * How many seats a booking may actually take is a property of the slot, not
     * of the service, so the ceiling is enforced by {@see hasAvailableCapacity()}
     * against the schedule's per-day capacity.
     *
     * This used to also test `$service->allowQuantitySelection` and
     * `$service->maxCapacity`. Neither has ever existed — not as a property, a
     * column, or a Control Panel field — so both `isset()` calls were always
     * false and the branches were unreachable. They are gone, along with the
     * per-call Service lookup they needed, which ran on every availability check.
     *
     * @param int|null $serviceId Unused; kept so existing callers keep working.
     */
    public function isQuantityAllowed(int $quantity, ?int $serviceId = null): bool
    {
        return $quantity > 0;
    }

    /**
     * WARNING: This method is NOT concurrency-safe on its own. Callers must hold
     * a mutex lock on the slot before calling to prevent TOCTOU race conditions.
     */
    public function hasAvailableCapacity(
        string $date,
        string $startTime,
        string $endTime,
        ?int $employeeId,
        ?int $serviceId,
        int $requestedQuantity,
        ?int $excludeReservationId = null,
    ): bool {
        $maxCapacity = $this->getCapacityForSlot($date, $startTime, $employeeId, $serviceId);
        if ($maxCapacity === null) {
            return true;
        }

        $bookedQuantity = $this->getBookedQuantity($date, $startTime, $endTime, $employeeId, $serviceId, $excludeReservationId);
        $totalQuantity = $requestedQuantity + $bookedQuantity;

        if ($totalQuantity > $maxCapacity) {
            Craft::error(
                "Capacity FAILED: date={$date} time={$startTime}-{$endTime} employeeId=" . ($employeeId ?? 'NULL')
                . " serviceId=" . ($serviceId ?? 'NULL') . " | requested={$requestedQuantity} + booked={$bookedQuantity} = {$totalQuantity} > max={$maxCapacity}",
                __METHOD__
            );
        }

        return $totalQuantity <= $maxCapacity;
    }

    public function getCapacityForSlot(
        string $date,
        string $startTime,
        ?int $employeeId,
        ?int $serviceId,
    ): ?int {
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) {
            return null;
        }

        $dayOfWeek = (int)$dateObj->format('N');

        // Employee-less services: capacity from Schedule's per-day capacity
        if ($employeeId === null && $serviceId !== null) {
            $schedule = Booked::getInstance()->getScheduleAssignment()
                ->getActiveScheduleForServiceOnDate($serviceId, $date);
            return $schedule?->getCapacityForDay($dayOfWeek) ?? ($schedule !== null ? 1 : null);
        }

        $query = Employee::find()->siteId('*')->enabled();
        if ($employeeId !== null) {
            $query->id($employeeId);
        }
        if ($serviceId !== null) {
            $query->serviceId($serviceId);
        }

        $employees = $query->all();
        if (empty($employees)) {
            return null;
        }

        $slotMinutes = $this->getTimeWindowService()->timeToMinutes($startTime);
        $scheduleAssignment = Booked::getInstance()->getScheduleAssignment();
        $employeeIds = array_map(fn($e) => $e->id, $employees);
        $scheduleMap = $scheduleAssignment->getActiveSchedulesForDateBatch($employeeIds, $date);

        $serviceSchedule = $serviceId !== null
            ? $scheduleAssignment->getActiveScheduleForServiceOnDate($serviceId, $date)
            : null;

        foreach ($employees as $employee) {
            $activeSchedule = $scheduleMap[$employee->id] ?? null;
            if ($activeSchedule !== null) {
                foreach ($activeSchedule->getWorkingSlotsForDay($dayOfWeek) as $slot) {
                    if ($this->minutesInRange($slotMinutes, $slot['start'], $slot['end'])) {
                        return $activeSchedule->getCapacityForDay($dayOfWeek) ?? 1;
                    }
                }
                continue;
            }

            // Fallback: inline workingHours
            $hours = $employee->getWorkingHoursForDay($dayOfWeek);
            if ($hours && $this->minutesInRange($slotMinutes, $hours['start'], $hours['end'])) {
                // Employees carry no capacity of their own, so a service schedule
                // that also covers the slot supplies the seats.
                return $serviceSchedule?->getCapacityForDay($dayOfWeek) ?? 1;
            }
        }

        // Final fallback: service schedule
        if ($serviceSchedule !== null) {
            $dayHours = $serviceSchedule->getWorkingHoursForDay($dayOfWeek);
            if ($dayHours !== null && $this->minutesInRange($slotMinutes, $dayHours['start'], $dayHours['end'])) {
                return $serviceSchedule->getCapacityForDay($dayOfWeek) ?? 1;
            }
        }

        return null;
    }

    /**
     * Seats one employee holds on every slot of a day.
     *
     * Mirrors the resolution order of {@see getCapacityFromPreloaded()}, but at
     * day level, so availability subtraction and capacity enrichment agree on
     * how many bookings a slot takes. A schedule's capacity is a per-day value,
     * so the day is the finest grain this needs.
     *
     * Should the two ever drift, this is the more generous of the pair and
     * filterByCapacity() still clamps the outcome — a slot can then be withheld,
     * but never oversold.
     */
    public function getEmployeeSeatsForDay(?Schedule $employeeSchedule, ?Schedule $serviceSchedule, int $dayOfWeek): int
    {
        // Working SLOTS, not working hours: a day can be enabled with its start
        // and end left blank, which yields hours but no slots. Enrichment reads
        // the slots, so testing the hours here made the subtraction stricter than
        // the seat count on exactly that day — a slot leaving the calendar while
        // the API still reported seats free, which is the defect this fixes.
        if ($employeeSchedule !== null && !empty($employeeSchedule->getWorkingSlotsForDay($dayOfWeek))) {
            return max(1, $employeeSchedule->getCapacityForDay($dayOfWeek) ?? 1);
        }

        // The service fallback mirrors getCapacityFromPreloaded(), which checks
        // the day's hours rather than its slots.
        if ($serviceSchedule !== null && $serviceSchedule->getWorkingHoursForDay($dayOfWeek) !== null) {
            return max(1, $serviceSchedule->getCapacityForDay($dayOfWeek) ?? 1);
        }

        return 1;
    }

    public function getBookedQuantity(
        string $date,
        string $startTime,
        string $endTime,
        ?int $employeeId,
        ?int $serviceId,
        ?int $excludeReservationId = null,
    ): int {
        $query = ReservationFactory::find()
            ->siteId('*')
            ->bookingDate($date)
            ->status(self::SEAT_HOLDING_STATUSES);

        $employeeId !== null ? $query->employeeId($employeeId) : $query->andWhere(['employeeId' => null]);
        if ($serviceId !== null) {
            $query->serviceId($serviceId);
        }
        if ($excludeReservationId !== null) {
            $query->andWhere(['!=', 'booked_reservations.id', $excludeReservationId]);
        }

        // Overlap condition: booking.startTime < endTime AND booking.endTime > startTime
        $normalizedStart = substr($startTime, 0, 5);
        $normalizedEnd = substr($endTime, 0, 5);
        $query->andWhere(['<', 'booked_reservations.startTime', $normalizedEnd]);
        $query->andWhere(['>', 'booked_reservations.endTime', $normalizedStart]);

        /** @var \yii\db\ActiveQuery $query */
        return (int) $query->sum('[[booked_reservations.quantity]]');
    }

    /**
     * @param int|null $excludeReservationId Booking being rescheduled — it must not
     *                                       count against the seats on its own slot.
     */
    public function enrichSlotsWithCapacity(array $slots, string $date, ?int $serviceId, ?int $excludeReservationId = null): array
    {
        if (empty($slots)) {
            return $slots;
        }

        $data = $this->loadBatchCapacityData($slots, $date, $serviceId, $excludeReservationId);

        foreach ($slots as &$slot) {
            unset($slot['_scheduleCapacity']);

            $maxCapacity = $this->getCapacityFromPreloaded(
                $data['dayOfWeek'], $slot['time'], $slot['employeeId'] ?? null, $serviceId,
                $data['employees'], $data['schedulesByEmployee'], $data['serviceSchedule']
            );

            // Check overlapping bookings: a booking overlaps this slot if
            // booking.startTime < slot.endTime AND booking.endTime > slot.startTime
            $slotStart = $slot['time'] ?? '';
            $slotEnd = $slot['endTime'] ?? '';
            if ($slotEnd === '' && !empty($slot['duration']) && $slotStart !== '') {
                $slotEnd = $this->getTimeWindowService()->minutesToTime(
                    $this->getTimeWindowService()->timeToMinutes($slotStart) + (int) $slot['duration']
                );
            }
            $slotEmployeeId = $slot['employeeId'] ?? null;
            $bookedQuantity = 0;
            foreach ($data['reservationRecords'] as $rec) {
                if ($slotEmployeeId !== null && $rec['employeeId'] !== null && $rec['employeeId'] !== $slotEmployeeId) {
                    continue;
                }
                if ($slotEmployeeId === null && $rec['employeeId'] !== null) {
                    continue;
                }
                if ($rec['startTime'] < $slotEnd && $rec['endTime'] > $slotStart) {
                    $bookedQuantity += $rec['quantity'];
                }
            }

            $slot['maxCapacity'] = $maxCapacity;
            $slot['bookedQuantity'] = $bookedQuantity;
            $slot['availableCapacity'] = $maxCapacity !== null ? max(0, $maxCapacity - $bookedQuantity) : null;
            $slot['capacity'] = $maxCapacity ?? 1;
        }

        return $slots;
    }

    /**
     * Pre-load all data needed for batch capacity enrichment (~3-4 queries total).
     *
     * @return array{employees: array, schedulesByEmployee: array, serviceSchedule: ?Schedule, reservationRecords: array, dayOfWeek: int}
     */
    protected function loadBatchCapacityData(array $slots, string $date, ?int $serviceId, ?int $excludeReservationId = null): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map(fn($s) => $s['employeeId'] ?? null, $slots))));

        $employees = !empty($employeeIds)
            ? Employee::find()->siteId('*')->id($employeeIds)->indexBy('id')->all()
            : [];

        $scheduleAssignment = Booked::getInstance()->getScheduleAssignment();
        $schedulesByEmployee = !empty($employeeIds)
            ? $scheduleAssignment->getActiveSchedulesForDateBatch($employeeIds, $date)
            : [];

        $serviceSchedule = $serviceId !== null
            ? $scheduleAssignment->getActiveScheduleForServiceOnDate($serviceId, $date)
            : null;

        // Batch-load reservations with time range data for overlap checking
        $reservationQuery = ReservationFactory::find()->siteId('*')->bookingDate($date)->status(self::SEAT_HOLDING_STATUSES);
        if ($serviceId !== null) {
            $reservationQuery->serviceId($serviceId);
        }
        if ($excludeReservationId !== null) {
            $reservationQuery->andWhere(['!=', 'booked_reservations.id', $excludeReservationId]);
        }
        $reservations = $reservationQuery->all();

        // Store reservations as time-range records for overlap-based capacity checking
        $reservationRecords = [];
        /** @var \anvildev\booked\contracts\ReservationInterface $res */
        foreach ($reservations as $res) {
            if (empty($res->startTime) || empty($res->endTime)) {
                continue;
            }
            $reservationRecords[] = [
                'startTime' => substr($res->startTime, 0, 5),
                'endTime' => substr($res->endTime, 0, 5),
                'employeeId' => $res->employeeId,
                'quantity' => $res->quantity ?? 1,
            ];
        }

        $dateObj = DateTime::createFromFormat('Y-m-d', $date);

        if (!$dateObj) {
            Craft::error("Failed to parse date '{$date}' in loadBatchCapacityData", __METHOD__);
            return [
                'employees' => [],
                'schedulesByEmployee' => [],
                'serviceSchedule' => null,
                'reservationRecords' => [],
                'dayOfWeek' => 1,
            ];
        }

        return [
            'employees' => $employees,
            'schedulesByEmployee' => $schedulesByEmployee,
            'serviceSchedule' => $serviceSchedule,
            'reservationRecords' => $reservationRecords,
            'dayOfWeek' => (int)$dateObj->format('N'),
        ];
    }

    private function getCapacityFromPreloaded(
        int $dayOfWeek,
        string $startTime,
        ?int $employeeId,
        ?int $serviceId,
        array $employees,
        array $schedulesByEmployee,
        ?Schedule $serviceSchedule,
    ): ?int {
        $slotMinutes = $this->getTimeWindowService()->timeToMinutes($startTime);

        if ($employeeId === null) {
            return $serviceSchedule !== null ? ($serviceSchedule->getCapacityForDay($dayOfWeek) ?? 1) : null;
        }

        // Check employee schedule
        $schedule = $schedulesByEmployee[$employeeId] ?? null;
        if ($schedule !== null) {
            foreach ($schedule->getWorkingSlotsForDay($dayOfWeek) as $slot) {
                if ($this->minutesInRange($slotMinutes, $slot['start'], $slot['end'])) {
                    return $schedule->getCapacityForDay($dayOfWeek) ?? 1;
                }
            }
        }

        // Fallback: employee inline working hours
        $employee = $employees[$employeeId] ?? null;
        if ($employee !== null) {
            $hours = $employee->getWorkingHoursForDay($dayOfWeek);
            if ($hours && $this->minutesInRange($slotMinutes, $hours['start'], $hours['end'])) {
                // Employees carry no capacity of their own, so a service schedule
                // that also covers the slot supplies the seats.
                return $serviceSchedule?->getCapacityForDay($dayOfWeek) ?? 1;
            }
        }

        // Final fallback: service schedule
        if ($serviceSchedule !== null) {
            $dayHours = $serviceSchedule->getWorkingHoursForDay($dayOfWeek);
            if ($dayHours !== null && $this->minutesInRange($slotMinutes, $dayHours['start'], $dayHours['end'])) {
                return $serviceSchedule->getCapacityForDay($dayOfWeek) ?? 1;
            }
        }

        return null;
    }

    /** Check if minutes-since-midnight falls within a time range. */
    private function minutesInRange(int $minutes, string $start, string $end): bool
    {
        return $minutes >= $this->getTimeWindowService()->timeToMinutes($start)
            && $minutes < $this->getTimeWindowService()->timeToMinutes($end);
    }

    public function getAvailableCapacity(
        string $date,
        string $startTime,
        string $endTime,
        ?int $employeeId,
        ?int $serviceId,
    ): ?int {
        $maxCapacity = $this->getCapacityForSlot($date, $startTime, $employeeId, $serviceId);
        return $maxCapacity !== null
            ? max(0, $maxCapacity - $this->getBookedQuantity($date, $startTime, $endTime, $employeeId, $serviceId))
            : null;
    }
}
