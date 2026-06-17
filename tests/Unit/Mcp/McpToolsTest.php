<?php

namespace anvildev\booked\tests\Unit\Mcp;

use anvildev\booked\mcp\AvailabilityTools;
use anvildev\booked\mcp\BlackoutDateTools;
use anvildev\booked\mcp\CatalogTools;
use anvildev\booked\mcp\EventDateTools;
use anvildev\booked\mcp\ReportTools;
use anvildev\booked\mcp\ReservationTools;
use anvildev\booked\mcp\ScheduleTools;
use anvildev\booked\mcp\ServiceExtraTools;
use anvildev\booked\mcp\ToolResponseTrait;
use anvildev\booked\mcp\WaitlistTools;
use anvildev\booked\tests\Support\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Verifies Booked's craft-mcp integration: the tool classes declare the
 * expected `#[McpTool]` set, write tools are flagged dangerous, and Booked.php
 * wires registration through the (guarded) EVENT_REGISTER_TOOLS event.
 *
 * The assertions read attribute arguments reflectively via
 * {@see \ReflectionAttribute::getArguments()}, which never instantiates the
 * attribute classes — so the suite passes whether or not the optional
 * stimmt/craft-mcp package is installed.
 */
class McpToolsTest extends TestCase
{
    private const MCP_TOOL_ATTR = 'Mcp\\Capability\\Attribute\\McpTool';
    private const MCP_META_ATTR = 'stimmt\\craft\\Mcp\\attributes\\McpToolMeta';

    /** @var array<class-string, list<string>> */
    private const EXPECTED_TOOLS = [
        CatalogTools::class => [
            'booked_list_services',
            'booked_get_service',
            'booked_create_service',
            'booked_list_employees',
            'booked_list_locations',
            'booked_update_service',
            'booked_get_location',
            'booked_create_location',
            'booked_update_location',
            'booked_get_employee',
            'booked_create_employee',
            'booked_update_employee',
        ],
        AvailabilityTools::class => [
            'booked_check_availability',
            'booked_next_available_date',
            'booked_availability_summary',
        ],
        ReservationTools::class => [
            'booked_list_reservations',
            'booked_get_reservation',
            'booked_booking_stats',
            'booked_create_booking',
            'booked_create_event_booking',
            'booked_cancel_reservation',
            'booked_update_reservation',
            'booked_reduce_reservation_quantity',
            'booked_increase_reservation_quantity',
            'booked_refund_reservation',
        ],
        EventDateTools::class => [
            'booked_list_event_dates',
            'booked_get_event_date',
            'booked_create_event_date',
            'booked_update_event_date',
            'booked_delete_event_date',
        ],
        ScheduleTools::class => [
            'booked_list_schedules',
            'booked_get_schedule',
            'booked_create_schedule',
            'booked_update_schedule',
            'booked_get_employee_schedules',
            'booked_set_employee_schedules',
        ],
        BlackoutDateTools::class => [
            'booked_list_blackout_dates',
            'booked_create_blackout_date',
            'booked_set_blackout_date_active',
            'booked_check_date_blacked_out',
        ],
        ServiceExtraTools::class => [
            'booked_list_service_extras',
            'booked_create_service_extra',
            'booked_get_service_extras',
            'booked_set_service_extras',
            'booked_get_service_locations',
            'booked_set_service_locations',
        ],
        ReportTools::class => [
            'booked_revenue_report',
            'booked_bookings_by_service',
            'booked_bookings_by_employee',
            'booked_bookings_by_location',
            'booked_cancellation_report',
            'booked_peak_hours_report',
            'booked_utilization_report',
            'booked_dashboard_summary',
        ],
        WaitlistTools::class => [
            'booked_list_waitlist',
            'booked_waitlist_stats',
            'booked_add_to_waitlist',
            'booked_add_to_event_waitlist',
            'booked_cancel_waitlist_entry',
            'booked_notify_waitlist_entry',
        ],
    ];

