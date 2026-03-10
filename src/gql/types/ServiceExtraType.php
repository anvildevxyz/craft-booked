<?php

namespace anvildev\booked\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\Type;

class ServiceExtraType extends ObjectType
{
    public function __construct(array $config)
    {
        $config['fields'] = [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the service extra',
            ],
            'title' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The title of the service extra',
            ],
            'description' => [
                'type' => Type::string(),
                'description' => 'The description of the service extra',
            ],
            'price' => [
                'type' => Type::nonNull(Type::float()),
                'description' => 'The price of the service extra',
            ],
            'duration' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Additional duration in minutes',
            ],
            'maxQuantity' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Maximum quantity allowed per booking',
            ],
            'isRequired' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether this extra is required',
            ],
            'sortOrder' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'Sort order for display',
            ],
            'enabled' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether this extra is enabled',
            ],
            'dateCreated' => [
                'type' => Type::string(),
                'description' => 'The date the extra was created',
                'resolve' => fn($source) => $source->dateCreated?->format('Y-m-d H:i:s'),
            ],
            'dateUpdated' => [
                'type' => Type::string(),
                'description' => 'The date the extra was last updated',
                'resolve' => fn($source) => $source->dateUpdated?->format('Y-m-d H:i:s'),
            ],
        ];

        parent::__construct($config);
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getEntity(self::getName())
            ?: GqlEntityRegistry::createEntity(self::getName(), new self([
                'name' => self::getName(),
                'description' => 'This entity represents a service extra/add-on',
            ]));
    }

    public static function getName(): string
    {
        return 'ServiceExtra';
    }
}
