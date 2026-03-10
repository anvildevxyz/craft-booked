<?php

namespace anvildev\booked\tests\Unit\GraphQL;

use anvildev\booked\tests\Support\TestCase;

class ReservationResolverTest extends TestCase
{
    public function testResolverScopesQueriesForNonAdminUsers(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/ReservationResolver.php'
        );
        $this->assertStringContainsString(
            'getPermission()->scopeReservationQuery',
            $source,
            'ReservationResolver must scope queries using PermissionService for non-admin users'
        );
    }

    public function testResolverBlocksUnauthenticatedAccess(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/ReservationResolver.php'
        );
        $this->assertStringContainsString(
            '$query->id(0)',
            $source,
            'ReservationResolver must return no results for unauthenticated users'
        );
    }

    public function testResolverUsesArgumentAllowlist(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/ReservationResolver.php'
        );
        $this->assertStringContainsString(
            'ALLOWED_QUERY_PARAMS',
            $source,
            'ReservationResolver must define an ALLOWED_QUERY_PARAMS allowlist'
        );
        $this->assertStringContainsString(
            'in_array($key, self::ALLOWED_QUERY_PARAMS, true)',
            $source,
            'ReservationResolver must check arguments against the allowlist before calling query methods'
        );
    }

    public function testAllowlistContainsReservationSpecificArguments(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/ReservationResolver.php'
        );
        $requiredArgs = ['bookingDate', 'serviceId', 'employeeId', 'locationId', 'userId'];
        foreach ($requiredArgs as $arg) {
            $this->assertStringContainsString(
                "'" . $arg . "'",
                $source,
                "ALLOWED_QUERY_PARAMS must include '{$arg}'"
            );
        }
    }

    public function testAllowlistContainsBaseElementArguments(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/elements/ReservationResolver.php'
        );
        $baseArgs = ['id', 'uid', 'limit', 'offset', 'orderBy', 'status', 'search', 'siteId'];
        foreach ($baseArgs as $arg) {
            $this->assertStringContainsString(
                "'" . $arg . "'",
                $source,
                "ALLOWED_QUERY_PARAMS must include base element argument '{$arg}'"
            );
        }
    }
}
