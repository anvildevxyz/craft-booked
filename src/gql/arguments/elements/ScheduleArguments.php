<?php

namespace anvildev\booked\gql\arguments\elements;

use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;

class ScheduleArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), [
            'employeeId' => ['name' => 'employeeId', 'type' => Type::int(), 'description' => 'Filter by assigned employee ID.'],
            'activeOn' => ['name' => 'activeOn', 'type' => Type::string(), 'description' => 'Filter schedules active on a specific date (Y-m-d).'],
        ]);
    }
}
