<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\MultiDayAvailabilityService;
use anvildev\booked\tests\Support\TestCase;

class MultiDayAvailabilityServiceTest extends TestCase
{
    public function testCalculateEndDateFromDuration(): void
    {
        $this->assertEquals('2026-06-12', MultiDayAvailabilityService::calculateEndDate('2026-06-10', 3));
    }

    public function testCalculateEndDateSingleDay(): void
    {
        $this->assertEquals('2026-06-10', MultiDayAvailabilityService::calculateEndDate('2026-06-10', 1));
    }

    public function testCalculateEndDateCrossesMonth(): void
    {
        $this->assertEquals('2026-07-03', MultiDayAvailabilityService::calculateEndDate('2026-06-29', 5));
    }

    public function testDateRangeOverlapDetectsOverlap(): void
    {
        $this->assertTrue(MultiDayAvailabilityService::dateRangesOverlap('2026-06-10', '2026-06-12', '2026-06-11', '2026-06-14'));
    }

    public function testDateRangeOverlapDetectsNoOverlap(): void
    {
        $this->assertFalse(MultiDayAvailabilityService::dateRangesOverlap('2026-06-10', '2026-06-12', '2026-06-13', '2026-06-15'));
    }

    public function testDateRangeOverlapAdjacentDaysDoNotOverlap(): void
    {
        $this->assertFalse(MultiDayAvailabilityService::dateRangesOverlap('2026-06-10', '2026-06-12', '2026-06-13', '2026-06-14'));
    }

    public function testDateRangeOverlapSameDayOverlaps(): void
    {
        $this->assertTrue(MultiDayAvailabilityService::dateRangesOverlap('2026-06-10', '2026-06-10', '2026-06-10', '2026-06-10'));
    }

    public function testGetDatesInRange(): void
    {
        $this->assertEquals(['2026-06-10', '2026-06-11', '2026-06-12'], MultiDayAvailabilityService::getDatesInRange('2026-06-10', '2026-06-12'));
    }

    public function testGetDatesInRangeSingleDay(): void
    {
        $this->assertEquals(['2026-06-10'], MultiDayAvailabilityService::getDatesInRange('2026-06-10', '2026-06-10'));
    }
}
