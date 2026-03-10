<?php

namespace anvildev\booked\gql\queries;

use anvildev\booked\gql\resolvers\ReportSummaryResolver;
use anvildev\booked\gql\types\ReportSummaryType;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

class ReportSummaryQuery extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('bookedReports', 'read')) {
            return [];
        }

        return [
            'bookedReportSummary' => [
                'type' => ReportSummaryType::getType(),
                'args' => [
                    'startDate' => ['type' => Type::string(), 'description' => 'Start date (Y-m-d). Defaults to first of current month.'],
                    'endDate' => ['type' => Type::string(), 'description' => 'End date (Y-m-d). Defaults to last of current month.'],
                ],
                'resolve' => ReportSummaryResolver::class . '::resolve',
                'description' => 'Query Booked report summary with aggregated booking statistics.',
            ],
        ];
    }
}