    /** @var list<string> Tools that mutate data and must be flagged dangerous. */
    private const DANGEROUS_TOOLS = [
        'booked_create_service',
        'booked_update_service',
        'booked_create_location',
        'booked_update_location',
        'booked_create_employee',
        'booked_update_employee',
        'booked_create_booking',
        'booked_create_event_booking',
        'booked_cancel_reservation',
        'booked_update_reservation',
        'booked_reduce_reservation_quantity',
        'booked_increase_reservation_quantity',
        'booked_refund_reservation',
        'booked_create_event_date',
        'booked_update_event_date',
        'booked_delete_event_date',
        'booked_create_schedule',
        'booked_update_schedule',
        'booked_set_employee_schedules',
        'booked_create_blackout_date',
        'booked_set_blackout_date_active',
        'booked_create_service_extra',
        'booked_set_service_extras',
        'booked_set_service_locations',
        'booked_add_to_waitlist',
        'booked_add_to_event_waitlist',
        'booked_cancel_waitlist_entry',
        'booked_notify_waitlist_entry',
    ];

    /**
     * @dataProvider toolClassProvider
     * @param class-string $class
     */
    public function testToolClassUsesResponseTrait(string $class): void
    {
        $this->assertContains(
            ToolResponseTrait::class,
            $this->traitNames($class),
            "{$class} should use ToolResponseTrait for uniform error handling",
        );
    }

    /**
     * @dataProvider toolClassProvider
     * @param class-string $class
     * @param list<string> $expectedNames
     */
    public function testToolClassDeclaresExpectedTools(string $class, array $expectedNames): void
    {
        $declared = $this->declaredToolNames($class);
        sort($declared);
        sort($expectedNames);

        $this->assertSame(
            $expectedNames,
            $declared,
            "{$class} should declare exactly the expected #[McpTool] names",
        );
    }

    public function testToolNamesAreGloballyUnique(): void
    {
        $all = [];
        foreach (array_keys(self::EXPECTED_TOOLS) as $class) {
            $all = array_merge($all, $this->declaredToolNames($class));
        }

        $this->assertSame(
            array_values(array_unique($all)),
            $all,
            'MCP tool names must be unique across all Booked tool classes',
        );
    }

    public function testWriteToolsAreFlaggedDangerous(): void
    {
        // The dangerous flag is read from source rather than via
        // ReflectionAttribute::getArguments(), because evaluating the McpToolMeta
        // arguments would resolve the `ToolCategory` enum that ships with the
        // (optional, here-absent) craft-mcp package.
        $dangerous = [];
        foreach (self::EXPECTED_TOOLS as $class => $names) {
            $source = file_get_contents((new ReflectionClass($class))->getFileName());
            foreach ($names as $tool) {
                if ($this->methodIsFlaggedDangerous($source, $tool)) {
                    $dangerous[] = $tool;
                }
            }
        }

        sort($dangerous);
        $expected = self::DANGEROUS_TOOLS;
        sort($expected);

        $this->assertSame(
            $expected,
            $dangerous,
            'Exactly the data-mutating tools must carry #[McpToolMeta(dangerous: true)]',
        );
    }

    /**
     * Whether the tool whose #[McpTool(name: $tool)] is declared in $source is
     * flagged dangerous, by inspecting the attribute block up to its method body.
     */
    private function methodIsFlaggedDangerous(string $source, string $tool): bool
    {
        $start = strpos($source, "name: '{$tool}'");
        if ($start === false) {
            return false;
        }
        $end = strpos($source, 'public function', $start);
        $block = substr($source, $start, $end === false ? null : $end - $start);

        return str_contains($block, 'dangerous: true');
    }

