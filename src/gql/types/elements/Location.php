<?php

namespace anvildev\booked\gql\types\elements;

use anvildev\booked\gql\interfaces\elements\LocationInterface;
use craft\gql\base\ObjectType;

class Location extends ObjectType
{
    public function __construct(array $config)
    {
        $config['interfaces'] = array_merge(
            [LocationInterface::getType()],
            $config['interfaces'] ?? [],
        );

        parent::__construct($config);
    }
}
