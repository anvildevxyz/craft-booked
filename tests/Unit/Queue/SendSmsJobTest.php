<?php

namespace anvildev\booked\tests\Unit\Queue;

use anvildev\booked\queue\jobs\SendSmsJob;
use anvildev\booked\tests\Support\TestCase;

/**
 * SendSmsJob Test
 *
 * Tests the configuration and behavior of the SMS queue job
 */
class SendSmsJobTest extends TestCase
{
    // =========================================================================
    // Job Configuration Tests
    // =========================================================================

    public function testJobHasToProperty(): void
    {
        $job = new SendSmsJob();

        $this->assertTrue(property_exists($job, 'to'));
    }

    public function testJobHasBodyProperty(): void
    {
        $job = new SendSmsJob();

        $this->assertTrue(property_exists($job, 'body'));
    }

    public function testJobHasReservationIdProperty(): void
    {
        $job = new SendSmsJob();

        $this->assertTrue(property_exists($job, 'reservationId'));
    }

    public function testJobHasMessageTypeProperty(): void
    {
        $job = new SendSmsJob();

        $this->assertTrue(property_exists($job, 'messageType'));
    }

    public function testJobHasAttemptProperty(): void
    {
        $job = new SendSmsJob();

        $this->assertTrue(property_exists($job, 'attempt'));
    }

    // =========================================================================
    // Default Value Tests
    // =========================================================================

    public function testJobReservationIdDefaultsToNull(): void
    {
        $job = new SendSmsJob();

        $this->assertNull($job->reservationId);
    }

    public function testJobMessageTypeDefaultsToGeneral(): void
    {
        $job = new SendSmsJob();

        $this->assertEquals('general', $job->messageType);
    }

    public function testJobAttemptDefaultsToOne(): void
    {
        $job = new SendSmsJob();

        $this->assertEquals(1, $job->attempt);
    }

    // =========================================================================
    // Configuration Assignment Tests
    // =========================================================================

    public function testJobAcceptsToPhoneNumber(): void
    {
        $job = new SendSmsJob();
        $job->to = '+12025551234';

        $this->assertEquals('+12025551234', $job->to);
    }

    public function testJobAcceptsBody(): void
    {
        $job = new SendSmsJob();
        $job->body = 'Your booking is confirmed!';

        $this->assertEquals('Your booking is confirmed!', $job->body);
    }

    public function testJobAcceptsReservationId(): void
    {
        $job = new SendSmsJob();
        $job->reservationId = 789;

        $this->assertEquals(789, $job->reservationId);
    }

    // =========================================================================
    // Message Type Tests
    // =========================================================================

    public function testJobAcceptsMessageTypeConfirmation(): void
    {
        $job = new SendSmsJob();
        $job->messageType = 'confirmation';

        $this->assertEquals('confirmation', $job->messageType);
    }

    public function testJobAcceptsMessageTypeReminder24h(): void
    {
        $job = new SendSmsJob();
        $job->messageType = 'reminder_24h';

        $this->assertEquals('reminder_24h', $job->messageType);
    }

    public function testJobAcceptsMessageTypeCancellation(): void
    {
        $job = new SendSmsJob();
        $job->messageType = 'cancellation';

        $this->assertEquals('cancellation', $job->messageType);
    }

    public function testAllSupportedMessageTypes(): void
    {
        $supportedTypes = [
            'general',
            'confirmation',
            'reminder_24h',
            'cancellation',
        ];

        foreach ($supportedTypes as $type) {
            $job = new SendSmsJob();
            $job->messageType = $type;

            $this->assertEquals($type, $job->messageType, "Message type '{$type}' should be assignable");
        }
    }

    // =========================================================================
    // TTR (Time To Reserve) Tests
    // =========================================================================

    public function testGetTtrReturns30Seconds(): void
    {
        $job = new SendSmsJob();

        $this->assertEquals(30, $job->getTtr());
    }

    public function testTtrIsShorterThanEmailJob(): void
    {
        $smsJob = new SendSmsJob();
        $emailJob = new \anvildev\booked\queue\jobs\SendBookingEmailJob();

        $this->assertLessThan($emailJob->getTtr(), $smsJob->getTtr());
    }

    // =========================================================================
    // Constructor Configuration Tests
    // =========================================================================

    public function testJobCanBeConfiguredViaConstructor(): void
    {
        $job = new SendSmsJob([
            'to' => '+41791234567',
            'body' => 'Test SMS message',
            'reservationId' => 999,
            'messageType' => 'confirmation',
            'attempt' => 2,
        ]);

        $this->assertEquals('+41791234567', $job->to);
        $this->assertEquals('Test SMS message', $job->body);
        $this->assertEquals(999, $job->reservationId);
        $this->assertEquals('confirmation', $job->messageType);
        $this->assertEquals(2, $job->attempt);
    }

    // =========================================================================
    // Phone Number Format Tests
    // =========================================================================

    public function testJobAcceptsE164PhoneNumber(): void
    {
        $job = new SendSmsJob();
        $job->to = '+12025551234';

        $this->assertStringStartsWith('+', $job->to);
    }

    public function testJobAcceptsInternationalPhoneNumbers(): void
    {
        $phoneNumbers = [
            '+12025551234',   // US
            '+447911123456',  // UK
            '+41791234567',   // Switzerland
            '+4915112345678', // Germany
        ];

        foreach ($phoneNumbers as $phone) {
            $job = new SendSmsJob();
            $job->to = $phone;

            $this->assertEquals($phone, $job->to);
        }
    }

    // =========================================================================
    // Body Content Tests
    // =========================================================================

    public function testJobAcceptsEmptyBody(): void
    {
        $job = new SendSmsJob();
        $job->body = '';

        $this->assertEquals('', $job->body);
    }

    public function testJobAcceptsLongBody(): void
    {
        $longMessage = str_repeat('Test ', 200); // ~1000 characters
        $job = new SendSmsJob();
        $job->body = $longMessage;

        $this->assertEquals($longMessage, $job->body);
    }

    public function testJobAcceptsUnicodeInBody(): void
    {
        $job = new SendSmsJob();
        $job->body = 'Bestätigung für Müller 日本語 🎉';

        $this->assertEquals('Bestätigung für Müller 日本語 🎉', $job->body);
    }

    public function testJobBodyDefaultsToEmptyString(): void
    {
        $job = new SendSmsJob(['to' => '+1234567890', 'reservationId' => 1, 'messageType' => 'confirmation']);

        $this->assertSame('', $job->body);
    }
}
