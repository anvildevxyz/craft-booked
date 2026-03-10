<?php

namespace anvildev\booked\gql\arguments\elements;

use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;

class EmployeeArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), [
            'userId' => ['name' => 'userId', 'type' => Type::int(), 'description' => 'Filter by linked Craft user ID.'],
            'locationId' => ['name' => 'locationId', 'type' => Type::int(), 'description' => 'Filter by assigned location ID.'],
            'serviceId' => ['name' => 'serviceId', 'type' => Type::int(), 'description' => 'Filter by assigned service ID.'],
        ]);
    }
}
