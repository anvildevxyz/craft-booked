<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\EventDate;
use anvildev\booked\tests\Support\TestCase;

/**
 * EventDate Element Test
 *
 * Tests pure business logic methods on the EventDate element.
 * Uses ReflectionClass to bypass Element::init() which requires Craft::$app.
 *
 * DB-dependent methods (getRemainingCapacity, isFullyBooked, isAvailable, getLocation)
 * require integration tests.
 */
class EventDateTest extends TestCase
{
    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    /**
     * Create an EventDate instance without calling init()
     */
    private function makeEventDate(array $props = []): EventDate
    {
        $ref = new \ReflectionClass(EventDate::class);
        $event = $ref->newInstanceWithoutConstructor();

        $event->eventDate = $props['eventDate'] ?? '2025-06-15';
        $event->endDate = $props['endDate'] ?? null;
        $event->startTime = $props['startTime'] ?? '09:00';
        $event->endTime = $props['endTime'] ?? '17:00';
        $event->enabled = $props['enabled'] ?? true;
        $event->capacity = $props['capacity'] ?? null;
        $event->locationId = $props['locationId'] ?? null;
        $event->title = $props['title'] ?? null;
        $event->description = $props['description'] ?? null;

        return $event;
    }

    // =========================================================================
    // getFormattedTimeRange()
    // =========================================================================

    public function testGetFormattedTimeRangeWithHoursMinutes(): void
    {
        $event = $this->makeEventDate(['startTime' => '09:00', 'endTime' => '17:30']);
        $result = $event->getFormattedTimeRange();
        $this->assertNotEmpty($result);
        $this->assertStringContainsString(' - ', $result);
    }

    public function testGetFormattedTimeRangeWithSeconds(): void
    {
        $event = $this->makeEventDate(['startTime' => '09:00:00', 'endTime' => '17:30:00']);
        $result = $event->getFormattedTimeRange();
        $this->assertNotEmpty($result);
        $this->assertStringContainsString(' - ', $result);
    }

    public function testGetFormattedTimeRangeEmptyStartTime(): void
    {
        $event = $this->makeEventDate(['startTime' => '', 'endTime' => '17:00']);
        $this->assertEquals('', $event->getFormattedTimeRange());
    }

    public function testGetFormattedTimeRangeEmptyEndTime(): void
    {
        $event = $this->makeEventDate(['startTime' => '09:00', 'endTime' => '']);
        $this->assertEquals('', $event->getFormattedTimeRange());
    }

    public function testGetFormattedTimeRangeInvalidTimeFallback(): void
    {
        $event = $this->makeEventDate(['startTime' => 'invalid', 'endTime' => 'bad']);
        $this->assertEquals('invalid - bad', $event->getFormattedTimeRange());
    }

    // =========================================================================
    // getFormattedDate()
    // =========================================================================

    public function testGetFormattedDateUsesFormatter(): void
    {
        $event = $this->makeEventDate(['eventDate' => '2025-06-15']);
        $result = $event->getFormattedDate();
        $this->assertNotEmpty($result);
        // Should contain the year and not be a raw Y-m-d string
        $this->assertStringContainsString('2025', $result);
        $this->assertNotEquals('2025-06-15', $result);
    }

    public function testGetFormattedDateEmptyReturnsEmpty(): void
    {
        $event = $this->makeEventDate(['eventDate' => '']);
        $this->assertEquals('', $event->getFormattedDate());
    }

    public function testGetFormattedDateInvalidReturnsRaw(): void
    {
        $event = $this->makeEventDate(['eventDate' => 'not-a-date']);
        $this->assertEquals('not-a-date', $event->getFormattedDate());
    }

    public function testGetFormattedDateLeadingZeros(): void
    {
        $event = $this->makeEventDate(['eventDate' => '2025-01-05']);
        $result = $event->getFormattedDate();
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('2025', $result);
        $this->assertNotEquals('2025-01-05', $result);
    }

    // =========================================================================
    // getEffectiveEndDate()
    // =========================================================================

    public function testGetEffectiveEndDateReturnsEndDateWhenSet(): void
    {
        $event = $this->makeEventDate(['eventDate' => '2025-06-15', 'endDate' => '2025-06-16']);
        $this->assertEquals('2025-06-16', $event->getEffectiveEndDate());
    }

    public function testGetEffectiveEndDateFallsBackToEventDate(): void
    {
        $event = $this->makeEventDate(['eventDate' => '2025-06-15', 'endDate' => null]);
        $this->assertEquals('2025-06-15', $event->getEffectiveEndDate());
    }

    // =========================================================================
    // getStatus()
    // =========================================================================

    public function testGetStatusReturnsEnabledWhenEnabled(): void
    {
        $event = $this->makeEventDate(['enabled' => true]);
        $this->assertEquals('enabled', $event->getStatus());
    }

    public function testGetStatusReturnsDisabledWhenDisabled(): void
    {
        $event = $this->makeEventDate(['enabled' => false]);
        $this->assertEquals('disabled', $event->getStatus());
    }

    // =========================================================================
    // Static methods
    // =========================================================================

    public function testHasTitlesReturnsTrue(): void
    {
        $this->assertTrue(EventDate::hasTitles());
    }

    public function testHasStatusesReturnsTrue(): void
    {
        $this->assertTrue(EventDate::hasStatuses());
    }

    public function testRefHandleReturnsEventDate(): void
    {
        $this->assertEquals('eventDate', EventDate::refHandle());
    }

    public function testStatusesContainsEnabledAndDisabled(): void
    {
        $statuses = EventDate::statuses();
        $this->assertArrayHasKey('enabled', $statuses);
        $this->assertArrayHasKey('disabled', $statuses);
    }

    public function testCanDuplicateReturnsTrue(): void
    {
        $event = $this->makeEventDate();
        $user = \Mockery::mock(\craft\elements\User::class);
        $this->assertTrue($event->canDuplicate($user));
    }
}
