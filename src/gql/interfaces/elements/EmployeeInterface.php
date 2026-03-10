<?php

namespace anvildev\booked\gql\interfaces\elements;

use anvildev\booked\gql\types\generators\EmployeeType;
use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;

class EmployeeInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return EmployeeType::class;
    }

    public static function getType($fields = null): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all employees.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        EmployeeType::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'EmployeeInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(
            parent::getFieldDefinitions(),
            [
                'userId' => [
                    'name' => 'userId',
                    'type' => Type::int(),
                    'description' => 'The linked Craft user ID.',
                ],
                'locationId' => [
                    'name' => 'locationId',
                    'type' => Type::int(),
                    'description' => 'The assigned location ID.',
                ],
            ]
        ), self::getName());
    }
}
