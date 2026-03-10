<?php

namespace anvildev\booked\gql\queries;

use anvildev\booked\gql\arguments\elements\BlackoutDateArguments;
use anvildev\booked\gql\interfaces\elements\BlackoutDateInterface;
use anvildev\booked\gql\resolvers\elements\BlackoutDateResolver;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

class BlackoutDateQuery extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('bookedBlackoutDates', 'read')) {
            return [];
        }

        return [
            'bookedBlackoutDates' => [
                'type' => Type::listOf(BlackoutDateInterface::getType()),
                'args' => BlackoutDateArguments::getArguments(),
                'resolve' => BlackoutDateResolver::class . '::resolve',
                'description' => 'Query Booked blackout dates.',
            ],
            'bookedBlackoutDate' => [
                'type' => BlackoutDateInterface::getType(),
                'args' => BlackoutDateArguments::getArguments(),
                'resolve' => BlackoutDateResolver::class . '::resolveOne',
                'description' => 'Query a single Booked blackout date.',
            ],
            'bookedBlackoutDateCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => BlackoutDateArguments::getArguments(),
                'resolve' => BlackoutDateResolver::class . '::resolveCount',
                'description' => 'Returns the count of Booked blackout dates.',
            ],
        ];
    }
}
