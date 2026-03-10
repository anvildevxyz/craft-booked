<?php

namespace anvildev\booked\tests\Unit\GraphQL;

use anvildev\booked\gql\resolvers\ReportSummaryResolver;
use anvildev\booked\tests\Support\TestCase;

class ReportSummaryResolverTest extends TestCase
{
    public function testDateValidationRejectsInvalidCalendarDates(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/ReportSummaryResolver.php'
        );
        $this->assertStringContainsString(
            'createFromFormat',
            $source,
            'ReportSummaryResolver must use createFromFormat for strict date validation'
        );
    }

    public function testResolverChecksUserAuthentication(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/ReportSummaryResolver.php'
        );
        $this->assertStringContainsString(
            'getUser()->getIdentity()',
            $source,
            'ReportSummaryResolver must check the current user identity for staff scoping'
        );
    }

    public function testResolverReturnsEmptyResultForUnauthenticatedUsers(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/ReportSummaryResolver.php'
        );
        $this->assertStringContainsString(
            'emptyResult',
            $source,
            'ReportSummaryResolver must return empty result when user is not authenticated'
        );
    }

    public function testResolverCallsGetStaffEmployeeIds(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/ReportSummaryResolver.php'
        );
        $this->assertStringContainsString(
            'getStaffEmployeeIds()',
            $source,
            'ReportSummaryResolver must call getStaffEmployeeIds() for staff scoping'
        );
    }

    public function testResolverScopesCustomerQueryByEmployeeId(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/ReportSummaryResolver.php'
        );
        $this->assertStringContainsString(
            "andWhere(['employeeId' => \$staffEmployeeIds])",
            $source,
            'ReportSummaryResolver must scope the customer counts query by employeeId for staff users'
        );
    }

    public function testResolverBypassesScopingForAdmins(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/gql/resolvers/ReportSummaryResolver.php'
        );
        $this->assertStringContainsString(
            '$user->admin',
            $source,
            'ReportSummaryResolver must check admin flag to bypass staff scoping'
        );
    }

    public function testEmptyResultContainsAllExpectedKeys(): void
    {
        $reflection = new \ReflectionMethod(ReportSummaryResolver::class, 'emptyResult');
        $reflection->setAccessible(true);

        $result = $reflection->invoke(null, '2025-01-01', '2025-01-31');

        $expectedKeys = [
            'totalBookings',
            'confirmedBookings',
            'cancelledBookings',
            'cancellationRate',
            'totalRevenue',
            'averageBookingValue',
            'newCustomers',
            'returningCustomers',
            'startDate',
            'endDate',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "emptyResult() must contain key '{$key}'");
        }
    }

    public function testEmptyResultReturnsZeroValues(): void
    {
        $reflection = new \ReflectionMethod(ReportSummaryResolver::class, 'emptyResult');
        $reflection->setAccessible(true);

        $result = $reflection->invoke(null, '2025-01-01', '2025-01-31');

        $this->assertSame(0, $result['totalBookings']);
        $this->assertSame(0, $result['confirmedBookings']);
        $this->assertSame(0, $result['cancelledBookings']);
        $this->assertSame(0.0, $result['cancellationRate']);
        $this->assertSame(0.0, $result['totalRevenue']);
        $this->assertSame(0.0, $result['averageBookingValue']);
        $this->assertSame(0, $result['newCustomers']);
        $this->assertSame(0, $result['returningCustomers']);
    }

    public function testEmptyResultPreservesDateRange(): void
    {
        $reflection = new \ReflectionMethod(ReportSummaryResolver::class, 'emptyResult');
        $reflection->setAccessible(true);

        $result = $reflection->invoke(null, '2025-03-01', '2025-03-31');

        $this->assertSame('2025-03-01', $result['startDate']);
        $this->assertSame('2025-03-31', $result['endDate']);
    }
}
