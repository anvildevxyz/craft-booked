<?php

namespace anvildev\booked\tests\Unit\Models;

use anvildev\booked\models\Settings;
use anvildev\booked\tests\Support\TestCase;

class SettingsConfigurationTest extends TestCase
{
    // =========================================================================
    // Defaults
    // =========================================================================

    public function testDefaultValues(): void
    {
        $s = new Settings();

        $this->assertNull($s->defaultCurrency);
        $this->assertSame(5, $s->softLockDurationMinutes);
        $this->assertSame(0, $s->minimumAdvanceBookingHours);
        $this->assertSame(90, $s->maximumAdvanceBookingDays);
        $this->assertSame(24, $s->cancellationPolicyHours);
        $this->assertTrue($s->enableRateLimiting);
        $this->assertSame(5, $s->rateLimitPerEmail);
        $this->assertSame(10, $s->rateLimitPerIp);
        $this->assertFalse($s->enableVirtualMeetings);
    }

    public function testSecurityDefaults(): void
    {
        $s = new Settings();

        $this->assertFalse($s->enableCaptcha);
        $this->assertTrue($s->enableHoneypot);
        $this->assertSame('website', $s->honeypotFieldName);
        $this->assertFalse($s->enableIpBlocking);
        $this->assertTrue($s->enableTimeBasedLimits);
        $this->assertSame(3, $s->minimumSubmissionTime);
        $this->assertFalse($s->enableAuditLog);
    }

    public function testNotificationDefaults(): void
    {
        $s = new Settings();

        $this->assertTrue($s->ownerNotificationEnabled);
        $this->assertTrue($s->emailRemindersEnabled);
        $this->assertSame(24, $s->emailReminderHoursBefore);
        $this->assertTrue($s->sendCancellationEmail);
        $this->assertFalse($s->smsEnabled);
        $this->assertFalse($s->smsRemindersEnabled);
        $this->assertSame(24, $s->smsReminderHoursBefore);
        $this->assertFalse($s->smsConfirmationEnabled);
        $this->assertFalse($s->smsCancellationEnabled);
        $this->assertSame(3, $s->smsMaxRetries);
        $this->assertSame('US', $s->defaultCountryCode);
    }

    public function testCommerceDefaults(): void
    {
        $s = new Settings();

        $this->assertFalse($s->commerceEnabled);
        $this->assertNull($s->commerceTaxCategoryId);
        $this->assertSame(48, $s->pendingCartExpirationHours);
    }

    public function testWebhookDefaults(): void
    {
        $s = new Settings();

        $this->assertFalse($s->webhooksEnabled);
        $this->assertSame(30, $s->webhookTimeout);
        $this->assertTrue($s->webhookLogEnabled);
        $this->assertSame(30, $s->webhookLogRetentionDays);
    }

    public function testWaitlistDefaults(): void
    {
        $s = new Settings();

        $this->assertTrue($s->enableWaitlist);
        $this->assertSame(30, $s->waitlistExpirationDays);
        $this->assertSame(10, $s->waitlistNotificationLimit);
    }

    // =========================================================================
    // Google Calendar
    // =========================================================================

    public function testGoogleCalendarNotConfiguredWhenDisabled(): void
    {
        $s = new Settings();
        $s->googleCalendarEnabled = false;
        $s->googleCalendarClientId = 'id';
        $s->googleCalendarClientSecret = 'secret';

        $this->assertFalse($s->isGoogleCalendarConfigured());
    }

    public function testGoogleCalendarNotConfiguredWithMissingClientId(): void
    {
        $s = new Settings();
        $s->googleCalendarEnabled = true;
        $s->googleCalendarClientId = null;
        $s->googleCalendarClientSecret = 'secret';

        $this->assertFalse($s->isGoogleCalendarConfigured());
    }

    public function testGoogleCalendarNotConfiguredWithMissingSecret(): void
    {
        $s = new Settings();
        $s->googleCalendarEnabled = true;
        $s->googleCalendarClientId = 'id';
        $s->googleCalendarClientSecret = null;

        $this->assertFalse($s->isGoogleCalendarConfigured());
    }

    public function testGoogleCalendarConfiguredWithAllCredentials(): void
    {
        $s = new Settings();
        $s->googleCalendarEnabled = true;
        $s->googleCalendarClientId = 'id';
        $s->googleCalendarClientSecret = 'secret';

        $this->assertTrue($s->isGoogleCalendarConfigured());
    }

    // =========================================================================
    // Outlook Calendar
    // =========================================================================

