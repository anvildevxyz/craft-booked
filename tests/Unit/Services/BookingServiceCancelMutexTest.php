<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\BookingService;
use anvildev\booked\tests\Support\TestCase;

class BookingServiceCancelMutexTest extends TestCase
{
    public function testCancelLockKeyForEventBooking(): void
    {
        $eventDateId = 42;
        $expected = "booked-event-booking-{$eventDateId}";
        $this->assertEquals('booked-event-booking-42', $expected);
    }

    public function testCancelLockKeyForServiceBooking(): void
    {
        $date = '2026-03-15';
        $time = '14:00:00';
        $employeeId = 5;
        $serviceId = 10;
        $expected = "booked-booking-{$date}-{$time}-{$employeeId}-{$serviceId}";
        $this->assertEquals('booked-booking-2026-03-15-14:00:00-5-10', $expected);
    }
}
