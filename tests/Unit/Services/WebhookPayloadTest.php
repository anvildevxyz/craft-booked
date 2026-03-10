<?php

declare(strict_types=1);

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\WebhookService;
use PHPUnit\Framework\TestCase;

/**
 * Source-level tests for webhook payload structure.
 *
 * Verifies standard and flat payload formats contain expected fields,
 * confirmation token truncation for security, and event type constants.
 */
class WebhookPayloadTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/WebhookService.php'
        );
    }

    // =========================================================================
    // Standard payload structure
    // =========================================================================

    public function testStandardPayloadContainsBookingNode(): void
    {
        $this->assertStringContainsString("'booking' =>", $this->source);
    }

    public function testStandardPayloadContainsCustomerNode(): void
    {
        $this->assertStringContainsString("'customer' =>", $this->source);
    }

    public function testStandardPayloadContainsServiceNode(): void
    {
        $this->assertStringContainsString("'service' => \$service ?", $this->source);
    }

    public function testStandardPayloadContainsMetaNode(): void
    {
        $this->assertStringContainsString("'meta' =>", $this->source);
    }

    public function testStandardPayloadContainsTimestamp(): void
    {
        $this->assertStringContainsString("'timestamp' =>", $this->source);
    }

    // =========================================================================
    // Flat payload structure
    // =========================================================================

    public function testFlatPayloadUsesSnakeCaseKeys(): void
    {
        $flatKeys = ['booking_id', 'booking_status', 'booking_date', 'customer_name', 'customer_email'];
        foreach ($flatKeys as $key) {
            $this->assertStringContainsString("'{$key}'", $this->source,
                "Flat payload must contain '{$key}' key");
        }
    }

    public function testFlatPayloadIncludesServiceFields(): void
    {
        $this->assertStringContainsString("'service_id'", $this->source);
        $this->assertStringContainsString("'service_title'", $this->source);
    }

    public function testFlatPayloadIncludesLocationTimezone(): void
    {
        $this->assertStringContainsString("'location_timezone'", $this->source);
    }

    // =========================================================================
    // Security: confirmation token truncation
    // =========================================================================

    public function testConfirmationTokenIsTruncatedInStandardPayload(): void
    {
        // Standard payload should truncate confirmationToken to 8 chars
        $this->assertStringContainsString(
            "substr(\$reservation->confirmationToken, 0, 8)",
            $this->source,
            'Standard payload must truncate confirmationToken to 8 chars for security'
        );
    }

    public function testConfirmationTokenIsTruncatedInFlatPayload(): void
    {
        // Flat payload should also truncate
        preg_match_all('/substr\(\$reservation->confirmationToken, 0, 8\)/', $this->source, $matches);
        $this->assertGreaterThanOrEqual(2, count($matches[0]),
            'Both standard and flat payloads must truncate confirmationToken');
    }

    // =========================================================================
    // Format dispatch
    // =========================================================================

    public function testBuildPayloadDispatchesByFormat(): void
    {
        $this->assertStringContainsString(
            "\$format === 'flat'",
            $this->source,
            'buildPayload must dispatch between standard and flat formats'
        );
    }

    public function testBuildPayloadDefaultsToStandard(): void
    {
        // The default parameter should be 'standard'
        $this->assertMatchesRegularExpression(
            '/function buildPayload\([^)]*string \$format = \'standard\'/',
            $this->source,
            'buildPayload format parameter must default to "standard"'
        );
    }

    // =========================================================================
    // Event constants
    // =========================================================================

    public function testEventConstantsAreDefined(): void
    {
        $this->assertEquals('booking.created', WebhookService::EVENT_BOOKING_CREATED);
        $this->assertEquals('booking.cancelled', WebhookService::EVENT_BOOKING_CANCELLED);
        $this->assertEquals('booking.updated', WebhookService::EVENT_BOOKING_UPDATED);
    }

    public function testAllWebhookEventConstantsFollowDotNotation(): void
    {
        $ref = new \ReflectionClass(WebhookService::class);
        $constants = $ref->getConstants();

        foreach ($constants as $name => $value) {
            // Only check webhook event type constants (EVENT_BOOKING_*), not Yii events (EVENT_BEFORE_*)
            if (str_starts_with($name, 'EVENT_BOOKING_')) {
                $this->assertMatchesRegularExpression(
                    '/^[a-z]+(\.[a-z_]+)+$/',
                    $value,
                    "Webhook event constant {$name} must use dot notation (e.g., 'booking.created')"
                );
            }
        }
    }

    // =========================================================================
    // HMAC signature uses hash_equals (timing-safe)
    // =========================================================================

    public function testVerifySignatureUsesTimingSafeComparison(): void
    {
        $this->assertStringContainsString(
            'hash_equals',
            $this->source,
            'verifySignature must use hash_equals for timing-safe comparison'
        );
    }
}
