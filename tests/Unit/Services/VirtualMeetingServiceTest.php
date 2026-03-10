<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\VirtualMeetingService;
use anvildev\booked\tests\Support\TestCase;

/**
 * VirtualMeetingService Test
 *
 * Tests provider dispatch logic and guard clauses.
 * Note: Actual API calls are not tested in unit tests.
 */
class VirtualMeetingServiceTest extends TestCase
{
    public function testCreateMeetingReturnsNullForUnknownProvider(): void
    {
        $this->requiresCraft();

        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);

        $result = $service->createMeeting($reservation, 'unknown_provider');

        $this->assertNull($result);
    }

    public function testCreateMeetingReturnsNullForEmptyProvider(): void
    {
        $this->requiresCraft();

        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);

        $result = $service->createMeeting($reservation, '');

        $this->assertNull($result);
    }

    public function testCreateZoomMeetingReturnsNullWhenDisabled(): void
    {
        $this->requiresCraft();

        $settings = \anvildev\booked\models\Settings::loadSettings();
        $settings->zoomEnabled = false;

        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);

        // createMeeting dispatches to createZoomMeeting which checks settings
        $result = $service->createMeeting($reservation, 'zoom');

        $this->assertNull($result);
    }

    public function testCreateTeamsMeetingReturnsNullWhenDisabled(): void
    {
        $this->requiresCraft();

        $settings = \anvildev\booked\models\Settings::loadSettings();
        $settings->teamsEnabled = false;

        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);

        $result = $service->createMeeting($reservation, 'teams');

        $this->assertNull($result);
    }

    public function testCreateTeamsMeetingReturnsNullWithoutEmployee(): void
    {
        $this->requiresCraft();

        $settings = \anvildev\booked\models\Settings::loadSettings();
        $settings->teamsEnabled = true;

        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->shouldReceive('getEmployee')->andReturn(null);

        $service = new VirtualMeetingService();
        $result = $service->createMeeting($reservation, 'teams');

        $this->assertNull($result);
    }

    public function testDeleteMeetingSkipsWhenNoMeetingId(): void
    {
        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = null;
        $reservation->virtualMeetingProvider = 'zoom';

        // Should return without error
        $service->deleteMeeting($reservation);
        $this->assertTrue(true);
    }

    public function testDeleteMeetingSkipsWhenNoProvider(): void
    {
        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = '12345';
        $reservation->virtualMeetingProvider = null;

        // Should return without error
        $service->deleteMeeting($reservation);
        $this->assertTrue(true);
    }

    public function testDeleteMeetingSkipsWhenBothEmpty(): void
    {
        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = null;
        $reservation->virtualMeetingProvider = null;

        $service->deleteMeeting($reservation);
        $this->assertTrue(true);
    }

    public function testUpdateMeetingSkipsWhenNoMeetingId(): void
    {
        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = null;
        $reservation->virtualMeetingProvider = 'zoom';

        // Should return without error
        $service->updateMeeting($reservation);
        $this->assertTrue(true);
    }

    public function testUpdateMeetingSkipsWhenNoProvider(): void
    {
        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = '12345';
        $reservation->virtualMeetingProvider = null;

        // Should return without error
        $service->updateMeeting($reservation);
        $this->assertTrue(true);
    }

    public function testUpdateMeetingSkipsWhenBothEmpty(): void
    {
        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = null;
        $reservation->virtualMeetingProvider = null;

        $service->updateMeeting($reservation);
        $this->assertTrue(true);
    }

    public function testDeleteMeetingHandlesUnknownProvider(): void
    {
        $this->requiresCraft();

        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = '12345';
        $reservation->virtualMeetingProvider = 'unknown_provider';

        // Should log warning but not throw
        $service->deleteMeeting($reservation);
        $this->assertTrue(true);
    }

    public function testUpdateMeetingHandlesUnknownProvider(): void
    {
        $this->requiresCraft();

        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = '12345';
        $reservation->virtualMeetingProvider = 'unknown_provider';

        // Should log warning but not throw
        $service->updateMeeting($reservation);
        $this->assertTrue(true);
    }

    public function testDeleteZoomMeetingReturnsWithoutTokenWhenDisabled(): void
    {
        $this->requiresCraft();

        $settings = \anvildev\booked\models\Settings::loadSettings();
        $settings->zoomEnabled = false;

        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = '12345';
        $reservation->virtualMeetingProvider = 'zoom';

        // Should not throw — Zoom disabled means no access token
        $service->deleteMeeting($reservation);
        $this->assertTrue(true);
    }

    public function testUpdateZoomMeetingReturnsWithoutTokenWhenDisabled(): void
    {
        $this->requiresCraft();

        $settings = \anvildev\booked\models\Settings::loadSettings();
        $settings->zoomEnabled = false;

        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = '12345';
        $reservation->virtualMeetingProvider = 'zoom';

        // Should not throw — Zoom disabled means no access token
        $service->updateMeeting($reservation);
        $this->assertTrue(true);
    }

    public function testDeleteTeamsMeetingSkipsWithoutEmployee(): void
    {
        $this->requiresCraft();

        $settings = \anvildev\booked\models\Settings::loadSettings();
        $settings->teamsEnabled = true;

        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = '12345';
        $reservation->virtualMeetingProvider = 'teams';
        $reservation->shouldReceive('getEmployee')->andReturn(null);

        // Should not throw — just logs warning
        $service->deleteMeeting($reservation);
        $this->assertTrue(true);
    }

    public function testUpdateTeamsMeetingSkipsWithoutEmployee(): void
    {
        $this->requiresCraft();

        $settings = \anvildev\booked\models\Settings::loadSettings();
        $settings->teamsEnabled = true;

        $service = new VirtualMeetingService();
        $reservation = \Mockery::mock(\anvildev\booked\contracts\ReservationInterface::class);
        $reservation->virtualMeetingId = '12345';
        $reservation->virtualMeetingProvider = 'teams';
        $reservation->shouldReceive('getEmployee')->andReturn(null);

        // Should not throw — just logs warning
        $service->updateMeeting($reservation);
        $this->assertTrue(true);
    }
}
