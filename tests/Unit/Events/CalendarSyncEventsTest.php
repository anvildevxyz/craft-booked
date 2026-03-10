<?php

namespace anvildev\booked\tests\Unit\Events;

use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\events\AfterCalendarSyncEvent;
use anvildev\booked\events\BeforeCalendarSyncEvent;
use anvildev\booked\tests\Support\TestCase;
use Mockery;

/**
 * Calendar Sync Events Test
 */
class CalendarSyncEventsTest extends TestCase
{
    // =========================================================================
    // BeforeCalendarSyncEvent
    // =========================================================================

    public function testBeforeCalendarSyncEventDefaults(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new BeforeCalendarSyncEvent([
            'reservation' => $reservation,
            'provider' => 'google',
            'action' => 'create',
        ]);

        $this->assertSame($reservation, $event->reservation);
        $this->assertEquals('google', $event->provider);
        $this->assertEquals('create', $event->action);
        $this->assertEquals([], $event->eventData);
        $this->assertNull($event->employeeId);
        $this->assertTrue($event->isValid);
    }

    public function testBeforeCalendarSyncEventIsCancelable(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new BeforeCalendarSyncEvent([
            'reservation' => $reservation,
            'provider' => 'outlook',
            'action' => 'update',
        ]);

        $event->isValid = false;
        $this->assertFalse($event->isValid);
    }

    public function testBeforeCalendarSyncEventAcceptsEventData(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new BeforeCalendarSyncEvent([
            'reservation' => $reservation,
            'provider' => 'google',
            'action' => 'create',
            'eventData' => ['colorId' => '11'],
            'employeeId' => 5,
        ]);

        $this->assertEquals(['colorId' => '11'], $event->eventData);
        $this->assertEquals(5, $event->employeeId);
    }

    // =========================================================================
    // AfterCalendarSyncEvent
    // =========================================================================

    public function testAfterCalendarSyncEventDefaults(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new AfterCalendarSyncEvent([
            'reservation' => $reservation,
            'provider' => 'google',
            'action' => 'create',
        ]);

        $this->assertTrue($event->success);
        $this->assertNull($event->errorMessage);
        $this->assertNull($event->externalEventId);
        $this->assertEquals([], $event->response);
        $this->assertEquals(0.0, $event->duration);
    }

    public function testAfterCalendarSyncEventWithFailure(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new AfterCalendarSyncEvent([
            'reservation' => $reservation,
            'provider' => 'outlook',
            'action' => 'delete',
            'success' => false,
            'errorMessage' => 'Token expired',
            'duration' => 1.5,
        ]);

        $this->assertFalse($event->success);
        $this->assertEquals('Token expired', $event->errorMessage);
        $this->assertEquals(1.5, $event->duration);
    }

    public function testAfterCalendarSyncEventWithExternalId(): void
    {
        $reservation = Mockery::mock(ReservationInterface::class);
        $event = new AfterCalendarSyncEvent([
            'reservation' => $reservation,
            'provider' => 'google',
            'action' => 'create',
            'externalEventId' => 'abc123',
            'response' => ['id' => 'abc123', 'status' => 'confirmed'],
        ]);

        $this->assertEquals('abc123', $event->externalEventId);
        $this->assertEquals(['id' => 'abc123', 'status' => 'confirmed'], $event->response);
    }
}
