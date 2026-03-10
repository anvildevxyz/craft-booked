<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\elements\EventDate;
use anvildev\booked\services\EventDateService;
use anvildev\booked\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * EventDateService Test
 *
 * Tests orchestration logic using partial mocks to stub element queries.
 * CRUD operations that call Craft::$app->elements require integration tests.
 */
class EventDateServiceTest extends TestCase
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
     * @return EventDateService|MockInterface
     */
    private function makePartialService(): MockInterface
    {
        return Mockery::mock(EventDateService::class)->makePartial();
    }

    // =========================================================================
    // getRemainingCapacity() - Delegation to event element
    // =========================================================================

    public function testGetRemainingCapacityReturnsNullWhenEventNotFound(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getEventDateById')->with(999)->andReturn(null);

        $this->assertNull($service->getRemainingCapacity(999));
    }

    public function testGetRemainingCapacityDelegatesToEvent(): void
    {
        $mockEvent = Mockery::mock(EventDate::class);
        $mockEvent->shouldReceive('getRemainingCapacity')->once()->andReturn(15);

        $service = $this->makePartialService();
        $service->shouldReceive('getEventDateById')->with(1)->andReturn($mockEvent);

        $this->assertEquals(15, $service->getRemainingCapacity(1));
    }

    public function testGetRemainingCapacityReturnsZeroWhenEventFull(): void
    {
        $mockEvent = Mockery::mock(EventDate::class);
        $mockEvent->shouldReceive('getRemainingCapacity')->once()->andReturn(0);

        $service = $this->makePartialService();
        $service->shouldReceive('getEventDateById')->with(1)->andReturn($mockEvent);

        $this->assertEquals(0, $service->getRemainingCapacity(1));
    }

    public function testGetRemainingCapacityReturnsNullForUnlimitedEvent(): void
    {
        $mockEvent = Mockery::mock(EventDate::class);
        $mockEvent->shouldReceive('getRemainingCapacity')->once()->andReturn(null);

        $service = $this->makePartialService();
        $service->shouldReceive('getEventDateById')->with(1)->andReturn($mockEvent);

        $this->assertNull($service->getRemainingCapacity(1));
    }

    // =========================================================================
    // updateEventDate() - Error handling
    // =========================================================================

    public function testUpdateEventDateThrowsWhenEventNotFound(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getEventDateById')->with(999)->andReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Event date with ID 999 not found');

        $service->updateEventDate(999, ['title' => 'Updated']);
    }

    // =========================================================================
    // deleteEventDate() - Error handling
    // =========================================================================

    public function testDeleteEventDateThrowsWhenEventNotFound(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getEventDateById')->with(999)->andReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Event date with ID 999 not found');

        $service->deleteEventDate(999);
    }

    // =========================================================================
    // getAvailableEventDates() - Filtering logic
    // =========================================================================

    public function testGetAvailableEventDatesFiltersUnavailable(): void
    {
        $available = Mockery::mock();
        $available->shouldReceive('isAvailable')->andReturn(true);

        $unavailable = Mockery::mock();
        $unavailable->shouldReceive('isAvailable')->andReturn(false);

        $service = $this->makePartialService();
        $service->shouldReceive('getEventDates')->andReturn([$available, $unavailable, $available]);

        $result = $service->getAvailableEventDates('2025-06-15');

        $this->assertCount(2, $result);
        $this->assertSame($available, $result[0]);
        $this->assertSame($available, $result[1]);
    }

    public function testGetAvailableEventDatesReturnsEmptyWhenAllUnavailable(): void
    {
        $unavailable = Mockery::mock();
        $unavailable->shouldReceive('isAvailable')->andReturn(false);

        $service = $this->makePartialService();
        $service->shouldReceive('getEventDates')->andReturn([$unavailable, $unavailable]);

        $result = $service->getAvailableEventDates('2025-06-15');

        $this->assertEmpty($result);
    }

    public function testGetAvailableEventDatesReturnsEmptyWhenNoEvents(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getEventDates')->andReturn([]);

        $result = $service->getAvailableEventDates('2025-06-15');

        $this->assertEmpty($result);
    }

    public function testGetAvailableEventDatesReturnsAllWhenAllAvailable(): void
    {
        $event1 = Mockery::mock();
        $event1->shouldReceive('isAvailable')->andReturn(true);
        $event2 = Mockery::mock();
        $event2->shouldReceive('isAvailable')->andReturn(true);

        $service = $this->makePartialService();
        $service->shouldReceive('getEventDates')->andReturn([$event1, $event2]);

        $result = $service->getAvailableEventDates('2025-06-15');

        $this->assertCount(2, $result);
    }

    public function testGetAvailableEventDatesPassesDateFromToGetEventDates(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getEventDates')
            ->once()
            ->with('2025-09-01', null, \Mockery::any())
            ->andReturn([]);

        $result = $service->getAvailableEventDates('2025-09-01');

        $this->assertEmpty($result);
    }

    public function testGetAvailableEventDatesDefaultsToTodayWhenNull(): void
    {
        $today = date('Y-m-d');

        $service = $this->makePartialService();
        $service->shouldReceive('getEventDates')
            ->once()
            ->with($today, null, \Mockery::any())
            ->andReturn([]);

        $result = $service->getAvailableEventDates(null);

        $this->assertEmpty($result);
    }

    // =========================================================================
    // Service structure
    // =========================================================================

    public function testServiceIsComponent(): void
    {
        $service = new EventDateService();
        $this->assertInstanceOf(EventDateService::class, $service);
    }

    public function testServiceHasExpectedMethods(): void
    {
        $service = new EventDateService();
        $this->assertTrue(method_exists($service, 'getEventDates'));
        $this->assertTrue(method_exists($service, 'getAvailableEventDates'));
        $this->assertTrue(method_exists($service, 'getEventDateById'));
        $this->assertTrue(method_exists($service, 'createEventDate'));
        $this->assertTrue(method_exists($service, 'updateEventDate'));
        $this->assertTrue(method_exists($service, 'deleteEventDate'));
        $this->assertTrue(method_exists($service, 'getRemainingCapacity'));
        $this->assertTrue(method_exists($service, 'getBookedCount'));
    }
}
