<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\elements\Service;
use Craft;
use craft\base\Component;

/**
 * Generates bookable time slots from time windows, applying duration/interval
 * settings, employee assignment, deduplication, and quantity-based filtering.
 */
class SlotGeneratorService extends Component
{
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
     * @param array $windows Time windows [['start' => 'H:i', 'end' => 'H:i', ...], ...]
     * @param int $duration Slot duration in minutes
     * @param int|null $interval Interval between slot starts (null = use duration)
     * @param array $slotDefaults Default values to include in each slot
     */
    public function generateSlots(
        array $windows,
        int $duration,
        ?int $interval = null,
        array $slotDefaults = [],
    ): array {
        $slots = [];
        $slotInterval = $interval ?? $duration;

        if ($slotInterval <= 0 || $duration <= 0) {
            Craft::warning("SlotGeneratorService: Invalid duration ({$duration}) or interval ({$slotInterval})", __METHOD__);
            return [];
        }

        foreach ($windows as $window) {
            if (empty($window['start']) || empty($window['end'])) {
                continue;
            }

            $start = $this->getTimeWindowService()->timeToMinutes($window['start']);
            $end = $this->getTimeWindowService()->timeToMinutes($window['end']);

            Craft::debug("SlotGeneratorService: Window {$window['start']}-{$window['end']}, duration: {$duration}, interval: {$slotInterval}", __METHOD__);

            $slotCount = 0;
            for ($current = $start; $current + $duration <= $end; $current += $slotInterval) {
                $slots[] = array_merge($slotDefaults, [
                    'time' => $this->getTimeWindowService()->minutesToTime($current),
                    'endTime' => $this->getTimeWindowService()->minutesToTime($current + $duration),
                    'duration' => $duration,
                    'employeeId' => $window['employeeId'] ?? null,
                    'locationId' => $window['locationId'] ?? null,
                ]);
                $slotCount++;
            }

            Craft::debug("SlotGeneratorService: Generated {$slotCount} slot(s) for window", __METHOD__);
        }

        return $slots;
    }

    /**
     * Priority: Service timeSlotLength -> Global defaultTimeSlotLength -> duration
     */
    public function getSlotInterval(Service|int|null $serviceOrId, int $duration): int
    {
        if ($serviceOrId !== null) {
            $service = $serviceOrId instanceof Service ? $serviceOrId : Service::find()->siteId('*')->id($serviceOrId)->one();
            if ($service?->timeSlotLength > 0) {
                return $service->timeSlotLength;
            }
        }

        $globalInterval = Booked::getInstance()->getSettings()->defaultTimeSlotLength;
        return ($globalInterval !== null && $globalInterval > 0) ? $globalInterval : $duration;
    }

    public function addEmployeeInfo(array $slots, int $employeeId, string $employeeName, ?string $timezone): array
    {
        $tz = $timezone ?? Craft::$app->getTimezone();
        return array_map(fn($slot) => array_merge($slot, [
            'employeeId' => $employeeId,
            'employeeName' => $employeeName,
            'timezone' => $tz,
        ]), $slots);
    }

    /**
     * Deduplicate slots by time (for "Any available" employee selection).
     *
     * A booking is assigned to ONE employee, so the merged slot advertises the
     * best single employee behind it rather than the seats of whichever came
     * first — and rather than their sum. The wizard uses `availableCapacity` as
     * the largest party it will let a customer pick, and a total spread across
     * staff is not a party anyone can book: two employees with two seats each
     * cannot seat a party of four.
     *
     * `seatsByEmployee` keeps the per-employee split, so a filter asking "is any
     * seat left at all" can still add them up. A null capacity means unlimited
     * and beats any number it is merged with.
     */
    public function deduplicateByTime(array $slots): array
    {
        $seen = [];
        $unique = [];

        foreach ($slots as $slot) {
            $key = $slot['time'] . '-' . ($slot['endTime'] ?? '');
            $employeeId = !empty($slot['employeeId']) ? $slot['employeeId'] : null;

            if (!isset($seen[$key])) {
                $seen[$key] = count($unique);
                $unique[] = array_merge($slot, [
                    'employeeId' => null,
                    'employeeName' => null,
                    'availableEmployeeIds' => $employeeId !== null ? [$employeeId] : [],
                    'seatsByEmployee' => $employeeId !== null ? [$employeeId => $slot['availableCapacity'] ?? null] : [],
                ]);
                continue;
            }

            $idx = $seen[$key];
            if ($employeeId === null || in_array($employeeId, $unique[$idx]['availableEmployeeIds'], true)) {
                continue;
            }

            $unique[$idx]['availableEmployeeIds'][] = $employeeId;
            $unique[$idx]['seatsByEmployee'][$employeeId] = $slot['availableCapacity'] ?? null;
            $unique[$idx] = $this->mergeSlotCapacity($unique[$idx], $slot);
        }

        return $unique;
    }

    /**
     * Adopt an employee's seats into a merged slot when they beat what it holds.
     *
     * The three capacity attributes are carried over together: they describe one
     * employee, and mixing this employee's remaining seats with another's total
     * would describe nobody. Null (unlimited) beats every number.
     */
    private function mergeSlotCapacity(array $merged, array $slot): array
    {
        $mergedSeats = $merged['availableCapacity'] ?? null;
        $slotSeats = $slot['availableCapacity'] ?? null;

        // Already unlimited, or the incoming employee has fewer seats to offer.
        if ($mergedSeats === null) {
            return $merged;
        }
        if ($slotSeats !== null && $slotSeats <= $mergedSeats) {
            return $merged;
        }

        $merged['maxCapacity'] = $slot['maxCapacity'] ?? null;
        $merged['availableCapacity'] = $slotSeats;
        $merged['bookedQuantity'] = $slot['bookedQuantity'] ?? 0;
        $merged['capacity'] = $merged['maxCapacity'] ?? 1;

        return $merged;
    }

    public function sortByTime(array $slots): array
    {
        usort($slots, fn($a, $b) => strcmp($a['time'], $b['time']));
        return $slots;
    }

    /**
     * Filter slots that cannot seat $quantity bookings at the same time.
     *
     * A party is one reservation held by one employee, so the test is whether
     * any SINGLE employee has enough seats — not whether the seats add up across
     * staff. Two employees with two seats each cannot seat a party of four, and
     * offering that slot only to have the booking refused is worse than not
     * offering it.
     *
     * With one seat per employee this reduces to the old head count. An
     * unlimited slot (null capacity) always passes.
     */
    public function filterByEmployeeQuantity(array $slots, int $quantity): array
    {
        $byTime = [];
        foreach ($slots as $slot) {
            $key = $slot['time'] . '-' . ($slot['endTime'] ?? '');
            $byTime[$key][] = $slot;
        }

        $filtered = array_values(array_filter($byTime, function(array $group) use ($quantity) {
            $best = 0;
            foreach ($group as $slot) {
                // A slot that never went through capacity enrichment brings the one
                // seat of its employee. Only an explicit null means unlimited.
                if (!array_key_exists('availableCapacity', $slot)) {
                    $best = max($best, 1);
                    continue;
                }
                if ($slot['availableCapacity'] === null) {
                    return true;
                }
                $best = max($best, (int)$slot['availableCapacity']);
            }
            return $best >= $quantity;
        }));

        return $filtered !== [] ? array_merge(...$filtered) : [];
    }
}
