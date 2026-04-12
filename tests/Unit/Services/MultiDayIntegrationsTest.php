<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\MultiDayAvailabilityService;
use anvildev\booked\tests\Support\TestCase;

class MultiDayIntegrationsTest extends TestCase
{
    public function testCalculateEndDateWithVariousDurations(): void
    {
        // 1 day = same day
        $this->assertEquals('2026-06-10', MultiDayAvailabilityService::calculateEndDate('2026-06-10', 1));
        // 3 days
        $this->assertEquals('2026-06-12', MultiDayAvailabilityService::calculateEndDate('2026-06-10', 3));
        // 7 days (week)
        $this->assertEquals('2026-06-16', MultiDayAvailabilityService::calculateEndDate('2026-06-10', 7));
    }

    public function testCalculateEndDateAcrossMonthBoundary(): void
    {
        $this->assertEquals('2026-07-02', MultiDayAvailabilityService::calculateEndDate('2026-06-29', 4));
    }

    public function testCalculateEndDateAcrossYearBoundary(): void
    {
        $this->assertEquals('2027-01-02', MultiDayAvailabilityService::calculateEndDate('2026-12-30', 4));
    }

    public function testDateRangesOverlapAdjacent(): void
    {
        $this->assertFalse(MultiDayAvailabilityService::dateRangesOverlap(
            '2026-06-10', '2026-06-12',
            '2026-06-13', '2026-06-15',
        ));
    }

    public function testDateRangesOverlapPartial(): void
    {
        $this->assertTrue(MultiDayAvailabilityService::dateRangesOverlap(
            '2026-06-10', '2026-06-14',
            '2026-06-12', '2026-06-16',
        ));
    }

    public function testDateRangesOverlapContained(): void
    {
        $this->assertTrue(MultiDayAvailabilityService::dateRangesOverlap(
            '2026-06-10', '2026-06-20',
            '2026-06-12', '2026-06-15',
        ));
    }

    public function testDateRangesOverlapIdentical(): void
    {
        $this->assertTrue(MultiDayAvailabilityService::dateRangesOverlap(
            '2026-06-10', '2026-06-12',
            '2026-06-10', '2026-06-12',
        ));
    }

    public function testDateRangesOverlapSingleDayTouchingEnd(): void
    {
        $this->assertTrue(MultiDayAvailabilityService::dateRangesOverlap(
            '2026-06-10', '2026-06-15',
            '2026-06-15', '2026-06-15',
        ));
    }

    public function testGetDatesInRangeSingleDay(): void
    {
        $dates = MultiDayAvailabilityService::getDatesInRange('2026-06-10', '2026-06-10');
        $this->assertEquals(['2026-06-10'], $dates);
    }

    public function testGetDatesInRangeMultipleDays(): void
    {
        $dates = MultiDayAvailabilityService::getDatesInRange('2026-06-10', '2026-06-13');
        $this->assertEquals(['2026-06-10', '2026-06-11', '2026-06-12', '2026-06-13'], $dates);
    }

    public function testGetDatesInRangeAcrossMonth(): void
    {
        $dates = MultiDayAvailabilityService::getDatesInRange('2026-06-29', '2026-07-02');
        $this->assertEquals(['2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02'], $dates);
    }
}
