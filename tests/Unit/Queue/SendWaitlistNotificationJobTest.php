<?php

namespace anvildev\booked\tests\Unit\Queue;

use anvildev\booked\queue\jobs\SendWaitlistNotificationJob;
use anvildev\booked\tests\Support\TestCase;

/**
 * SendWaitlistNotificationJob Test
 *
 * Tests the configuration and idempotency guards of the waitlist notification job
 */
class SendWaitlistNotificationJobTest extends TestCase
{
    // =========================================================================
    // Job Configuration Tests
    // =========================================================================

    public function testJobHasWaitlistIdProperty(): void
    {
        $job = new SendWaitlistNotificationJob();

        $this->assertTrue(property_exists($job, 'waitlistId'));
    }

    public function testJobHasDateProperty(): void
    {
        $job = new SendWaitlistNotificationJob();

        $this->assertTrue(property_exists($job, 'date'));
    }

    public function testJobHasStartTimeProperty(): void
    {
        $job = new SendWaitlistNotificationJob();

        $this->assertTrue(property_exists($job, 'startTime'));
    }

    public function testJobHasEndTimeProperty(): void
    {
        $job = new SendWaitlistNotificationJob();

        $this->assertTrue(property_exists($job, 'endTime'));
    }

    public function testJobHasAttemptProperty(): void
    {
        $job = new SendWaitlistNotificationJob();

        $this->assertTrue(property_exists($job, 'attempt'));
    }

    public function testJobDefaultAttemptIsOne(): void
    {
        $job = new SendWaitlistNotificationJob();

        $this->assertEquals(1, $job->attempt);
    }

    public function testJobDateDefaultsToNull(): void
    {
        $job = new SendWaitlistNotificationJob();

        $this->assertNull($job->date);
    }

    // =========================================================================
    // TTR and Retry Tests
    // =========================================================================

    public function testGetTtrReturns120Seconds(): void
    {
        $job = new SendWaitlistNotificationJob();

        $this->assertEquals(120, $job->getTtr());
    }

    public function testCanRetryOnFirstAttempt(): void
    {
        $job = new SendWaitlistNotificationJob();

        $this->assertTrue($job->canRetry(1, new \Exception('Test')));
    }

    public function testCannotRetryOnThirdAttempt(): void
    {
        $job = new SendWaitlistNotificationJob();

        $this->assertFalse($job->canRetry(3, new \Exception('Test')));
    }

    // =========================================================================
    // Idempotency Guard (Source Verification)
    // =========================================================================

    public function testExecuteChecksTerminalStatusGuard(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/queue/jobs/SendWaitlistNotificationJob.php');
        $executeStart = strpos($source, 'function execute');
        $guardPos = strpos($source, 'STATUS_CONVERTED', $executeStart);
        $sendPos = strpos($source, 'mailer->send', $executeStart);
        $this->assertNotFalse($guardPos, 'Must check terminal statuses before sending');
        $this->assertNotFalse($sendPos, 'Must have mailer->send');
        $this->assertLessThan($sendPos, $guardPos,
            'Terminal status check must come before mailer->send');
    }

    // =========================================================================
    // Constructor Configuration Tests
    // =========================================================================

    public function testJobCanBeConfiguredViaConstructor(): void
    {
        $job = new SendWaitlistNotificationJob([
            'waitlistId' => 42,
            'date' => '2025-06-15',
            'startTime' => '10:00',
            'endTime' => '11:00',
            'attempt' => 2,
        ]);

        $this->assertEquals(42, $job->waitlistId);
        $this->assertEquals('2025-06-15', $job->date);
        $this->assertEquals('10:00', $job->startTime);
        $this->assertEquals('11:00', $job->endTime);
        $this->assertEquals(2, $job->attempt);
    }
}
