<?php

namespace anvildev\booked\gql\types\elements;

use anvildev\booked\gql\interfaces\elements\EventDateInterface;
use craft\gql\base\ObjectType;

class EventDate extends ObjectType
{
    public function __construct(array $config)
    {
        $config['interfaces'] = array_merge(
            [EventDateInterface::getType()],
            $config['interfaces'] ?? [],
        );

        parent::__construct($config);
    }
}
