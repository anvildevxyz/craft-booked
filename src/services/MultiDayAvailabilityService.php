<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\elements\Service;
use anvildev\booked\records\ReservationRecord;
use craft\base\Component;

/**
 * Handles availability calculation for day-based services (durationType = 'days' or 'flexible_days').
 * Separate from AvailabilityService to keep the slot-based path untouched.
 */
class MultiDayAvailabilityService extends Component
{
    /**
     * Get available start dates for a day-based service within a date window.
     *
     * @param int $extrasDuration Additional days from service extras
     * @return string[] Array of YYYY-MM-DD date strings that are valid start dates
     */
    public function getAvailableStartDates(
        string $rangeStart,
        string $rangeEnd,
        int $serviceId,
        ?int $employeeId = null,
        ?int $locationId = null,
        int $quantity = 1,
        int $extrasDuration = 0,
    ): array {
        $service = Service::find()->id($serviceId)->siteId('*')->one();
        if (!$service || !$service->isDayService()) {
            return [];
        }

        // For flexible_days, use minDays as the minimum duration to check
        $duration = $service->isFlexibleDayService()
            ? ($service->minDays ?? 1)
            : ($service->duration ?? 1);
        $duration += $extrasDuration;

        $bufferBefore = $service->bufferBefore ?? 0;
        $bufferAfter = $service->bufferAfter ?? 0;
        $blackoutService = Booked::getInstance()->getBlackoutDate();
        $scheduleResolver = Booked::getInstance()->scheduleResolver;

        $availableDates = [];
        foreach (self::collectCandidateStartDates($rangeStart, $rangeEnd) as $candidateStart) {
            $candidateEnd = self::calculateEndDate($candidateStart, $duration);

            if ($this->isStartDateAvailable(
                $candidateStart, $candidateEnd, $serviceId, $employeeId, $locationId,
                $quantity, $bufferBefore, $bufferAfter, $blackoutService, $scheduleResolver,
            )) {
                $availableDates[] = $candidateStart;
            }
        }

        return $availableDates;
    }

