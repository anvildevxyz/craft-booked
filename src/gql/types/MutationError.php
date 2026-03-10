<?php

namespace anvildev\booked\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class MutationError
{
    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getEntity('BookedMutationError')
            ?: GqlEntityRegistry::createEntity('BookedMutationError', new ObjectType([
                'name' => 'BookedMutationError',
                'description' => 'Represents an error that occurred during a mutation.',
                'fields' => [
                    'field' => [
                        'type' => Type::string(),
                        'description' => 'The field that caused the error (if applicable).',
                    ],
                    'message' => [
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The error message.',
                    ],
                    'code' => [
                        'type' => Type::string(),
                        'description' => 'An error code for programmatic handling.',
                    ],
                ],
            ]));
    }
}
