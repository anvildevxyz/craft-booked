<?php

namespace anvildev\booked\gql\types\input;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

class UpdateReservationInput
{
    public static function getType(): InputObjectType
    {
        return GqlEntityRegistry::getEntity('UpdateReservationInput')
            ?: GqlEntityRegistry::createEntity('UpdateReservationInput', new InputObjectType([
                'name' => 'UpdateReservationInput',
                'description' => 'Input for updating an existing booking reservation.',
                'fields' => [
                    'userName' => [
                        'type' => Type::string(),
                        'description' => 'The customer name.',
                    ],
                    'userEmail' => [
                        'type' => Type::string(),
                        'description' => 'The customer email address.',
                    ],
                    'userPhone' => [
                        'type' => Type::string(),
                        'description' => 'The customer phone number.',
                    ],
                    'notes' => [
                        'type' => Type::string(),
                        'description' => 'Customer notes.',
                    ],
                ],
            ]));
    }
}
