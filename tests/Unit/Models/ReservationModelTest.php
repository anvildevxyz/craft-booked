<?php

namespace anvildev\booked\tests\Unit\Models;

use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\models\ReservationModel;
use anvildev\booked\records\ReservationRecord;
use anvildev\booked\tests\Support\TestCase;

/**
 * ReservationModel Test
 *
 * Tests the ActiveRecord-based ReservationModel implementation
 * that is used when Commerce is disabled.
 *
 * Note: Tests requiring Craft CMS initialization are skipped in unit test mode.
 */
class ReservationModelTest extends TestCase
{
    public function testImplementsReservationInterface(): void
    {
        $model = new ReservationModel();
        $this->assertInstanceOf(ReservationInterface::class, $model);
    }

    // =========================================================================
    // Default Values
    // =========================================================================

    public function testDefaultValues(): void
    {
        $model = new ReservationModel();

        $this->assertNull($model->id);
        $this->assertNull($model->uid);
        $this->assertEquals('', $model->userName);
        $this->assertEquals('', $model->userEmail);
        $this->assertNull($model->userPhone);
        $this->assertNull($model->userId);
        $this->assertNull($model->userTimezone);
        $this->assertEquals('', $model->bookingDate);
        $this->assertEquals('', $model->startTime);
        $this->assertEquals('', $model->endTime);
        $this->assertEquals(ReservationRecord::STATUS_CONFIRMED, $model->status);
        $this->assertNull($model->notes);
        $this->assertFalse($model->notificationSent);
        $this->assertEquals(1, $model->quantity);
    }

    public function testDefaultStatusIsConfirmed(): void
    {
        $model = new ReservationModel();
        $this->assertEquals(ReservationRecord::STATUS_CONFIRMED, $model->status);
    }

    public function testDefaultQuantityIsOne(): void
    {
        $model = new ReservationModel();
        $this->assertEquals(1, $model->quantity);
    }

    // =========================================================================
    // Property Getters
    // =========================================================================

    public function testIdentityGetters(): void
    {
        $model = new ReservationModel([
            'id' => 123,
            'uid' => 'abc-123-def-456',
        ]);

        $this->assertEquals(123, $model->getId());
        $this->assertEquals('abc-123-def-456', $model->getUid());
    }

    public function testCustomerDataGetters(): void
    {
        $model = new ReservationModel([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'userPhone' => '+41791234567',
            'userId' => 42,
            'userTimezone' => 'Europe/Zurich',
        ]);

        $this->assertEquals('John Doe', $model->getUserName());
        $this->assertEquals('john@example.com', $model->getUserEmail());
        $this->assertEquals('+41791234567', $model->getUserPhone());
        $this->assertEquals(42, $model->getUserId());
        $this->assertEquals('Europe/Zurich', $model->getUserTimezone());
    }

    public function testCustomerEmailAndNameAliases(): void
    {
        $model = new ReservationModel([
            'userName' => 'Jane Doe',
            'userEmail' => 'jane@example.com',
        ]);

        // These are aliases used by PurchasableInterface
        $this->assertEquals('jane@example.com', $model->customerEmail());
        $this->assertEquals('Jane Doe', $model->customerName());
    }

