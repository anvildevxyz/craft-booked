<?php

namespace anvildev\booked\gql\types\elements;

use anvildev\booked\gql\interfaces\elements\BlackoutDateInterface;
use craft\gql\base\ObjectType;

class BlackoutDate extends ObjectType
{
    public function __construct(array $config)
    {
        $config['interfaces'] = array_merge(
            [BlackoutDateInterface::getType()],
            $config['interfaces'] ?? [],
        );

        parent::__construct($config);
    }
}
