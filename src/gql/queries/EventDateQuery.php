<?php

namespace anvildev\booked\gql\queries;

use anvildev\booked\gql\arguments\elements\EventDateArguments;
use anvildev\booked\gql\interfaces\elements\EventDateInterface;
use anvildev\booked\gql\resolvers\elements\EventDateResolver;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

class EventDateQuery extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('bookedEventDates', 'read')) {
            return [];
        }

        return [
            'bookedEventDates' => [
                'type' => Type::listOf(EventDateInterface::getType()),
                'args' => EventDateArguments::getArguments(),
                'resolve' => EventDateResolver::class . '::resolve',
                'description' => 'Query Booked event dates.',
            ],
            'bookedEventDate' => [
                'type' => EventDateInterface::getType(),
                'args' => EventDateArguments::getArguments(),
                'resolve' => EventDateResolver::class . '::resolveOne',
                'description' => 'Query a single Booked event date.',
            ],
            'bookedEventDateCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => EventDateArguments::getArguments(),
                'resolve' => EventDateResolver::class . '::resolveCount',
                'description' => 'Returns the count of Booked event dates.',
            ],
        ];
    }
}