    /**
     * Enumerate every YYYY-MM-DD date in [rangeStart, rangeEnd] inclusive.
     *
     * Returns an empty array when either input is not a valid Y-m-d date or
     * when rangeEnd precedes rangeStart.
     *
     * @return string[]
     */
    public static function collectCandidateStartDates(string $rangeStart, string $rangeEnd): array
    {
        $current = \DateTime::createFromFormat('Y-m-d', $rangeStart);
        $end = \DateTime::createFromFormat('Y-m-d', $rangeEnd);

        if (!$current || !$end || $current > $end) {
            return [];
        }

        $dates = [];
        while ($current <= $end) {
            $dates[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }

        return $dates;
    }

    /**
     * Minimum remaining capacity across the range (tightest day wins). 0 = blocked, null = unconstrained.
     */
    public function getRemainingCapacityForRange(
        string $startDate,
        string $endDate,
        int $serviceId,
        ?int $employeeId = null,
        ?int $locationId = null,
    ): ?int {
        $service = Service::find()->id($serviceId)->siteId('*')->one();
        if (!$service || !$service->isDayService()) {
            return null;
        }

        $blackoutService = Booked::getInstance()->getBlackoutDate();
        $scheduleResolver = Booked::getInstance()->scheduleResolver;

        $bookingDates = self::getDatesInRange($startDate, $endDate);
        if (empty($bookingDates)) {
            return 0;
        }

        $existingByDate = $this->getExistingQuantitiesByDate(
            $serviceId, $employeeId, $locationId, $startDate, $endDate,
        );

        $minRemaining = null;
        foreach ($bookingDates as $date) {
            if ($blackoutService->isDateBlackedOut($date, $employeeId, $locationId)) {
                return 0;
            }

            $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
            if (!$dateObj) {
                return 0;
            }
            $dayOfWeek = (int)$dateObj->format('N');

            if (!$scheduleResolver->hasScheduleForDay($serviceId, $employeeId, $date, $dayOfWeek)) {
                return 0;
            }

            $dayCapacity = $scheduleResolver->getCapacityForDay($serviceId, $employeeId, $date, $dayOfWeek);
            if ($dayCapacity === null) {
                continue;
            }

            $remaining = $dayCapacity - ($existingByDate[$date] ?? 0);
            if ($remaining <= 0) {
                return 0;
            }
            if ($minRemaining === null || $remaining < $minRemaining) {
                $minRemaining = $remaining;
            }
        }

        return $minRemaining;
    }

    public function isStartDateAvailable(
        string $startDate,
        string $endDate,
        int $serviceId,
        ?int $employeeId,
        ?int $locationId,
        int $quantity,
        int $bufferBefore,
        int $bufferAfter,
        BlackoutDateService $blackoutService,
        ScheduleResolverService $scheduleResolver,
    ): bool {
        // Buffer values are stored as days for day-based services.
        $bufferedStart = $bufferBefore > 0
            ? (new \DateTime($startDate))->modify("-{$bufferBefore} days")->format('Y-m-d')
            : $startDate;
        $bufferedEnd = $bufferAfter > 0
            ? (new \DateTime($endDate))->modify("+{$bufferAfter} days")->format('Y-m-d')
            : $endDate;

        $allDates = self::getDatesInRange($bufferedStart, $bufferedEnd);

        foreach ($allDates as $date) {
            if ($blackoutService->isDateBlackedOut($date, $employeeId, $locationId)) {
                return false;
            }
        }

        $existingByDate = $this->getExistingQuantitiesByDate(
            $serviceId, $employeeId, $locationId, $bufferedStart, $bufferedEnd,
        );

        foreach ($allDates as $date) {
            $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
            if (!$dateObj) {
                return false;
            }
            $dayOfWeek = (int)$dateObj->format('N');
            $isBookingDay = $date >= $startDate && $date <= $endDate;

            if ($isBookingDay && !$scheduleResolver->hasScheduleForDay($serviceId, $employeeId, $date, $dayOfWeek)) {
                return false;
            }

            $dayCapacity = $scheduleResolver->getCapacityForDay($serviceId, $employeeId, $date, $dayOfWeek);
            if ($dayCapacity === null) {
                continue;
            }

            $existing = $existingByDate[$date] ?? 0;
            if (($existing + $quantity) > $dayCapacity) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, int> Quantities keyed by YYYY-MM-DD. */
    private function getExistingQuantitiesByDate(
        int $serviceId,
        ?int $employeeId,
        ?int $locationId,
        string $rangeStart,
        string $rangeEnd,
    ): array {
        $query = (new \craft\db\Query())
            ->select(['r.quantity', 'r.bookingDate', 'r.endDate'])
            ->from('{{%booked_reservations}} r')
            ->where(['in', 'r.status', [ReservationRecord::STATUS_CONFIRMED, ReservationRecord::STATUS_PENDING]])
            ->andWhere(['r.serviceId' => $serviceId])
            ->andWhere(['<=', 'r.bookingDate', $rangeEnd])
            ->andWhere(['>=', new \yii\db\Expression('COALESCE(r.endDate, r.bookingDate)'), $rangeStart]);

        if ($employeeId) {
            $query->andWhere(['r.employeeId' => $employeeId]);
        }
        if ($locationId) {
            $query->andWhere(['r.locationId' => $locationId]);
        }

        $perDay = [];
        foreach ($query->all() as $row) {
            $rStart = (string)$row['bookingDate'];
            $rEnd = !empty($row['endDate']) ? (string)$row['endDate'] : $rStart;
            $overlapStart = $rStart < $rangeStart ? $rangeStart : $rStart;
            $overlapEnd = $rEnd > $rangeEnd ? $rangeEnd : $rEnd;
            foreach (self::getDatesInRange($overlapStart, $overlapEnd) as $d) {
                $perDay[$d] = ($perDay[$d] ?? 0) + (int)$row['quantity'];
            }
        }

        return $perDay;
    }

    public static function calculateEndDate(string $startDate, int $durationDays): string
    {
        $start = new \DateTime($startDate);
        $start->modify('+' . ($durationDays - 1) . ' days');
        return $start->format('Y-m-d');
    }

    public static function dateRangesOverlap(string $start1, string $end1, string $start2, string $end2): bool
    {
        return $start1 <= $end2 && $end1 >= $start2;
    }

    /**
     * @return string[]
     */
    public static function getDatesInRange(string $startDate, string $endDate): array
    {
        $dates = [];
        $current = new \DateTime($startDate);
        $end = new \DateTime($endDate);

        while ($current <= $end) {
            $dates[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }

        return $dates;
    }
}
