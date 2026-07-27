<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\Booked;
use anvildev\booked\Editions;
use anvildev\booked\tests\Support\TestCase;

/**
 * Pure unit tests for the edition capability gate. No Craft init required —
 * every assertion passes an explicit edition, and the default-open path is
 * exercised via the null-instance fallback.
 */
class EditionsTest extends TestCase
{
    public function testEditionsListedLowestTierFirst(): void
    {
        $this->assertSame([Editions::LITE, Editions::PRO], Booked::editions());
    }

    public function testConstantsAlign(): void
    {
        $this->assertSame(Editions::LITE, Booked::EDITION_LITE);
        $this->assertSame(Editions::PRO, Booked::EDITION_PRO);
    }

    public function testIsProByExplicitEdition(): void
    {
        $this->assertTrue(Editions::isPro(Editions::PRO));
        $this->assertFalse(Editions::isPro(Editions::LITE));
        $this->assertTrue(Editions::isLite(Editions::LITE));
        $this->assertFalse(Editions::isLite(Editions::PRO));
    }

    /**
     * @dataProvider proCapabilityProvider
     */
    public function testProCapabilitiesGatedToPro(string $capability): void
    {
        $this->assertFalse(Editions::can($capability, Editions::LITE), "{$capability} must be denied under Lite");
        $this->assertTrue(Editions::can($capability, Editions::PRO), "{$capability} must be allowed under Pro");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function proCapabilityProvider(): array
    {
        return [
            'events' => [Editions::CAP_EVENTS],
            'waitlist' => [Editions::CAP_WAITLIST],
            'calendarSync' => [Editions::CAP_CALENDAR_SYNC],
            'sms' => [Editions::CAP_SMS],
            'virtualMeetings' => [Editions::CAP_VIRTUAL_MEETINGS],
            'multiDay' => [Editions::CAP_MULTI_DAY],
            'tieredRefunds' => [Editions::CAP_TIERED_REFUNDS],
            'webhooks' => [Editions::CAP_WEBHOOKS],
            'fullReports' => [Editions::CAP_FULL_REPORTS],
            'graphql' => [Editions::CAP_GRAPHQL],
            'mcp' => [Editions::CAP_MCP],
            'commerce' => [Editions::CAP_COMMERCE],
        ];
    }

    public function testDefaultOpenWhenNoActiveEdition(): void
    {
        // With no resolvable plugin instance in the unit context, current()
        // falls back to Pro — a mis-resolved edition never locks features out.
        $this->assertTrue(Editions::isPro());
        $this->assertFalse(Editions::isLite());
    }
}
