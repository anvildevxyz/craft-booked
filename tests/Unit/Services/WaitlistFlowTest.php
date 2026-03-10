<?php

declare(strict_types=1);

namespace anvildev\booked\tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Source-level tests for the WaitlistService flow.
 *
 * Verifies the complete waitlist lifecycle at the code level:
 * add → notify → convert (or expire/cancel).
 */
class WaitlistFlowTest extends TestCase
{
    private string $source;
    private string $wizardSource;
    private string $eventWizardSource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/WaitlistService.php'
        );
        $this->wizardSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/web/js/booking-wizard.js'
        );
        $this->eventWizardSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/web/js/event-wizard.js'
        );
    }

    // =========================================================================
    // Entry creation — validation
    // =========================================================================

    public function testAddToWaitlistValidatesEmail(): void
    {
        $this->assertStringContainsString(
            'FILTER_VALIDATE_EMAIL',
            $this->source,
            'addToWaitlist must validate email address'
        );
    }

    public function testAddToWaitlistChecksWaitlistEnabled(): void
    {
        $this->assertStringContainsString(
            'enableWaitlist',
            $this->source,
            'addToWaitlist must check if waitlist is enabled'
        );
    }

    public function testAddToWaitlistSetsExpiration(): void
    {
        $this->assertStringContainsString(
            'waitlistExpirationDays',
            $this->source,
            'addToWaitlist must respect expiration days setting'
        );
    }

    public function testAddToWaitlistSetsPriority(): void
    {
        $this->assertStringContainsString(
            '$record->priority = $this->calculatePriority($data)',
            $this->source,
            'addToWaitlist must set priority on record'
        );
    }

    // =========================================================================
    // Notification flow
    // =========================================================================

    public function testNotifyEntrySetsStatusToNotified(): void
    {
        $this->assertStringContainsString(
            "\$entry->status = WaitlistRecord::STATUS_NOTIFIED",
            $this->source,
            'notifyEntry must set status to NOTIFIED'
        );
    }

    public function testNotifyEntryQueuesJob(): void
    {
        $this->assertStringContainsString(
            'SendWaitlistNotificationJob',
            $this->source,
            'notifyEntry must queue a SendWaitlistNotificationJob'
        );
    }

    public function testNotifyEntryCreatesConversionToken(): void
    {
        $this->assertStringContainsString(
            '$this->createConversionToken($entry->id)',
            $this->source,
            'notifyEntry must create a conversion token before queuing notification'
        );
    }

    public function testNotifyEntryPassesConversionTokenToJob(): void
    {
        $this->assertStringContainsString(
            "'conversionToken' => \$conversionToken",
            $this->source,
            'notifyEntry must pass conversion token to the notification job'
        );
    }

    public function testCheckAndNotifyWaitlistOrdersByPriorityThenDate(): void
    {
        $this->assertStringContainsString(
            "['priority' => SORT_ASC, 'dateCreated' => SORT_ASC]",
            $this->source,
            'checkAndNotifyWaitlist must order by priority then creation date'
        );
    }

    public function testCheckAndNotifyWaitlistRespectsNotificationLimit(): void
    {
        $this->assertStringContainsString(
            'waitlistNotificationLimit',
            $this->source,
            'checkAndNotifyWaitlist must respect notification limit setting'
        );
    }

    public function testManualNotifyChecksCanBeNotified(): void
    {
        $this->assertStringContainsString(
            'canBeNotified()',
            $this->source,
            'manualNotify must check canBeNotified() before notifying'
        );
    }

    // =========================================================================
    // Conversion flow
    // =========================================================================

    public function testConversionTokenRequiresNotifiedStatus(): void
    {
        $this->assertStringContainsString(
            "status !== WaitlistRecord::STATUS_NOTIFIED",
            $this->source,
            'createConversionToken must require NOTIFIED status'
        );
    }

    public function testConversionTokenUsesSecureRandom(): void
    {
        $this->assertStringContainsString(
            'random_bytes(16)',
            $this->source,
            'Conversion tokens must be generated with random_bytes'
        );
    }

    public function testConversionTokenHasExpiry(): void
    {
        $this->assertStringContainsString(
            'conversionExpiresAt',
            $this->source,
            'Conversion tokens must have an expiration time'
        );
    }

    // =========================================================================
    // Cancellation and cleanup
    // =========================================================================

    public function testCancelEntrySetsStatusToCancelled(): void
    {
        $this->assertStringContainsString(
            "WaitlistRecord::STATUS_CANCELLED",
            $this->source,
            'cancelEntry must set status to CANCELLED'
        );
    }

    public function testCleanupExpiredMarksAndDeletes(): void
    {
        // Should first mark overdue active entries as expired
        $this->assertStringContainsString(
            "['status' => WaitlistRecord::STATUS_EXPIRED]",
            $this->source,
            'cleanupExpired must mark entries as expired'
        );

        // Then delete expired entries
        $this->assertStringContainsString(
            'deleteAll',
            $this->source,
            'cleanupExpired must delete expired entries'
        );
    }

    // =========================================================================
    // Event waitlist
    // =========================================================================

    public function testAddToEventWaitlistUsesEventDateId(): void
    {
        $this->assertStringContainsString(
            "'eventDateId' => (int)\$data['eventDateId']",
            $this->source,
            'addToEventWaitlist must set eventDateId from data'
        );
    }

    public function testEventWaitlistChecksRemainingCapacity(): void
    {
        $this->assertStringContainsString(
            'getRemainingCapacity()',
            $this->source,
            'checkAndNotifyEventWaitlist must check remaining capacity'
        );
    }

    // =========================================================================
    // Frontend CAPTCHA and honeypot integration
    // =========================================================================

    public function testBookingWizardWaitlistSendsCaptchaToken(): void
    {
        $this->assertStringContainsString(
            'captchaToken',
            $this->wizardSource,
            'booking-wizard.js joinWaitlist must send captchaToken'
        );
    }

    public function testBookingWizardWaitlistAppendsHoneypot(): void
    {
        // The joinWaitlist method should call appendHoneypotData
        $this->assertStringContainsString(
            'appendHoneypotData(data)',
            $this->wizardSource,
            'booking-wizard.js joinWaitlist must call appendHoneypotData'
        );
    }

    public function testEventWizardWaitlistSendsCaptchaToken(): void
    {
        $this->assertStringContainsString(
            'captchaToken',
            $this->eventWizardSource,
            'event-wizard.js joinEventWaitlist must send captchaToken'
        );
    }

    public function testBookingWizardHandlesWaitlistConversionToken(): void
    {
        $this->assertStringContainsString(
            "urlParams.has('waitlist')",
            $this->wizardSource,
            'booking-wizard.js must check for waitlist conversion token in URL'
        );
        $this->assertStringContainsString(
            'handleWaitlistConversion',
            $this->wizardSource,
            'booking-wizard.js must have handleWaitlistConversion method'
        );
    }

    public function testEventWizardHandlesWaitlistConversionToken(): void
    {
        $this->assertStringContainsString(
            "urlParams.get('waitlist')",
            $this->eventWizardSource,
            'event-wizard.js must check for waitlist conversion token in URL'
        );
        $this->assertStringContainsString(
            'handleWaitlistConversion',
            $this->eventWizardSource,
            'event-wizard.js must have handleWaitlistConversion method'
        );
    }

    public function testConversionHandlerCallsConversionEndpoint(): void
    {
        $this->assertStringContainsString(
            'waitlist-conversion/convert',
            $this->wizardSource,
            'booking-wizard conversion must call the waitlist-conversion/convert endpoint'
        );
    }

    // =========================================================================
    // Stats
    // =========================================================================

    public function testGetStatsUsesGroupByQuery(): void
    {
        $this->assertStringContainsString(
            "->groupBy('status')",
            $this->source,
            'getStats must use a single GROUP BY query for efficiency'
        );
    }

    public function testGetStatsReturnsAllStatusKeys(): void
    {
        // The stats array should initialize all status keys
        $statuses = ['active', 'notified', 'converted', 'expired', 'cancelled', 'total'];
        foreach ($statuses as $status) {
            $this->assertStringContainsString(
                "'{$status}'",
                $this->source,
                "getStats must include '{$status}' key"
            );
        }
    }

    // =========================================================================
    // PII protection
    // =========================================================================

    // =========================================================================
    // Email template includes conversion link
    // =========================================================================

    public function testNotificationEmailIncludesConversionLink(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/src/templates/emails/waitlist-notification.twig'
        );
        $this->assertStringContainsString(
            'conversionToken',
            $template,
            'Waitlist notification email must reference conversion token'
        );
        $this->assertStringContainsString(
            'bookingPageUrl',
            $template,
            'Waitlist notification email must use bookingPageUrl setting to build link'
        );
        $this->assertStringContainsString(
            'waitlist=',
            $template,
            'Waitlist notification email must include waitlist= query parameter'
        );
    }

    public function testNotificationEmailShowsExpiryTime(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/src/templates/emails/waitlist-notification.twig'
        );
        $this->assertStringContainsString(
            'conversionExpiry',
            $template,
            'Waitlist notification email must show conversion expiry time'
        );
    }

    public function testNotificationJobPassesConversionTokenToTemplate(): void
    {
        $jobSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/queue/jobs/SendWaitlistNotificationJob.php'
        );
        $this->assertStringContainsString(
            "'conversionToken' => \$this->conversionToken",
            $jobSource,
            'SendWaitlistNotificationJob must pass conversionToken to email template'
        );
    }

    // =========================================================================
    // PII protection
    // =========================================================================

    public function testLogsRedactEmail(): void
    {
        $this->assertStringContainsString(
            'PiiRedactor::redactEmail',
            $this->source,
            'WaitlistService must redact email addresses in log messages'
        );
    }
}
