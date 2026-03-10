<?php

namespace anvildev\booked\gql\types\elements;

use anvildev\booked\gql\interfaces\elements\EmployeeInterface;
use craft\gql\base\ObjectType;

class Employee extends ObjectType
{
    public function __construct(array $config)
    {
        $config['interfaces'] = array_merge(
            [EmployeeInterface::getType()],
            $config['interfaces'] ?? [],
        );

        parent::__construct($config);
    }
}
