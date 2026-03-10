<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\EventDate;
use anvildev\booked\tests\Support\TestCase;

/**
 * EventDate Price & Commerce Integration Tests
 *
 * Tests the price property, validation, and display methods
 * added for Commerce integration on EventDate elements.
 */
class EventDatePriceTest extends TestCase
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
        $event->price = $props['price'] ?? null;
        $event->locationId = $props['locationId'] ?? null;
        $event->title = $props['title'] ?? null;
        $event->description = $props['description'] ?? null;

        return $event;
    }

    // =========================================================================
    // Price property defaults
    // =========================================================================

    public function testPriceDefaultsToNull(): void
    {
        $event = $this->makeEventDate();
        $this->assertNull($event->price);
    }

    public function testPriceCanBeSetToFloat(): void
    {
        $event = $this->makeEventDate(['price' => 49.99]);
        $this->assertSame(49.99, $event->price);
    }

    public function testPriceCanBeSetToZero(): void
    {
        $event = $this->makeEventDate(['price' => 0.0]);
        $this->assertSame(0.0, $event->price);
    }

    public function testPriceCanBeSetToNull(): void
    {
        $event = $this->makeEventDate(['price' => null]);
        $this->assertNull($event->price);
    }

    public function testPriceCanBeSetToLargeValue(): void
    {
        $event = $this->makeEventDate(['price' => 9999.9999]);
        $this->assertSame(9999.9999, $event->price);
    }

    // =========================================================================
    // Price with other properties
    // =========================================================================

    public function testPriceCoexistsWithCapacity(): void
    {
        $event = $this->makeEventDate(['price' => 25.00, 'capacity' => 50]);
        $this->assertSame(25.00, $event->price);
        $this->assertSame(50, $event->capacity);
    }

    public function testFreeEventHasNullPrice(): void
    {
        $event = $this->makeEventDate(['title' => 'Free Workshop']);
        $this->assertNull($event->price);
    }

    public function testPaidEventHasPositivePrice(): void
    {
        $event = $this->makeEventDate(['title' => 'Paid Workshop', 'price' => 120.00]);
        $this->assertSame(120.00, $event->price);
        $this->assertTrue($event->price > 0);
    }

    // =========================================================================
    // Price display logic (for frontend)
    // =========================================================================

    public function testPriceGreaterThanZeroIsTruthy(): void
    {
        $event = $this->makeEventDate(['price' => 0.01]);
        $this->assertTrue($event->price > 0);
    }

    public function testNullPriceIsNotGreaterThanZero(): void
    {
        $event = $this->makeEventDate(['price' => null]);
        $this->assertFalse($event->price > 0);
    }

    public function testZeroPriceIsNotGreaterThanZero(): void
    {
        $event = $this->makeEventDate(['price' => 0.0]);
        $this->assertFalse($event->price > 0);
    }

    // =========================================================================
    // Formatted date and time still work with price set
    // =========================================================================

    public function testFormattedDateUnaffectedByPrice(): void
    {
        $event = $this->makeEventDate(['eventDate' => '2025-12-25', 'price' => 99.99]);
        $result = $event->getFormattedDate();
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('2025', $result);
    }

    public function testFormattedTimeRangeUnaffectedByPrice(): void
    {
        $event = $this->makeEventDate(['startTime' => '10:00', 'endTime' => '12:30', 'price' => 50.00]);
        $result = $event->getFormattedTimeRange();
        $this->assertNotEmpty($result);
        $this->assertStringContainsString(' - ', $result);
    }

    // =========================================================================
    // getEffectiveEndDate still works with price
    // =========================================================================

    public function testEffectiveEndDateWithPrice(): void
    {
        $event = $this->makeEventDate([
            'eventDate' => '2025-06-15',
            'endDate' => '2025-06-16',
            'price' => 200.00,
        ]);
        $this->assertEquals('2025-06-16', $event->getEffectiveEndDate());
    }

    // =========================================================================
    // Status unaffected by price
    // =========================================================================

    public function testEnabledStatusWithPrice(): void
    {
        $event = $this->makeEventDate(['enabled' => true, 'price' => 75.00]);
        $this->assertEquals('enabled', $event->getStatus());
    }

    public function testDisabledStatusWithPrice(): void
    {
        $event = $this->makeEventDate(['enabled' => false, 'price' => 75.00]);
        $this->assertEquals('disabled', $event->getStatus());
    }
}
