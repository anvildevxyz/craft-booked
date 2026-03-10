<?php

namespace anvildev\booked\gql\queries;

use anvildev\booked\gql\arguments\elements\ScheduleArguments;
use anvildev\booked\gql\interfaces\elements\ScheduleInterface;
use anvildev\booked\gql\resolvers\elements\ScheduleResolver;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

class ScheduleQuery extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('bookedSchedules', 'read')) {
            return [];
        }

        return [
            'bookedSchedules' => [
                'type' => Type::listOf(ScheduleInterface::getType()),
                'args' => ScheduleArguments::getArguments(),
                'resolve' => ScheduleResolver::class . '::resolve',
                'description' => 'Query Booked schedules.',
            ],
            'bookedSchedule' => [
                'type' => ScheduleInterface::getType(),
                'args' => ScheduleArguments::getArguments(),
                'resolve' => ScheduleResolver::class . '::resolveOne',
                'description' => 'Query a single Booked schedule.',
            ],
            'bookedScheduleCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => ScheduleArguments::getArguments(),
                'resolve' => ScheduleResolver::class . '::resolveCount',
                'description' => 'Returns the count of Booked schedules.',
            ],
        ];
    }
}
