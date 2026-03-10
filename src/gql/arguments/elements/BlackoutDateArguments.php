<?php

namespace anvildev\booked\gql\arguments\elements;

use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;

class BlackoutDateArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), [
            'startDate' => ['name' => 'startDate', 'type' => Type::string(), 'description' => 'Filter by start date.'],
            'endDate' => ['name' => 'endDate', 'type' => Type::string(), 'description' => 'Filter by end date.'],
            'isActive' => ['name' => 'isActive', 'type' => Type::boolean(), 'description' => 'Filter by active status.'],
            'locationId' => ['name' => 'locationId', 'type' => Type::listOf(Type::int()), 'description' => 'Filter by location IDs.'],
            'employeeId' => ['name' => 'employeeId', 'type' => Type::listOf(Type::int()), 'description' => 'Filter by employee IDs.'],
        ]);
    }
}
