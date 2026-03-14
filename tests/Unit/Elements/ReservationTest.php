<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\Reservation;
use anvildev\booked\tests\Support\TestCase;

class ReservationTest extends TestCase
{
    public function testStatusesIncludesNoShow(): void
    {
        $this->requiresCraft();
        $statuses = Reservation::statuses();
        $this->assertArrayHasKey('no_show', $statuses);
        $this->assertCount(4, $statuses);
    }

    public function testStatusesHasCorrectColors(): void
    {
        $this->requiresCraft();
        $statuses = Reservation::statuses();
        $this->assertEquals('green', $statuses['confirmed']);
        $this->assertEquals('orange', $statuses['pending']);
        $this->assertNull($statuses['cancelled']);
        $this->assertEquals('red', $statuses['no_show']);
    }

    public function testMarkAsNoShowMethodExists(): void
    {
        $this->assertTrue(method_exists(Reservation::class, 'markAsNoShow'));
    }

    public function testDefineSourcesIncludesNoShow(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/elements/Reservation.php');
        $this->assertStringContainsString("'key' => 'no_show'", $source);
        $this->assertStringContainsString('STATUS_NO_SHOW', $source);
    }
}