    public function testOutlookCalendarNotConfiguredWhenDisabled(): void
    {
        $s = new Settings();
        $s->outlookCalendarEnabled = false;
        $s->outlookCalendarClientId = 'id';
        $s->outlookCalendarClientSecret = 'secret';

        $this->assertFalse($s->isOutlookCalendarConfigured());
    }

    public function testOutlookCalendarNotConfiguredWithMissingCredentials(): void
    {
        $s = new Settings();
        $s->outlookCalendarEnabled = true;
        $s->outlookCalendarClientId = null;
        $s->outlookCalendarClientSecret = 'secret';

        $this->assertFalse($s->isOutlookCalendarConfigured());
    }

    public function testOutlookCalendarConfiguredWithAllCredentials(): void
    {
        $s = new Settings();
        $s->outlookCalendarEnabled = true;
        $s->outlookCalendarClientId = 'id';
        $s->outlookCalendarClientSecret = 'secret';

        $this->assertTrue($s->isOutlookCalendarConfigured());
    }

    // =========================================================================
    // Calendar Sync (composite)
    // =========================================================================

    public function testCanUseCalendarSyncWithGoogleOnly(): void
    {
        $s = new Settings();
        $s->googleCalendarEnabled = true;
        $s->googleCalendarClientId = 'id';
        $s->googleCalendarClientSecret = 'secret';

        $this->assertTrue($s->canUseCalendarSync());
    }

    public function testCanUseCalendarSyncWithOutlookOnly(): void
    {
        $s = new Settings();
        $s->outlookCalendarEnabled = true;
        $s->outlookCalendarClientId = 'id';
        $s->outlookCalendarClientSecret = 'secret';

        $this->assertTrue($s->canUseCalendarSync());
    }

    public function testCannotUseCalendarSyncWithNeitherConfigured(): void
    {
        $s = new Settings();
        $this->assertFalse($s->canUseCalendarSync());
    }

    public function testCanUseCalendarSyncWithBothConfigured(): void
    {
        $s = new Settings();
        $s->googleCalendarEnabled = true;
        $s->googleCalendarClientId = 'id';
        $s->googleCalendarClientSecret = 'secret';
        $s->outlookCalendarEnabled = true;
        $s->outlookCalendarClientId = 'id';
        $s->outlookCalendarClientSecret = 'secret';

        $this->assertTrue($s->canUseCalendarSync());
    }

    // =========================================================================
    // Zoom
    // =========================================================================

    public function testZoomNotConfiguredWhenDisabled(): void
    {
        $s = new Settings();
        $s->zoomEnabled = false;
        $s->zoomAccountId = 'acct';
        $s->zoomClientId = 'id';
        $s->zoomClientSecret = 'secret';

        $this->assertFalse($s->isZoomConfigured());
    }

    /**
     * @dataProvider zoomMissingCredentialProvider
     */
    public function testZoomNotConfiguredWithMissingCredentials(?string $accountId, ?string $clientId, ?string $clientSecret): void
    {
        $s = new Settings();
        $s->zoomEnabled = true;
        $s->zoomAccountId = $accountId;
        $s->zoomClientId = $clientId;
        $s->zoomClientSecret = $clientSecret;

        $this->assertFalse($s->isZoomConfigured());
    }

    public static function zoomMissingCredentialProvider(): array
    {
        return [
            'missing account id' => [null, 'id', 'secret'],
            'missing client id' => ['acct', null, 'secret'],
            'missing client secret' => ['acct', 'id', null],
            'all missing' => [null, null, null],
        ];
    }

    public function testZoomConfiguredWithAllCredentials(): void
    {
        $s = new Settings();
        $s->zoomEnabled = true;
        $s->zoomAccountId = 'acct';
        $s->zoomClientId = 'id';
        $s->zoomClientSecret = 'secret';

        $this->assertTrue($s->isZoomConfigured());
    }

    // =========================================================================
    // Google Meet
    // =========================================================================

    public function testCanUseGoogleMeetWhenEnabled(): void
    {
        $s = new Settings();
        $s->googleMeetEnabled = true;

        $this->assertTrue($s->canUseGoogleMeet());
    }

    public function testCannotUseGoogleMeetWhenDisabled(): void
    {
        $s = new Settings();
        $s->googleMeetEnabled = false;

        $this->assertFalse($s->canUseGoogleMeet());
    }

    // =========================================================================
    // SMS
    // =========================================================================

