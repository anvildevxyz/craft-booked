<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\tests\Support\TestCase;

/**
 * Permissions Registration Test
 *
 * Verifies that all expected permissions are registered in Booked.php
 * and that controllers and nav items reference the correct permission strings.
 */
class PermissionsRegistrationTest extends TestCase
{
    private string $bookedSource;
    private string $srcDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->srcDir = dirname(__DIR__, 2) . '/src';
        $this->bookedSource = file_get_contents($this->srcDir . '/Booked.php');
    }

    // =========================================================================
    // Permission Registration
    // =========================================================================

    /**
     * @dataProvider registeredPermissionsProvider
     */
    public function testPermissionIsRegisteredInBookedPhp(string $permission): void
    {
        $this->assertStringContainsString(
            "'{$permission}'",
            $this->bookedSource,
            "Permission '{$permission}' should be registered in Booked.php"
        );
    }

    public static function registeredPermissionsProvider(): array
    {
        return [
            'accessPlugin' => ['booked-accessPlugin'],
            'viewBookings' => ['booked-viewBookings'],
            'manageBookings' => ['booked-manageBookings'],
            'viewCalendar' => ['booked-viewCalendar'],
            'viewReports' => ['booked-viewReports'],
            'manageServices' => ['booked-manageServices'],
            'manageEmployees' => ['booked-manageEmployees'],
            'manageLocations' => ['booked-manageLocations'],
            'manageSettings' => ['booked-manageSettings'],
            'manageEvents' => ['booked-manageEvents'],
            'manageBlackoutDates' => ['booked-manageBlackoutDates'],
            'manageWaitlist' => ['booked-manageWaitlist'],
        ];
    }

    public function testTotalPermissionCount(): void
    {
        preg_match_all("/('booked-[a-zA-Z]+')\s*=>\s*\[/", $this->bookedSource, $matches);

        $this->assertCount(
            12,
            $matches[1],
            'Booked.php should register exactly 12 permissions. Found: ' . implode(', ', $matches[1])
        );
    }

    // =========================================================================
    // Controller Permission Checks
    // =========================================================================

    /**
     * @dataProvider controllerPermissionProvider
     */
    public function testControllerUsesCorrectPermission(string $controllerFile, string $expectedPermission): void
    {
        $source = file_get_contents($this->srcDir . '/controllers/cp/' . $controllerFile);

        $this->assertStringContainsString(
            "requirePermission('{$expectedPermission}')",
            $source,
            "{$controllerFile} should require '{$expectedPermission}'"
        );
    }

    public static function controllerPermissionProvider(): array
    {
        return [
            'EventDatesController' => ['EventDatesController.php', 'booked-manageEvents'],
            'BlackoutDatesController' => ['BlackoutDatesController.php', 'booked-manageBlackoutDates'],
            'WaitlistController' => ['WaitlistController.php', 'booked-manageWaitlist'],
        ];
    }

    // =========================================================================
    // Nav Item Permission Checks
    // =========================================================================

    /**
     * @dataProvider navPermissionProvider
     */
    public function testNavItemUsesCorrectPermission(string $navKey, string $expectedPermission): void
    {
        // Match data-driven navDefs: ['key', 'translationKey', 'url', 'permission', ...]
        $found = false;
        $foundPermission = null;

        if (preg_match_all("/\['([^']+)',\s*'[^']+',\s*'[^']+',\s*'([^']+)'/", $this->bookedSource, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($match[1] === $navKey) {
                    $found = true;
                    $foundPermission = $match[2];
                    break;
                }
            }
        }

        $this->assertTrue($found, "Nav key '{$navKey}' should exist in Booked.php navDefs");
        $this->assertSame(
            $expectedPermission,
            $foundPermission,
            "Nav item '{$navKey}' should be gated by '{$expectedPermission}', got '{$foundPermission}'"
        );
    }

    public static function navPermissionProvider(): array
    {
        return [
            'blackout-dates' => ['blackout-dates', 'booked-manageBlackoutDates'],
            'event-dates' => ['event-dates', 'booked-manageEvents'],
            'waitlist' => ['waitlist', 'booked-manageWaitlist'],
        ];
    }

    // =========================================================================
    // Translation Strings
    // =========================================================================

    /**
     * @dataProvider translationKeyProvider
     */
    public function testEnglishTranslationExists(string $key): void
    {
        $translations = require $this->srcDir . '/translations/en/booked.php';

        $this->assertArrayHasKey(
            $key,
            $translations,
            "English translation for '{$key}' should exist"
        );
    }

    /**
     * @dataProvider translationKeyProvider
     */
    public function testGermanTranslationExists(string $key): void
    {
        $translations = require $this->srcDir . '/translations/de/booked.php';

        $this->assertArrayHasKey(
            $key,
            $translations,
            "German translation for '{$key}' should exist"
        );
    }

    public static function translationKeyProvider(): array
    {
        return [
            'manageEvents' => ['permissions.manageEvents'],
            'manageBlackoutDates' => ['permissions.manageBlackoutDates'],
            'manageWaitlist' => ['permissions.manageWaitlist'],
        ];
    }
}
