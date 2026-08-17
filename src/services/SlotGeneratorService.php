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
     * The merged slot reports the seats of every employee behind it, not the
     * seats of whichever employee happened to come first. `seatsByEmployee`
     * keeps the per-employee split so later filters can withdraw one employee's
     * seats without discarding the rest. A null capacity means unlimited and
     * wins over any number it is merged with.
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

    /** Add one employee's seats to a merged slot. Null (unlimited) absorbs anything. */
    private function mergeSlotCapacity(array $merged, array $slot): array
    {
        foreach (['maxCapacity', 'availableCapacity'] as $attribute) {
            $mergedValue = $merged[$attribute] ?? null;
            $slotValue = $slot[$attribute] ?? null;
            $merged[$attribute] = ($mergedValue === null || $slotValue === null)
                ? null
                : $mergedValue + $slotValue;
        }

        $merged['bookedQuantity'] = ($merged['bookedQuantity'] ?? 0) + ($slot['bookedQuantity'] ?? 0);
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
     * Counts seats rather than employees, so one employee holding several seats
     * can take a group booking. With one seat per employee this is the same
     * count as before. An unlimited slot (null capacity) always passes.
     */
    public function filterByEmployeeQuantity(array $slots, int $quantity): array
    {
        $byTime = [];
        foreach ($slots as $slot) {
            $key = $slot['time'] . '-' . ($slot['endTime'] ?? '');
            $byTime[$key][] = $slot;
        }

        $filtered = array_values(array_filter($byTime, function(array $group) use ($quantity) {
            $seats = 0;
            foreach ($group as $slot) {
                // A slot that never went through capacity enrichment brings the one
                // seat of its employee. Only an explicit null means unlimited.
                if (!array_key_exists('availableCapacity', $slot)) {
                    $seats++;
                    continue;
                }
                if ($slot['availableCapacity'] === null) {
                    return true;
                }
                $seats += (int)$slot['availableCapacity'];
            }
            return $seats >= $quantity;
        }));

        return $filtered !== [] ? array_merge(...$filtered) : [];
    }
}
