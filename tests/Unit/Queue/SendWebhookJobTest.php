<?php

namespace anvildev\booked\tests\Unit\Queue;

use anvildev\booked\queue\jobs\SendWebhookJob;
use anvildev\booked\tests\Support\TestCase;

/**
 * SendWebhookJob Test
 *
 * Tests the configuration and behavior of the webhook queue job
 */
class SendWebhookJobTest extends TestCase
{
    // =========================================================================
    // Job Configuration Tests
    // =========================================================================

    public function testJobHasWebhookIdProperty(): void
    {
        $job = new SendWebhookJob();

        $this->assertTrue(property_exists($job, 'webhookId'));
    }

    public function testJobHasEventProperty(): void
    {
        $job = new SendWebhookJob();

        $this->assertTrue(property_exists($job, 'event'));
    }

    public function testJobHasPayloadProperty(): void
    {
        $job = new SendWebhookJob();

        $this->assertTrue(property_exists($job, 'payload'));
    }

    public function testJobHasReservationIdProperty(): void
    {
        $job = new SendWebhookJob();

        $this->assertTrue(property_exists($job, 'reservationId'));
    }

    public function testJobHasSiteIdProperty(): void
    {
        $job = new SendWebhookJob();

        $this->assertTrue(property_exists($job, 'siteId'));
    }

    public function testJobHasAttemptProperty(): void
    {
        $job = new SendWebhookJob();

        $this->assertTrue(property_exists($job, 'attempt'));
        $this->assertSame(1, $job->attempt);
    }

    // =========================================================================
    // Default Value Tests
    // =========================================================================

    public function testJobReservationIdDefaultsToNull(): void
    {
        $job = new SendWebhookJob();

        $this->assertNull($job->reservationId);
    }

    public function testJobSiteIdDefaultsToNull(): void
    {
        $job = new SendWebhookJob();

        $this->assertNull($job->siteId);
    }

    public function testJobReliesOnFrameworkAttemptTracking(): void
    {
        $job = new SendWebhookJob();

        $this->assertTrue(method_exists($job, 'canRetry'), 'Job should use canRetry($attempt) for framework-based attempt tracking');
    }

    // =========================================================================
    // Configuration Assignment Tests
    // =========================================================================

    public function testJobAcceptsWebhookId(): void
    {
        $job = new SendWebhookJob();
        $job->webhookId = 42;

        $this->assertEquals(42, $job->webhookId);
    }

    public function testJobAcceptsEventBookingCreated(): void
    {
        $job = new SendWebhookJob();
        $job->event = 'booking.created';

        $this->assertEquals('booking.created', $job->event);
    }

    public function testJobAcceptsEventBookingUpdated(): void
    {
        $job = new SendWebhookJob();
        $job->event = 'booking.updated';

        $this->assertEquals('booking.updated', $job->event);
    }

    public function testJobAcceptsEventBookingCancelled(): void
    {
        $job = new SendWebhookJob();
        $job->event = 'booking.cancelled';

        $this->assertEquals('booking.cancelled', $job->event);
    }

    public function testJobAcceptsPayload(): void
    {
        $payload = [
            'reservationId' => 123,
            'service' => 'Haircut',
            'customer' => ['name' => 'John Doe', 'email' => 'john@example.com'],
        ];

        $job = new SendWebhookJob();
        $job->payload = $payload;

        $this->assertEquals($payload, $job->payload);
    }

    public function testJobAcceptsEmptyPayload(): void
    {
        $job = new SendWebhookJob();
        $job->payload = [];

        $this->assertEquals([], $job->payload);
    }

    public function testJobAcceptsNestedPayload(): void
    {
        $payload = [
            'event' => 'booking.created',
            'data' => [
                'reservation' => [
                    'id' => 456,
                    'service' => [
                        'id' => 1,
                        'name' => 'Massage',
                    ],
                    'extras' => [
                        ['id' => 1, 'name' => 'Hot Stones'],
                        ['id' => 2, 'name' => 'Aromatherapy'],
                    ],
                ],
            ],
        ];

        $job = new SendWebhookJob();
        $job->payload = $payload;

        $this->assertEquals(456, $job->payload['data']['reservation']['id']);
        $this->assertCount(2, $job->payload['data']['reservation']['extras']);
    }

    // =========================================================================
    // TTR (Time To Reserve) Tests
    // =========================================================================

    public function testGetTtrReturns120Seconds(): void
    {
        $job = new SendWebhookJob();

        $this->assertEquals(120, $job->getTtr());
    }

    public function testTtrIsLongerThanEmailAndSmsJobs(): void
    {
        $webhookJob = new SendWebhookJob();
        $emailJob = new \anvildev\booked\queue\jobs\SendBookingEmailJob();
        $smsJob = new \anvildev\booked\queue\jobs\SendSmsJob();

        $this->assertGreaterThan($emailJob->getTtr(), $webhookJob->getTtr());
        $this->assertGreaterThan($smsJob->getTtr(), $webhookJob->getTtr());
    }

    // =========================================================================
    // Constructor Configuration Tests
    // =========================================================================

    public function testJobCanBeConfiguredViaConstructor(): void
    {
        $job = new SendWebhookJob([
            'webhookId' => 99,
            'event' => 'booking.created',
            'payload' => ['test' => 'data'],
            'reservationId' => 555,
            'siteId' => 1,
        ]);

        $this->assertEquals(99, $job->webhookId);
        $this->assertEquals('booking.created', $job->event);
        $this->assertEquals(['test' => 'data'], $job->payload);
        $this->assertEquals(555, $job->reservationId);
        $this->assertEquals(1, $job->siteId);
    }

    // =========================================================================
    // Event Type Tests
    // =========================================================================

    public function testAllSupportedEventTypes(): void
    {
        $supportedEvents = [
            'booking.created',
            'booking.updated',
            'booking.cancelled',
        ];

        foreach ($supportedEvents as $event) {
            $job = new SendWebhookJob();
            $job->event = $event;

            $this->assertEquals($event, $job->event, "Event type '{$event}' should be assignable");
        }
    }

    // =========================================================================
    // Payload Structure Tests
    // =========================================================================

    public function testPayloadCanContainTimestamp(): void
    {
        $job = new SendWebhookJob();
        $job->payload = [
            'timestamp' => 1704067200,
            'event' => 'booking.created',
        ];

        $this->assertEquals(1704067200, $job->payload['timestamp']);
    }

    public function testPayloadCanContainSignature(): void
    {
        $job = new SendWebhookJob();
        $job->payload = [
            'signature' => 'sha256=abc123',
            'data' => ['id' => 1],
        ];

        $this->assertArrayHasKey('signature', $job->payload);
    }

    // =========================================================================
    // canRetry Purity (Source Verification)
    // =========================================================================

    public function testCanRetryDoesNotMutateState(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/queue/jobs/SendWebhookJob.php');
        $methodStart = strpos($source, 'function canRetry');
        $methodSource = substr($source, $methodStart, 300);
        $this->assertStringNotContainsString(
            '$this->attempt =',
            $methodSource,
            'canRetry must not mutate state'
        );
    }

    public function testExecuteDoesNotTrackAttemptManually(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/queue/jobs/SendWebhookJob.php');
        $this->assertStringNotContainsString(
            '$this->attempt++',
            $source,
            'execute() must not track attempts manually — the framework handles this via canRetry($attempt)'
        );
    }
}
