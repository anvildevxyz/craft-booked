<?php

namespace anvildev\booked\tests\Unit\Helpers;

use anvildev\booked\helpers\IcsHelper;
use anvildev\booked\tests\Support\TestCase;
use Mockery;

/**
 * IcsHelper Test
 *
 * Tests the ICS (iCalendar) file generation functionality
 */
class IcsHelperTest extends TestCase
{
    public function testEscapeMethod(): void
    {
        $reflection = new \ReflectionClass(IcsHelper::class);
        $method = $reflection->getMethod('escape');
        $method->setAccessible(true);

        $result = $method->invoke(null, 'Test; with, special\\ characters');
        $this->assertEquals('Test\\; with\\, special\\\\ characters', $result);
    }

    public function testEscapeMethodHandlesNewlines(): void
    {
        $reflection = new \ReflectionClass(IcsHelper::class);
        $method = $reflection->getMethod('escape');
        $method->setAccessible(true);

        $result = $method->invoke(null, "Line 1\nLine 2\r\nLine 3");
        $this->assertEquals('Line 1\\nLine 2\\nLine 3', $result);
    }

    public function testGenerateBasicStructure(): void
    {
        $this->markTestSkipped('Requires full Craft CMS initialization to test element mocks');
        
        $reservation = Mockery::mock('anvildev\booked\elements\Reservation');
        $reservation->shouldReceive('getAttribute')->with('id')->andReturn(123);
        $reservation->shouldReceive('getAttribute')->with('bookingDate')->andReturn('2025-06-15');
        $reservation->shouldReceive('getAttribute')->with('startTime')->andReturn('14:00');
        $reservation->shouldReceive('getAttribute')->with('endTime')->andReturn('15:00');
        $reservation->shouldReceive('getAttribute')->with('uid')->andReturn('test-uid-123');
        $reservation->shouldReceive('getAttribute')->with('userName')->andReturn('John Doe');
        $reservation->shouldReceive('getAttribute')->with('userEmail')->andReturn('john@example.com');
        $reservation->shouldReceive('getAttribute')->with('userPhone')->andReturn('+1234567890');
        $reservation->shouldReceive('getAttribute')->with('notes')->andReturn('');
        $reservation->shouldReceive('getAttribute')->with('virtualMeetingUrl')->andReturn(null);

        $reservation->id = 123;
        $reservation->bookingDate = '2025-06-15';
        $reservation->startTime = '14:00';
        $reservation->endTime = '15:00';
        $reservation->uid = 'test-uid-123';
        $reservation->userName = 'John Doe';
        $reservation->userEmail = 'john@example.com';
        $reservation->userPhone = '+1234567890';
        $reservation->notes = '';
        $reservation->virtualMeetingUrl = null;

        $service = Mockery::mock('anvildev\booked\elements\Service');
        $service->shouldReceive('getAttribute')->with('title')->andReturn('Test Service');
        $service->title = 'Test Service';

        $reservation->shouldReceive('getService')->andReturn($service);
        $reservation->shouldReceive('getEmployee')->andReturn(null);
        $reservation->shouldReceive('getLocation')->andReturn(null);
        $reservation->shouldReceive('getCancelUrl')->andReturn(null);

        $ics = IcsHelper::generate($reservation);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('END:VEVENT', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
        $this->assertStringContainsString('SUMMARY:Test Service', $ics);
        $this->assertStringContainsString('UID:test-uid-123', $ics);
        $this->assertStringContainsString('STATUS:CONFIRMED', $ics);
    }

    public function testGenerateIncludesCustomerInfo(): void
    {
        $this->markTestSkipped('Requires full Craft CMS initialization to test element mocks');
        
        $reservation = Mockery::mock('anvildev\booked\elements\Reservation');
        $reservation->shouldReceive('getAttribute')->andReturn(null)->byDefault();
        $reservation->id = 123;
        $reservation->bookingDate = '2025-06-15';
        $reservation->startTime = '14:00';
        $reservation->endTime = '15:00';
        $reservation->uid = 'test-uid';
        $reservation->userName = 'Jane Smith';
        $reservation->userEmail = 'jane@example.com';
        $reservation->userPhone = '+9876543210';
        $reservation->notes = 'Test notes';
        $reservation->virtualMeetingUrl = null;

        $service = Mockery::mock('anvildev\booked\elements\Service');
        $service->title = 'Meeting';
        $service->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $reservation->shouldReceive('getService')->andReturn($service);
        $reservation->shouldReceive('getEmployee')->andReturn(null);
        $reservation->shouldReceive('getLocation')->andReturn(null);
        $reservation->shouldReceive('getCancelUrl')->andReturn(null);

        $ics = IcsHelper::generate($reservation);

        $this->assertStringContainsString('Kunde: Jane Smith', $ics);
        $this->assertStringContainsString('E-Mail: jane@example.com', $ics);
        $this->assertStringContainsString('Telefon: +9876543210', $ics);
        $this->assertStringContainsString('Notizen: Test notes', $ics);
        $this->assertStringContainsString('ATTENDEE', $ics);
        $this->assertStringContainsString('jane@example.com', $ics);
    }

    public function testGenerateHandlesVirtualMeeting(): void
    {
        $this->markTestSkipped('Requires full Craft CMS initialization to test element mocks');
        
        $reservation = Mockery::mock('anvildev\booked\elements\Reservation');
        $reservation->shouldReceive('getAttribute')->andReturn(null)->byDefault();
        $reservation->id = 123;
        $reservation->bookingDate = '2025-06-15';
        $reservation->startTime = '14:00';
        $reservation->endTime = '15:00';
        $reservation->uid = 'test-uid';
        $reservation->userName = 'Test User';
        $reservation->userEmail = 'test@example.com';
        $reservation->userPhone = null;
        $reservation->notes = '';
        $reservation->virtualMeetingUrl = 'https://meet.example.com/abc123';

        $service = Mockery::mock('anvildev\booked\elements\Service');
        $service->title = 'Online Meeting';
        $service->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $reservation->shouldReceive('getService')->andReturn($service);
        $reservation->shouldReceive('getEmployee')->andReturn(null);
        $reservation->shouldReceive('getLocation')->andReturn(null);
        $reservation->shouldReceive('getCancelUrl')->andReturn(null);

        $ics = IcsHelper::generate($reservation);

        $this->assertStringContainsString('Meeting-Link: https://meet.example.com/abc123', $ics);
        $this->assertStringContainsString('LOCATION:https://meet.example.com/abc123', $ics);
    }

    public function testGenerateWithEmployee(): void
    {
        $this->markTestSkipped('Requires full Craft CMS initialization to test element mocks');
        
        $reservation = Mockery::mock('anvildev\booked\elements\Reservation');
        $reservation->shouldReceive('getAttribute')->andReturn(null)->byDefault();
        $reservation->id = 123;
        $reservation->bookingDate = '2025-06-15';
        $reservation->startTime = '14:00';
        $reservation->endTime = '15:00';
        $reservation->uid = 'test-uid';
        $reservation->userName = 'Customer';
        $reservation->userEmail = 'customer@example.com';
        $reservation->userPhone = null;
        $reservation->notes = '';
        $reservation->virtualMeetingUrl = null;

        $service = Mockery::mock('anvildev\booked\elements\Service');
        $service->title = 'Consultation';
        $service->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $user = Mockery::mock('craft\elements\User');
        $user->email = 'employee@example.com';
        $user->shouldReceive('getName')->andReturn('Dr. Smith');

        $employee = Mockery::mock('anvildev\booked\elements\Employee');
        $employee->title = 'Dr. Smith';
        $employee->shouldReceive('getUser')->andReturn($user);
        $employee->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $reservation->shouldReceive('getService')->andReturn($service);
        $reservation->shouldReceive('getEmployee')->andReturn($employee);
        $reservation->shouldReceive('getLocation')->andReturn(null);
        $reservation->shouldReceive('getCancelUrl')->andReturn(null);

        $ics = IcsHelper::generate($reservation);

        $this->assertStringContainsString('Mitarbeiter: Dr. Smith', $ics);
        $this->assertStringContainsString('ORGANIZER', $ics);
        $this->assertStringContainsString('employee@example.com', $ics);
    }

    public function testGenerateWithLocation(): void
    {
        $this->markTestSkipped('Requires full Craft CMS initialization to test element mocks');
        
        $reservation = Mockery::mock('anvildev\booked\elements\Reservation');
        $reservation->shouldReceive('getAttribute')->andReturn(null)->byDefault();
        $reservation->id = 123;
        $reservation->bookingDate = '2025-06-15';
        $reservation->startTime = '14:00';
        $reservation->endTime = '15:00';
        $reservation->uid = 'test-uid';
        $reservation->userName = 'Customer';
        $reservation->userEmail = 'customer@example.com';
        $reservation->userPhone = null;
        $reservation->notes = '';
        $reservation->virtualMeetingUrl = null;

        $service = Mockery::mock('anvildev\booked\elements\Service');
        $service->title = 'Appointment';
        $service->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $location = Mockery::mock('anvildev\booked\elements\Location');
        $location->title = 'Main Office';
        $location->shouldReceive('getAddress')->andReturn('123 Main St, City, Country');
        $location->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $reservation->shouldReceive('getService')->andReturn($service);
        $reservation->shouldReceive('getEmployee')->andReturn(null);
        $reservation->shouldReceive('getLocation')->andReturn($location);
        $reservation->shouldReceive('getCancelUrl')->andReturn(null);

        $ics = IcsHelper::generate($reservation);

        $this->assertStringContainsString('Standort: 123 Main St, City, Country', $ics);
        $this->assertStringContainsString('LOCATION:123 Main St\\, City\\, Country', $ics);
    }

    public function testGenerateFoldsLongLines(): void
    {
        $this->markTestSkipped('Requires full Craft CMS initialization to test element mocks');
        
        $reservation = Mockery::mock('anvildev\booked\elements\Reservation');
        $reservation->shouldReceive('getAttribute')->andReturn(null)->byDefault();
        $reservation->id = 123;
        $reservation->bookingDate = '2025-06-15';
        $reservation->startTime = '14:00';
        $reservation->endTime = '15:00';
        $reservation->uid = 'test-uid';
        $reservation->userName = 'A very long customer name that exceeds seventy five characters in total length for testing';
        $reservation->userEmail = 'verylongemailaddress@anexampledomainthatistoolongforonelineinthecalendarfile.com';
        $reservation->userPhone = null;
        $reservation->notes = str_repeat('This is a very long note. ', 20);
        $reservation->virtualMeetingUrl = null;

        $service = Mockery::mock('anvildev\booked\elements\Service');
        $service->title = 'Service';
        $service->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $reservation->shouldReceive('getService')->andReturn($service);
        $reservation->shouldReceive('getEmployee')->andReturn(null);
        $reservation->shouldReceive('getLocation')->andReturn(null);
        $reservation->shouldReceive('getCancelUrl')->andReturn(null);

        $ics = IcsHelper::generate($reservation);

        $lines = explode("\r\n", $ics);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(76, strlen($line), "Line exceeds 76 characters: " . substr($line, 0, 80));
        }
    }

