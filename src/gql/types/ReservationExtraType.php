<?php

namespace anvildev\booked\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\Type;

class ReservationExtraType extends ObjectType
{
    public function __construct(array $config)
    {
        $config['fields'] = [
            'extra' => [
                'type' => ServiceExtraType::getType(),
                'description' => 'The service extra',
            ],
            'quantity' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The quantity selected',
            ],
            'price' => [
                'type' => Type::nonNull(Type::float()),
                'description' => 'The price at the time of booking',
            ],
            'totalPrice' => [
                'type' => Type::nonNull(Type::float()),
                'description' => 'The total price (price x quantity)',
                'resolve' => fn($source) => $source['price'] * $source['quantity'],
            ],
        ];

        parent::__construct($config);
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getEntity(self::getName())
            ?: GqlEntityRegistry::createEntity(self::getName(), new self([
                'name' => self::getName(),
                'description' => 'This entity represents a service extra selected for a reservation',
            ]));
    }

    public static function getName(): string
    {
        return 'ReservationExtra';
    }
}
