<?php

namespace anvildev\booked\gql\types\elements;

use anvildev\booked\gql\interfaces\elements\ServiceInterface;
use craft\gql\base\ObjectType;

class Service extends ObjectType
{
    public function __construct(array $config)
    {
        $config['interfaces'] = array_merge(
            [ServiceInterface::getType()],
            $config['interfaces'] ?? [],
        );

        parent::__construct($config);
    }
}
