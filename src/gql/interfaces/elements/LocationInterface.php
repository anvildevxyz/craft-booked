<?php

namespace anvildev\booked\gql\interfaces\elements;

use anvildev\booked\gql\types\generators\LocationType;
use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;

class LocationInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return LocationType::class;
    }

    public static function getType($fields = null): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all locations.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        LocationType::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'LocationInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(
            parent::getFieldDefinitions(),
            [
                'timezone' => [
                    'name' => 'timezone',
                    'type' => Type::string(),
                    'description' => 'The location timezone.',
                ],
                'addressLine1' => [
                    'name' => 'addressLine1',
                    'type' => Type::string(),
                    'description' => 'Address line 1.',
                ],
                'addressLine2' => [
                    'name' => 'addressLine2',
                    'type' => Type::string(),
                    'description' => 'Address line 2.',
                ],
                'locality' => [
                    'name' => 'locality',
                    'type' => Type::string(),
                    'description' => 'The locality (city).',
                ],
                'administrativeArea' => [
                    'name' => 'administrativeArea',
                    'type' => Type::string(),
                    'description' => 'The administrative area (state/province).',
                ],
                'postalCode' => [
                    'name' => 'postalCode',
                    'type' => Type::string(),
                    'description' => 'The postal code.',
                ],
                'countryCode' => [
                    'name' => 'countryCode',
                    'type' => Type::string(),
                    'description' => 'The country code.',
                ],
            ]
        ), self::getName());
    }
}
