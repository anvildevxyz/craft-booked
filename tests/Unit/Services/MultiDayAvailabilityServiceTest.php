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

    public function testCollectCandidateStartDatesEnumeratesFullRange(): void
    {
        $this->assertEquals(
            ['2026-04-28', '2026-04-29', '2026-04-30'],
            MultiDayAvailabilityService::collectCandidateStartDates('2026-04-28', '2026-04-30'),
        );
    }

    public function testCollectCandidateStartDatesSingleDay(): void
    {
        $this->assertEquals(
            ['2026-04-30'],
            MultiDayAvailabilityService::collectCandidateStartDates('2026-04-30', '2026-04-30'),
        );
    }

    public function testCollectCandidateStartDatesInvertedRangeReturnsEmpty(): void
    {
        $this->assertEquals(
            [],
            MultiDayAvailabilityService::collectCandidateStartDates('2026-05-10', '2026-05-01'),
        );
    }

    public function testCollectCandidateStartDatesInvalidDatesReturnsEmpty(): void
    {
        $this->assertEquals(
            [],
            MultiDayAvailabilityService::collectCandidateStartDates('not-a-date', '2026-05-01'),
        );
    }

    /**
     * Regression: candidate start dates near the end of the search window
     * must still be returned even though their booking end falls outside
     * rangeEnd. Before fa36187..522e282, the getAvailableStartDates loop
     * short-circuited on `candidateEnd > rangeEnd`, which made the last
     * (duration − 1) days of every visible calendar month unselectable for
     * fixed-day services. rangeEnd bounds the start-date search window,
     * not the booking itself.
     */
    public function testCollectCandidateStartDatesIncludesStartsWhoseBookingExtendsPastRangeEnd(): void
    {
        // For a 3-day service on a visible month of April, starting Apr 30
        // gives end date May 2 — past rangeEnd, but still a valid start.
        $candidates = MultiDayAvailabilityService::collectCandidateStartDates('2026-04-01', '2026-04-30');

        $this->assertContains('2026-04-30', $candidates);
        $this->assertContains('2026-04-29', $candidates);
        $this->assertContains('2026-04-28', $candidates);

        // Sanity: the end dates for the last three start candidates with a
        // 3-day service spill past rangeEnd, which is exactly the case the
        // old `break` in getAvailableStartDates was incorrectly filtering.
        $this->assertGreaterThan('2026-04-30', MultiDayAvailabilityService::calculateEndDate('2026-04-30', 3));
        $this->assertGreaterThan('2026-04-30', MultiDayAvailabilityService::calculateEndDate('2026-04-29', 3));
    }
}