    public function testBookingDetailsGetters(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'status' => ReservationRecord::STATUS_PENDING,
            'notes' => 'Please call ahead',
            'quantity' => 2,
            'confirmationToken' => 'abc123token',
        ]);

        $this->assertEquals('2025-06-15', $model->getBookingDate());
        $this->assertEquals('14:00', $model->getStartTime());
        $this->assertEquals('15:00', $model->getEndTime());
        $this->assertEquals(ReservationRecord::STATUS_PENDING, $model->getStatus());
        $this->assertEquals('Please call ahead', $model->getNotes());
        $this->assertEquals(2, $model->getQuantity());
        $this->assertEquals('abc123token', $model->getConfirmationToken());
    }

    public function testVirtualMeetingGetters(): void
    {
        $model = new ReservationModel([
            'virtualMeetingUrl' => 'https://zoom.us/j/123456',
            'virtualMeetingProvider' => 'zoom',
            'virtualMeetingId' => '123456',
        ]);

        $this->assertEquals('https://zoom.us/j/123456', $model->getVirtualMeetingUrl());
        $this->assertEquals('zoom', $model->getVirtualMeetingProvider());
        $this->assertEquals('123456', $model->getVirtualMeetingId());
    }

    public function testCalendarIntegrationGetters(): void
    {
        $model = new ReservationModel([
            'googleEventId' => 'google-event-123',
            'outlookEventId' => 'outlook-event-456',
        ]);

        $this->assertEquals('google-event-123', $model->getGoogleEventId());
        $this->assertEquals('outlook-event-456', $model->getOutlookEventId());
    }

    public function testNotificationTrackingGetters(): void
    {
        $model = new ReservationModel([
            'notificationSent' => true,
            'emailReminder24hSent' => true,
            'emailReminder1hSent' => false,
            'smsReminder24hSent' => true,
            'smsConfirmationSent' => true,
            'smsCancellationSent' => false,
            'smsDeliveryStatus' => 'delivered',
        ]);

        $this->assertTrue($model->getNotificationSent());
        $this->assertTrue($model->getEmailReminder24hSent());
        $this->assertFalse($model->getEmailReminder1hSent());
        $this->assertTrue($model->getSmsReminder24hSent());
        $this->assertTrue($model->getSmsConfirmationSent());
        $this->assertFalse($model->getSmsCancellationSent());
        $this->assertEquals('delivered', $model->getSmsDeliveryStatus());
    }

    public function testForeignKeyGetters(): void
    {
        $model = new ReservationModel([
            'employeeId' => 10,
            'locationId' => 20,
            'serviceId' => 30,
            'eventDateId' => 40,
        ]);

        $this->assertEquals(10, $model->getEmployeeId());
        $this->assertEquals(20, $model->getLocationId());
        $this->assertEquals(30, $model->getServiceId());
        $this->assertEquals(40, $model->getEventDateId());
    }

    public function testTimestampGetters(): void
    {
        $now = new \DateTime();
        $model = new ReservationModel([
            'dateCreated' => $now,
            'dateUpdated' => $now,
        ]);

        $this->assertEquals($now, $model->getDateCreated());
        $this->assertEquals($now, $model->getDateUpdated());
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function testValidationRequiresBasicFields(): void
    {
        $model = new ReservationModel();

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('userName', $model->getErrors());
        $this->assertArrayHasKey('userEmail', $model->getErrors());
        $this->assertArrayHasKey('bookingDate', $model->getErrors());
        $this->assertArrayHasKey('startTime', $model->getErrors());
        $this->assertArrayHasKey('endTime', $model->getErrors());
    }

    public function testValidDataPassesValidation(): void
    {
        $model = new ReservationModel([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $this->assertTrue($model->validate());
        $this->assertEmpty($model->getErrors());
    }

    public function testValidationRejectsInvalidEmail(): void
    {
        $model = new ReservationModel([
            'userName' => 'John Doe',
            'userEmail' => 'not-an-email',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('userEmail', $model->getErrors());
    }

    public function testValidationRejectsInvalidDateFormat(): void
    {
        $model = new ReservationModel([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '15-06-2025', // Wrong format
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('bookingDate', $model->getErrors());
    }

    public function testValidationRejectsInvalidTimeFormat(): void
    {
        $model = new ReservationModel([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '2:00 PM', // Wrong format
            'endTime' => '15:00',
        ]);

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('startTime', $model->getErrors());
    }

    public function testValidationRejectsInvalidStatus(): void
    {
        $model = new ReservationModel([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'status' => 'invalid_status',
        ]);

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('status', $model->getErrors());
    }

    public function testValidationAcceptsValidStatuses(): void
    {
        $statuses = [
            ReservationRecord::STATUS_PENDING,
            ReservationRecord::STATUS_CONFIRMED,
            ReservationRecord::STATUS_CANCELLED,
        ];

        foreach ($statuses as $status) {
            $model = new ReservationModel([
                'userName' => 'John Doe',
                'userEmail' => 'john@example.com',
                'bookingDate' => '2025-06-15',
                'startTime' => '14:00',
                'endTime' => '15:00',
                'status' => $status,
            ]);

            $this->assertTrue($model->validate(), "Status {$status} should be valid");
        }
    }

    public function testValidationAcceptsTimeWithSeconds(): void
    {
        $model = new ReservationModel([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00:00',
            'endTime' => '15:00:00',
        ]);

        $this->assertTrue($model->validate());
    }

    public function testValidationRejectsNegativeQuantity(): void
    {
        $model = new ReservationModel([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'quantity' => 0,
        ]);

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('quantity', $model->getErrors());
    }

    // =========================================================================
    // Business Logic - Duration
    // =========================================================================

    public function testGetDurationMinutes(): void
    {
        $model = new ReservationModel([
            'startTime' => '14:00',
            'endTime' => '15:30',
        ]);

        $this->assertEquals(90, $model->getDurationMinutes());
    }

    public function testGetDurationMinutesWithSeconds(): void
    {
        $model = new ReservationModel([
            'startTime' => '14:00:00',
            'endTime' => '15:00:00',
        ]);

        $this->assertEquals(60, $model->getDurationMinutes());
    }

    public function testGetDurationMinutesWithEmptyTimes(): void
    {
        $model = new ReservationModel();
        $this->assertEquals(0, $model->getDurationMinutes());
    }

    // =========================================================================
    // Business Logic - Conflict Detection
    // =========================================================================

    public function testConflictsWithOverlappingReservation(): void
    {
        $reservation1 = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $reservation2 = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:30',
            'endTime' => '15:30',
        ]);

        $this->assertTrue($reservation1->conflictsWith($reservation2));
        $this->assertTrue($reservation2->conflictsWith($reservation1));
    }

    public function testNoConflictWithAdjacentReservation(): void
    {
        $reservation1 = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $reservation2 = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '15:00',
            'endTime' => '16:00',
        ]);

        $this->assertFalse($reservation1->conflictsWith($reservation2));
        $this->assertFalse($reservation2->conflictsWith($reservation1));
    }

    public function testNoConflictOnDifferentDates(): void
    {
        $reservation1 = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $reservation2 = new ReservationModel([
            'bookingDate' => '2025-06-16',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $this->assertFalse($reservation1->conflictsWith($reservation2));
    }

    public function testConflictsWithContainedReservation(): void
    {
        $outer = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '16:00',
        ]);

        $inner = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:30',
            'endTime' => '15:30',
        ]);

        $this->assertTrue($outer->conflictsWith($inner));
        $this->assertTrue($inner->conflictsWith($outer));
    }

    // =========================================================================
    // Business Logic - Event Based
    // =========================================================================

    public function testIsEventBasedWithEventDateId(): void
    {
        $model = new ReservationModel([
            'eventDateId' => 123,
        ]);

        $this->assertTrue($model->isEventBased());
    }

    public function testIsEventBasedWithoutEventDateId(): void
    {
        $model = new ReservationModel();
        $this->assertFalse($model->isEventBased());
    }

    // =========================================================================
    // Business Logic - Status Label
    // =========================================================================

    public function testGetStatusLabel(): void
    {
        $statuses = ReservationModel::getStatuses();

        foreach ($statuses as $status => $label) {
            $model = new ReservationModel(['status' => $status]);
            $this->assertEquals($label, $model->getStatusLabel());
        }
    }

    public function testGetStatusLabelWithUnknownStatus(): void
    {
        $model = new ReservationModel(['status' => 'unknown']);
        $this->assertEquals('Unknown', $model->getStatusLabel());
    }

    // =========================================================================
    // Business Logic - Booking DateTime
    // =========================================================================

    public function testGetBookingDateTime(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:30',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertInstanceOf(\DateTime::class, $dateTime);
        $this->assertEquals('2025-06-15', $dateTime->format('Y-m-d'));
        $this->assertEquals('14:30', $dateTime->format('H:i'));
    }

    public function testGetBookingDateTimeWithSeconds(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:30:00',
        ]);

        $dateTime = $model->getBookingDateTime();
        $this->assertInstanceOf(\DateTime::class, $dateTime);
    }

    public function testGetBookingDateTimeReturnsNullWhenEmpty(): void
    {
        $model = new ReservationModel();
        $this->assertNull($model->getBookingDateTime());
    }

    // =========================================================================
    // Business Logic - Formatted DateTime
    // =========================================================================

    public function testGetFormattedDateTime(): void
    {
        $model = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $formatted = $model->getFormattedDateTime();
        $this->assertNotEmpty($formatted);
        // Should contain the translated time connectors and locale-formatted times
        $this->assertStringContainsString('2025', $formatted);
        // Verify it contains some time representation (locale-dependent: "14:00" or "2:00 PM")
        $this->assertMatchesRegularExpression('/\d{1,2}[:.]\d{2}/', $formatted);
    }

    public function testGetFormattedDateTimeWithEmptyValues(): void
    {
        $model = new ReservationModel();
        $this->assertEquals('', $model->getFormattedDateTime());
    }

    // =========================================================================
    // URLs (require Craft CMS)
    // =========================================================================

    public function testGetManagementUrl(): void
    {
        $this->requiresCraft();

        $model = new ReservationModel([
            'confirmationToken' => 'test-token-123',
        ]);

        $url = $model->getManagementUrl();
        $this->assertStringContainsString('booking/manage/test-token-123', $url);
    }

    public function testGetCancelUrl(): void
    {
        $this->requiresCraft();

        $model = new ReservationModel([
            'confirmationToken' => 'test-token-123',
        ]);

        $url = $model->getCancelUrl();
        $this->assertStringContainsString('booking/cancel/test-token-123', $url);
    }

    public function testGetIcsUrl(): void
    {
        $this->requiresCraft();

        $model = new ReservationModel([
            'confirmationToken' => 'test-token-123',
        ]);

        $url = $model->getIcsUrl();
        $this->assertStringContainsString('booking/ics/test-token-123', $url);
    }

    public function testGetCpEditUrl(): void
    {
        $this->requiresCraft();

        $model = new ReservationModel(['id' => 456]);
        $url = $model->getCpEditUrl();

        $this->assertStringContainsString('booked/bookings/456', $url);
    }

    public function testGetCpEditUrlReturnsNullWithoutId(): void
    {
        $this->requiresCraft();

        $model = new ReservationModel();
        $this->assertNull($model->getCpEditUrl());
    }

    // =========================================================================
    // Static Methods
    // =========================================================================

    public function testGetStatuses(): void
    {
        $statuses = ReservationModel::getStatuses();

        $this->assertIsArray($statuses);
        $this->assertNotEmpty($statuses);
        $this->assertArrayHasKey(ReservationRecord::STATUS_PENDING, $statuses);
        $this->assertArrayHasKey(ReservationRecord::STATUS_CONFIRMED, $statuses);
        $this->assertArrayHasKey(ReservationRecord::STATUS_CANCELLED, $statuses);
    }

    // =========================================================================
    // Pricing (without real service)
    // =========================================================================

    public function testGetExtrasPriceWithoutId(): void
    {
        $model = new ReservationModel();
        $this->assertEquals(0.0, $model->getExtrasPrice());
    }

    public function testGetExtrasSummaryWithoutId(): void
    {
        $model = new ReservationModel();
        $this->assertEquals('', $model->getExtrasSummary());
    }

    public function testHasExtrasWithoutId(): void
    {
        $model = new ReservationModel();
        $this->assertFalse($model->hasExtras());
    }

    // =========================================================================
    // Quantity handling
    // =========================================================================

    public function testGetQuantityDefaultsToOne(): void
    {
        $model = new ReservationModel();
        $this->assertEquals(1, $model->getQuantity());
    }

    public function testGetQuantityReturnsSetValue(): void
    {
        $model = new ReservationModel(['quantity' => 5]);
        $this->assertEquals(5, $model->getQuantity());
    }
}
