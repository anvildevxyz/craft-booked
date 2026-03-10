<?php

namespace anvildev\booked\gql\interfaces\elements;

use anvildev\booked\gql\types\generators\ReservationType;
use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;

class ReservationInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return ReservationType::class;
    }

    public static function getType($fields = null): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all reservations.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        ReservationType::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'ReservationInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(
            parent::getFieldDefinitions(),
            [
                'userName' => [
                    'name' => 'userName',
                    'type' => Type::string(),
                    'description' => 'The customer name.',
                ],
                'userEmail' => [
                    'name' => 'userEmail',
                    'type' => Type::string(),
                    'description' => 'The customer email.',
                ],
                'userPhone' => [
                    'name' => 'userPhone',
                    'type' => Type::string(),
                    'description' => 'The customer phone number.',
                ],
                'userId' => [
                    'name' => 'userId',
                    'type' => Type::int(),
                    'description' => 'The linked Craft user ID (if logged in when booking).',
                ],
                'userTimezone' => [
                    'name' => 'userTimezone',
                    'type' => Type::string(),
                    'description' => 'The customer timezone.',
                ],
                'bookingDate' => [
                    'name' => 'bookingDate',
                    'type' => Type::string(),
                    'description' => 'The booking date (YYYY-MM-DD).',
                ],
                'startTime' => [
                    'name' => 'startTime',
                    'type' => Type::string(),
                    'description' => 'The start time (HH:MM).',
                ],
                'endTime' => [
                    'name' => 'endTime',
                    'type' => Type::string(),
                    'description' => 'The end time (HH:MM).',
                ],
                'status' => [
                    'name' => 'status',
                    'type' => Type::string(),
                    'description' => 'The reservation status (pending, confirmed, cancelled, completed).',
                ],
                'notes' => [
                    'name' => 'notes',
                    'type' => Type::string(),
                    'description' => 'Customer notes.',
                ],
                'quantity' => [
                    'name' => 'quantity',
                    'type' => Type::int(),
                    'description' => 'The number of spots booked.',
                ],
                'serviceId' => [
                    'name' => 'serviceId',
                    'type' => Type::int(),
                    'description' => 'The service ID.',
                ],
                'employeeId' => [
                    'name' => 'employeeId',
                    'type' => Type::int(),
                    'description' => 'The employee ID.',
                ],
                'locationId' => [
                    'name' => 'locationId',
                    'type' => Type::int(),
                    'description' => 'The location ID.',
                ],
                'eventDateId' => [
                    'name' => 'eventDateId',
                    'type' => Type::int(),
                    'description' => 'The event date ID (for event bookings).',
                ],
                'virtualMeetingUrl' => [
                    'name' => 'virtualMeetingUrl',
                    'type' => Type::string(),
                    'description' => 'Virtual meeting URL.',
                ],
                'virtualMeetingProvider' => [
                    'name' => 'virtualMeetingProvider',
                    'type' => Type::string(),
                    'description' => 'Virtual meeting provider.',
                ],
            ]
        ), self::getName());
    }
}
