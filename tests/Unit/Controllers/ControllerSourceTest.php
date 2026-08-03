<?php

namespace anvildev\booked\tests\Unit\Controllers;

use anvildev\booked\tests\Support\TestCase;

class ControllerSourceTest extends TestCase
{
    private string $bookedSource;
    private string $srcDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->srcDir = dirname(__DIR__, 3) . '/src';
        $this->bookedSource = file_get_contents($this->srcDir . '/Booked.php');
    }

    private function controllerSource(string $relativePath): string
    {
        return file_get_contents($this->srcDir . '/controllers/' . $relativePath);
    }

    /**
     * @dataProvider siteRouteProvider
     */
    public function testSiteRouteExists(string $route, string $action): void
    {
        $this->assertStringContainsString(
            $route,
            $this->bookedSource,
            "Site route '{$route}' should be registered in Booked.php"
        );
        $this->assertStringContainsString(
            $action,
            $this->bookedSource,
            "Site route should map to action '{$action}'"
        );
    }

    public static function siteRouteProvider(): array
    {
        return [
            'manage booking' => ['booking/manage/<token:', 'booked/booking-management/manage-booking'],
            'cancel by token' => ['booking/cancel/<token:', 'booked/booking-management/cancel-booking-by-token'],
            'download ics' => ['booking/ics/<token:', 'booked/booking-management/download-ics'],
            'my bookings' => ['account/bookings', 'booked/booking-management/my-bookings'],
            'employee schedule' => ['employee/schedule', 'booked/employee-schedule/index'],
            'employee schedule with id' => ['employee/schedule/<employeeId:', 'booked/employee-schedule/index'],
            'calendar connect' => ['booked/calendar/connect', 'booked/calendar-connect/connect'],
            'calendar callback' => ['booked/calendar/frontend-callback', 'booked/calendar-connect/callback'],
            'calendar success' => ['booked/calendar/success', 'booked/calendar-connect/success'],
            'calendar error' => ['booked/calendar/error', 'booked/calendar-connect/error'],
            'account index' => ["'booked/account'", 'booked/account/index'],
            'account bookings' => ['booked/account/bookings', 'booked/account/bookings'],
            'account upcoming' => ['booked/account/upcoming', 'booked/account/upcoming'],
            'account past' => ['booked/account/past', 'booked/account/past'],
            'account view' => ['booked/account/<id:', 'booked/account/view'],
        ];
    }

    /**
     * @dataProvider cpRouteProvider
     */
    public function testCpRouteExists(string $route, string $action): void
    {
        $this->assertStringContainsString(
            $route,
            $this->bookedSource,
            "CP route '{$route}' should be registered"
        );
        $this->assertStringContainsString(
            $action,
            $this->bookedSource,
            "CP route should map to action '{$action}'"
        );
    }

    public static function cpRouteProvider(): array
    {
        return [
            'dashboard default' => ["'booked'", 'booked/cp/dashboard/index'],
            'dashboard' => ['booked/dashboard', 'booked/cp/dashboard/index'],
            'calendar month' => ['booked/calendar-view/month', 'booked/cp/calendar-view/month'],
            'calendar week' => ['booked/calendar-view/week', 'booked/cp/calendar-view/week'],
            'calendar day' => ['booked/calendar-view/day', 'booked/cp/calendar-view/day'],
            'calendar reschedule' => ['booked/calendar-view/reschedule', 'booked/cp/calendar-view/reschedule'],
            'reports index' => ["'booked/reports'", 'booked/cp/reports/index'],
            'reports revenue' => ['booked/reports/revenue', 'booked/cp/reports/revenue'],
            'reports by service' => ['booked/reports/by-service', 'booked/cp/reports/by-service'],
            'reports by employee' => ['booked/reports/by-employee', 'booked/cp/reports/by-employee'],
            'reports cancellations' => ['booked/reports/cancellations', 'booked/cp/reports/cancellations'],
            'reports peak hours' => ['booked/reports/peak-hours', 'booked/cp/reports/peak-hours'],
            'reports utilization' => ['booked/reports/utilization', 'booked/cp/reports/utilization'],
            'reports by location' => ['booked/reports/by-location', 'booked/cp/reports/by-location'],
            'reports day of week' => ['booked/reports/day-of-week', 'booked/cp/reports/day-of-week'],
            'reports waitlist' => ['booked/reports/waitlist', 'booked/cp/reports/waitlist'],
            'reports event attendance' => ['booked/reports/event-attendance', 'booked/cp/reports/event-attendance'],
            'services index' => ["'booked/services'", 'booked/cp/services/index'],
            'services new' => ['booked/services/new', 'booked/cp/services/edit'],
            'employees index' => ["'booked/employees'", 'booked/cp/employees/index'],
            'employees new' => ['booked/employees/new', 'booked/cp/employees/edit'],
            'schedules index' => ["'booked/schedules'", 'booked/cp/schedules/index'],
            'locations index' => ["'booked/locations'", 'booked/cp/locations/index'],
            'blackout dates index' => ["'booked/blackout-dates'", 'booked/cp/blackout-dates/index'],
            'event dates index' => ['booked/cp/event-dates', 'booked/cp/event-dates/index'],
            'service extras index' => ["'booked/service-extras'", 'booked/cp/service-extra/index'],
            'bookings index' => ["'booked/bookings'", 'booked/cp/bookings/index'],
            'bookings new' => ['booked/bookings/new', 'booked/cp/bookings/edit'],
            'bookings export' => ['booked/bookings/export', 'booked/cp/bookings/export'],
            'settings default' => ["'booked/settings'", 'booked/cp/settings/booking'],
            'settings booking' => ['booked/settings/booking', 'booked/cp/settings/booking'],
            'settings security' => ['booked/settings/security', 'booked/cp/settings/security'],
            'settings notifications' => ['booked/settings/notifications', 'booked/cp/settings/notifications'],
            'settings sms' => ['booked/settings/sms', 'booked/cp/settings/sms'],
            'settings calendar' => ['booked/settings/calendar', 'booked/cp/settings/calendar'],
            'settings meetings' => ['booked/settings/meetings', 'booked/cp/settings/meetings'],
            'settings commerce' => ['booked/settings/commerce', 'booked/cp/settings/commerce'],
            'settings webhooks' => ['booked/settings/webhooks', 'booked/cp/settings/webhooks'],
            'waitlist index' => ["'booked/waitlist'", 'booked/cp/waitlist/index'],
            'webhooks index' => ["'booked/webhooks'", 'booked/cp/webhooks/index'],
            'webhooks new' => ['booked/webhooks/new', 'booked/cp/webhooks/edit'],
            'cp calendar connect' => ['booked/calendar/connect', 'booked/cp/calendar/connect'],
            'cp calendar callback' => ['booked/calendar/callback', 'booked/cp/calendar/callback'],
        ];
    }

    /**
     * @dataProvider allowAnonymousProvider
     */
    public function testAllowAnonymousActions(string $controllerFile, array $expectedActions): void
    {
        $source = $this->controllerSource($controllerFile);

        // Compare the whole declared set, not just "are the expected ones
        // present". This is the list of actions reachable with no authentication
        // at all, so an *extra* entry matters as much as a missing one — and the
        // old containment check would have passed with any number of them.
        $this->assertSame(
            1,
            preg_match('/\$allowAnonymous\s*=\s*(\[[^\]]*\])/', $source, $m),
            "{$controllerFile} should declare \$allowAnonymous",
        );

        preg_match_all("/'([a-z0-9\-]+)'/i", $m[1], $found);
        $declared = $found[1];
        sort($declared);
        sort($expectedActions);

        $this->assertSame(
            $expectedActions,
            $declared,
            "{$controllerFile} allows anonymous access to a different set of actions than expected",
        );
    }

    public static function allowAnonymousProvider(): array
    {
        return [
            'BookingController' => ['BookingController.php', ['create-booking']],
            // Availability and soft-lock endpoints the public booking wizard
            // calls before anyone has identified themselves.
            'SlotController' => ['SlotController.php', [
                'get-available-slots', 'get-availability-calendar', 'get-available-dates',
                'get-valid-end-dates', 'get-event-dates', 'get-range-capacity',
                'create-lock', 'create-event-lock', 'create-multi-day-lock',
                'extend-lock', 'release-lock',
            ]],
            'WaitlistController' => ['WaitlistController.php', ['join-waitlist', 'join-event-waitlist']],
            'BookingDataController' => ['BookingDataController.php', [
                'get-services', 'get-service-extras', 'get-employees', 'get-commerce-settings',
            ]],
            // Reached from the link in a confirmation email, so the visitor is
            // not logged in. Each one authenticates on the reservation's
            // confirmation token with hash_equals() and audits a failure.
            'BookingManagementController' => ['BookingManagementController.php', [
                'manage-booking', 'cancel-booking-by-token', 'download-ics',
                'increase-quantity', 'reduce-quantity',
            ]],
            'CalendarConnectController' => ['CalendarConnectController.php', [
                'connect', 'callback', 'success', 'error',
            ]],
            // current-user is deliberately anonymous: it answers
            // {loggedIn: false} for a guest and only ever returns the requesting
            // user's own record, so the wizard can prefill without a 403.
            'AccountController' => ['AccountController.php', ['current-user']],
        ];
    }

    /**
     * @dataProvider cpPermissionProvider
     */
    public function testCpControllerRequiresPermission(string $controllerFile, string $permission): void
    {
        $source = $this->controllerSource('cp/' . $controllerFile);
        $this->assertStringContainsString(
            "'{$permission}'",
            $source,
            "{$controllerFile} should require permission '{$permission}'"
        );
    }

    public static function cpPermissionProvider(): array
    {
        return [
            'DashboardController' => ['DashboardController.php', 'booked-accessPlugin'],
            'CalendarViewController' => ['CalendarViewController.php', 'booked-viewCalendar'],
            'ReportsController' => ['ReportsController.php', 'booked-viewReports'],
            'BookingsController view' => ['BookingsController.php', 'booked-viewBookings'],
            'BookingsController manage' => ['BookingsController.php', 'booked-manageBookings'],
            'ServicesController' => ['ServicesController.php', 'booked-manageServices'],
            'ServiceExtraController' => ['ServiceExtraController.php', 'booked-manageServices'],
            'EmployeesController' => ['EmployeesController.php', 'booked-manageEmployees'],
            'SchedulesController' => ['SchedulesController.php', 'booked-manageEmployees'],
            'CalendarController' => ['CalendarController.php', 'booked-manageEmployees'],
            'LocationsController' => ['LocationsController.php', 'booked-manageLocations'],
            'BlackoutDatesController' => ['BlackoutDatesController.php', 'booked-manageBlackoutDates'],
            'EventDatesController' => ['EventDatesController.php', 'booked-manageEvents'],
            'WaitlistController' => ['WaitlistController.php', 'booked-manageWaitlist'],
            'SettingsController' => ['SettingsController.php', 'booked-manageSettings'],
            'WebhooksController' => ['WebhooksController.php', 'booked-manageSettings'],
        ];
    }

    public function testBookingsControllerHasDynamicPermissions(): void
    {
        $source = $this->controllerSource('cp/BookingsController.php');
        $this->assertStringContainsString("'index', 'view', 'edit', 'export'", $source);
        $this->assertStringContainsString('booked-viewBookings', $source);
        $this->assertStringContainsString('booked-manageBookings', $source);
    }

    public function testCalendarViewControllerHasDynamicPermissions(): void
    {
        $source = $this->controllerSource('cp/CalendarViewController.php');
        $this->assertStringContainsString('reschedule', $source);
        $this->assertStringContainsString('booked-manageBookings', $source);
        $this->assertStringContainsString('booked-viewCalendar', $source);
    }

    public function testCalendarViewControllerValidatesDateParams(): void
    {
        $source = $this->controllerSource('cp/CalendarViewController.php');
        $this->assertStringContainsString('preg_match', $source,
            'CalendarViewController must validate date params with regex');
        $this->assertStringContainsString('max(2000', $source,
            'CalendarViewController actionMonth must clamp year range');
    }

    public function testWebhooksControllerEnforcesHttpsScheme(): void
    {
        $source = $this->controllerSource('cp/WebhooksController.php');
        $this->assertStringContainsString("'https://'", $source,
            'WebhooksController must enforce https:// scheme on webhook URLs');
    }

    /**
     * @dataProvider loginRequiredProvider
     */
    public function testControllerRequiresLogin(string $controllerFile): void
    {
        $source = $this->controllerSource($controllerFile);
        $this->assertStringContainsString(
            'requireLogin()',
            $source,
            "{$controllerFile} should call requireLogin()"
        );
    }

    public static function loginRequiredProvider(): array
    {
        return [
            'AccountController' => ['AccountController.php'],
            'EmployeeScheduleController' => ['EmployeeScheduleController.php'],
        ];
    }
}
