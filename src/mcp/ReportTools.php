<?php

namespace anvildev\booked\mcp;

use anvildev\booked\Booked;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * MCP tools exposing Booked's reporting/analytics surface.
 *
 * All read-only. Date arguments are Y-m-d; most reports default to a sensible
 * recent window when dates are omitted. Monetary figures use the store currency
 * (see the `currency` field in revenue results).
 */
class ReportTools
{
    use ToolResponseTrait;

    /**
     * @param string|null $startDate Window start, Y-m-d.
     * @param string|null $endDate Window end, Y-m-d.
     * @param bool $includePreviousPeriod Also return the preceding period for comparison.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_revenue_report',
        description: 'Revenue totals over a date range (optionally with the previous period for comparison).',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function revenueReport(?string $startDate = null, ?string $endDate = null, bool $includePreviousPeriod = false): array
    {
        return $this->guard(static function() use ($startDate, $endDate, $includePreviousPeriod): array {
            $reports = Booked::getInstance()->getReports();
            $revenue = $reports->getRevenueData($startDate, $endDate, $includePreviousPeriod);

            // getRevenueData carries the underlying reservation models for the CP
            // view/CSV export; over MCP they serialise to opaque {_class} stubs and
            // would leak per-customer detail into a totals report, so summarise as a count.
            if (is_array($revenue['reservations'] ?? null)) {
                $revenue['reservationCount'] = count($revenue['reservations']);
                unset($revenue['reservations']);
            }

            return [
                'currency' => $reports->getCurrency(),
                'revenue' => $revenue,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_bookings_by_service',
        description: 'Booking counts and revenue grouped by service over a date range.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function bookingsByService(?string $startDate = null, ?string $endDate = null): array
    {
        return $this->guard(static fn(): array => [
            'byService' => Booked::getInstance()->getReports()->getByServiceData($startDate, $endDate),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_bookings_by_employee',
        description: 'Booking counts and revenue grouped by employee over a date range.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function bookingsByEmployee(?string $startDate = null, ?string $endDate = null): array
    {
        return $this->guard(static fn(): array => [
            'byEmployee' => Booked::getInstance()->getReports()->getByEmployeeData($startDate, $endDate),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_bookings_by_location',
        description: 'Booking counts and revenue grouped by location over a date range.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function bookingsByLocation(?string $startDate = null, ?string $endDate = null): array
    {
        return $this->guard(static fn(): array => [
            'byLocation' => Booked::getInstance()->getReports()->getByLocationData($startDate, $endDate),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_cancellation_report',
        description: 'Cancellation and no-show counts/rates over a date range.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function cancellationReport(?string $startDate = null, ?string $endDate = null): array
    {
        return $this->guard(static fn(): array => [
            'cancellations' => Booked::getInstance()->getReports()->getCancellationData($startDate, $endDate),
        ]);
    }

    /**
     * @param bool $includeDayOfWeek Break results down by day of week as well as hour.
     * @param int|null $serviceId Restrict to a single service.
     * @param int|null $employeeId Restrict to a single employee.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_peak_hours_report',
        description: 'Busiest hours (and optionally days of week) over a date range, optionally filtered by service/employee.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function peakHoursReport(
        ?string $startDate = null,
        ?string $endDate = null,
        bool $includeDayOfWeek = false,
        ?int $serviceId = null,
        ?int $employeeId = null,
    ): array {
        return $this->guard(static fn(): array => [
            'peakHours' => Booked::getInstance()->getReports()->getPeakHoursData($startDate, $endDate, $includeDayOfWeek, $serviceId, $employeeId),
        ]);
    }

    /**
     * @param string $startDate Window start, Y-m-d (required).
     * @param string $endDate Window end, Y-m-d (required).
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_utilization_report',
        description: 'Capacity utilization (booked vs available) over a date range, optionally filtered by service/employee/location.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function utilizationReport(
        string $startDate,
        string $endDate,
        ?int $serviceId = null,
        ?int $employeeId = null,
        ?int $locationId = null,
    ): array {
        return $this->guard(static fn(): array => [
            'utilization' => Booked::getInstance()->getReports()->getUtilizationData($startDate, $endDate, $serviceId, $employeeId, $locationId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_dashboard_summary',
        description: 'High-level dashboard summary (upcoming bookings, today/this-week counts, recent activity).',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function dashboardSummary(): array
    {
        return $this->guard(static fn(): array => [
            'dashboard' => Booked::getInstance()->getDashboard()->getDashboardData(null),
        ]);
    }
}
