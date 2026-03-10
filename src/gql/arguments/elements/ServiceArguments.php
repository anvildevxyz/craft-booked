<?php

namespace anvildev\booked\gql\arguments\elements;

use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;

class ServiceArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), [
            'duration' => ['name' => 'duration', 'type' => Type::int(), 'description' => 'Filter by duration.'],
            'price' => ['name' => 'price', 'type' => Type::float(), 'description' => 'Filter by price.'],
            'locationId' => ['name' => 'locationId', 'type' => Type::int(), 'description' => 'Filter by assigned location ID.'],
        ]);
    }
}
