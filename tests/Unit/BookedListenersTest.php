<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\tests\Support\TestCase;

/**
 * Booked.php Listener Wiring Test
 *
 * Source-level checks for the EVENT_AFTER_BOOKING_SAVE listener that reacts
 * to a reschedule (#135's calendar-sync fix, #136's shared notification fix).
 * Both live in the registerWebhookListeners() update branch (isNew === false)
 * rather than a dedicated method — these tests exist so a future refactor
 * can't silently drop either without a test going red.
 */
class BookedListenersTest extends TestCase
{
    private string $bookedSource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookedSource = file_get_contents(dirname(__DIR__, 2) . '/src/Booked.php');
    }

    private function afterBookingSaveUpdateBranchSource(): string
    {
        $registrarStart = strpos($this->bookedSource, 'function registerWebhookListeners');
        $this->assertNotFalse($registrarStart, 'registerWebhookListeners method must exist');

        $registrarSource = substr($this->bookedSource, $registrarStart, 4000);
        $elsePos = strpos($registrarSource, '} else {');
        $this->assertNotFalse($elsePos, 'The isNew/update branch (} else {) must exist');

        return substr($registrarSource, $elsePos, 3000);
    }

    public function testUpdateBranchQueuesACalendarUpdate(): void
    {
        $this->assertStringContainsString(
            'calendarSync->queueCalendarUpdate(',
            $this->afterBookingSaveUpdateBranchSource(),
            'An update (including a reschedule, from either entry point) must re-sync an already-connected calendar'
        );
    }

    public function testCalendarUpdateIsWrappedInTryCatch(): void
    {
        $branch = $this->afterBookingSaveUpdateBranchSource();
        $calendarPos = strpos($branch, 'queueCalendarUpdate(');
        $this->assertNotFalse($calendarPos);
        // A failure to queue the calendar sync must not break the save/webhook/
        // notification that already succeeded.
        $this->assertStringContainsString('try {', substr($branch, max(0, $calendarPos - 200), 200));
    }

    public function testUpdateBranchQueuesTheRescheduledNotificationGatedOnIsReschedule(): void
    {
        $branch = $this->afterBookingSaveUpdateBranchSource();

        $this->assertStringContainsString(
            'queueRescheduledNotification(',
            $branch,
            'The rescheduled email must be queued from this shared listener — not from the individual caller — so every updateReservation() caller (customer manage link, CP drag calendar) notifies the customer the same way (#136)'
        );

        $notificationPos = strpos($branch, 'queueRescheduledNotification(');
        $gatePos = strpos($branch, 'if ($event->isReschedule)');
        $this->assertNotFalse($gatePos, 'queueRescheduledNotification must be gated on $event->isReschedule');
        $this->assertLessThan(
            $notificationPos,
            $gatePos,
            'The isReschedule check must gate the notification — a notes-only or status-only update must not send a "rescheduled" email'
        );
    }

    public function testRescheduledNotificationIsWrappedInTryCatch(): void
    {
        $branch = $this->afterBookingSaveUpdateBranchSource();
        $notificationPos = strpos($branch, 'queueRescheduledNotification(');
        $this->assertNotFalse($notificationPos);
        $this->assertStringContainsString('try {', substr($branch, max(0, $notificationPos - 200), 200));
    }

    public function testRescheduledNotificationUsesThePreviousDateTimeFromTheEvent(): void
    {
        $branch = $this->afterBookingSaveUpdateBranchSource();
        $notificationPos = strpos($branch, 'queueRescheduledNotification(');
        $this->assertNotFalse($notificationPos);
        $callSource = substr($branch, $notificationPos, 400);

        $this->assertStringContainsString('$event->previousBookingDate', $callSource);
        $this->assertStringContainsString('$event->previousStartTime', $callSource);
    }
}
