<?php

declare(strict_types=1);

namespace anvildev\booked\tests\Unit\GraphQL;

use anvildev\booked\gql\queries\ReportSummaryQuery;
use anvildev\booked\gql\types\ReportSummaryType;
use GraphQL\Type\Definition\FloatType;
use GraphQL\Type\Definition\IntType;
use GraphQL\Type\Definition\StringType;
use anvildev\booked\tests\Support\TestCase;

/**
 * Contract tests for the ReportSummary GraphQL layer.
 *
 * These tests verify that the public GQL contract (field names, argument
 * names, resolver reference) stays in sync with what the rest of the
 * codebase and any external clients depend on.
 */
class ReportSummaryContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresCraft();
    }
    // -------------------------------------------------------------------------
    // ReportSummaryType field definitions
    // -------------------------------------------------------------------------

    public function testGetFieldDefinitionsReturnsArray(): void
    {
        $fields = ReportSummaryType::getType()->getFields();

        $this->assertIsArray($fields);
        $this->assertNotEmpty($fields);
    }

    /**
     * @dataProvider expectedFieldProvider
     */
    public function testExpectedFieldExists(string $fieldName): void
    {
        $fields = ReportSummaryType::getType()->getFields();

        $this->assertArrayHasKey(
            $fieldName,
            $fields,
            "ReportSummaryType is missing expected field '{$fieldName}'."
        );
    }

    public function expectedFieldProvider(): array
    {
        return [
            'totalBookings'       => ['totalBookings'],
            'confirmedBookings'   => ['confirmedBookings'],
            'cancelledBookings'   => ['cancelledBookings'],
            'cancellationRate'    => ['cancellationRate'],
            'totalRevenue'        => ['totalRevenue'],
            'averageBookingValue' => ['averageBookingValue'],
            'newCustomers'        => ['newCustomers'],
            'returningCustomers'  => ['returningCustomers'],
            'startDate'           => ['startDate'],
            'endDate'             => ['endDate'],
        ];
    }

    public function testTypeHasExactlyTheExpectedFields(): void
    {
        $fields = array_keys(ReportSummaryType::getType()->getFields());
        sort($fields);

        $expected = [
            'averageBookingValue',
            'cancelledBookings',
            'cancellationRate',
            'confirmedBookings',
            'endDate',
            'newCustomers',
            'returningCustomers',
            'startDate',
            'totalBookings',
            'totalRevenue',
        ];

        $this->assertSame(
            $expected,
            $fields,
            'ReportSummaryType field set does not exactly match the expected contract.'
        );
    }

    // -------------------------------------------------------------------------
    // Field type correctness
    // -------------------------------------------------------------------------

    /**
     * @dataProvider intFieldProvider
     */
    public function testIntegerFields(string $fieldName): void
    {
        $field = ReportSummaryType::getType()->getField($fieldName);

        $this->assertInstanceOf(
            IntType::class,
            $field->getType(),
            "Field '{$fieldName}' should be of type Int."
        );
    }

    public function intFieldProvider(): array
    {
        return [
            'totalBookings'     => ['totalBookings'],
            'confirmedBookings' => ['confirmedBookings'],
            'cancelledBookings' => ['cancelledBookings'],
            'newCustomers'      => ['newCustomers'],
            'returningCustomers' => ['returningCustomers'],
        ];
    }

    /**
     * @dataProvider floatFieldProvider
     */
    public function testFloatFields(string $fieldName): void
    {
        $field = ReportSummaryType::getType()->getField($fieldName);

        $this->assertInstanceOf(
            FloatType::class,
            $field->getType(),
            "Field '{$fieldName}' should be of type Float."
        );
    }

    public function floatFieldProvider(): array
    {
        return [
            'cancellationRate'    => ['cancellationRate'],
            'totalRevenue'        => ['totalRevenue'],
            'averageBookingValue' => ['averageBookingValue'],
        ];
    }

    /**
     * @dataProvider stringFieldProvider
     */
    public function testStringFields(string $fieldName): void
    {
        $field = ReportSummaryType::getType()->getField($fieldName);

        $this->assertInstanceOf(
            StringType::class,
            $field->getType(),
            "Field '{$fieldName}' should be of type String."
        );
    }

    public function stringFieldProvider(): array
    {
        return [
            'startDate' => ['startDate'],
            'endDate'   => ['endDate'],
        ];
    }

    // -------------------------------------------------------------------------
    // ReportSummaryType name
    // -------------------------------------------------------------------------

    public function testTypeNameIsBookedReportSummary(): void
    {
        $this->assertSame('BookedReportSummary', ReportSummaryType::getName());
    }

    public function testGetTypeObjectNameMatchesGetName(): void
    {
        $this->assertSame(
            ReportSummaryType::getName(),
            ReportSummaryType::getType()->name
        );
    }

    // -------------------------------------------------------------------------
    // ReportSummaryQuery structure
    // -------------------------------------------------------------------------

    public function testGetQueriesWithoutTokenCheckReturnsNonEmptyArray(): void
    {
        $queries = ReportSummaryQuery::getQueries(checkToken: false);

        $this->assertIsArray($queries);
        $this->assertNotEmpty($queries);
    }

    public function testGetQueriesRegistersBookedReportSummaryKey(): void
    {
        $queries = ReportSummaryQuery::getQueries(checkToken: false);

        $this->assertArrayHasKey(
            'bookedReportSummary',
            $queries,
            "ReportSummaryQuery::getQueries() must expose a 'bookedReportSummary' key."
        );
    }

    public function testQueryAcceptsStartDateArgument(): void
    {
        $queries = ReportSummaryQuery::getQueries(checkToken: false);
        $args = $queries['bookedReportSummary']['args'] ?? [];

        $this->assertArrayHasKey(
            'startDate',
            $args,
            "The 'bookedReportSummary' query must accept a 'startDate' argument."
        );
    }

    public function testQueryAcceptsEndDateArgument(): void
    {
        $queries = ReportSummaryQuery::getQueries(checkToken: false);
        $args = $queries['bookedReportSummary']['args'] ?? [];

        $this->assertArrayHasKey(
            'endDate',
            $args,
            "The 'bookedReportSummary' query must accept an 'endDate' argument."
        );
    }

    public function testStartDateArgumentIsStringType(): void
    {
        $queries = ReportSummaryQuery::getQueries(checkToken: false);
        $argType = $queries['bookedReportSummary']['args']['startDate']['type'] ?? null;

        $this->assertInstanceOf(
            StringType::class,
            $argType,
            "The 'startDate' argument must be of type String."
        );
    }

    public function testEndDateArgumentIsStringType(): void
    {
        $queries = ReportSummaryQuery::getQueries(checkToken: false);
        $argType = $queries['bookedReportSummary']['args']['endDate']['type'] ?? null;

        $this->assertInstanceOf(
            StringType::class,
            $argType,
            "The 'endDate' argument must be of type String."
        );
    }

    // -------------------------------------------------------------------------
    // Resolver reference consistency
    // -------------------------------------------------------------------------

    public function testQueryResolverReferenceIsCorrectCallable(): void
    {
        $queries = ReportSummaryQuery::getQueries(checkToken: false);
        $resolve = $queries['bookedReportSummary']['resolve'] ?? null;

        $this->assertSame(
            'anvildev\booked\gql\resolvers\ReportSummaryResolver::resolve',
            $resolve,
            "The 'bookedReportSummary' query resolver must point to ReportSummaryResolver::resolve."
        );
    }

    // -------------------------------------------------------------------------
    // Resolver output key / Type field name consistency
    // -------------------------------------------------------------------------

    /**
     * Every key returned by the resolver must correspond to a field declared
     * on ReportSummaryType so that GraphQL can resolve the values correctly.
     */
    public function testResolverOutputKeysMatchTypeFields(): void
    {
        // These are the keys documented in ReportSummaryResolver::resolve().
        $resolverOutputKeys = [
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

        $typeFields = array_keys(ReportSummaryType::getType()->getFields());

        foreach ($resolverOutputKeys as $key) {
            $this->assertContains(
                $key,
                $typeFields,
                "Resolver output key '{$key}' has no matching field in ReportSummaryType."
            );
        }
    }

    /**
     * Every field declared on the type should be produced by the resolver so
     * that clients never receive null for a field they did not expect to be
     * optional.
     */
    public function testTypeFieldsAllHaveMatchingResolverOutputKeys(): void
    {
        $resolverOutputKeys = [
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

        $typeFields = array_keys(ReportSummaryType::getType()->getFields());

        foreach ($typeFields as $fieldName) {
            $this->assertContains(
                $fieldName,
                $resolverOutputKeys,
                "Type field '{$fieldName}' is not produced by the resolver — clients would always receive null."
            );
        }
    }
}