    public function testEveryToolAlsoDeclaresMetaCategory(): void
    {
        foreach (array_keys(self::EXPECTED_TOOLS) as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($this->toolName($method) === null) {
                    continue;
                }
                $this->assertNotEmpty(
                    $method->getAttributes(self::MCP_META_ATTR),
                    "{$class}::{$method->getName()} should also carry #[McpToolMeta]",
                );
            }
        }
    }

    public function testBookedRegistersToolsGuardedByClassExists(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Booked.php');

        $this->assertStringContainsString('registerMcpTools', $source);
        $this->assertStringContainsString('class_exists(\\stimmt\\craft\\Mcp\\Mcp::class)', $source);
        $this->assertStringContainsString('\\stimmt\\craft\\Mcp\\Mcp::EVENT_REGISTER_TOOLS', $source);

        foreach (array_keys(self::EXPECTED_TOOLS) as $class) {
            $this->assertStringContainsString(
                "\$event->addTool(\\{$class}::class, 'booked')",
                $source,
                "Booked.php should register {$class} with the 'booked' source",
            );
        }
    }

    public function testReservationPresenterHidesCapabilityTokens(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/support/Presenter.php');

        $this->assertStringNotContainsString(
            "'confirmationToken' =>",
            $src,
            'confirmationToken must never be serialised over MCP — it authenticates the public cancel/reschedule endpoints.',
        );
        $this->assertStringNotContainsString(
            "'virtualMeetingUrl' =>",
            $src,
            'virtualMeetingUrl must never be serialised over MCP — it is an unauthenticated meeting join link.',
        );
    }

    public function testBulkListToolsRedactCustomerPii(): void
    {
        $base = dirname(__DIR__, 3) . '/src/mcp';

        $this->assertStringContainsString(
            'redactPii: true',
            file_get_contents($base . '/ReservationTools.php'),
            'booked_list_reservations must redact PII so one call cannot exfiltrate the customer base.',
        );
        $this->assertStringContainsString(
            'redactPii: true',
            file_get_contents($base . '/WaitlistTools.php'),
            'booked_list_waitlist must redact PII in bulk output.',
        );
    }

    public function testGetReservationRedactsPii(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/ReservationTools.php');

        $this->assertMatchesRegularExpression(
            '/getReservationById\(\$id\);.*?Presenter::reservation\(\$reservation,\s*redactPii:\s*true\)/s',
            $src,
            'booked_get_reservation must redact PII; ids are enumerable so an un-redacted get defeats list redaction.',
        );
    }

    public function testListReservationsSingleBoundDateFilterUsesOperatorArray(): void
    {
        // The string form ">= {$date}" matches literally and finds nothing.
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/ReservationTools.php');

        $this->assertStringContainsString("bookingDate(['>=', \$fromDate])", $src);
        $this->assertStringContainsString("bookingDate(['<=', \$toDate])", $src);
        $this->assertStringNotContainsString('bookingDate(">= {$fromDate}")', $src);
        $this->assertStringNotContainsString('bookingDate("<= {$toDate}")', $src);
    }

    public function testUpdateReservationRejectsCancelStatus(): void
    {
        // status=cancelled here would skip the refund/capacity-release flow.
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/ReservationTools.php');

        $this->assertStringContainsString("if (\$status === 'cancelled') {", $src);
        $this->assertStringContainsString('booked_cancel_reservation', $src);
    }

    public function testListEventDatesIncludesDisabled(): void
    {
        // Admin assistant must see retired events to be able to re-enable them.
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/EventDateTools.php');

        $this->assertStringContainsString('enabledOnly: false', $src);
    }

    public function testRevenueReportSummarisesReservationsAsCount(): void
    {
        // getRevenueData returns raw reservation models (for the CP view); over MCP
        // those are opaque stubs + per-customer detail, so the tool must reduce them.
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/ReportTools.php');

        $this->assertStringContainsString("\$revenue['reservationCount']", $src);
        $this->assertStringContainsString("unset(\$revenue['reservations'])", $src);
    }

    public function testEventDateGetUpdateDeleteResolveDisabled(): void
    {
        // Listing disabled events is useless if get/update/delete can't then act
        // on them (getEventDateById is enabled-only by default).
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/EventDateTools.php');

        $this->assertSame(
            3,
            substr_count($src, 'includeDisabled: true'),
            'get/update/delete event-date tools must resolve disabled events.',
        );
    }

    public function testEmployeeServiceIdsAreValidated(): void
    {
        // serviceIds linking to bogus ids corrupts downstream availability/assignment.
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/CatalogTools.php');

        $this->assertStringContainsString('unknownServiceIds', $src);
        $this->assertSame(
            2,
            substr_count($src, '$this->unknownServiceIds('),
            'Both createEmployee and updateEmployee must validate serviceIds.',
        );
    }

    public function testRateLimiterUsesFixedWindowAndRecordsAfterSuccess(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/ToolResponseTrait.php');

        // Pinned expiry = fixed window (no TTL reset); mutex = atomic increment.
        $this->assertStringContainsString("'expiresAt'", $src);
        $this->assertStringContainsString('getMutex()', $src);
    }

    public function testGuardSanitisesNonBookedExceptionMessages(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/ToolResponseTrait.php');

        $this->assertStringContainsString(
            "str_starts_with(\$e::class, 'anvildev\\\\booked\\\\exceptions\\\\')",
            $src,
            'guard() must only pass through messages from Booked\'s own exceptions; others leak DB/internal detail.',
        );
        $this->assertStringContainsString('rateLimitReached', $src, 'A rate-limit check helper must exist.');
        $this->assertStringContainsString('recordRateLimitedCall', $src, 'A rate-limit record helper must exist.');
    }

    public function testNotificationToolsAreRateLimited(): void
    {
        $resv = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/ReservationTools.php');
        $wait = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/WaitlistTools.php');

        // All side-effecting reservation writes (create/cancel/update/quantity/refund)
        // and the three notifying waitlist tools (add x2 + notify) must be throttled.
        $this->assertGreaterThanOrEqual(3, substr_count($resv, 'rateLimitReached('));
        $this->assertGreaterThanOrEqual(3, substr_count($wait, 'rateLimitReached('));
    }

    public function testEmployeeToolsDoNotAcceptUserId(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/CatalogTools.php');

        // userId controls CP booking visibility — it must not be client-settable over MCP.
        $this->assertStringNotContainsString('$userId', $src, 'create/update_employee must not expose a userId param.');
        $this->assertStringNotContainsString('->userId =', $src, 'Employee.userId must not be written from MCP input.');
    }

    public function testJsonSafeGuardsRecursionAndDoesNotDumpArbitraryObjects(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/mcp/support/Presenter.php');

        $this->assertStringContainsString('$depth', $src, 'jsonSafe must carry a recursion-depth guard.');
        $this->assertStringContainsString("'_class' =>", $src, 'Unknown objects must collapse to an opaque class stub, not their internals.');
        $this->assertStringContainsString('instanceof \\stdClass', $src, 'Only plain stdClass should be expanded via get_object_vars.');
    }

    /**
     * @return array<string, array{class-string, list<string>}>
     */
    public static function toolClassProvider(): array
    {
        $cases = [];
        foreach (self::EXPECTED_TOOLS as $class => $names) {
            $cases[$class] = [$class, $names];
        }
        return $cases;
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function declaredToolNames(string $class): array
    {
        $names = [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $this->toolName($method);
            if ($name !== null) {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function toolName(ReflectionMethod $method): ?string
    {
        $attr = $method->getAttributes(self::MCP_TOOL_ATTR)[0] ?? null;
        return $attr?->getArguments()['name'] ?? null;
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function traitNames(string $class): array
    {
        $names = [];
        $ref = new ReflectionClass($class);
        while ($ref) {
            $names = array_merge($names, array_keys($ref->getTraits()));
            $ref = $ref->getParentClass();
        }
        return $names;
    }
}
