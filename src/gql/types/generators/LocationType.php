<?php

namespace anvildev\booked\gql\types\generators;

use anvildev\booked\elements\Location;
use anvildev\booked\gql\interfaces\elements\LocationInterface;
use anvildev\booked\gql\types\elements\Location as LocationObjectType;
use Craft;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;

class LocationType implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        $type = static::generateType(null);
        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = Location::gqlTypeNameByContext(null);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new LocationObjectType([
            'name' => $typeName,
            'fields' => fn() => Craft::$app->getGql()->prepareFieldDefinitions(
                LocationInterface::getFieldDefinitions(),
                $typeName,
            ),
        ]));
    }
}
