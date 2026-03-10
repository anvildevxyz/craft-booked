<?php

namespace anvildev\booked\gql\types\input;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

class CreateReservationInput
{
    public static function getType(): InputObjectType
    {
        return GqlEntityRegistry::getEntity('CreateReservationInput')
            ?: GqlEntityRegistry::createEntity('CreateReservationInput', new InputObjectType([
                'name' => 'CreateReservationInput',
                'description' => 'Input for creating a new booking reservation.',
                'fields' => [
                    'serviceId' => [
                        'type' => Type::nonNull(Type::id()),
                        'description' => 'The service ID to book.',
                    ],
                    'employeeId' => [
                        'type' => Type::id(),
                        'description' => 'The employee ID (optional).',
                    ],
                    'locationId' => [
                        'type' => Type::id(),
                        'description' => 'The location ID (optional).',
                    ],
                    'eventDateId' => [
                        'type' => Type::id(),
                        'description' => 'The event date ID for event-based bookings (optional).',
                    ],
                    'bookingDate' => [
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The booking date in YYYY-MM-DD format.',
                    ],
                    'startTime' => [
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The start time in HH:MM format.',
                    ],
                    'userName' => [
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The customer name.',
                    ],
                    'userEmail' => [
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The customer email address.',
                    ],
                    'userPhone' => [
                        'type' => Type::string(),
                        'description' => 'The customer phone number (optional).',
                    ],
                    'userTimezone' => [
                        'type' => Type::string(),
                        'description' => 'The customer timezone (optional).',
                    ],
                    'notes' => [
                        'type' => Type::string(),
                        'description' => 'Customer notes (optional).',
                    ],
                    'quantity' => [
                        'type' => Type::int(),
                        'description' => 'Number of spots to book (default: 1).',
                    ],
                    'extraIds' => [
                        'type' => Type::listOf(Type::id()),
                        'description' => 'Service extra IDs to include (optional).',
                    ],
                    'extraQuantities' => [
                        'type' => Type::listOf(Type::int()),
                        'description' => 'Quantities for each extra (must match extraIds order).',
                    ],
                ],
            ]));
    }
}
