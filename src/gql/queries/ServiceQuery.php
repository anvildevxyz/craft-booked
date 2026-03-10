<?php

namespace anvildev\booked\gql\queries;

use anvildev\booked\gql\arguments\elements\ServiceArguments;
use anvildev\booked\gql\interfaces\elements\ServiceInterface;
use anvildev\booked\gql\resolvers\elements\ServiceResolver;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

class ServiceQuery extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('bookedServices', 'read')) {
            return [];
        }

        return [
            'bookedServices' => [
                'type' => Type::listOf(ServiceInterface::getType()),
                'args' => ServiceArguments::getArguments(),
                'resolve' => ServiceResolver::class . '::resolve',
                'description' => 'Query Booked services.',
            ],
            'bookedService' => [
                'type' => ServiceInterface::getType(),
                'args' => ServiceArguments::getArguments(),
                'resolve' => ServiceResolver::class . '::resolveOne',
                'description' => 'Query a single Booked service.',
            ],
            'bookedServiceCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => ServiceArguments::getArguments(),
                'resolve' => ServiceResolver::class . '::resolveCount',
                'description' => 'Returns the count of Booked services.',
            ],
        ];
    }
}
