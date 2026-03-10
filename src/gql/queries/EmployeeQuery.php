<?php

namespace anvildev\booked\gql\queries;

use anvildev\booked\gql\arguments\elements\EmployeeArguments;
use anvildev\booked\gql\interfaces\elements\EmployeeInterface;
use anvildev\booked\gql\resolvers\elements\EmployeeResolver;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

class EmployeeQuery extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('bookedEmployees', 'read')) {
            return [];
        }

        return [
            'bookedEmployees' => [
                'type' => Type::listOf(EmployeeInterface::getType()),
                'args' => EmployeeArguments::getArguments(),
                'resolve' => EmployeeResolver::class . '::resolve',
                'description' => 'Query Booked employees.',
            ],
            'bookedEmployee' => [
                'type' => EmployeeInterface::getType(),
                'args' => EmployeeArguments::getArguments(),
                'resolve' => EmployeeResolver::class . '::resolveOne',
                'description' => 'Query a single Booked employee.',
            ],
            'bookedEmployeeCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => EmployeeArguments::getArguments(),
                'resolve' => EmployeeResolver::class . '::resolveCount',
                'description' => 'Returns the count of Booked employees.',
            ],
        ];
    }
}
