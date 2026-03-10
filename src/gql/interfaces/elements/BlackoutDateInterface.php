<?php

namespace anvildev\booked\gql\interfaces\elements;

use anvildev\booked\gql\types\generators\BlackoutDateType;
use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;

class BlackoutDateInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return BlackoutDateType::class;
    }

    public static function getType($fields = null): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all blackout dates.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        BlackoutDateType::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'BlackoutDateInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(
            parent::getFieldDefinitions(),
            [
                'startDate' => [
                    'name' => 'startDate',
                    'type' => Type::string(),
                    'description' => 'The blackout start date.',
                ],
                'endDate' => [
                    'name' => 'endDate',
                    'type' => Type::string(),
                    'description' => 'The blackout end date.',
                ],
                'isActive' => [
                    'name' => 'isActive',
                    'type' => Type::boolean(),
                    'description' => 'Whether the blackout date is active.',
                ],
                'locationIds' => [
                    'name' => 'locationIds',
                    'type' => Type::listOf(Type::int()),
                    'description' => 'The associated location IDs.',
                    'resolve' => fn($source) => $source->locationIds,
                ],
                'employeeIds' => [
                    'name' => 'employeeIds',
                    'type' => Type::listOf(Type::int()),
                    'description' => 'The associated employee IDs.',
                    'resolve' => fn($source) => $source->employeeIds,
                ],
            ]
        ), self::getName());
    }
}
