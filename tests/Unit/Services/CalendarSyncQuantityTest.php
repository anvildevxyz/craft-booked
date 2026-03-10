<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\CalendarSyncService;
use anvildev\booked\tests\Support\TestCase;

/**
 * Tests for CalendarSyncService quantity-change integration.
 */
class CalendarSyncQuantityTest extends TestCase
{
    public function testQueueCalendarUpdateMethodExists(): void
    {
        $service = new CalendarSyncService();
        $this->assertTrue(method_exists($service, 'queueCalendarUpdate'));
    }

    public function testQueueCalendarUpdateAcceptsReservationId(): void
    {
        $ref = new \ReflectionMethod(CalendarSyncService::class, 'queueCalendarUpdate');
        $params = $ref->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('reservationId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
    }

    public function testQueueCalendarUpdateReturnsVoid(): void
    {
        $ref = new \ReflectionMethod(CalendarSyncService::class, 'queueCalendarUpdate');
        $this->assertSame('void', $ref->getReturnType()->getName());
    }

    public function testSyncToExternalMethodExists(): void
    {
        $service = new CalendarSyncService();
        $this->assertTrue(method_exists($service, 'syncToExternal'));
    }

    public function testSyncToCalendarJobHasIsUpdateProperty(): void
    {
        $job = new \anvildev\booked\queue\jobs\SyncToCalendarJob([
            'reservationId' => 1,
            'isUpdate' => true,
        ]);

        $this->assertTrue($job->isUpdate);
    }

    public function testSyncToCalendarJobDefaultsToNotUpdate(): void
    {
        $job = new \anvildev\booked\queue\jobs\SyncToCalendarJob([
            'reservationId' => 1,
        ]);

        $this->assertFalse($job->isUpdate);
    }
}
