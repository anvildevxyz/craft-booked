<?php

namespace anvildev\booked\gql\mutations;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface as ReservationContract;
use anvildev\booked\exceptions\BookingConflictException;
use anvildev\booked\exceptions\BookingValidationException;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\gql\interfaces\elements\ReservationInterface;
use anvildev\booked\gql\types\input\CreateReservationInput;
use anvildev\booked\gql\types\input\UpdateReservationInput;
use anvildev\booked\gql\types\MutationError;
use Craft;
use craft\gql\base\Mutation;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class ReservationMutations extends Mutation
{
    public static function getMutations(): array
    {
        $mutations = [];

        if (GqlHelper::canSchema('bookedReservations', 'create')) {
            $mutations['createBookedReservation'] = [
                'name' => 'createBookedReservation',
                'type' => self::getPayloadType('CreateBookedReservationPayload', 'createBookedReservation', 'created'),
                'args' => [
                    'input' => ['type' => Type::nonNull(CreateReservationInput::getType()), 'description' => 'The reservation data.'],
                ],
                'resolve' => fn($source, array $arguments) => self::resolveCreate($arguments['input']),
                'description' => 'Create a new booking reservation.',
            ];
        }

        if (GqlHelper::canSchema('bookedReservations', 'update')) {
            $mutations['updateBookedReservation'] = [
                'name' => 'updateBookedReservation',
                'type' => self::getPayloadType('UpdateBookedReservationPayload', 'updateBookedReservation', 'updated'),
                'args' => [
                    'id' => ['type' => Type::nonNull(Type::id()), 'description' => 'The reservation ID to update.'],
                    'token' => ['type' => Type::nonNull(Type::string()), 'description' => 'The reservation confirmation token for authorization.'],
                    'input' => ['type' => Type::nonNull(UpdateReservationInput::getType()), 'description' => 'The updated reservation data.'],
                ],
                'resolve' => fn($source, array $arguments) => self::resolveUpdate((int) $arguments['id'], $arguments['token'], $arguments['input']),
                'description' => 'Update an existing booking reservation. Requires confirmation token for authorization.',
            ];
        }

        if (GqlHelper::canSchema('bookedReservations', 'cancel')) {
            $mutations['cancelBookedReservation'] = [
                'name' => 'cancelBookedReservation',
                'type' => self::getPayloadType('CancelBookedReservationPayload', 'cancelBookedReservation', 'cancelled'),
                'args' => [
                    'id' => ['type' => Type::nonNull(Type::id()), 'description' => 'The reservation ID to cancel.'],
                    'token' => ['type' => Type::nonNull(Type::string()), 'description' => 'The reservation confirmation token for authorization.'],
                    'reason' => ['type' => Type::string(), 'description' => 'The reason for cancellation (optional).'],
                ],
                'resolve' => fn($source, array $arguments) => self::resolveCancel((int) $arguments['id'], $arguments['token'], $arguments['reason'] ?? null),
                'description' => 'Cancel an existing booking reservation. Requires confirmation token for authorization.',
            ];
        }

        return $mutations;
    }

    protected static function getPayloadType(string $typeName, string $mutationName, string $verb): ObjectType
    {
        return GqlEntityRegistry::getEntity($typeName) ?? GqlEntityRegistry::createEntity($typeName, new ObjectType([
            'name' => $typeName,
            'description' => "Payload for the {$mutationName} mutation.",
            'fields' => [
                'success' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Whether the mutation was successful.'],
                'reservation' => ['type' => ReservationInterface::getType(), 'description' => "The {$verb} reservation."],
                'errors' => ['type' => Type::listOf(MutationError::getType()), 'description' => 'Any errors that occurred.'],
            ],
        ]));
    }

    private static function sanitizeInput(array $input): array
    {
        $sanitize = static fn(?string $v, int $max = 255): ?string =>
            $v !== null ? substr(trim(strip_tags($v)), 0, $max) : null;

        if (isset($input['userName'])) {
            $input['userName'] = $sanitize($input['userName']);
        }
        if (isset($input['userEmail'])) {
            $input['userEmail'] = strtolower($sanitize($input['userEmail']));
        }
        if (isset($input['userPhone'])) {
            $input['userPhone'] = $sanitize($input['userPhone']);
        }
        if (isset($input['notes'])) {
            $input['notes'] = $sanitize($input['notes'], 5000);
        }
        if (isset($input['userTimezone']) && !in_array($input['userTimezone'], \DateTimeZone::listIdentifiers(), true)) {
            $input['userTimezone'] = null;
        }

        return $input;
    }

    // Note: CAPTCHA/honeypot checks are not applied in GraphQL — schemas granting
    // bookedReservations:create should use authenticated tokens only. IP and email
    // rate limiting is enforced below.
    protected static function resolveCreate(array $input): array
    {
        try {
            // Rate limiting — match REST BookingController security behavior
            $request = Craft::$app->getRequest();
            if (!$request->getIsConsoleRequest()) {
                $ipAddress = $request->getUserIP();
                $validationService = Booked::getInstance()->getBookingValidation();

                $rateLimits = $validationService->checkAllRateLimits(
                    $input['userEmail'] ?? null,
                    $ipAddress
                );
                if (!$rateLimits['allowed']) {
                    return self::errorResponse(null, Craft::t('booked', 'booking.rateLimit'), 'RATE_LIMITED');
                }
            }

            // Sanitize input
            $input = self::sanitizeInput($input);

            $extras = [];
            if (!empty($input['extraIds']) && is_array($input['extraIds'])) {
                $quantities = $input['extraQuantities'] ?? [];
                foreach ($input['extraIds'] as $index => $extraId) {
                    $extras[$extraId] = $quantities[$index] ?? 1;
                }
            }

            return [
                'success' => true,
                'reservation' => Booked::getInstance()->booking->createReservation([
                    'serviceId' => (int) $input['serviceId'],
                    'employeeId' => !empty($input['employeeId']) ? (int) $input['employeeId'] : null,
                    'locationId' => !empty($input['locationId']) ? (int) $input['locationId'] : null,
                    'eventDateId' => !empty($input['eventDateId']) ? (int) $input['eventDateId'] : null,
                    'bookingDate' => $input['bookingDate'],
                    'startTime' => $input['startTime'],
                    'userName' => $input['userName'],
                    'userEmail' => $input['userEmail'],
                    'userPhone' => $input['userPhone'] ?? null,
                    'userTimezone' => $input['userTimezone'] ?? null,
                    'notes' => $input['notes'] ?? null,
                    'quantity' => $input['quantity'] ?? 1,
                    'extras' => $extras,
                    'source' => 'graphql',
                ]),
                'errors' => [],
            ];
        } catch (BookingValidationException $e) {
            return ['success' => false, 'reservation' => null, 'errors' => self::formatValidationErrors($e)];
        } catch (BookingConflictException $e) {
            return self::errorResponse(null, $e->getMessage(), 'BOOKING_CONFLICT');
        } catch (\Exception $e) {
            Craft::error('GraphQL createBookedReservation error: ' . $e->getMessage(), __METHOD__);
            return self::errorResponse(null, 'An internal error occurred. Please try again later.', 'INTERNAL_ERROR');
        }
    }

    // SECURITY: Requires confirmation token to prevent unauthorized modifications.
    protected static function resolveUpdate(int $id, string $token, array $input): array
    {
        try {
            $reservation = ReservationFactory::findById($id);

            if (!$reservation) {
                return self::errorResponse('id', 'Reservation not found.', 'NOT_FOUND');
            }

            if (!hash_equals($reservation->getConfirmationToken(), $token)) {
                Craft::warning("Unauthorized GraphQL update attempt for reservation ID: {$id}", __METHOD__);
                Booked::getInstance()->getAudit()->logAuthFailure('invalid_update_token', ['reservationId' => $id, 'source' => 'graphql']);
                return self::errorResponse('token', 'Invalid or missing authorization token.', 'UNAUTHORIZED');
            }

            $input = self::sanitizeInput($input);
            foreach (['userName', 'userEmail', 'userPhone', 'notes'] as $field) {
                if (isset($input[$field])) {
                    $reservation->$field = $input[$field];
                }
            }

            if (!$reservation->save()) {
                return ['success' => false, 'reservation' => null, 'errors' => self::formatReservationErrors($reservation)];
            }

            return ['success' => true, 'reservation' => $reservation, 'errors' => []];
        } catch (\Exception $e) {
            Craft::error('GraphQL updateBookedReservation error: ' . $e->getMessage(), __METHOD__);
            return self::errorResponse(null, 'An internal error occurred. Please try again later.', 'INTERNAL_ERROR');
        }
    }

    // SECURITY: Requires confirmation token to prevent unauthorized cancellations.
    protected static function resolveCancel(int $id, string $token, ?string $reason): array
    {
        try {
            $reservation = ReservationFactory::findById($id);

            if (!$reservation) {
                return self::errorResponse('id', 'Reservation not found.', 'NOT_FOUND');
            }

            if (!hash_equals($reservation->getConfirmationToken(), $token)) {
                Craft::warning("Unauthorized GraphQL cancellation attempt for reservation ID: {$id}", __METHOD__);
                Booked::getInstance()->getAudit()->logAuthFailure('invalid_cancel_token', ['reservationId' => $id, 'source' => 'graphql']);
                return self::errorResponse('token', 'Invalid or missing authorization token.', 'UNAUTHORIZED');
            }

            if (!$reservation->canBeCancelled()) {
                return ['success' => false, 'reservation' => $reservation, 'errors' => [
                    ['field' => null, 'message' => 'This reservation cannot be cancelled.', 'code' => 'CANCELLATION_NOT_ALLOWED'],
                ]];
            }

            if (!Booked::getInstance()->booking->cancelReservation($id, $reason ?? '')) {
                return ['success' => false, 'reservation' => $reservation, 'errors' => [
                    ['field' => null, 'message' => 'Failed to cancel reservation.', 'code' => 'CANCELLATION_FAILED'],
                ]];
            }

            return ['success' => true, 'reservation' => ReservationFactory::findById($id), 'errors' => []];
        } catch (\Exception $e) {
            Craft::error('GraphQL cancelBookedReservation error: ' . $e->getMessage(), __METHOD__);
            return self::errorResponse(null, 'An internal error occurred. Please try again later.', 'INTERNAL_ERROR');
        }
    }

    protected static function errorResponse(?string $field, string $message, string $code): array
    {
        return ['success' => false, 'reservation' => null, 'errors' => [['field' => $field, 'message' => $message, 'code' => $code]]];
    }

    protected static function formatValidationErrors(BookingValidationException $e): array
    {
        $errors = [];
        $validationErrors = $e->getValidationErrors();

        if (empty($validationErrors)) {
            return [['field' => null, 'message' => self::sanitizeErrorMessage($e->getMessage()), 'code' => 'VALIDATION_ERROR']];
        }

        foreach ($validationErrors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = ['field' => $field, 'message' => self::sanitizeErrorMessage($message), 'code' => 'VALIDATION_ERROR'];
            }
        }

        return $errors;
    }

    protected static function formatReservationErrors(ReservationContract $reservation): array
    {
        $errors = [];

        foreach ($reservation->getErrors() as $field => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = ['field' => $field, 'message' => self::sanitizeErrorMessage($message), 'code' => 'VALIDATION_ERROR'];
            }
        }

        return $errors;
    }

    /**
     * Strip internal details (table names, column names, file paths, SQL) from error messages
     * to prevent information leakage through the GraphQL API.
     */
    private static function sanitizeErrorMessage(string $message): string
    {
        if (preg_match('/SQLSTATE|\.php|{{%|`\w+`\.\`|INTO\s|FROM\s|SELECT\s/i', $message)) {
            Craft::error('Sanitized DB error in GraphQL response: ' . $message, __METHOD__);
            return 'A validation error occurred.';
        }

        return $message;
    }
}
