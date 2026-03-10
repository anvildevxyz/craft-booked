<?php

namespace anvildev\booked\gql\types\elements;

use anvildev\booked\gql\interfaces\elements\ReservationInterface;
use craft\gql\base\ObjectType;

class Reservation extends ObjectType
{
    public function __construct(array $config)
    {
        $config['interfaces'] = array_merge(
            [ReservationInterface::getType()],
            $config['interfaces'] ?? [],
        );

        parent::__construct($config);
    }
}
