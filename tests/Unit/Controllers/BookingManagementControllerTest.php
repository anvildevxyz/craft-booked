<?php

namespace anvildev\booked\tests\Unit\Controllers;

use anvildev\booked\controllers\BookingManagementController;
use anvildev\booked\tests\Support\TestCase;

/**
 * BookingManagementController Test
 *
 * Source-level checks for the booking management controller.
 */
class BookingManagementControllerTest extends TestCase
{
    private string $controllerSource;
    private string $bookedSource;

    protected function setUp(): void
    {
        parent::setUp();
        $srcDir = dirname(__DIR__, 3) . '/src';
        $this->controllerSource = file_get_contents($srcDir . '/controllers/BookingManagementController.php');
        $this->bookedSource = file_get_contents($srcDir . '/Booked.php');
    }

    public function testDownloadIcsIsAllowedAnonymous(): void
    {
        $this->assertStringContainsString(
            "'download-ics'",
            $this->controllerSource,
            'download-ics should be listed in $allowAnonymous'
        );
    }

    public function testDownloadIcsActionMethodExists(): void
    {
        $this->assertStringContainsString(
            'function actionDownloadIcs(string $token)',
            $this->controllerSource,
            'actionDownloadIcs method should exist in BookingManagementController'
        );
    }

    public function testIcsRouteExistsInBookedPhp(): void
    {
        $this->assertStringContainsString(
            "booking/ics/<token:[^\/]+>",
            $this->bookedSource,
            'ICS route should be registered in Booked.php site routes'
        );
    }

    public function testIcsRouteMapsToCorrectAction(): void
    {
        $this->assertStringContainsString(
            "'booking/ics/<token:[^\\/]+>' => 'booked/booking-management/download-ics'",
            $this->bookedSource,
            'ICS route should map to booking-management/download-ics action'
        );
    }

    public function testManageBookingJsonResponseDoesNotExposeConfirmationToken(): void
    {
        // Extract the actionManageBooking method body and verify it does not
        // include 'token' => ... getConfirmationToken() in the asJson array
        $this->assertStringNotContainsString(
            "'token' => \$reservation->getConfirmationToken()",
            $this->controllerSource,
            'actionManageBooking JSON response must not expose confirmationToken'
        );
    }

    public function testCancelBookingByTokenSanitizesReason(): void
    {
        $this->assertStringContainsString(
            'strip_tags',
            $this->controllerSource,
            'Cancellation reason must be sanitized with strip_tags'
        );
    }

    // ── Confirmation token verification tests ──

    public function testCancelBookingRequiresTokenBodyParam(): void
    {
        $this->assertStringContainsString(
            "getRequiredBodyParam('token')",
            $this->controllerSource,
            'actionCancelBooking must require a token body param'
        );
    }

    public function testCancelBookingValidatesTokenWithHashEquals(): void
    {
        // Extract the actionCancelBooking method to verify hash_equals is used
        preg_match('/function actionCancelBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionCancelBooking method should exist');
        $this->assertStringContainsString(
            'hash_equals($reservation->getConfirmationToken(), $token)',
            $matches[0],
            'actionCancelBooking must use hash_equals to compare confirmation token (timing-safe)'
        );
    }

    public function testCancelBookingRejectsWrongTokenWithForbidden(): void
    {
        preg_match('/function actionCancelBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertStringContainsString(
            'ForbiddenHttpException',
            $matches[0],
            'actionCancelBooking must throw ForbiddenHttpException on wrong token'
        );
    }

    public function testCancelBookingLogsAuthFailureOnWrongToken(): void
    {
        preg_match('/function actionCancelBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertStringContainsString(
            "logAuthFailure('invalid_cancel_token'",
            $matches[0],
            'actionCancelBooking must log auth failure on invalid token'
        );
    }

    public function testReduceQuantityValidatesTokenWithHashEquals(): void
    {
        preg_match('/function actionReduceQuantity\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionReduceQuantity method should exist');
        $this->assertStringContainsString(
            'hash_equals($reservation->getConfirmationToken(), $token)',
            $matches[0],
            'actionReduceQuantity must use hash_equals for token validation'
        );
    }

    public function testIncreaseQuantityValidatesTokenWithHashEquals(): void
    {
        preg_match('/function actionIncreaseQuantity\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionIncreaseQuantity method should exist');
        $this->assertStringContainsString(
            'hash_equals($reservation->getConfirmationToken(), $token)',
            $matches[0],
            'actionIncreaseQuantity must use hash_equals for token validation'
        );
    }

