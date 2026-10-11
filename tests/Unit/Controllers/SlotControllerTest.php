<?php

namespace anvildev\booked\tests\Unit\Controllers;

use anvildev\booked\tests\Support\TestCase;

/**
 * SlotController Test
 *
 * Source-level checks for the manage-token-scoped availability path
 * (resolveManageTokenContext()) used by the reschedule picker.
 */
class SlotControllerTest extends TestCase
{
    private string $controllerSource;

    protected function setUp(): void
    {
        parent::setUp();
        $srcDir = dirname(__DIR__, 3) . '/src';
        $this->controllerSource = file_get_contents($srcDir . '/controllers/SlotController.php');
    }

    private function resolveManageTokenContextSource(): string
    {
        preg_match('/function resolveManageTokenContext\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'resolveManageTokenContext method should exist');
        return $matches[0];
    }

    public function testResolveManageTokenContextExcludesEventBookings(): void
    {
        $this->assertStringContainsString(
            'getEventDateId() !== null',
            $this->resolveManageTokenContextSource(),
            'resolveManageTokenContext must exclude event bookings (no slot grid to move within)'
        );
    }

    public function testResolveManageTokenContextExcludesMultiDayBookings(): void
    {
        $this->assertStringContainsString(
            'isMultiDay()',
            $this->resolveManageTokenContextSource(),
            'resolveManageTokenContext must exclude multi-day bookings'
        );
    }

    public function testResolveManageTokenContextExcludesNoShowBookings(): void
    {
        $this->assertStringContainsString(
            'STATUS_NO_SHOW',
            $this->resolveManageTokenContextSource(),
            'resolveManageTokenContext must exclude no-show bookings'
        );
    }

    public function testResolveManageTokenContextChecksCanBeRescheduled(): void
    {
        // Without this, availability data for a booking inside the policy window
        // (or otherwise ineligible) could still be read via a valid token even
        // though the write path correctly refuses to move it — a guard mismatch
        // between the read and write sides of the same feature.
        $this->assertStringContainsString(
            'canBeRescheduled()',
            $this->resolveManageTokenContextSource(),
            'resolveManageTokenContext must check canBeRescheduled(), matching the write-path guard'
        );
    }

    public function testResolveManageTokenContextDoesNotHardcodeExtrasDurationToZero(): void
    {
        // A booking with extras (e.g. a 60-min service + a 30-min extra) has a
        // longer slot than the base service duration. Hardcoding 0 here silently
        // truncates the rescheduled booking by the extras' time.
        $source = $this->resolveManageTokenContextSource();
        $this->assertStringContainsString(
            'getTotalDuration()',
            $source,
            'resolveManageTokenContext must derive extrasDuration from the reservation\'s own total duration'
        );
        $this->assertStringNotContainsString(
            "'extrasDuration' => 0",
            $source,
            'resolveManageTokenContext must not hardcode extrasDuration to 0'
        );
    }

    public function testAvailableSlotsAndCalendarActionsUseExtrasDurationFromManageContext(): void
    {
        foreach (['actionGetAvailableSlots', 'actionGetAvailabilityCalendar'] as $action) {
            preg_match("/function {$action}\\b.*?^    \\}/ms", $this->controllerSource, $matches);
            $this->assertNotEmpty($matches, "{$action} method should exist");
            $this->assertStringNotContainsString(
                '$extrasDuration = 0;',
                $matches[0],
                "{$action} must not hardcode extrasDuration to 0 on the manage-token path"
            );
        }
    }

    public function testAvailabilityCalendarRequiresPostRequest(): void
    {
        // manageToken is a secret that fully controls a customer's booking — it
        // must never ride in a GET query string, where it lands in server access
        // logs and browser history.
        preg_match('/function actionGetAvailabilityCalendar\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionGetAvailabilityCalendar method should exist');
        $this->assertStringContainsString(
            'requirePostRequest()',
            $matches[0],
            'actionGetAvailabilityCalendar must require POST so manageToken is never sent as a query param'
        );
    }
}
