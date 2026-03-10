<?php

namespace anvildev\booked\gql\types\generators;

use anvildev\booked\elements\EventDate;
use anvildev\booked\gql\interfaces\elements\EventDateInterface;
use anvildev\booked\gql\types\elements\EventDate as EventDateObjectType;
use Craft;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;

class EventDateType implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        $type = static::generateType(null);
        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = EventDate::gqlTypeNameByContext(null);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new EventDateObjectType([
            'name' => $typeName,
            'fields' => fn() => Craft::$app->getGql()->prepareFieldDefinitions(
                EventDateInterface::getFieldDefinitions(),
                $typeName,
            ),
        ]));
    }
}
