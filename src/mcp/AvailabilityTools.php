<?php

namespace anvildev\booked\mcp;

use anvildev\booked\Booked;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * MCP tools that expose Booked's availability engine.
 *
 * These are read-only computations — they never create or mutate reservations,
 * so they are safe for an AI assistant to call freely when helping a user find
 * a time to book.
 */
class AvailabilityTools
{
    use ToolResponseTrait;

    /**
     * Return bookable time slots for a single day.
     *
     * @param string $date Day to check, formatted Y-m-d.
     * @param int|null $serviceId Restrict slots to this service.
     * @param int|null $employeeId Restrict slots to this employee.
     * @param int|null $locationId Restrict slots to this location.
     * @param int $quantity Requested capacity per slot.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_check_availability',
        description: 'Get available booking slots for a given day (Y-m-d), optionally filtered by service, '
            . 'employee and location. Read-only; does not reserve anything.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function checkAvailability(
        string $date,
        ?int $serviceId = null,
        ?int $employeeId = null,
        ?int $locationId = null,
        int $quantity = 1,
    ): array {
        return $this->guard(function() use ($date, $serviceId, $employeeId, $locationId, $quantity): array {
            $slots = Booked::getInstance()->getAvailability()->getAvailableSlots(
                $date,
                $employeeId,
                $locationId,
                $serviceId,
                max(1, $quantity),
            );

            return [
                'date' => $date,
                'count' => count($slots),
                'slots' => $slots,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_next_available_date',
        description: 'Find the next day (Y-m-d) that has at least one available slot, searching forward from today.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function nextAvailableDate(
        ?int $serviceId = null,
        ?int $employeeId = null,
        ?int $locationId = null,
        int $maxDaysToSearch = 90,
    ): array {
        return $this->guard(function() use ($serviceId, $employeeId, $locationId, $maxDaysToSearch): array {
            $date = Booked::getInstance()->getAvailability()->getNextAvailableDate(
                $serviceId,
                $employeeId,
                $locationId,
                $maxDaysToSearch,
            );

            return ['nextAvailableDate' => $date];
        });
    }

    /**
     * Summarise availability across a date range (e.g. to power a calendar).
     *
     * @param string $startDate Range start, Y-m-d.
     * @param string $endDate Range end, Y-m-d.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_availability_summary',
        description: 'Summarise availability across a date range (Y-m-d to Y-m-d), optionally filtered by '
            . 'service, employee and location. Useful for rendering a month of open/closed days.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function availabilitySummary(
        string $startDate,
        string $endDate,
        ?int $serviceId = null,
        ?int $employeeId = null,
        ?int $locationId = null,
    ): array {
        return $this->guard(function() use ($startDate, $endDate, $serviceId, $employeeId, $locationId): array {
            $summary = Booked::getInstance()->getAvailability()->getAvailabilitySummary(
                $startDate,
                $endDate,
                $serviceId,
                $employeeId,
                $locationId,
            );

            return ['summary' => $summary];
        });
    }
}
