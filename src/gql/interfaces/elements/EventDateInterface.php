<?php

namespace anvildev\booked\gql\interfaces\elements;

use anvildev\booked\gql\types\generators\EventDateType;
use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;

class EventDateInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return EventDateType::class;
    }

    public static function getType($fields = null): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all event dates.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        EventDateType::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'EventDateInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(
            parent::getFieldDefinitions(),
            [
                'locationId' => [
                    'name' => 'locationId',
                    'type' => Type::int(),
                    'description' => 'The location ID.',
                ],
                'eventDate' => [
                    'name' => 'eventDate',
                    'type' => Type::string(),
                    'description' => 'The event date.',
                ],
                'endDate' => [
                    'name' => 'endDate',
                    'type' => Type::string(),
                    'description' => 'The end date.',
                ],
                'startTime' => [
                    'name' => 'startTime',
                    'type' => Type::string(),
                    'description' => 'The start time.',
                ],
                'endTime' => [
                    'name' => 'endTime',
                    'type' => Type::string(),
                    'description' => 'The end time.',
                ],
                'description' => [
                    'name' => 'description',
                    'type' => Type::string(),
                    'description' => 'The event description.',
                ],
                'capacity' => [
                    'name' => 'capacity',
                    'type' => Type::int(),
                    'description' => 'The event capacity.',
                ],
                'price' => [
                    'name' => 'price',
                    'type' => Type::float(),
                    'description' => 'The event price.',
                ],
                'enabled' => [
                    'name' => 'enabled',
                    'type' => Type::boolean(),
                    'description' => 'Whether the event date is enabled.',
                ],
            ]
        ), self::getName());
    }
}
