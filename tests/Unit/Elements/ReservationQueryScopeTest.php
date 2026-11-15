<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\db\ReservationQuery;
use anvildev\booked\services\PermissionService;
use anvildev\booked\tests\Support\TestCase;

/**
 * Staff scoping for the native bookings index lives in the element query.
 *
 * Craft builds that query inside its own controller, so a filter applied by
 * our controller never runs for it. Seat counts and availability use the same
 * query, and scoping those would hide other employees' bookings.
 */
class ReservationQueryScopeTest extends TestCase
{
    private function scopeMethodSource(): string
    {
        return $this->sourceOfMethod(ReservationQuery::class, '_applyStaffScope');
    }

    public function testTheQueryAppliesStaffScope(): void
    {
        $this->assertStringContainsString(
            'scopeReservationQuery',
            $this->scopeMethodSource(),
            'ReservationQuery must scope staff itself — Craft builds the index query, not our controller',
        );
    }

    /**
     * Applying it after beforePrepare() has returned would mean it never
     * reaches the query at all.
     */
    public function testTheScopeIsAppliedWhilePreparingTheQuery(): void
    {
        $source = $this->sourceOfMethod(ReservationQuery::class, 'beforePrepare');
        $this->assertStringContainsString(
            '$this->_applyStaffScope(',
            $source,
            'beforePrepare() should apply the staff scope',
        );
    }

    /**
     * An empty managed-employee list means "nothing", stated outright. Left to
     * an empty IN() it is driver-dependent, and the failure mode is showing
     * everything rather than nothing. This guarantee lives in
     * PermissionService::scopeReservationQuery(), which ReservationQuery
     * delegates to rather than re-deriving the same condition itself.
     */
    public function testAnEmptyManagedListMatchesNothing(): void
    {
        $this->assertStringContainsString(
            "'0=1'",
            $this->sourceOfMethod(PermissionService::class, 'scopeReservationQuery'),
        );
    }

    /**
     * Console and queue runs have no user session, and asking for one there is
     * a fatal rather than an empty result.
     */
    public function testTheScopeIsSkippedOutsideWebRequests(): void
    {
        $this->assertStringContainsString(
            'WebApplication',
            $this->scopeMethodSource(),
            'The scope must not try to read a user session in console or queue runs',
        );
    }

    /**
     * Craft's own element-indexes controller only requires CP access, not any
     * Booked permission — unlike BookingsController, which this query bypasses.
     * Without an explicit check here, a CP user with no Booked access at all
     * would inherit getStaffEmployeeIds()'s "null means full access", which
     * was written assuming a permission check had already happened upstream.
     */
    public function testUsersWithoutViewBookingsSeeNothing(): void
    {
        $source = $this->scopeMethodSource();
        $this->assertStringContainsString("can('booked-viewBookings')", $source);
        $this->assertStringContainsString("'0=1'", $source);
    }

    /**
     * Availability and seat counts use this query too. Scoping every request
     * would hide bookings held by other employees and allow a double booking.
     */
    public function testTheScopeIsLimitedToTheElementIndex(): void
    {
        $this->assertStringContainsString(
            "'element-indexes'",
            $this->scopeMethodSource(),
            'Only Craft element-index requests may apply the staff scope',
        );
    }

    /**
     * The controller must not be the only thing enforcing this. It may still
     * scope explicitly — that is harmless — but the index does not go through it.
     */
    public function testTheIndexActionDoesNotRelyOnControllerScoping(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 3) . '/src/controllers/cp/BookingsController.php');

        $start = strpos($controller, 'public function actionIndex(): Response');
        $this->assertNotFalse($start);

        $end = strpos($controller, "\n    public function ", $start + 1);
        $body = substr($controller, $start, $end !== false ? $end - $start : null);

        $this->assertStringNotContainsString(
            'scopeReservationQuery',
            $body,
            'The index renders an element index; scoping it here would give false confidence',
        );
    }
}
