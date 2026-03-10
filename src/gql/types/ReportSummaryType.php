<?php

namespace anvildev\booked\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class ReportSummaryType
{
    public static function getName(): string
    {
        return 'BookedReportSummary';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getEntity(self::getName())
            ?: GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
                'name' => self::getName(),
                'fields' => [
                    'totalBookings' => ['type' => Type::int()],
                    'confirmedBookings' => ['type' => Type::int()],
                    'cancelledBookings' => ['type' => Type::int()],
                    'cancellationRate' => ['type' => Type::float()],
                    'totalRevenue' => ['type' => Type::float()],
                    'averageBookingValue' => ['type' => Type::float()],
                    'newCustomers' => ['type' => Type::int()],
                    'returningCustomers' => ['type' => Type::int()],
                    'startDate' => ['type' => Type::string()],
                    'endDate' => ['type' => Type::string()],
                ],
            ]));
    }
}
