<?php

namespace anvildev\booked\gql\arguments\elements;

use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;

class LocationArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), [
            'timezone' => ['name' => 'timezone', 'type' => Type::string(), 'description' => 'Filter by timezone.'],
        ]);
    }
}
