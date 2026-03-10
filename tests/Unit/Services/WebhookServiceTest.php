<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\WebhookService;
use anvildev\booked\tests\Support\TestCase;

/**
 * WebhookService Test
 *
 * Tests the pure utility functions in WebhookService
 */
class WebhookServiceTest extends TestCase
{
    private WebhookService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }

        $this->service = new WebhookService();
    }

    // =========================================================================
    // Constants Tests
    // =========================================================================

    public function testEventConstants(): void
    {
        $this->assertEquals('booking.created', WebhookService::EVENT_BOOKING_CREATED);
        $this->assertEquals('booking.cancelled', WebhookService::EVENT_BOOKING_CANCELLED);
        $this->assertEquals('booking.updated', WebhookService::EVENT_BOOKING_UPDATED);
    }

    // =========================================================================
    // generateSignature() Tests
    // =========================================================================

    public function testGenerateSignatureReturnsString(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'test-secret-key';
        $timestamp = 1704067200; // 2024-01-01 00:00:00

        $signature = $this->service->generateSignature($payload, $secret, $timestamp);

        $this->assertIsString($signature);
        $this->assertStringStartsWith('sha256=', $signature);
    }

    public function testGenerateSignatureIsConsistent(): void
    {
        $payload = '{"event":"booking.created"}';
        $secret = 'my-webhook-secret';
        $timestamp = 1704067200;

        $signature1 = $this->service->generateSignature($payload, $secret, $timestamp);
        $signature2 = $this->service->generateSignature($payload, $secret, $timestamp);

        $this->assertEquals($signature1, $signature2);
    }

    public function testGenerateSignatureDifferentPayloadsProduceDifferentSignatures(): void
    {
        $secret = 'test-secret';
        $timestamp = 1704067200;

        $sig1 = $this->service->generateSignature('{"event":"created"}', $secret, $timestamp);
        $sig2 = $this->service->generateSignature('{"event":"cancelled"}', $secret, $timestamp);

        $this->assertNotEquals($sig1, $sig2);
    }

    public function testGenerateSignatureDifferentSecretsProduceDifferentSignatures(): void
    {
        $payload = '{"event":"test"}';
        $timestamp = 1704067200;

        $sig1 = $this->service->generateSignature($payload, 'secret-1', $timestamp);
        $sig2 = $this->service->generateSignature($payload, 'secret-2', $timestamp);

        $this->assertNotEquals($sig1, $sig2);
    }

    public function testGenerateSignatureDifferentTimestampsProduceDifferentSignatures(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'test-secret';

        $sig1 = $this->service->generateSignature($payload, $secret, 1704067200);
        $sig2 = $this->service->generateSignature($payload, $secret, 1704067201);

        $this->assertNotEquals($sig1, $sig2);
    }

    public function testGenerateSignatureUsesHmacSha256(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'test-secret';
        $timestamp = 1704067200;

        $signaturePayload = $timestamp . '.' . $payload;
        $expectedHash = 'sha256=' . hash_hmac('sha256', $signaturePayload, $secret);

        $signature = $this->service->generateSignature($payload, $secret, $timestamp);

        $this->assertEquals($expectedHash, $signature);
    }

    public function testGenerateSignatureWithEmptyPayload(): void
    {
        $signature = $this->service->generateSignature('', 'secret', 1704067200);

        $this->assertIsString($signature);
        $this->assertStringStartsWith('sha256=', $signature);
    }

    public function testGenerateSignatureWithLongPayload(): void
    {
        $payload = json_encode([
            'event' => 'booking.created',
            'data' => [
                'booking' => [
                    'id' => 12345,
                    'customerName' => 'A very long customer name that might cause issues',
                    'notes' => str_repeat('x', 10000),
                ],
            ],
        ]);
        $secret = 'test-secret';
        $timestamp = 1704067200;

        $signature = $this->service->generateSignature($payload, $secret, $timestamp);

        $this->assertIsString($signature);
        $this->assertStringStartsWith('sha256=', $signature);
        // SHA256 hash is always 64 hex characters
        $this->assertEquals(71, strlen($signature)); // "sha256=" + 64 chars
    }

    // =========================================================================
    // verifySignature() Tests
    // =========================================================================

    public function testVerifySignatureValidSignature(): void
    {
        $payload = '{"event":"booking.created"}';
        $secret = 'webhook-secret-key';
        $timestamp = time(); // Current time

        $signature = $this->service->generateSignature($payload, $secret, $timestamp);
        $isValid = $this->service->verifySignature($payload, $signature, $secret, $timestamp);

        $this->assertTrue($isValid);
    }

    public function testVerifySignatureInvalidSignature(): void
    {
        $payload = '{"event":"booking.created"}';
        $secret = 'webhook-secret-key';
        $timestamp = time();

        $isValid = $this->service->verifySignature(
            $payload,
            'sha256=invalid_signature_here',
            $secret,
            $timestamp
        );

        $this->assertFalse($isValid);
    }

    public function testVerifySignatureWrongSecret(): void
    {
        $payload = '{"event":"booking.created"}';
        $correctSecret = 'correct-secret';
        $wrongSecret = 'wrong-secret';
        $timestamp = time();

        $signature = $this->service->generateSignature($payload, $correctSecret, $timestamp);
        $isValid = $this->service->verifySignature($payload, $signature, $wrongSecret, $timestamp);

        $this->assertFalse($isValid);
    }

    public function testVerifySignatureModifiedPayload(): void
    {
        $originalPayload = '{"event":"booking.created"}';
        $modifiedPayload = '{"event":"booking.cancelled"}';
        $secret = 'webhook-secret-key';
        $timestamp = time();

        $signature = $this->service->generateSignature($originalPayload, $secret, $timestamp);
        $isValid = $this->service->verifySignature($modifiedPayload, $signature, $secret, $timestamp);

        $this->assertFalse($isValid);
    }

    public function testVerifySignatureExpiredTimestamp(): void
    {
        $payload = '{"event":"booking.created"}';
        $secret = 'webhook-secret-key';
        $oldTimestamp = time() - 400; // 6+ minutes ago (exceeds 5 minute window)

        $signature = $this->service->generateSignature($payload, $secret, $oldTimestamp);
        $isValid = $this->service->verifySignature($payload, $signature, $secret, $oldTimestamp);

        $this->assertFalse($isValid);
    }

    public function testVerifySignatureWithinTimeWindow(): void
    {
        $payload = '{"event":"booking.created"}';
        $secret = 'webhook-secret-key';
        $recentTimestamp = time() - 60; // 1 minute ago (within 5 minute window)

        $signature = $this->service->generateSignature($payload, $secret, $recentTimestamp);
        $isValid = $this->service->verifySignature($payload, $signature, $secret, $recentTimestamp);

        $this->assertTrue($isValid);
    }

    public function testVerifySignatureFutureTimestamp(): void
    {
        $payload = '{"event":"booking.created"}';
        $secret = 'webhook-secret-key';
        $futureTimestamp = time() + 400; // 6+ minutes in future

        $signature = $this->service->generateSignature($payload, $secret, $futureTimestamp);
        $isValid = $this->service->verifySignature($payload, $signature, $secret, $futureTimestamp);

        $this->assertFalse($isValid);
    }

    public function testVerifySignatureNearFutureTimestamp(): void
    {
        $payload = '{"event":"booking.created"}';
        $secret = 'webhook-secret-key';
        $nearFutureTimestamp = time() + 60; // 1 minute in future (within 5 minute window)

        $signature = $this->service->generateSignature($payload, $secret, $nearFutureTimestamp);
        $isValid = $this->service->verifySignature($payload, $signature, $secret, $nearFutureTimestamp);

        $this->assertTrue($isValid);
    }

    public function testVerifySignatureBoundaryExact5Minutes(): void
    {
        $payload = '{"event":"booking.created"}';
        $secret = 'webhook-secret-key';
        $boundaryTimestamp = time() - 300; // Exactly 5 minutes ago

        $signature = $this->service->generateSignature($payload, $secret, $boundaryTimestamp);
        $isValid = $this->service->verifySignature($payload, $signature, $secret, $boundaryTimestamp);

        // Should be valid at exactly 5 minutes (abs(time() - timestamp) <= 300)
        $this->assertTrue($isValid);
    }

    public function testVerifySignatureBoundaryJustOver5Minutes(): void
    {
        $payload = '{"event":"booking.created"}';
        $secret = 'webhook-secret-key';
        $overBoundaryTimestamp = time() - 301; // Just over 5 minutes ago

        $signature = $this->service->generateSignature($payload, $secret, $overBoundaryTimestamp);
        $isValid = $this->service->verifySignature($payload, $signature, $secret, $overBoundaryTimestamp);

        // Should be invalid at 5 minutes + 1 second
        $this->assertFalse($isValid);
    }

    // =========================================================================
    // Signature Security Tests
    // =========================================================================

    public function testSignatureUsesTimingAttackResistantComparison(): void
    {
        // This test verifies that hash_equals is used (timing-safe comparison)
        // We can't directly test timing, but we can verify consistent behavior
        $payload = '{"event":"test"}';
        $secret = 'test-secret';
        $timestamp = time();

        $validSignature = $this->service->generateSignature($payload, $secret, $timestamp);

        // Test with signatures that differ at different positions
        $invalid1 = 'sha256=x' . substr($validSignature, 8); // Different at start
        $invalid2 = substr($validSignature, 0, -1) . 'x';    // Different at end

        $result1 = $this->service->verifySignature($payload, $invalid1, $secret, $timestamp);
        $result2 = $this->service->verifySignature($payload, $invalid2, $secret, $timestamp);

        $this->assertFalse($result1);
        $this->assertFalse($result2);
    }

    public function testGenerateSignatureWithSpecialCharactersInPayload(): void
    {
        $payload = '{"name":"Test äöü 日本語 emoji 🎉","notes":"Special chars: <>&\\"\'"}';
        $secret = 'test-secret';
        $timestamp = time(); // Use current time for verification to pass

        $signature = $this->service->generateSignature($payload, $secret, $timestamp);

        $this->assertIsString($signature);
        $this->assertStringStartsWith('sha256=', $signature);

        // Verify the signature is valid
        $isValid = $this->service->verifySignature($payload, $signature, $secret, $timestamp);
        $this->assertTrue($isValid);
    }

    public function testGenerateSignatureWithSpecialCharactersInSecret(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'secret-with-special-chars-äöü-日本語-🔑';
        $timestamp = time();

        $signature = $this->service->generateSignature($payload, $secret, $timestamp);

        $this->assertIsString($signature);
        $isValid = $this->service->verifySignature($payload, $signature, $secret, $timestamp);
        $this->assertTrue($isValid);
    }

    // =========================================================================
    // URL Validation Tests
    // =========================================================================

    public function testValidateWebhookUrlRejectsUnresolvableDomain(): void
    {
        $result = $this->service->validateWebhookUrl('https://this-domain-definitely-does-not-exist-xyz123.invalid/webhook');
        $this->assertIsArray($result);
        $this->assertFalse($result['valid']);
    }
}
