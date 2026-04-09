<?php

namespace anvildev\booked\gql\arguments\elements;

use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;

class ReservationArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), [
            'bookingDate' => ['name' => 'bookingDate', 'type' => Type::listOf(Type::string()), 'description' => 'Filter by booking date. Supports single date or range.'],
            'status' => ['name' => 'status', 'type' => Type::listOf(Type::string()), 'description' => 'Filter by status (pending, confirmed, cancelled, no_show).'],
            'serviceId' => ['name' => 'serviceId', 'type' => Type::listOf(Type::int()), 'description' => 'Filter by service ID(s).'],
            'employeeId' => ['name' => 'employeeId', 'type' => Type::listOf(Type::int()), 'description' => 'Filter by employee ID(s).'],
            'locationId' => ['name' => 'locationId', 'type' => Type::listOf(Type::int()), 'description' => 'Filter by location ID(s).'],
            'userId' => ['name' => 'userId', 'type' => Type::listOf(Type::int()), 'description' => 'Filter by linked Craft user ID(s).'],
            'endDate' => [
                'name' => 'endDate',
                'type' => Type::listOf(Type::string()),
                'description' => 'Filter by end date',
            ],
        ]);
    }
}
