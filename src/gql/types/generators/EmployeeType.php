<?php

namespace anvildev\booked\gql\types\generators;

use anvildev\booked\elements\Employee;
use anvildev\booked\gql\interfaces\elements\EmployeeInterface;
use anvildev\booked\gql\types\elements\Employee as EmployeeObjectType;
use Craft;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;

class EmployeeType implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        $type = static::generateType(null);
        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = Employee::gqlTypeNameByContext(null);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new EmployeeObjectType([
            'name' => $typeName,
            'fields' => fn() => Craft::$app->getGql()->prepareFieldDefinitions(
                EmployeeInterface::getFieldDefinitions(),
                $typeName,
            ),
        ]));
    }
}
