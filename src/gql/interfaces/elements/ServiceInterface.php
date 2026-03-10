<?php

namespace anvildev\booked\gql\interfaces\elements;

use anvildev\booked\gql\types\generators\ServiceType;
use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;

class ServiceInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return ServiceType::class;
    }

    public static function getType($fields = null): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all services.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        ServiceType::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'ServiceInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(
            parent::getFieldDefinitions(),
            [
                'description' => [
                    'name' => 'description',
                    'type' => Type::string(),
                    'description' => 'The service description.',
                ],
                'duration' => [
                    'name' => 'duration',
                    'type' => Type::int(),
                    'description' => 'The service duration in minutes.',
                ],
                'price' => [
                    'name' => 'price',
                    'type' => Type::float(),
                    'description' => 'The service price.',
                ],
                'bufferBefore' => [
                    'name' => 'bufferBefore',
                    'type' => Type::int(),
                    'description' => 'Buffer time before the service in minutes.',
                ],
                'bufferAfter' => [
                    'name' => 'bufferAfter',
                    'type' => Type::int(),
                    'description' => 'Buffer time after the service in minutes.',
                ],
                'virtualMeetingProvider' => [
                    'name' => 'virtualMeetingProvider',
                    'type' => Type::string(),
                    'description' => 'Virtual meeting provider (e.g., zoom, google_meet).',
                ],
                'minTimeBeforeBooking' => [
                    'name' => 'minTimeBeforeBooking',
                    'type' => Type::int(),
                    'description' => 'Minimum time before booking in minutes.',
                ],
                'timeSlotLength' => [
                    'name' => 'timeSlotLength',
                    'type' => Type::int(),
                    'description' => 'Time slot length in minutes.',
                ],
                'locationIds' => [
                    'name' => 'locationIds',
                    'type' => Type::listOf(Type::int()),
                    'description' => 'IDs of locations directly assigned to this service.',
                    'resolve' => function($source) {
                        if (!$source->id) {
                            return [];
                        }
                        return array_map(
                            fn($location) => $location->id,
                            \anvildev\booked\Booked::getInstance()->serviceLocation->getLocationsForService($source->id)
                        );
                    },
                ],
            ]
        ), self::getName());
    }
}
