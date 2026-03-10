<?php

namespace anvildev\booked\gql\types\generators;

use anvildev\booked\elements\BlackoutDate;
use anvildev\booked\gql\interfaces\elements\BlackoutDateInterface;
use anvildev\booked\gql\types\elements\BlackoutDate as BlackoutDateObjectType;
use Craft;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;

class BlackoutDateType implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        $type = static::generateType(null);
        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = BlackoutDate::gqlTypeNameByContext(null);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new BlackoutDateObjectType([
            'name' => $typeName,
            'fields' => fn() => Craft::$app->getGql()->prepareFieldDefinitions(
                BlackoutDateInterface::getFieldDefinitions(),
                $typeName,
            ),
        ]));
    }
}
