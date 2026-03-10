<?php

namespace anvildev\booked\gql\types\elements;

use anvildev\booked\gql\interfaces\elements\ScheduleInterface;
use craft\gql\base\ObjectType;

class Schedule extends ObjectType
{
    public function __construct(array $config)
    {
        $config['interfaces'] = array_merge(
            [ScheduleInterface::getType()],
            $config['interfaces'] ?? [],
        );

        parent::__construct($config);
    }
}
