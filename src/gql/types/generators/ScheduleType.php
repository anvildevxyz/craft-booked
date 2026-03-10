<?php

namespace anvildev\booked\gql\types\generators;

use anvildev\booked\elements\Schedule;
use anvildev\booked\gql\interfaces\elements\ScheduleInterface;
use anvildev\booked\gql\types\elements\Schedule as ScheduleObjectType;
use Craft;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;

class ScheduleType implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        $type = static::generateType(null);
        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = Schedule::gqlTypeNameByContext(null);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new ScheduleObjectType([
            'name' => $typeName,
            'fields' => fn() => Craft::$app->getGql()->prepareFieldDefinitions(
                ScheduleInterface::getFieldDefinitions(),
                $typeName,
            ),
        ]));
    }
}
