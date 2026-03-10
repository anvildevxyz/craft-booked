<?php

namespace anvildev\booked\gql\arguments\elements;

use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;

class EventDateArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), [
            'locationId' => ['name' => 'locationId', 'type' => Type::int(), 'description' => 'Filter by location ID.'],
            'eventDate' => ['name' => 'eventDate', 'type' => Type::string(), 'description' => 'Filter by event date (Y-m-d).'],
            'endDate' => ['name' => 'endDate', 'type' => Type::string(), 'description' => 'Filter by end date (Y-m-d).'],
            'startTime' => ['name' => 'startTime', 'type' => Type::string(), 'description' => 'Filter by start time (HH:MM).'],
            'endTime' => ['name' => 'endTime', 'type' => Type::string(), 'description' => 'Filter by end time (HH:MM).'],
            'enabled' => ['name' => 'enabled', 'type' => Type::boolean(), 'description' => 'Filter by enabled status.'],
        ]);
    }
}
