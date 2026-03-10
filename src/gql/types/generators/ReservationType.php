<?php

namespace anvildev\booked\gql\types\generators;

use anvildev\booked\elements\Reservation;
use anvildev\booked\gql\interfaces\elements\ReservationInterface;
use anvildev\booked\gql\types\elements\Reservation as ReservationObjectType;
use Craft;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;

class ReservationType implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        $type = static::generateType(null);
        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = Reservation::gqlTypeNameByContext(null);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new ReservationObjectType([
            'name' => $typeName,
            'fields' => fn() => Craft::$app->getGql()->prepareFieldDefinitions(
                ReservationInterface::getFieldDefinitions(),
                $typeName,
            ),
        ]));
    }
}
