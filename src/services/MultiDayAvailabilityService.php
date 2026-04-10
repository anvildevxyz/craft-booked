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
        $capacity = $service->capacity ?? 1;
        $blackoutService = Booked::getInstance()->getBlackoutDate();
        $scheduleResolver = Booked::getInstance()->scheduleResolver;

        $availableDates = [];
        $current = \DateTime::createFromFormat('Y-m-d', $rangeStart);
        $end = \DateTime::createFromFormat('Y-m-d', $rangeEnd);

        if (!$current || !$end) {
            return [];
        }

        while ($current <= $end) {
            $candidateStart = $current->format('Y-m-d');
            $candidateEnd = self::calculateEndDate($candidateStart, $duration);

            if ($candidateEnd > $rangeEnd) {
                break;
            }

            if ($this->isStartDateAvailable(
                $candidateStart, $candidateEnd, $serviceId, $employeeId, $locationId,
                $quantity, $bufferBefore, $bufferAfter, $blackoutService, $scheduleResolver, $capacity
            )) {
                $availableDates[] = $candidateStart;
            }

            $current->modify('+1 day');
        }

        return $availableDates;
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
        ?int $capacity = null,
    ): bool {
        // For day-based services, buffer values are stored as days directly
        $bufferedStart = $bufferBefore > 0
            ? (new \DateTime($startDate))->modify("-{$bufferBefore} days")->format('Y-m-d')
            : $startDate;
        $bufferedEnd = $bufferAfter > 0
            ? (new \DateTime($endDate))->modify("+{$bufferAfter} days")->format('Y-m-d')
            : $endDate;

        $allDates = self::getDatesInRange($bufferedStart, $bufferedEnd);
        $bookingDates = self::getDatesInRange($startDate, $endDate);

        foreach ($allDates as $date) {
            if ($blackoutService->isDateBlackedOut($date, $employeeId, $locationId)) {
                return false;
            }
        }

        foreach ($bookingDates as $date) {
            $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
            if (!$dateObj) {
                return false;
            }
            $dayOfWeek = (int)$dateObj->format('N');
            if (!$scheduleResolver->hasScheduleForDay($serviceId, $employeeId, $date, $dayOfWeek)) {
                return false;
            }
        }

        $conflictQuery = (new \craft\db\Query())
            ->from('{{%booked_reservations}} r')
            ->where(['in', 'r.status', [ReservationRecord::STATUS_CONFIRMED, ReservationRecord::STATUS_PENDING]])
            ->andWhere(['r.serviceId' => $serviceId]);

        if ($employeeId) {
            $conflictQuery->andWhere(['r.employeeId' => $employeeId]);
        }

        if ($locationId) {
            $conflictQuery->andWhere(['r.locationId' => $locationId]);
        }

        $conflictQuery->andWhere(['<=', 'r.bookingDate', $bufferedEnd]);
        $conflictQuery->andWhere(['>=', "COALESCE(r.endDate, r.bookingDate)", $bufferedStart]);

        // Check capacity: sum existing quantities, not just existence
        $existingQuantity = (int)$conflictQuery->sum('r.quantity');
        if ($capacity === null) {
            $service = Service::find()->id($serviceId)->siteId('*')->one();
            $capacity = $service->capacity ?? 1;
        }

        return ($existingQuantity + $quantity) <= $capacity;
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
