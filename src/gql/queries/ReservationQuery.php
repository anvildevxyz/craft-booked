<?php

namespace anvildev\booked\gql\queries;

use anvildev\booked\gql\arguments\elements\ReservationArguments;
use anvildev\booked\gql\interfaces\elements\ReservationInterface;
use anvildev\booked\gql\resolvers\elements\ReservationResolver;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

class ReservationQuery extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('bookedReservations', 'read')) {
            return [];
        }

        return [
            'bookedReservations' => [
                'type' => Type::listOf(ReservationInterface::getType()),
                'args' => ReservationArguments::getArguments(),
                'resolve' => ReservationResolver::class . '::resolve',
                'description' => 'Query Booked reservations.',
            ],
            'bookedReservation' => [
                'type' => ReservationInterface::getType(),
                'args' => ReservationArguments::getArguments(),
                'resolve' => ReservationResolver::class . '::resolveOne',
                'description' => 'Query a single Booked reservation.',
            ],
            'bookedReservationCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => ReservationArguments::getArguments(),
                'resolve' => ReservationResolver::class . '::resolveCount',
                'description' => 'Returns the count of Booked reservations.',
            ],
        ];
    }
}
