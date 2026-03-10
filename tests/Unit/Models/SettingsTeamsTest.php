<?php

namespace anvildev\booked\tests\Unit\Models;

use anvildev\booked\models\Settings;
use anvildev\booked\tests\Support\TestCase;

/**
 * Settings Teams Test
 *
 * Tests Microsoft Teams settings defaults, validation, and helper methods.
 */
class SettingsTeamsTest extends TestCase
{
    public function testTeamsDefaultsAreCorrect(): void
    {
        $settings = new Settings();

        $this->assertFalse($settings->teamsEnabled);
        $this->assertNull($settings->teamsTenantId);
        $this->assertNull($settings->teamsClientId);
        $this->assertNull($settings->teamsClientSecret);
    }

    public function testIsTeamsConfiguredReturnsFalseWhenDisabled(): void
    {
        $settings = new Settings();
        $settings->teamsEnabled = false;
        $settings->teamsTenantId = 'tenant-id';
        $settings->teamsClientId = 'client-id';
        $settings->teamsClientSecret = 'client-secret';

        $this->assertFalse($settings->isTeamsConfigured());
    }

    public function testIsTeamsConfiguredReturnsFalseWithMissingCredentials(): void
    {
        $settings = new Settings();
        $settings->teamsEnabled = true;
        $settings->teamsTenantId = 'tenant-id';
        $settings->teamsClientId = null;
        $settings->teamsClientSecret = 'client-secret';

        $this->assertFalse($settings->isTeamsConfigured());
    }

    public function testIsTeamsConfiguredReturnsTrueWithAllCredentials(): void
    {
        $settings = new Settings();
        $settings->teamsEnabled = true;
        $settings->teamsTenantId = 'tenant-id';
        $settings->teamsClientId = 'client-id';
        $settings->teamsClientSecret = 'client-secret';

        $this->assertTrue($settings->isTeamsConfigured());
    }

    public function testCanUseTeamsReturnsTrueWhenConfigured(): void
    {
        $settings = new Settings();
        $settings->teamsEnabled = true;
        $settings->teamsTenantId = 'tenant-id';
        $settings->teamsClientId = 'client-id';
        $settings->teamsClientSecret = 'client-secret';

        $this->assertTrue($settings->isTeamsConfigured());
    }

    public function testCanUseTeamsReturnsFalseWhenNotConfigured(): void
    {
        $settings = new Settings();
        $settings->teamsEnabled = false;

        $this->assertFalse($settings->isTeamsConfigured());
    }

    public function testCanUseVirtualMeetingsIncludesTeams(): void
    {
        $settings = new Settings();
        $settings->enableVirtualMeetings = true;
        $settings->teamsEnabled = true;
        $settings->teamsTenantId = 'tenant-id';
        $settings->teamsClientId = 'client-id';
        $settings->teamsClientSecret = 'client-secret';

        $this->assertTrue($settings->canUseVirtualMeetings());
    }

    public function testTeamsValidationRulesAcceptValidSettings(): void
    {
        $this->requiresCraft();

        $settings = new Settings();
        $settings->teamsEnabled = true;
        $settings->teamsTenantId = 'some-tenant-id';
        $settings->teamsClientId = 'some-client-id';
        $settings->teamsClientSecret = 'some-client-secret';

        $settings->validate(['teamsEnabled', 'teamsTenantId', 'teamsClientId', 'teamsClientSecret']);

        $this->assertFalse($settings->hasErrors('teamsEnabled'));
        $this->assertFalse($settings->hasErrors('teamsTenantId'));
        $this->assertFalse($settings->hasErrors('teamsClientId'));
        $this->assertFalse($settings->hasErrors('teamsClientSecret'));
    }
}