    public function testManageBookingRequiresTokenForLookup(): void
    {
        // The manage-booking action uses findByToken which performs an exact DB match
        preg_match('/function actionManageBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionManageBooking method should exist');
        $this->assertStringContainsString(
            'ReservationFactory::findByToken($token)',
            $matches[0],
            'actionManageBooking must use findByToken for secure lookup'
        );
    }

    public function testManageBookingReturns404ForMissingToken(): void
    {
        preg_match('/function actionManageBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertStringContainsString(
            'NotFoundHttpException',
            $matches[0],
            'actionManageBooking must throw NotFoundHttpException when token not found'
        );
    }

    public function testCancelBookingByTokenUsesTokenForLookup(): void
    {
        preg_match('/function actionCancelBookingByToken\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionCancelBookingByToken method should exist');
        $this->assertStringContainsString(
            'ReservationFactory::findByToken($token)',
            $matches[0],
            'actionCancelBookingByToken must look up reservation by token'
        );
    }

    public function testManageBookingJsonResponseDoesNotExposeToken(): void
    {
        // Ensure the JSON response from manage-booking doesn't leak the confirmation token
        preg_match('/function actionManageBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertStringNotContainsString(
            'confirmationToken',
            $matches[0],
            'actionManageBooking JSON response must not expose confirmationToken field'
        );
    }

    public function testRescheduleIsProtectedByManagementToken(): void
    {
        // Reschedule is only reachable through the legacy manage-booking dispatch
        // or the dedicated actionRescheduleBooking() — both token-gated. Verify
        // handleRescheduleAction itself is private (not directly callable as an action).
        $reflection = new \ReflectionMethod(BookingManagementController::class, 'handleRescheduleAction');
        $this->assertTrue(
            $reflection->isPrivate(),
            'handleRescheduleAction must be private (only accessible through token-protected callers)'
        );
    }

    public function testRescheduleActionValidatesTokenWithHashEquals(): void
    {
        preg_match('/function actionRescheduleBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionRescheduleBooking method should exist');
        $this->assertStringContainsString(
            'hash_equals($reservation->getConfirmationToken(), $token)',
            $matches[0],
            'actionRescheduleBooking must use hash_equals for token validation'
        );
    }

    public function testRescheduleActionLogsAuthFailureOnWrongToken(): void
    {
        preg_match('/function actionRescheduleBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertStringContainsString(
            "logAuthFailure('invalid_reschedule_token'",
            $matches[0],
            'actionRescheduleBooking must log auth failure on invalid token'
        );
    }

    public function testHandleRescheduleActionExcludesCancelledAndNoShowBookings(): void
    {
        // 'completed' is not a real ReservationRecord status — the guard must
        // name the statuses that actually exist (cancelled, no_show), or a
        // no-show booking staff already closed out stays reschedulable.
        preg_match('/function handleRescheduleAction\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'handleRescheduleAction method should exist');
        $this->assertStringContainsString(
            "['cancelled', 'no_show']",
            $matches[0],
            'handleRescheduleAction must exclude cancelled and no_show bookings'
        );
        $this->assertStringNotContainsString(
            "'completed'",
            $matches[0],
            'handleRescheduleAction must not check for a non-existent "completed" status'
        );
    }

    public function testHandleRescheduleActionExcludesMultiDayBookings(): void
    {
        preg_match('/function handleRescheduleAction\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertStringContainsString(
            '$reservation->isMultiDay()',
            $matches[0],
            'handleRescheduleAction must exclude multi-day bookings, which have no single start/end time to write'
        );
    }

    public function testHandleRescheduleActionQueuesRescheduledNotification(): void
    {
        preg_match('/function handleRescheduleAction\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertStringContainsString(
            'queueRescheduledNotification',
            $matches[0],
            'handleRescheduleAction must queue a rescheduled notification email on success'
        );
    }

    public function testCanRescheduleJsonFlagMirrorsTheWriteGuards(): void
    {
        preg_match('/function actionManageBooking\b.*?^    \}/ms', $this->controllerSource, $matches);
        $this->assertNotEmpty($matches, 'actionManageBooking method should exist');
        foreach (['isMultiDay()', 'STATUS_NO_SHOW', 'canBeRescheduled()'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $matches[0],
                "canReschedule computation must check {$needle}, matching handleRescheduleAction's guards"
            );
        }
    }

    public function testHandleCancelActionIsPrivate(): void
    {
        $reflection = new \ReflectionMethod(BookingManagementController::class, 'handleCancelAction');
        $this->assertTrue(
            $reflection->isPrivate(),
            'handleCancelAction must be private (only accessible through token-protected actionManageBooking)'
        );
    }
}
