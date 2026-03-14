<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\Reservation;
use anvildev\booked\tests\Support\TestCase;

class ReservationSessionNotesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresCraft();
    }

    public function testSessionNotesPropertyDefaultsToNull(): void
    {
        $reservation = new Reservation();
        $this->assertNull($reservation->sessionNotes);
    }

    public function testSessionNotesCanBeSet(): void
    {
        $reservation = new Reservation();
        $reservation->sessionNotes = 'Client completed all exercises.';
        $this->assertEquals('Client completed all exercises.', $reservation->sessionNotes);
    }

    public function testGetSessionNotesReturnsValue(): void
    {
        $reservation = new Reservation();
        $reservation->sessionNotes = 'Follow-up in 2 weeks';
        $this->assertEquals('Follow-up in 2 weeks', $reservation->getSessionNotes());
    }
}
