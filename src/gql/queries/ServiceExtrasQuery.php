<?php

namespace anvildev\booked\gql\queries;

use anvildev\booked\Booked;
use anvildev\booked\gql\types\ServiceExtraType;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

class ServiceExtrasQuery extends Query
{
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('bookedServiceExtras', 'read')) {
            return [];
        }

        return [
            'bookedServiceExtras' => [
                'type' => Type::listOf(ServiceExtraType::getType()),
                'description' => 'Query all service extras',
                'args' => [
                    'serviceId' => ['type' => Type::int(), 'description' => 'Filter by service ID'],
                    'enabled' => ['type' => Type::boolean(), 'description' => 'Filter by enabled status'],
                ],
                'resolve' => function($source, array $arguments) {
                    $enabled = $arguments['enabled'] ?? true;
                    return isset($arguments['serviceId'])
                        ? Booked::getInstance()->serviceExtra->getExtrasForService($arguments['serviceId'], $enabled)
                        : Booked::getInstance()->serviceExtra->getAllExtras($enabled);
                },
            ],
            'bookedServiceExtra' => [
                'type' => ServiceExtraType::getType(),
                'description' => 'Query a single service extra by ID',
                'args' => [
                    'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The ID of the service extra'],
                ],
                'resolve' => fn($source, array $arguments) => Booked::getInstance()->serviceExtra->getExtraById($arguments['id']),
            ],
        ];
    }
}
