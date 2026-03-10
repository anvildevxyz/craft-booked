<?php

namespace anvildev\booked\gql\interfaces\elements;

use anvildev\booked\gql\types\generators\ScheduleType;
use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;

class ScheduleInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return ScheduleType::class;
    }

    public static function getType($fields = null): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all schedules.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        ScheduleType::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'ScheduleInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(
            parent::getFieldDefinitions(),
            [
                'startDate' => [
                    'name' => 'startDate',
                    'type' => Type::string(),
                    'description' => 'The schedule start date (Y-m-d).',
                ],
                'endDate' => [
                    'name' => 'endDate',
                    'type' => Type::string(),
                    'description' => 'The schedule end date (Y-m-d).',
                ],
                'workingHours' => [
                    'name' => 'workingHours',
                    'type' => Type::string(),
                    'description' => 'The working hours data as JSON string.',
                    'resolve' => fn($source) => json_encode($source->workingHours),
                ],
            ]
        ), self::getName());
    }
}