    public function testGenerateUsesCarriageReturnLineFeed(): void
    {
        $this->markTestSkipped('Requires full Craft CMS initialization to test element mocks');
        
        $reservation = Mockery::mock('anvildev\booked\elements\Reservation');
        $reservation->shouldReceive('getAttribute')->andReturn(null)->byDefault();
        $reservation->id = 123;
        $reservation->bookingDate = '2025-06-15';
        $reservation->startTime = '14:00';
        $reservation->endTime = '15:00';
        $reservation->uid = 'test-uid';
        $reservation->userName = 'Test';
        $reservation->userEmail = 'test@example.com';
        $reservation->userPhone = null;
        $reservation->notes = '';
        $reservation->virtualMeetingUrl = null;

        $service = Mockery::mock('anvildev\booked\elements\Service');
        $service->title = 'Service';
        $service->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $reservation->shouldReceive('getService')->andReturn($service);
        $reservation->shouldReceive('getEmployee')->andReturn(null);
        $reservation->shouldReceive('getLocation')->andReturn(null);
        $reservation->shouldReceive('getCancelUrl')->andReturn(null);

        $ics = IcsHelper::generate($reservation);

        // ICS files must use \r\n line endings
        $this->assertStringContainsString("\r\n", $ics);
        $this->assertStringNotContainsString("\n\n", $ics);
    }
}
