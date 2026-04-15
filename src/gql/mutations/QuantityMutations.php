<?php

namespace anvildev\booked\gql\mutations;

use anvildev\booked\Booked;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\gql\interfaces\elements\ReservationInterface;
use anvildev\booked\gql\types\MutationError;
use Craft;
use craft\gql\base\Mutation;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class QuantityMutations extends Mutation
{
    public static function getMutations(): array
    {
        $mutations = [];

        if (GqlHelper::canSchema('bookedReservations', 'update')) {
            $mutations['reduceBookedReservationQuantity'] = [
                'name' => 'reduceBookedReservationQuantity',
                'type' => self::getPayloadType('ReduceBookedReservationQuantityPayload', 'reduceBookedReservationQuantity', 'updated'),
                'args' => [
                    'id' => ['type' => Type::nonNull(Type::id()), 'description' => 'The reservation ID.'],
                    'token' => ['type' => Type::nonNull(Type::string()), 'description' => 'The reservation confirmation token for authorization.'],
                    'reduceBy' => ['type' => Type::nonNull(Type::int()), 'description' => 'The number of guests to remove.'],
                    'reason' => ['type' => Type::string(), 'description' => 'The reason for the reduction (optional).'],
                ],
                'resolve' => fn($source, array $arguments) => self::resolveReduce(
                    (int) $arguments['id'],
                    $arguments['token'],
                    $arguments['reduceBy'],
                    $arguments['reason'] ?? ''
                ),
                'description' => 'Reduce the quantity of an existing booking reservation. Requires confirmation token for authorization.',
            ];

            $mutations['increaseBookedReservationQuantity'] = [
                'name' => 'increaseBookedReservationQuantity',
                'type' => self::getPayloadType('IncreaseBookedReservationQuantityPayload', 'increaseBookedReservationQuantity', 'updated'),
                'args' => [
                    'id' => ['type' => Type::nonNull(Type::id()), 'description' => 'The reservation ID.'],
                    'token' => ['type' => Type::nonNull(Type::string()), 'description' => 'The reservation confirmation token for authorization.'],
                    'increaseBy' => ['type' => Type::nonNull(Type::int()), 'description' => 'The number of additional guests.'],
                ],
                'resolve' => fn($source, array $arguments) => self::resolveIncrease(
                    (int) $arguments['id'],
                    $arguments['token'],
                    $arguments['increaseBy']
                ),
                'description' => 'Increase the quantity of an existing booking reservation. Requires confirmation token for authorization.',
            ];
        }

        return $mutations;
    }

    protected static function getPayloadType(string $typeName, string $mutationName, string $verb): ObjectType
    {
        return GqlEntityRegistry::getEntity($typeName) ?: GqlEntityRegistry::createEntity($typeName, new ObjectType([
            'name' => $typeName,
            'description' => "Payload for the {$mutationName} mutation.",
            'fields' => [
                'success' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Whether the mutation was successful.'],
                'reservation' => ['type' => ReservationInterface::getType(), 'description' => "The {$verb} reservation."],
                'errors' => ['type' => Type::listOf(MutationError::getType()), 'description' => 'Any errors that occurred.'],
            ],
        ]));
    }

    // SECURITY: Requires confirmation token to prevent unauthorized modifications.
    protected static function resolveReduce(int $id, string $token, int $reduceBy, string $reason): array
    {
        try {
            $reservation = ReservationFactory::findById($id);

            if (!$reservation) {
                return self::errorResponse('id', 'Reservation not found.', 'NOT_FOUND');
            }

            if (!hash_equals($reservation->getConfirmationToken(), $token)) {
                Craft::warning("Unauthorized GraphQL quantity reduction attempt for reservation ID: {$id}", __METHOD__);
                Booked::getInstance()->getAudit()->logAuthFailure('invalid_reduce_token', ['reservationId' => $id, 'source' => 'graphql']);
                return self::errorResponse('token', 'Invalid or missing authorization token.', 'UNAUTHORIZED');
            }

            if ($reduceBy < 1) {
                return self::errorResponse('reduceBy', 'Reduction amount must be at least 1.', 'VALIDATION_ERROR');
            }

            $reduceBy = min($reduceBy, 10000);

            $result = Booked::getInstance()->getBooking()->reduceQuantity($id, $reduceBy, $reason);

            if (!$result) {
                return ['success' => false, 'reservation' => $reservation, 'errors' => [
                    ['field' => null, 'message' => 'Failed to reduce quantity. The reduction may exceed the current booking quantity.', 'code' => 'QUANTITY_REDUCTION_FAILED'],
                ]];
            }

            return ['success' => true, 'reservation' => ReservationFactory::findById($id), 'errors' => []];
        } catch (\Exception $e) {
            Craft::error('GraphQL reduceBookedReservationQuantity error: ' . $e->getMessage(), __METHOD__);
            return self::errorResponse(null, 'An internal error occurred. Please try again later.', 'INTERNAL_ERROR');
        }
    }

    // SECURITY: Requires confirmation token to prevent unauthorized modifications.
    protected static function resolveIncrease(int $id, string $token, int $increaseBy): array
    {
        try {
            $reservation = ReservationFactory::findById($id);

            if (!$reservation) {
                return self::errorResponse('id', 'Reservation not found.', 'NOT_FOUND');
            }

            if (!hash_equals($reservation->getConfirmationToken(), $token)) {
                Craft::warning("Unauthorized GraphQL quantity increase attempt for reservation ID: {$id}", __METHOD__);
                Booked::getInstance()->getAudit()->logAuthFailure('invalid_increase_token', ['reservationId' => $id, 'source' => 'graphql']);
                return self::errorResponse('token', 'Invalid or missing authorization token.', 'UNAUTHORIZED');
            }

            if ($increaseBy < 1) {
                return self::errorResponse('increaseBy', 'Increase amount must be at least 1.', 'VALIDATION_ERROR');
            }

            $increaseBy = min($increaseBy, 10000);

            $result = Booked::getInstance()->getBooking()->increaseQuantity($id, $increaseBy);

            if (!$result) {
                return ['success' => false, 'reservation' => $reservation, 'errors' => [
                    ['field' => null, 'message' => 'Failed to increase quantity. Capacity may have been exceeded.', 'code' => 'QUANTITY_INCREASE_FAILED'],
                ]];
            }

            return ['success' => true, 'reservation' => ReservationFactory::findById($id), 'errors' => []];
        } catch (\Exception $e) {
            Craft::error('GraphQL increaseBookedReservationQuantity error: ' . $e->getMessage(), __METHOD__);
            return self::errorResponse(null, 'An internal error occurred. Please try again later.', 'INTERNAL_ERROR');
        }
    }

    protected static function errorResponse(?string $field, string $message, string $code): array
    {
        return ['success' => false, 'reservation' => null, 'errors' => [['field' => $field, 'message' => $message, 'code' => $code]]];
    }
}
