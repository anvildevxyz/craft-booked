<?php

namespace anvildev\booked\tests\Unit\Elements;

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
    private function querySource(): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/src/elements/db/ReservationQuery.php');
    }

    public function testTheQueryAppliesStaffScope(): void
    {
        $this->assertStringContainsString(
            'getStaffEmployeeIds()',
            $this->querySource(),
            'ReservationQuery must scope staff itself — Craft builds the index query, not our controller',
        );
    }

    /**
     * Applying it after beforePrepare() has returned would mean it never
     * reaches the query at all.
     */
    public function testTheScopeIsAppliedWhilePreparingTheQuery(): void
    {
        $source = $this->querySource();

        $prepare = strpos($source, 'protected function beforePrepare()');
        $call = strpos($source, '$this->_applyStaffScope(');

        $this->assertNotFalse($prepare);
        $this->assertNotFalse($call, 'beforePrepare() should apply the staff scope');
        $this->assertGreaterThan($prepare, $call);
    }

    /**
     * An empty managed-employee list means "nothing", stated outright. Left to
     * an empty IN() it is driver-dependent, and the failure mode is showing
     * everything rather than nothing.
     */
    public function testAnEmptyManagedListMatchesNothing(): void
    {
        $this->assertStringContainsString("'0=1'", $this->querySource());
    }

    /**
     * Console and queue runs have no user session, and asking for one there is
     * a fatal rather than an empty result.
     */
    public function testTheScopeIsSkippedOutsideWebRequests(): void
    {
        $this->assertStringContainsString(
            'WebApplication',
            $this->querySource(),
            'The scope must not try to read a user session in console or queue runs',
        );
    }

    /**
     * Availability and seat counts use this query too. Scoping every request
     * would hide bookings held by other employees and allow a double booking.
     */
    public function testTheScopeIsLimitedToTheElementIndex(): void
    {
        $this->assertStringContainsString(
            "'element-indexes'",
            $this->querySource(),
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
