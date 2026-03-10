<?php

namespace anvildev\booked\gql\types\generators;

use anvildev\booked\elements\Service;
use anvildev\booked\gql\interfaces\elements\ServiceInterface;
use anvildev\booked\gql\types\elements\Service as ServiceObjectType;
use Craft;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;

class ServiceType implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        $type = static::generateType(null);
        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = Service::gqlTypeNameByContext(null);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new ServiceObjectType([
            'name' => $typeName,
            'fields' => fn() => Craft::$app->getGql()->prepareFieldDefinitions(
                ServiceInterface::getFieldDefinitions(),
                $typeName,
            ),
        ]));
    }
}
