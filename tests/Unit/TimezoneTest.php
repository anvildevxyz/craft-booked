<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\helpers\DateHelper;
use anvildev\booked\helpers\IcsHelper;
use anvildev\booked\models\ReservationModel;
use anvildev\booked\services\CalendarSyncService;
use anvildev\booked\services\calendar\GoogleCalendarProvider;
use anvildev\booked\services\calendar\OutlookCalendarProvider;
use anvildev\booked\tests\Support\TestCase;
use Mockery;

/**
 * Timezone Tests
 *
 * Verifies that timezone handling is dynamic throughout the codebase,
 * using the location's timezone or system timezone instead of hardcoded values.
 */
class TimezoneTest extends TestCase
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

    // =========================================================================
    // ReservationModel::getBookingDateTime() timezone tests
    // =========================================================================

    public function testGetBookingDateTimeUsesUserTimezone(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'userTimezone' => 'America/New_York',
        ]);

        $dateTime = $model->getBookingDateTime();

        $this->assertNotNull($dateTime);
        $this->assertEquals('America/New_York', $dateTime->getTimezone()->getName());
        $this->assertEquals('14:00', $dateTime->format('H:i'));
        $this->assertEquals('2025-06-15', $dateTime->format('Y-m-d'));
    }

    public function testGetBookingDateTimeWithEuropeBerlin(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-12-20',
            'startTime' => '09:30',
            'userTimezone' => 'Europe/Berlin',
        ]);

        $dateTime = $model->getBookingDateTime();

        $this->assertNotNull($dateTime);
        $this->assertEquals('Europe/Berlin', $dateTime->getTimezone()->getName());
        $this->assertEquals('09:30', $dateTime->format('H:i'));
    }

    public function testGetBookingDateTimeWithAsiaTokyo(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '09:00',
            'userTimezone' => 'Asia/Tokyo',
        ]);

        $dateTime = $model->getBookingDateTime();

        $this->assertNotNull($dateTime);
        $this->assertEquals('Asia/Tokyo', $dateTime->getTimezone()->getName());
    }

    public function testGetBookingDateTimeFallsBackToSystemTimezone(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'userTimezone' => null,
        ]);

        $dateTime = $model->getBookingDateTime();

        $this->assertNotNull($dateTime);
        // Should use system timezone (Yii::$app->getTimeZone()), not hardcoded Europe/Zurich
        $systemTz = \Yii::$app->getTimeZone();
        $this->assertEquals($systemTz, $dateTime->getTimezone()->getName());
    }

    public function testGetBookingDateTimeWithSecondsFormat(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:30:00',
            'userTimezone' => 'Pacific/Auckland',
        ]);

        $dateTime = $model->getBookingDateTime();

        $this->assertNotNull($dateTime);
        $this->assertEquals('Pacific/Auckland', $dateTime->getTimezone()->getName());
        $this->assertEquals('14:30', $dateTime->format('H:i'));
    }

    public function testGetBookingDateTimeReturnsNullForEmptyFields(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '',
            'startTime' => '',
        ]);

        $this->assertNull($model->getBookingDateTime());
    }

    public function testGetBookingDateTimeReturnsNullForEmptyDate(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '',
            'startTime' => '14:00',
        ]);

        $this->assertNull($model->getBookingDateTime());
    }

    // =========================================================================
    // Timezone conversion correctness (UTC offset verification)
    // =========================================================================

    public function testTimezoneConversionToUtcNewYork(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'userTimezone' => 'America/New_York',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // June 15 = EDT (UTC-4), so 14:00 EDT = 18:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('18:00', $utc->format('H:i'));
    }

    public function testTimezoneConversionToUtcTokyo(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '09:00',
            'userTimezone' => 'Asia/Tokyo',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // JST is UTC+9, so 09:00 JST = 00:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('00:00', $utc->format('H:i'));
    }

    public function testTimezoneConversionToUtcLondonSummer(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-07-01',
            'startTime' => '15:00',
            'userTimezone' => 'Europe/London',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // BST (UTC+1) in July, so 15:00 BST = 14:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('14:00', $utc->format('H:i'));
    }

    public function testTimezoneConversionToUtcLondonWinter(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-01-15',
            'startTime' => '15:00',
            'userTimezone' => 'Europe/London',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // GMT (UTC+0) in January, so 15:00 GMT = 15:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('15:00', $utc->format('H:i'));
    }

    public function testTimezoneConversionDateBoundary(): void
    {
        // Late-night booking in New York should cross date boundary when converted to UTC
        $model = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '22:00',
            'userTimezone' => 'America/New_York',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // 22:00 EDT = 02:00 UTC next day
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('02:00', $utc->format('H:i'));
        $this->assertEquals('2025-06-16', $utc->format('Y-m-d'));
    }

    // =========================================================================
    // CalendarSyncService::toWindowsTimezone() tests
    // =========================================================================

    public function testToWindowsTimezoneKnownMappings(): void
    {
        $this->assertEquals('W. Europe Standard Time', DateHelper::toWindowsTimezone('Europe/Zurich'));
        $this->assertEquals('W. Europe Standard Time', DateHelper::toWindowsTimezone('Europe/Berlin'));
        $this->assertEquals('Eastern Standard Time', DateHelper::toWindowsTimezone('America/New_York'));
        $this->assertEquals('Pacific Standard Time', DateHelper::toWindowsTimezone('America/Los_Angeles'));
        $this->assertEquals('Tokyo Standard Time', DateHelper::toWindowsTimezone('Asia/Tokyo'));
        $this->assertEquals('China Standard Time', DateHelper::toWindowsTimezone('Asia/Shanghai'));
        $this->assertEquals('AUS Eastern Standard Time', DateHelper::toWindowsTimezone('Australia/Sydney'));
        $this->assertEquals('New Zealand Standard Time', DateHelper::toWindowsTimezone('Pacific/Auckland'));
        $this->assertEquals('GMT Standard Time', DateHelper::toWindowsTimezone('Europe/London'));
        $this->assertEquals('India Standard Time', DateHelper::toWindowsTimezone('Asia/Kolkata'));
    }

    public function testToWindowsTimezoneFallsBackToUtc(): void
    {
        // Unknown IANA timezones should fall back to UTC
        $this->assertEquals('UTC', DateHelper::toWindowsTimezone('Antarctica/McMurdo'));
        $this->assertEquals('UTC', DateHelper::toWindowsTimezone('Invalid/Timezone'));
        $this->assertEquals('UTC', DateHelper::toWindowsTimezone(''));
    }

    public function testToWindowsTimezoneUtcMapsToUtc(): void
    {
        $this->assertEquals('UTC', DateHelper::toWindowsTimezone('UTC'));
    }

    // =========================================================================
    // OutlookCalendarProvider::getWindowsTimezone() tests
    // =========================================================================

    public function testOutlookProviderWindowsTimezoneMapping(): void
    {
        // DateHelper::toWindowsTimezone is now the canonical implementation
        $result = DateHelper::toWindowsTimezone('America/New_York');
        $this->assertEquals('Eastern Standard Time', $result);
    }

    public function testOutlookProviderWindowsTimezoneFallback(): void
    {
        // Unknown timezone falls back to UTC
        $result = DateHelper::toWindowsTimezone('Unknown/Timezone');
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
        $this->assertEquals('UTC', $result);
    }

    // =========================================================================
    // GoogleCalendarProvider timezone tests
    // =========================================================================

    public function testGoogleProviderTimezoneCanBeSet(): void
    {
        $provider = new GoogleCalendarProvider();

        $reflection = new \ReflectionClass(GoogleCalendarProvider::class);
        $method = $reflection->getMethod('eventDateTime');
        $method->setAccessible(true);

        $result = $method->invoke($provider, '2025-06-15', '14:00', 'America/Chicago');

        $this->assertEquals('America/Chicago', $result['timeZone']);
        $this->assertEquals('2025-06-15T14:00:00', $result['dateTime']);
    }

    public function testGoogleProviderDefaultTimezoneIsUtc(): void
    {
        $provider = new GoogleCalendarProvider();

        $reflection = new \ReflectionClass(GoogleCalendarProvider::class);
        $method = $reflection->getMethod('eventDateTime');
        $method->setAccessible(true);

        $result = $method->invoke($provider, '2025-06-15', '09:00', 'UTC');

        $this->assertEquals('UTC', $result['timeZone']);
    }

    public function testGoogleProviderTimezoneWithVarious(): void
    {
        $provider = new GoogleCalendarProvider();

        $reflection = new \ReflectionClass(GoogleCalendarProvider::class);
        $method = $reflection->getMethod('eventDateTime');
        $method->setAccessible(true);

        $timezones = [
            'Asia/Tokyo',
            'Europe/London',
            'America/Los_Angeles',
            'Australia/Sydney',
            'Pacific/Auckland',
        ];

        foreach ($timezones as $tz) {
            $result = $method->invoke($provider, '2025-06-15', '10:00', $tz);
            $this->assertEquals($tz, $result['timeZone'], "Timezone should be {$tz}");
        }
    }

    // =========================================================================
    // IcsHelper timezone tests
    // =========================================================================

    public function testIcsGenerateUsesLocationTimezone(): void
    {
        $this->requiresCraft();

        $location = Mockery::mock('anvildev\booked\elements\Location');
        $location->timezone = 'America/New_York';
        $location->title = 'NYC Office';
        $location->shouldReceive('getAddress')->andReturn('123 Broadway, New York');
        $location->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $service = Mockery::mock('anvildev\booked\elements\Service');
        $service->title = 'Consultation';
        $service->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $reservation = Mockery::mock('anvildev\booked\contracts\ReservationInterface');
        $reservation->shouldReceive('getBookingDate')->andReturn('2025-06-15');
        $reservation->shouldReceive('getStartTime')->andReturn('14:00');
        $reservation->shouldReceive('getEndTime')->andReturn('15:00');
        $reservation->shouldReceive('getUid')->andReturn('test-uid-tz');
        $reservation->shouldReceive('getId')->andReturn(999);
        $reservation->shouldReceive('getUserName')->andReturn('Test User');
        $reservation->shouldReceive('getUserEmail')->andReturn('test@example.com');
        $reservation->shouldReceive('getUserPhone')->andReturn(null);
        $reservation->shouldReceive('getNotes')->andReturn(null);
        $reservation->shouldReceive('getVirtualMeetingUrl')->andReturn(null);
        $reservation->shouldReceive('getService')->andReturn($service);
        $reservation->shouldReceive('getEmployee')->andReturn(null);
        $reservation->shouldReceive('getLocation')->andReturn($location);
        $reservation->shouldReceive('getCancelUrl')->andReturn(null);

        $ics = IcsHelper::generate($reservation);

        // 14:00 EDT (UTC-4) = 18:00 UTC
        $this->assertStringContainsString('DTSTART:20250615T180000Z', $ics);
        // 15:00 EDT (UTC-4) = 19:00 UTC
        $this->assertStringContainsString('DTEND:20250615T190000Z', $ics);
    }

    public function testIcsGenerateUsesLocationTimezoneAsiaTokyo(): void
    {
        $this->requiresCraft();

        $location = Mockery::mock('anvildev\booked\elements\Location');
        $location->timezone = 'Asia/Tokyo';
        $location->title = 'Tokyo Office';
        $location->shouldReceive('getAddress')->andReturn('Shibuya, Tokyo');
        $location->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $service = Mockery::mock('anvildev\booked\elements\Service');
        $service->title = 'Meeting';
        $service->shouldReceive('getAttribute')->andReturn(null)->byDefault();

        $reservation = Mockery::mock('anvildev\booked\contracts\ReservationInterface');
        $reservation->shouldReceive('getBookingDate')->andReturn('2025-06-15');
        $reservation->shouldReceive('getStartTime')->andReturn('09:00');
        $reservation->shouldReceive('getEndTime')->andReturn('10:00');
        $reservation->shouldReceive('getUid')->andReturn('test-uid-tokyo');
        $reservation->shouldReceive('getId')->andReturn(888);
        $reservation->shouldReceive('getUserName')->andReturn('Tanaka');
        $reservation->shouldReceive('getUserEmail')->andReturn('tanaka@example.com');
        $reservation->shouldReceive('getUserPhone')->andReturn(null);
        $reservation->shouldReceive('getNotes')->andReturn(null);
        $reservation->shouldReceive('getVirtualMeetingUrl')->andReturn(null);
        $reservation->shouldReceive('getService')->andReturn($service);
        $reservation->shouldReceive('getEmployee')->andReturn(null);
        $reservation->shouldReceive('getLocation')->andReturn($location);
        $reservation->shouldReceive('getCancelUrl')->andReturn(null);

        $ics = IcsHelper::generate($reservation);

        // 09:00 JST (UTC+9) = 00:00 UTC
        $this->assertStringContainsString('DTSTART:20250615T000000Z', $ics);
        // 10:00 JST (UTC+9) = 01:00 UTC
        $this->assertStringContainsString('DTEND:20250615T010000Z', $ics);
    }

    public function testIcsGenerateUsesSystemTimezoneFallback(): void
    {
        $this->requiresCraft();

        // Location with no timezone set
        $reservation = Mockery::mock('anvildev\booked\contracts\ReservationInterface');
        $reservation->shouldReceive('getBookingDate')->andReturn('2025-06-15');
        $reservation->shouldReceive('getStartTime')->andReturn('14:00');
        $reservation->shouldReceive('getEndTime')->andReturn('15:00');
        $reservation->shouldReceive('getUid')->andReturn('test-uid-fallback');
        $reservation->shouldReceive('getId')->andReturn(777);
        $reservation->shouldReceive('getUserName')->andReturn('Test');
        $reservation->shouldReceive('getUserEmail')->andReturn('test@example.com');
        $reservation->shouldReceive('getUserPhone')->andReturn(null);
        $reservation->shouldReceive('getNotes')->andReturn(null);
        $reservation->shouldReceive('getVirtualMeetingUrl')->andReturn(null);
        $reservation->shouldReceive('getService')->andReturn(null);
        $reservation->shouldReceive('getEmployee')->andReturn(null);
        $reservation->shouldReceive('getLocation')->andReturn(null);
        $reservation->shouldReceive('getCancelUrl')->andReturn(null);

        $ics = IcsHelper::generate($reservation);

        // Should still produce valid ICS output (using system timezone)
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('DTSTART:', $ics);
        $this->assertStringContainsString('DTEND:', $ics);
    }

    public function testIcsContainsProdId(): void
    {
        $this->requiresCraft();

        $reservation = Mockery::mock('anvildev\booked\contracts\ReservationInterface');
        $reservation->shouldReceive('getBookingDate')->andReturn('2025-06-15');
        $reservation->shouldReceive('getStartTime')->andReturn('14:00');
        $reservation->shouldReceive('getEndTime')->andReturn('15:00');
        $reservation->shouldReceive('getUid')->andReturn('test-uid-prodid');
        $reservation->shouldReceive('getId')->andReturn(666);
        $reservation->shouldReceive('getUserName')->andReturn('Test');
        $reservation->shouldReceive('getUserEmail')->andReturn('test@example.com');
        $reservation->shouldReceive('getUserPhone')->andReturn(null);
        $reservation->shouldReceive('getNotes')->andReturn(null);
        $reservation->shouldReceive('getVirtualMeetingUrl')->andReturn(null);
        $reservation->shouldReceive('getService')->andReturn(null);
        $reservation->shouldReceive('getEmployee')->andReturn(null);
        $reservation->shouldReceive('getLocation')->andReturn(null);
        $reservation->shouldReceive('getCancelUrl')->andReturn(null);

        $ics = IcsHelper::generate($reservation);

        // Verify PRODID (not PROID) is present
        $this->assertStringContainsString('PRODID:-//Booked Plugin//EN', $ics);
        $this->assertStringNotContainsString('PROID:', $ics);
    }

    // =========================================================================
    // DST edge cases
    // =========================================================================

    public function testDstTransitionSpringForward(): void
    {
        // March 9, 2025: US clocks spring forward (2:00 AM -> 3:00 AM)
        // A booking at 3:00 AM should be in EDT (UTC-4)
        $model = new ReservationModel([
            'bookingDate' => '2025-03-09',
            'startTime' => '15:00',
            'userTimezone' => 'America/New_York',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // After spring forward, 15:00 EDT = 19:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('19:00', $utc->format('H:i'));
    }

    public function testDstTransitionFallBack(): void
    {
        // November 2, 2025: US clocks fall back (2:00 AM -> 1:00 AM)
        // A booking at 15:00 should be in EST (UTC-5)
        $model = new ReservationModel([
            'bookingDate' => '2025-11-02',
            'startTime' => '15:00',
            'userTimezone' => 'America/New_York',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // After fall back, 15:00 EST = 20:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('20:00', $utc->format('H:i'));
    }

    public function testEuropeDstTransition(): void
    {
        // March 30, 2025: Europe clocks spring forward
        $model = new ReservationModel([
            'bookingDate' => '2025-03-30',
            'startTime' => '15:00',
            'userTimezone' => 'Europe/Berlin',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertNotNull($dateTime);

        // After spring forward, CEST (UTC+2), so 15:00 CEST = 13:00 UTC
        $utc = clone $dateTime;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('13:00', $utc->format('H:i'));
    }

    // =========================================================================
    // No hardcoded timezones regression tests
    // =========================================================================

    public function testReservationModelSaveDoesNotHardcodeTimezone(): void
    {
        // Verify the code path in save() uses system timezone, not 'Europe/Zurich'
        $reflection = new \ReflectionClass(ReservationModel::class);
        $source = file_get_contents($reflection->getFileName());

        // The save() method should not contain hardcoded 'Europe/Zurich'
        // (it was replaced with Craft::$app->getTimeZone())
        $this->assertStringNotContainsString("'Europe/Zurich'", $source,
            'ReservationModel should not contain hardcoded Europe/Zurich timezone');
    }

    public function testCalendarSyncServiceDoesNotHardcodeTimezone(): void
    {
        $reflection = new \ReflectionClass(CalendarSyncService::class);
        $source = file_get_contents($reflection->getFileName());

        // The sync methods should not contain hardcoded timezones in event data
        // (The toWindowsTimezone mapping table is allowed — we're checking for
        // direct usage like "'timeZone' => 'Europe/Zurich'")
        $this->assertStringNotContainsString("'timeZone' => 'Europe/Zurich'", $source,
            'CalendarSyncService should not use hardcoded Europe/Zurich in event data');
        $this->assertStringNotContainsString("'timeZone' => 'W. Europe Standard Time'", $source,
            'CalendarSyncService should not use hardcoded W. Europe Standard Time in event data');
    }

    public function testGoogleCalendarProviderDoesNotHardcodeTimezone(): void
    {
        $reflection = new \ReflectionClass(GoogleCalendarProvider::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString("'Europe/Berlin'", $source,
            'GoogleCalendarProvider should not contain hardcoded Europe/Berlin timezone');
        $this->assertStringNotContainsString("'Europe/Zurich'", $source,
            'GoogleCalendarProvider should not contain hardcoded Europe/Zurich timezone');
    }

    public function testOutlookCalendarProviderDoesNotHardcodeTimezoneInEventData(): void
    {
        $reflection = new \ReflectionClass(OutlookCalendarProvider::class);
        $source = file_get_contents($reflection->getFileName());

        // The mapping table in getWindowsTimezone() legitimately contains Windows timezone names.
        // We verify that buildEventData() uses the dynamic method, not hardcoded values.
        $this->assertStringNotContainsString("'timeZone' => 'W. Europe Standard Time'", $source,
            'OutlookCalendarProvider should not hardcode W. Europe Standard Time directly in event data');
    }

    public function testIcsHelperDoesNotHardcodeTimezone(): void
    {
        $reflection = new \ReflectionClass(IcsHelper::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString("'Europe/Zurich'", $source,
            'IcsHelper should not contain hardcoded Europe/Zurich timezone');
    }
}
