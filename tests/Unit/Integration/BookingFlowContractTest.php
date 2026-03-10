<?php

namespace anvildev\booked\tests\Unit\Integration;

use anvildev\booked\tests\Support\TestCase;

/**
 * Booking Flow Contract Test
 *
 * Smoke tests that verify the booking flow components exist and are properly connected.
 * These tests validate the contract between services without requiring database operations.
 */
class BookingFlowContractTest extends TestCase
{
    // =========================================================================
    // Core Service Existence Tests
    // =========================================================================

    public function testBookingServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\services\BookingService::class),
            'BookingService class should exist'
        );
    }

    public function testAvailabilityServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\services\AvailabilityService::class),
            'AvailabilityService class should exist'
        );
    }

    public function testBookingValidationServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\services\BookingValidationService::class),
            'BookingValidationService class should exist'
        );
    }

    public function testBookingSecurityServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\services\BookingSecurityService::class),
            'BookingSecurityService class should exist'
        );
    }

    public function testBookingNotificationServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\services\BookingNotificationService::class),
            'BookingNotificationService class should exist'
        );
    }

    public function testSoftLockServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\services\SoftLockService::class),
            'SoftLockService class should exist'
        );
    }

    public function testCapacityServiceExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\services\CapacityService::class),
            'CapacityService class should exist'
        );
    }

    // =========================================================================
    // Element Type File Existence Tests
    // Note: Using file_exists() because class_exists() triggers autoloading
    // which fails when Commerce interfaces aren't available
    // =========================================================================

    public function testReservationElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/Reservation.php',
            'Reservation element file should exist'
        );
    }

    public function testServiceElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/Service.php',
            'Service element file should exist'
        );
    }

    public function testEmployeeElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/Employee.php',
            'Employee element file should exist'
        );
    }

    public function testLocationElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/Location.php',
            'Location element file should exist'
        );
    }

    public function testScheduleElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/Schedule.php',
            'Schedule element file should exist'
        );
    }

    public function testEventDateElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/EventDate.php',
            'EventDate element file should exist'
        );
    }

    public function testServiceExtraElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/ServiceExtra.php',
            'ServiceExtra element file should exist'
        );
    }

    public function testBlackoutDateElementFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/elements/BlackoutDate.php',
            'BlackoutDate element file should exist'
        );
    }

    public function testWaitlistRecordFileExists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 3) . '/src/records/WaitlistRecord.php',
            'WaitlistRecord file should exist'
        );
    }

    // =========================================================================
    // Record Type Existence Tests
    // =========================================================================

    public function testReservationRecordExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\records\ReservationRecord::class),
            'ReservationRecord class should exist'
        );
    }

    public function testSoftLockRecordExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\records\SoftLockRecord::class),
            'SoftLockRecord class should exist'
        );
    }

    public function testWebhookRecordExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\records\WebhookRecord::class),
            'WebhookRecord class should exist'
        );
    }

    // =========================================================================
    // Queue Job Existence Tests
    // =========================================================================

    public function testSendBookingEmailJobExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\queue\jobs\SendBookingEmailJob::class),
            'SendBookingEmailJob class should exist'
        );
    }

    public function testSendSmsJobExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\queue\jobs\SendSmsJob::class),
            'SendSmsJob class should exist'
        );
    }

    public function testSendWebhookJobExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\queue\jobs\SendWebhookJob::class),
            'SendWebhookJob class should exist'
        );
    }

    public function testSendRemindersJobExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\queue\jobs\SendRemindersJob::class),
            'SendRemindersJob class should exist'
        );
    }

    // =========================================================================
    // Exception Type Existence Tests
    // =========================================================================

    public function testBookingExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\exceptions\BookingException::class),
            'BookingException class should exist'
        );
    }

    public function testBookingConflictExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\exceptions\BookingConflictException::class),
            'BookingConflictException class should exist'
        );
    }

    public function testBookingNotFoundExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\exceptions\BookingNotFoundException::class),
            'BookingNotFoundException class should exist'
        );
    }

    public function testBookingRateLimitExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\exceptions\BookingRateLimitException::class),
            'BookingRateLimitException class should exist'
        );
    }

    public function testBookingValidationExceptionExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\exceptions\BookingValidationException::class),
            'BookingValidationException class should exist'
        );
    }

    // =========================================================================
    // Controller Existence Tests
    // =========================================================================

    public function testBookingControllerExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\controllers\BookingController::class),
            'BookingController class should exist'
        );
    }

    public function testAccountControllerExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\controllers\AccountController::class),
            'AccountController class should exist'
        );
    }

    // =========================================================================
    // ReservationRecord Status Constants Tests
    // =========================================================================

    public function testReservationStatusPendingConstant(): void
    {
        $this->assertEquals(
            'pending',
            \anvildev\booked\records\ReservationRecord::STATUS_PENDING
        );
    }

    public function testReservationStatusConfirmedConstant(): void
    {
        $this->assertEquals(
            'confirmed',
            \anvildev\booked\records\ReservationRecord::STATUS_CONFIRMED
        );
    }

    public function testReservationStatusCancelledConstant(): void
    {
        $this->assertEquals(
            'cancelled',
            \anvildev\booked\records\ReservationRecord::STATUS_CANCELLED
        );
    }

    // =========================================================================
    // WebhookService Event Constants Tests
    // =========================================================================

    public function testWebhookEventBookingCreatedConstant(): void
    {
        $this->assertEquals(
            'booking.created',
            \anvildev\booked\services\WebhookService::EVENT_BOOKING_CREATED
        );
    }

    public function testWebhookEventBookingCancelledConstant(): void
    {
        $this->assertEquals(
            'booking.cancelled',
            \anvildev\booked\services\WebhookService::EVENT_BOOKING_CANCELLED
        );
    }

    public function testWebhookEventBookingUpdatedConstant(): void
    {
        $this->assertEquals(
            'booking.updated',
            \anvildev\booked\services\WebhookService::EVENT_BOOKING_UPDATED
        );
    }

    // =========================================================================
    // BookingSecurityService Constants Tests
    // =========================================================================

    public function testSecurityResultValidConstant(): void
    {
        $this->assertEquals(
            'valid',
            \anvildev\booked\services\BookingSecurityService::RESULT_VALID
        );
    }

    public function testSecurityResultIpBlockedConstant(): void
    {
        $this->assertEquals(
            'ip_blocked',
            \anvildev\booked\services\BookingSecurityService::RESULT_IP_BLOCKED
        );
    }

    public function testSecurityResultRateLimitedConstant(): void
    {
        $this->assertEquals(
            'rate_limited',
            \anvildev\booked\services\BookingSecurityService::RESULT_RATE_LIMITED
        );
    }

    // =========================================================================
    // Helper Class Existence Tests
    // =========================================================================

    public function testDateHelperExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\helpers\DateHelper::class),
            'DateHelper class should exist'
        );
    }

    public function testValidationHelperExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\helpers\ValidationHelper::class),
            'ValidationHelper class should exist'
        );
    }

    public function testIcsHelperExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\helpers\IcsHelper::class),
            'IcsHelper class should exist'
        );
    }

    // =========================================================================
    // Model Class Existence Tests
    // =========================================================================

    public function testSettingsModelExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\models\Settings::class),
            'Settings model class should exist'
        );
    }

    public function testTimeSlotModelExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\models\TimeSlot::class),
            'TimeSlot model class should exist'
        );
    }

    public function testSoftLockModelExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\models\SoftLock::class),
            'SoftLock model class should exist'
        );
    }

    // =========================================================================
    // Plugin Class Existence Tests
    // =========================================================================

    public function testPluginClassExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\Booked::class),
            'Booked plugin class should exist'
        );
    }

    // =========================================================================
    // Service Method Existence Tests (Contract Verification)
    // =========================================================================

    public function testBookingServiceHasCreateReservationMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\booked\services\BookingService::class, 'createReservation'),
            'BookingService should have createReservation() method'
        );
    }

    public function testBookingServiceHasCancelReservationMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\booked\services\BookingService::class, 'cancelReservation'),
            'BookingService should have cancelReservation() method'
        );
    }

    public function testAvailabilityServiceHasGetAvailableSlotsMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\booked\services\AvailabilityService::class, 'getAvailableSlots'),
            'AvailabilityService should have getAvailableSlots() method'
        );
    }

    public function testSoftLockServiceHasCreateLockMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\booked\services\SoftLockService::class, 'createLock'),
            'SoftLockService should have createLock() method'
        );
    }

    public function testSoftLockServiceHasIsLockedMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\booked\services\SoftLockService::class, 'isLocked'),
            'SoftLockService should have isLocked() method'
        );
    }

    public function testSoftLockServiceHasReleaseLockMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\booked\services\SoftLockService::class, 'releaseLock'),
            'SoftLockService should have releaseLock() method'
        );
    }

    public function testCapacityServiceHasHasAvailableCapacityMethod(): void
    {
        $this->assertTrue(
            method_exists(\anvildev\booked\services\CapacityService::class, 'hasAvailableCapacity'),
            'CapacityService should have hasAvailableCapacity() method'
        );
    }
}