    public function testSmsNotConfiguredWhenDisabled(): void
    {
        $s = new Settings();
        $s->smsEnabled = false;
        $s->twilioAccountSid = 'sid';
        $s->twilioAuthToken = 'token';
        $s->twilioPhoneNumber = '+1234567890';

        $this->assertFalse($s->isSmsConfigured());
    }

    /**
     * @dataProvider smsMissingCredentialProvider
     */
    public function testSmsNotConfiguredWithMissingCredentials(?string $sid, ?string $token, ?string $phone): void
    {
        $s = new Settings();
        $s->smsEnabled = true;
        $s->twilioAccountSid = $sid;
        $s->twilioAuthToken = $token;
        $s->twilioPhoneNumber = $phone;

        $this->assertFalse($s->isSmsConfigured());
    }

    public static function smsMissingCredentialProvider(): array
    {
        return [
            'missing sid' => [null, 'token', '+1234567890'],
            'missing token' => ['sid', null, '+1234567890'],
            'missing phone' => ['sid', 'token', null],
            'all missing' => [null, null, null],
        ];
    }

    public function testSmsConfiguredWithAllCredentials(): void
    {
        $s = new Settings();
        $s->smsEnabled = true;
        $s->twilioAccountSid = 'sid';
        $s->twilioAuthToken = 'token';
        $s->twilioPhoneNumber = '+1234567890';

        $this->assertTrue($s->isSmsConfigured());
    }

    // =========================================================================
    // Webhooks
    // =========================================================================

    public function testCanUseWebhooksWhenEnabled(): void
    {
        $s = new Settings();
        $s->webhooksEnabled = true;

        $this->assertTrue($s->canUseWebhooks());
    }

    public function testCannotUseWebhooksWhenDisabled(): void
    {
        $s = new Settings();
        $s->webhooksEnabled = false;

        $this->assertFalse($s->canUseWebhooks());
    }

    // =========================================================================
    // Virtual Meetings (composite)
    // =========================================================================

    public function testCannotUseVirtualMeetingsWhenGloballyDisabled(): void
    {
        $s = new Settings();
        $s->enableVirtualMeetings = false;
        $s->zoomEnabled = true;
        $s->zoomAccountId = 'acct';
        $s->zoomClientId = 'id';
        $s->zoomClientSecret = 'secret';

        $this->assertFalse($s->canUseVirtualMeetings());
    }

    public function testCannotUseVirtualMeetingsWithNoProviders(): void
    {
        $s = new Settings();
        $s->enableVirtualMeetings = true;

        $this->assertFalse($s->canUseVirtualMeetings());
    }

    public function testCanUseVirtualMeetingsWithZoomOnly(): void
    {
        $s = new Settings();
        $s->enableVirtualMeetings = true;
        $s->zoomEnabled = true;
        $s->zoomAccountId = 'acct';
        $s->zoomClientId = 'id';
        $s->zoomClientSecret = 'secret';

        $this->assertTrue($s->canUseVirtualMeetings());
    }

    public function testCanUseVirtualMeetingsWithGoogleMeetOnly(): void
    {
        $s = new Settings();
        $s->enableVirtualMeetings = true;
        $s->googleMeetEnabled = true;

        $this->assertTrue($s->canUseVirtualMeetings());
    }

    public function testCanUseVirtualMeetingsWithTeamsOnly(): void
    {
        $s = new Settings();
        $s->enableVirtualMeetings = true;
        $s->teamsEnabled = true;
        $s->teamsTenantId = 'tenant';
        $s->teamsClientId = 'id';
        $s->teamsClientSecret = 'secret';

        $this->assertTrue($s->canUseVirtualMeetings());
    }

    // =========================================================================
    // Empty string treated as missing
    // =========================================================================

    public function testEmptyStringCredentialsTreatedAsMissing(): void
    {
        $s = new Settings();
        $s->googleCalendarEnabled = true;
        $s->googleCalendarClientId = '';
        $s->googleCalendarClientSecret = '';

        $this->assertFalse($s->isGoogleCalendarConfigured());
    }

    public function testEmptyStringSmsCredentialsTreatedAsMissing(): void
    {
        $s = new Settings();
        $s->smsEnabled = true;
        $s->twilioAccountSid = '';
        $s->twilioAuthToken = '';
        $s->twilioPhoneNumber = '';

        $this->assertFalse($s->isSmsConfigured());
    }

    public function testEmptyStringZoomCredentialsTreatedAsMissing(): void
    {
        $s = new Settings();
        $s->zoomEnabled = true;
        $s->zoomAccountId = '';
        $s->zoomClientId = '';
        $s->zoomClientSecret = '';

        $this->assertFalse($s->isZoomConfigured());
    }
}
