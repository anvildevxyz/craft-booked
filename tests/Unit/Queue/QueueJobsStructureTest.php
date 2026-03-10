<?php

namespace anvildev\booked\tests\Unit\Queue;

use anvildev\booked\queue\jobs\SendSmsJob;
use anvildev\booked\queue\jobs\SendWebhookJob;
use anvildev\booked\queue\jobs\SyncToCalendarJob;
use anvildev\booked\tests\Support\TestCase;

/**
 * Queue Jobs Structure Test
 *
 * All execute() methods require Booked::getInstance().
 * Tests verify class structure and properties.
 */
class QueueJobsStructureTest extends TestCase
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
    // SendWebhookJob
    // =========================================================================

    public function testSendWebhookJobExtendsBaseJob(): void
    {
        $this->assertTrue(is_subclass_of(SendWebhookJob::class, \craft\queue\BaseJob::class));
    }

    public function testSendWebhookJobHasRequiredProperties(): void
    {
        $ref = new \ReflectionClass(SendWebhookJob::class);
        $this->assertTrue($ref->hasProperty('webhookId'));
        $this->assertTrue($ref->hasProperty('event'));
        $this->assertTrue($ref->hasProperty('payload'));
        $this->assertTrue($ref->hasProperty('reservationId'));
    }

    public function testSendWebhookJobGetTtrReturns120(): void
    {
        $job = new SendWebhookJob([
            'webhookId' => 1,
            'event' => 'booking.created',
            'payload' => [],
        ]);
        $this->assertEquals(120, $job->getTtr());
    }

    // =========================================================================
    // SendSmsJob
    // =========================================================================

    public function testSendSmsJobExtendsBaseJob(): void
    {
        $this->assertTrue(is_subclass_of(SendSmsJob::class, \craft\queue\BaseJob::class));
    }

    public function testSendSmsJobHasRequiredProperties(): void
    {
        $ref = new \ReflectionClass(SendSmsJob::class);
        $this->assertTrue($ref->hasProperty('to'));
        $this->assertTrue($ref->hasProperty('body'));
        $this->assertTrue($ref->hasProperty('reservationId'));
        $this->assertTrue($ref->hasProperty('messageType'));
        $this->assertTrue($ref->hasProperty('attempt'));
    }

    public function testSendSmsJobGetTtrReturns30(): void
    {
        $job = new SendSmsJob([
            'to' => '+41791234567',
            'body' => 'Test',
        ]);
        $this->assertEquals(30, $job->getTtr());
    }

    public function testSendSmsJobDefaultMessageType(): void
    {
        $job = new SendSmsJob([
            'to' => '+41791234567',
            'body' => 'Test',
        ]);
        $this->assertEquals('general', $job->messageType);
    }

    // =========================================================================
    // SyncToCalendarJob
    // =========================================================================

    public function testSyncToCalendarJobExtendsBaseJob(): void
    {
        $this->assertTrue(is_subclass_of(SyncToCalendarJob::class, \craft\queue\BaseJob::class));
    }

    public function testSyncToCalendarJobHasReservationId(): void
    {
        $ref = new \ReflectionClass(SyncToCalendarJob::class);
        $this->assertTrue($ref->hasProperty('reservationId'));
    }

    public function testSyncToCalendarJobDoesNotRetryOnAuthErrors(): void
    {
        $job = new SyncToCalendarJob(['reservationId' => 1]);

        $this->assertFalse(
            $job->canRetry(1, new \Exception('Request failed with status 401 Unauthorized')),
            'Should not retry on 401 errors'
        );
        $this->assertFalse(
            $job->canRetry(1, new \Exception('Request failed with status 403 Forbidden')),
            'Should not retry on 403 errors'
        );
    }

    public function testSyncToCalendarJobRetriesOnTransientErrors(): void
    {
        $job = new SyncToCalendarJob(['reservationId' => 1]);

        $this->assertTrue(
            $job->canRetry(1, new \Exception('Connection timeout')),
            'Should retry on transient errors'
        );
        $this->assertTrue(
            $job->canRetry(2, new \Exception('Server error 500')),
            'Should retry on second attempt for server errors'
        );
    }

    public function testSyncToCalendarJobStopsRetryingAfterThreeAttempts(): void
    {
        $job = new SyncToCalendarJob(['reservationId' => 1]);

        $this->assertFalse(
            $job->canRetry(3, new \Exception('Connection timeout')),
            'Should stop retrying after 3 attempts'
        );
    }
}
