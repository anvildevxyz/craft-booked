<?php

namespace anvildev\booked\gql\queries;

use anvildev\booked\gql\arguments\elements\LocationArguments;
use anvildev\booked\gql\interfaces\elements\LocationInterface;
use anvildev\booked\gql\resolvers\elements\LocationResolver;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

class LocationQuery extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('bookedLocations', 'read')) {
            return [];
        }

        return [
            'bookedLocations' => [
                'type' => Type::listOf(LocationInterface::getType()),
                'args' => LocationArguments::getArguments(),
                'resolve' => LocationResolver::class . '::resolve',
                'description' => 'Query Booked locations.',
            ],
            'bookedLocation' => [
                'type' => LocationInterface::getType(),
                'args' => LocationArguments::getArguments(),
                'resolve' => LocationResolver::class . '::resolveOne',
                'description' => 'Query a single Booked location.',
            ],
            'bookedLocationCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => LocationArguments::getArguments(),
                'resolve' => LocationResolver::class . '::resolveCount',
                'description' => 'Returns the count of Booked locations.',
            ],
        ];
    }
}
