<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\Booked;
use anvildev\booked\Editions;
use anvildev\booked\tests\Support\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Edition release gate.
 *
 * Behavioural edition tests need a booted Craft app with the edition set to Lite
 * (skipped in the unit environment), so this suite asserts the *gating is wired*
 * at every Lite/Pro seam by inspecting the code — it fails if a future change
 * removes a guard and lets a Pro feature leak into Lite. Paired with the pure
 * capability-matrix assertions in {@see EditionsTest}.
 */
class EditionGatingTest extends TestCase
{
    private static function methodSource(string $class, string $method): string
    {
        $rm = new ReflectionMethod($class, $method);
        $lines = file($rm->getFileName());
        return implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
    }

    /**
     * @dataProvider proOnlyControllerProvider
     */
    public function testProOnlyCpControllersDenyLite(string $class): void
    {
        $src = self::methodSource($class, 'beforeAction');
        $this->assertStringContainsString(
            'Editions::requirePro(',
            $src,
            "{$class}::beforeAction() must gate Lite with Editions::requirePro()",
        );
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function proOnlyControllerProvider(): array
    {
        return [
            'event-dates' => ['anvildev\booked\controllers\cp\EventDatesController'],
            'cp-waitlist' => ['anvildev\booked\controllers\cp\WaitlistController'],
            'webhooks' => ['anvildev\booked\controllers\cp\WebhooksController'],
            'frontend-waitlist' => ['anvildev\booked\controllers\WaitlistController'],
        ];
    }

    public function testReportsControllerKeepsOnlyBasicRevenueUnderLite(): void
    {
        $src = self::methodSource('anvildev\booked\controllers\cp\ReportsController', 'beforeAction');
        $this->assertStringContainsString('Editions::requirePro(', $src);
        $this->assertStringContainsString("!== 'revenue'", $src, 'Lite must keep the basic revenue report');
    }

    public function testSettingsControllerGatesProTabs(): void
    {
        $src = self::methodSource('anvildev\booked\controllers\cp\SettingsController', 'beforeAction');
        $this->assertStringContainsString('Editions::requirePro(', $src);
        foreach (['waitlist', 'sms', 'calendar', 'meetings', 'commerce', 'webhooks'] as $tab) {
            $this->assertStringContainsString("'{$tab}'", $src, "Pro settings tab '{$tab}' must be gated");
        }
    }

    public function testInitGatesProRegistrationsUnderPro(): void
    {
        $src = self::methodSource(Booked::class, 'init');
        $this->assertStringContainsString('if (Editions::isPro())', $src);
        foreach ([
            'registerCommerceListeners',
            'registerCalendarSyncListeners',
            'registerVirtualMeetingListeners',
            'registerWebhookListeners',
            'registerGraphQl',
            'registerMcpTools',
        ] as $registration) {
            $this->assertStringContainsString($registration, $src);
        }
    }

    public function testCpNavHidesProOnlyItemsUnderLite(): void
    {
        $src = self::methodSource(Booked::class, 'getCpNavItem');
        $this->assertStringContainsString('Editions::isPro()', $src);
        foreach (['event-dates', 'waitlist', 'webhooks'] as $navKey) {
            $this->assertStringContainsString("'{$navKey}'", $src, "Pro nav item '{$navKey}' must be gated");
        }
    }

    public function testSmsGatedToPro(): void
    {
        $src = self::methodSource('anvildev\booked\models\Settings', 'isSmsConfigured');
        $this->assertStringContainsString('Editions::isPro()', $src, 'SMS must be Pro-gated in isSmsConfigured()');
    }

    public function testSettingsSidebarHidesProTabs(): void
    {
        $tpl = dirname((new ReflectionClass(Booked::class))->getFileName()) . '/templates/settings/_sidebar.twig';
        $this->assertFileExists($tpl);
        $src = file_get_contents($tpl);
        $this->assertStringContainsString('craft.booked.isPro', $src);
        $this->assertStringContainsString('proSettingsTabs', $src);
    }

    public function testExistingInstallsRemainPro(): void
    {
        // Existing installs are stored as `pro` and stay full-featured.
        $this->assertTrue(Editions::isPro(Editions::PRO));
        $this->assertFalse(Editions::isLite(Editions::PRO));
        // Lite listed first so an existing stored `pro` edition is preserved.
        $this->assertSame([Editions::LITE, Editions::PRO], Booked::editions());
    }
}
