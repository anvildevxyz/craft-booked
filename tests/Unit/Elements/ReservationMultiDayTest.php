<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\Reservation;
use anvildev\booked\tests\Support\TestCase;

class ReservationMultiDayTest extends TestCase
{
    private function makeReservation(array $props = []): Reservation
    {
        $ref = new \ReflectionClass(Reservation::class);
        $reservation = $ref->newInstanceWithoutConstructor();

        $reservation->bookingDate = $props['bookingDate'] ?? '2026-06-10';
        $reservation->endDate = $props['endDate'] ?? null;
        $reservation->startTime = $props['startTime'] ?? '10:00';
        $reservation->endTime = $props['endTime'] ?? '11:00';
        $reservation->quantity = $props['quantity'] ?? 1;
        $reservation->userName = $props['userName'] ?? 'Test';
        $reservation->userEmail = $props['userEmail'] ?? 'test@test.com';
        $reservation->status = $props['status'] ?? 'confirmed';

        return $reservation;
    }

    public function testIsMultiDayReturnsTrueWhenEndDateSet(): void
    {
        $reservation = $this->makeReservation([
            'bookingDate' => '2026-06-10',
            'endDate' => '2026-06-12',
            'startTime' => '',
            'endTime' => '',
        ]);
        $this->assertTrue($reservation->isMultiDay());
    }

    public function testIsMultiDayReturnsFalseWhenNoEndDate(): void
    {
        $reservation = $this->makeReservation(['endDate' => null]);
        $this->assertFalse($reservation->isMultiDay());
    }

    public function testGetDurationDaysReturnsCorrectSpan(): void
    {
        $reservation = $this->makeReservation([
            'bookingDate' => '2026-06-10',
            'endDate' => '2026-06-12',
        ]);
        $this->assertEquals(3, $reservation->getDurationDays());
    }

    public function testGetDurationDaysSingleDay(): void
    {
        $reservation = $this->makeReservation([
            'bookingDate' => '2026-06-10',
            'endDate' => '2026-06-10',
        ]);
        $this->assertEquals(1, $reservation->getDurationDays());
    }

    public function testGetDurationDaysReturnsNullWhenNoEndDate(): void
    {
        $reservation = $this->makeReservation(['endDate' => null]);
        $this->assertNull($reservation->getDurationDays());
    }

    public function testGetDurationMinutesReturnsZeroForMultiDay(): void
    {
        $reservation = $this->makeReservation([
            'bookingDate' => '2026-06-10',
            'endDate' => '2026-06-12',
            'startTime' => '',
            'endTime' => '',
        ]);
        $this->assertEquals(0, $reservation->getDurationMinutes());
    }

    public function testGetDurationMinutesStillWorksForSingleDay(): void
    {
        $reservation = $this->makeReservation([
            'startTime' => '10:00',
            'endTime' => '11:30',
            'endDate' => null,
        ]);
        $this->assertEquals(90, $reservation->getDurationMinutes());
    }

    public function testMultiDayConflictsWithOverlapping(): void
    {
        $a = $this->makeReservation([
            'bookingDate' => '2026-06-10', 'endDate' => '2026-06-12', 'startTime' => '', 'endTime' => '',
        ]);
        $b = $this->makeReservation([
            'bookingDate' => '2026-06-11', 'endDate' => '2026-06-14', 'startTime' => '', 'endTime' => '',
        ]);
        $this->assertTrue($a->conflictsWith($b));
    }

    public function testMultiDayDoesNotConflictWhenAdjacent(): void
    {
        $a = $this->makeReservation([
            'bookingDate' => '2026-06-10', 'endDate' => '2026-06-12', 'startTime' => '', 'endTime' => '',
        ]);
        $b = $this->makeReservation([
            'bookingDate' => '2026-06-13', 'endDate' => '2026-06-15', 'startTime' => '', 'endTime' => '',
        ]);
        $this->assertFalse($a->conflictsWith($b));
    }

    public function testMultiDayConflictsWithSingleDayInRange(): void
    {
        $multi = $this->makeReservation([
            'bookingDate' => '2026-06-10', 'endDate' => '2026-06-12', 'startTime' => '', 'endTime' => '',
        ]);
        $single = $this->makeReservation([
            'bookingDate' => '2026-06-11', 'endDate' => null, 'startTime' => '10:00', 'endTime' => '11:00',
        ]);
        $this->assertTrue($multi->conflictsWith($single));
    }

    public function testSingleDayDoesNotConflictOutsideMultiDayRange(): void
    {
        $multi = $this->makeReservation([
            'bookingDate' => '2026-06-10', 'endDate' => '2026-06-12', 'startTime' => '', 'endTime' => '',
        ]);
        $single = $this->makeReservation([
            'bookingDate' => '2026-06-13', 'endDate' => null, 'startTime' => '10:00', 'endTime' => '11:00',
        ]);
        $this->assertFalse($multi->conflictsWith($single));
    }
}
