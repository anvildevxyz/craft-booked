<?php

namespace anvildev\booked\tests\Unit\GraphQL;

use anvildev\booked\tests\Support\TestCase;

/**
 * Tests GraphQL query arguments, mutation resolvers, and argument/resolver consistency.
 */
class GraphQLQueryMutationTest extends TestCase
{
    // =========================================================================
    // Query Arguments — Verify arguments match resolver ALLOWED_QUERY_PARAMS
    // =========================================================================

    public function testServiceArgumentsMatchResolver(): void
    {
        $argsSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/arguments/elements/ServiceArguments.php'
        );
        $resolverSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/ServiceResolver.php'
        );

        // Each custom argument must be in both arguments and resolver
        foreach (['duration', 'price', 'locationId'] as $arg) {
            $this->assertStringContainsString("'{$arg}'", $argsSource, "ServiceArguments must define '{$arg}'");
            $this->assertStringContainsString("'{$arg}'", $resolverSource, "ServiceResolver must allow '{$arg}'");
        }

        // Must NOT expose arguments without backing query methods
        $this->assertStringNotContainsString("'maxCapacity'", $argsSource, 'maxCapacity has no backing query method');
        $this->assertStringNotContainsString("'requiresEmployee'", $argsSource, 'requiresEmployee has no backing query method');
    }

    public function testServiceArgumentsHaveBackingQueryMethods(): void
    {
        $querySource = file_get_contents(
            dirname(__DIR__, 3) . '/src/elements/db/ServiceQuery.php'
        );

        foreach (['duration', 'price', 'locationId'] as $method) {
            $this->assertStringContainsString(
                "function {$method}(",
                $querySource,
                "ServiceQuery must have {$method}() method for GQL argument"
            );
        }
    }

    public function testEmployeeArgumentsMatchResolver(): void
    {
        $argsSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/arguments/elements/EmployeeArguments.php'
        );
        $resolverSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/EmployeeResolver.php'
        );

        foreach (['userId', 'locationId', 'serviceId'] as $arg) {
            $this->assertStringContainsString("'{$arg}'", $argsSource, "EmployeeArguments must define '{$arg}'");
            $this->assertStringContainsString("'{$arg}'", $resolverSource, "EmployeeResolver must allow '{$arg}'");
        }

        // email has no backing query method — must not be exposed
        $this->assertStringNotContainsString("'email'", $argsSource, 'email has no backing query method on EmployeeQuery');
    }

    public function testEmployeeArgumentsHaveBackingQueryMethods(): void
    {
        $querySource = file_get_contents(
            dirname(__DIR__, 3) . '/src/elements/db/EmployeeQuery.php'
        );

        foreach (['userId', 'locationId', 'serviceId'] as $method) {
            $this->assertStringContainsString(
                "function {$method}(",
                $querySource,
                "EmployeeQuery must have {$method}() method for GQL argument"
            );
        }
    }

    public function testLocationArgumentsMatchResolver(): void
    {
        $argsSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/arguments/elements/LocationArguments.php'
        );
        $resolverSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/LocationResolver.php'
        );

        $this->assertStringContainsString("'timezone'", $argsSource, "LocationArguments must define 'timezone'");
        $this->assertStringContainsString("'timezone'", $resolverSource);
    }

    public function testEventDateArgumentsMatchResolver(): void
    {
        $argsSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/arguments/elements/EventDateArguments.php'
        );
        $resolverSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/EventDateResolver.php'
        );

        foreach (['locationId', 'eventDate', 'endDate', 'startTime', 'endTime', 'enabled'] as $arg) {
            $this->assertStringContainsString("'{$arg}'", $argsSource, "EventDateArguments must define '{$arg}'");
            $this->assertStringContainsString("'{$arg}'", $resolverSource, "EventDateResolver must allow '{$arg}'");
        }
    }

    public function testReservationArgumentsIncludeFilters(): void
    {
        $argsSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/arguments/elements/ReservationArguments.php'
        );

        foreach (['bookingDate', 'endDate', 'status', 'serviceId', 'employeeId', 'locationId', 'userId'] as $arg) {
            $this->assertStringContainsString("'{$arg}'", $argsSource, "ReservationArguments must define '{$arg}'");
        }
    }

    public function testReservationResolverAllowlistIncludesReservationArgumentNames(): void
    {
        $resolverSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/ReservationResolver.php'
        );

        preg_match('/ALLOWED_QUERY_PARAMS = \[(.*?)\];/s', $resolverSource, $blockMatches);
        $this->assertNotEmpty($blockMatches[1] ?? null, 'Could not parse ALLOWED_QUERY_PARAMS block');
        $allowBlock = $blockMatches[1];
        foreach (['bookingDate', 'endDate', 'status', 'serviceId', 'employeeId', 'locationId', 'userId'] as $arg) {
            $this->assertStringContainsString(
                "'{$arg}'",
                $allowBlock,
                "ReservationResolver allowlist must include '{$arg}' (ReservationArguments exposes it)"
            );
        }
    }

    // =========================================================================
    // Non-localized resolvers use siteId('*')
    // =========================================================================

    public function testEmployeeResolverUsesSiteIdStar(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/EmployeeResolver.php'
        );

        $this->assertStringContainsString(
            "Employee::find()->siteId('*')",
            $source,
            'EmployeeResolver must use siteId(\'*\') for non-localized element'
        );
    }

    public function testLocationResolverUsesSiteIdStar(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/LocationResolver.php'
        );

        $this->assertStringContainsString(
            "Location::find()->siteId('*')",
            $source,
            'LocationResolver must use siteId(\'*\') for non-localized element'
        );
    }

    // =========================================================================
    // Mutation: createBookedReservation
    // =========================================================================

    public function testCreateMutationSanitizesInput(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        preg_match('/function resolveCreate\b.*?^    \}/ms', $source, $matches);
        $this->assertNotEmpty($matches);
        $method = $matches[0];

        $this->assertStringContainsString('sanitizeInput', $method, 'resolveCreate must sanitize input');
    }

    public function testCreateMutationEnforcesRateLimiting(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        preg_match('/function resolveCreate\b.*?^    \}/ms', $source, $matches);
        $method = $matches[0];

        $this->assertStringContainsString('checkAllRateLimits', $method, 'resolveCreate must check all rate limits');
        $this->assertStringContainsString('RATE_LIMITED', $method, 'resolveCreate must return RATE_LIMITED error code');
    }

    public function testCreateMutationPassesExtrasCorrectly(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        preg_match('/function resolveCreate\b.*?^    \}/ms', $source, $matches);
        $method = $matches[0];

        $this->assertStringContainsString('extraIds', $method, 'resolveCreate must handle extraIds');
        $this->assertStringContainsString('extraQuantities', $method, 'resolveCreate must handle extraQuantities');
        $this->assertStringContainsString("'extras' =>", $method, 'resolveCreate must pass extras to booking service');
    }

    public function testCreateMutationPassesEndDateAndNullableStartTime(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        preg_match('/function resolveCreate\b.*?^    \}/ms', $source, $matches);
        $method = $matches[0];

        $this->assertStringContainsString(
            "'endDate' => \$input['endDate'] ?? null",
            $method,
            'resolveCreate must pass endDate to createReservation for multi-day GraphQL bookings'
        );
        $this->assertStringContainsString(
            "'startTime' => \$input['startTime'] ?? null",
            $method,
            'resolveCreate must allow null startTime for multi-day GraphQL bookings'
        );
    }

    public function testCreateMutationSetsGraphqlSource(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        $this->assertStringContainsString(
            "'source' => 'graphql'",
            $source,
            'resolveCreate must set source to graphql for audit trail'
        );
    }

    // =========================================================================
    // Mutation: cancelBookedReservation
    // =========================================================================

    public function testCancelMutationRequiresToken(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        preg_match('/function resolveCancel\b.*?^    \}/ms', $source, $matches);
        $this->assertNotEmpty($matches);
        $method = $matches[0];

        $this->assertStringContainsString('hash_equals', $method, 'resolveCancel must use hash_equals for token validation');
        $this->assertStringContainsString('UNAUTHORIZED', $method, 'resolveCancel must return UNAUTHORIZED on bad token');
        $this->assertStringContainsString('logAuthFailure', $method, 'resolveCancel must log auth failure');
    }

    public function testCancelMutationPassesReasonToService(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        preg_match('/function resolveCancel\b.*?^    \}/ms', $source, $matches);
        $method = $matches[0];

        $this->assertStringContainsString(
            "cancelReservation(\$id, \$reason",
            $method,
            'resolveCancel must pass $reason to cancelReservation()'
        );
    }

    public function testCancelMutationChecksCanBeCancelled(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        preg_match('/function resolveCancel\b.*?^    \}/ms', $source, $matches);
        $method = $matches[0];

        $this->assertStringContainsString('canBeCancelled', $method, 'resolveCancel must check canBeCancelled()');
        $this->assertStringContainsString('CANCELLATION_NOT_ALLOWED', $method);
    }

    public function testCancelMutationReturnsUpdatedReservation(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        preg_match('/function resolveCancel\b.*?^    \}/ms', $source, $matches);
        $method = $matches[0];

        // After successful cancel, should re-fetch to get updated status
        $this->assertStringContainsString(
            'ReservationFactory::findById($id)',
            $method,
            'resolveCancel must re-fetch reservation after cancellation to return updated status'
        );
    }

    // =========================================================================
    // Mutation: updateBookedReservation
    // =========================================================================

    public function testUpdateMutationRequiresToken(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        preg_match('/function resolveUpdate\b.*?^    \}/ms', $source, $matches);
        $this->assertNotEmpty($matches);
        $method = $matches[0];

        $this->assertStringContainsString('hash_equals', $method);
        $this->assertStringContainsString('UNAUTHORIZED', $method);
        $this->assertStringContainsString('logAuthFailure', $method);
    }

    public function testUpdateMutationOnlyAllowsContactFields(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        preg_match('/function resolveUpdate\b.*?^    \}/ms', $source, $matches);
        $method = $matches[0];

        // Should only iterate over safe fields
        $this->assertStringContainsString("'userName', 'userEmail', 'userPhone', 'notes'", $method);
    }

    public function testUpdateMutationSanitizesInput(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        preg_match('/function resolveUpdate\b.*?^    \}/ms', $source, $matches);
        $method = $matches[0];

        $this->assertStringContainsString('sanitizeInput', $method, 'resolveUpdate must sanitize input');
    }

    // =========================================================================
    // Mutation: reduceBookedReservationQuantity / increaseBookedReservationQuantity
    // =========================================================================

    public function testReduceQuantityMutationRequiresToken(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/QuantityMutations.php'
        );

        preg_match('/function resolveReduce\b.*?^    \}/ms', $source, $matches);
        $this->assertNotEmpty($matches);
        $method = $matches[0];

        $this->assertStringContainsString('hash_equals', $method);
        $this->assertStringContainsString('UNAUTHORIZED', $method);
    }

    public function testReduceQuantityValidatesMinimum(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/QuantityMutations.php'
        );

        preg_match('/function resolveReduce\b.*?^    \}/ms', $source, $matches);
        $method = $matches[0];

        $this->assertStringContainsString('$reduceBy < 1', $method, 'Must validate reduceBy >= 1');
        $this->assertStringContainsString('min($reduceBy, 10000)', $method, 'Must cap reduceBy at 10000');
    }

    public function testIncreaseQuantityMutationRequiresToken(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/QuantityMutations.php'
        );

        preg_match('/function resolveIncrease\b.*?^    \}/ms', $source, $matches);
        $this->assertNotEmpty($matches);
        $method = $matches[0];

        $this->assertStringContainsString('hash_equals', $method);
        $this->assertStringContainsString('UNAUTHORIZED', $method);
    }

    public function testIncreaseQuantityValidatesMinimum(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/QuantityMutations.php'
        );

        preg_match('/function resolveIncrease\b.*?^    \}/ms', $source, $matches);
        $method = $matches[0];

        $this->assertStringContainsString('$increaseBy < 1', $method, 'Must validate increaseBy >= 1');
        $this->assertStringContainsString('min($increaseBy, 10000)', $method, 'Must cap increaseBy at 10000');
    }

    // =========================================================================
    // Invalid/missing tokens — verify proper error codes
    // =========================================================================

    public function testAllMutationsReturnNotFoundForMissingReservation(): void
    {
        $reservationSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );
        $quantitySource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/QuantityMutations.php'
        );

        // All mutations must return NOT_FOUND when reservation doesn't exist
        foreach (['resolveUpdate', 'resolveCancel'] as $method) {
            preg_match("/function {$method}\b.*?^    \}/ms", $reservationSource, $matches);
            $this->assertStringContainsString('NOT_FOUND', $matches[0], "{$method} must return NOT_FOUND error code");
        }

        foreach (['resolveReduce', 'resolveIncrease'] as $method) {
            preg_match("/function {$method}\b.*?^    \}/ms", $quantitySource, $matches);
            $this->assertStringContainsString('NOT_FOUND', $matches[0], "{$method} must return NOT_FOUND error code");
        }
    }

    public function testAllMutationsReturnUnauthorizedForBadToken(): void
    {
        $reservationSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );
        $quantitySource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/QuantityMutations.php'
        );

        foreach (['resolveUpdate', 'resolveCancel'] as $method) {
            preg_match("/function {$method}\b.*?^    \}/ms", $reservationSource, $matches);
            $this->assertStringContainsString('UNAUTHORIZED', $matches[0], "{$method} must return UNAUTHORIZED for bad token");
        }

        foreach (['resolveReduce', 'resolveIncrease'] as $method) {
            preg_match("/function {$method}\b.*?^    \}/ms", $quantitySource, $matches);
            $this->assertStringContainsString('UNAUTHORIZED', $matches[0], "{$method} must return UNAUTHORIZED for bad token");
        }
    }

    public function testAllMutationsLogAuthFailureOnBadToken(): void
    {
        $reservationSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );
        $quantitySource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/QuantityMutations.php'
        );

        foreach (['resolveUpdate', 'resolveCancel'] as $method) {
            preg_match("/function {$method}\b.*?^    \}/ms", $reservationSource, $matches);
            $this->assertStringContainsString('logAuthFailure', $matches[0], "{$method} must log auth failure");
        }

        foreach (['resolveReduce', 'resolveIncrease'] as $method) {
            preg_match("/function {$method}\b.*?^    \}/ms", $quantitySource, $matches);
            $this->assertStringContainsString('logAuthFailure', $matches[0], "{$method} must log auth failure");
        }
    }

    public function testAllMutationsCatchGenericExceptions(): void
    {
        $reservationSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );
        $quantitySource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/QuantityMutations.php'
        );

        foreach (['resolveCreate', 'resolveUpdate', 'resolveCancel'] as $method) {
            preg_match("/function {$method}\b.*?^    \}/ms", $reservationSource, $matches);
            $this->assertStringContainsString('INTERNAL_ERROR', $matches[0], "{$method} must return INTERNAL_ERROR on unexpected exceptions");
        }

        foreach (['resolveReduce', 'resolveIncrease'] as $method) {
            preg_match("/function {$method}\b.*?^    \}/ms", $quantitySource, $matches);
            $this->assertStringContainsString('INTERNAL_ERROR', $matches[0], "{$method} must return INTERNAL_ERROR on unexpected exceptions");
        }
    }

    // =========================================================================
    // ReportSummary query
    // =========================================================================

    public function testReportSummaryValidatesDates(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/ReportSummaryResolver.php'
        );

        $this->assertStringContainsString('preg_match', $source, 'Must validate date format');
        $this->assertStringContainsString('createFromFormat', $source, 'Must validate calendar dates');
        $this->assertStringContainsString('P365D', $source, 'Must cap date range to 365 days');
    }

    public function testReportSummaryReturnsEmptyForUnauthenticated(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/ReportSummaryResolver.php'
        );

        $this->assertStringContainsString(
            'emptyResult',
            $source,
            'Must return empty result for unauthenticated users'
        );
    }

    public function testReportSummaryImplementsStaffScoping(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/ReportSummaryResolver.php'
        );

        $this->assertStringContainsString('getStaffEmployeeIds', $source, 'Must scope to staff employees');
        $this->assertStringContainsString('$user->admin', $source, 'Must check admin flag');
    }

    public function testReportSummaryReturnFieldsMatchType(): void
    {
        $resolverSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/ReportSummaryResolver.php'
        );
        $typeSource = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/types/ReportSummaryType.php'
        );

        $expectedFields = [
            'totalBookings', 'confirmedBookings', 'cancelledBookings',
            'cancellationRate', 'totalRevenue', 'averageBookingValue',
            'newCustomers', 'returningCustomers', 'startDate', 'endDate',
        ];

        foreach ($expectedFields as $field) {
            $this->assertStringContainsString(
                "'{$field}'",
                $resolverSource,
                "Resolver must return '{$field}'"
            );
            $this->assertStringContainsString(
                "'{$field}'",
                $typeSource,
                "ReportSummaryType must define '{$field}'"
            );
        }
    }

    // =========================================================================
    // Error sanitization
    // =========================================================================

    public function testMutationErrorMessagesSanitized(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/mutations/ReservationMutations.php'
        );

        $this->assertStringContainsString(
            'sanitizeErrorMessage',
            $source,
            'Must have error message sanitization'
        );

        // Verify SQL patterns are filtered
        $this->assertStringContainsString('SQLSTATE', $source, 'Must filter SQL errors');
        $this->assertStringContainsString('.php', $source, 'Must filter file paths');
    }

    // =========================================================================
    // Input type validation
    // =========================================================================

    public function testCreateReservationInputHasRequiredFields(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/types/input/CreateReservationInput.php'
        );

        foreach (['serviceId', 'bookingDate', 'startTime', 'endDate', 'userName', 'userEmail'] as $field) {
            $this->assertStringContainsString(
                "'{$field}'",
                $source,
                "CreateReservationInput must define '{$field}'"
            );
        }
    }

    public function testUpdateReservationInputHasFields(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/types/input/UpdateReservationInput.php'
        );

        foreach (['userName', 'userEmail', 'userPhone', 'notes'] as $field) {
            $this->assertStringContainsString(
                "'{$field}'",
                $source,
                "UpdateReservationInput must define '{$field}'"
            );
        }
    }

    // =========================================================================
    // Query limit cap
    // =========================================================================

    public function testAllResolversCapQueryLimit(): void
    {
        $resolverDir = dirname(__DIR__, 3) . '/src/gql/resolvers/elements/';

        foreach (['ServiceResolver', 'EmployeeResolver', 'LocationResolver', 'EventDateResolver', 'ReservationResolver'] as $resolver) {
            $source = file_get_contents($resolverDir . $resolver . '.php');
            $this->assertStringContainsString(
                'limit(100)',
                $source,
                "{$resolver} must cap query limit to 100"
            );
        }
    }
}
