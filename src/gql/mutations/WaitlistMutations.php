<?php

namespace anvildev\booked\gql\mutations;

use anvildev\booked\Booked;
use anvildev\booked\gql\types\MutationError;
use Craft;
use craft\gql\base\Mutation;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class WaitlistMutations extends Mutation
{
    public static function getMutations(): array
    {
        $mutations = [];

        if (GqlHelper::canSchema('bookedReservations', 'create')) {
            $mutations['convertBookedWaitlistEntry'] = [
                'name' => 'convertBookedWaitlistEntry',
                'type' => self::getPayloadType(),
                'args' => [
                    'token' => ['type' => Type::nonNull(Type::string()), 'description' => 'The waitlist conversion token.'],
                ],
                'resolve' => fn($source, array $arguments) => self::resolveConvert($arguments['token']),
                'description' => 'Validate a waitlist conversion token. Returns success if the token is valid and the entry can be converted to a booking.',
            ];
        }

        return $mutations;
    }

    protected static function getPayloadType(): ObjectType
    {
        $typeName = 'ConvertBookedWaitlistEntryPayload';

        return GqlEntityRegistry::getEntity($typeName) ?? GqlEntityRegistry::createEntity($typeName, new ObjectType([
            'name' => $typeName,
            'description' => 'Payload for the convertBookedWaitlistEntry mutation.',
            'fields' => [
                'success' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'Whether the conversion token is valid.'],
                'waitlistEntryId' => ['type' => Type::int(), 'description' => 'The validated waitlist entry ID.'],
                'errors' => ['type' => Type::listOf(MutationError::getType()), 'description' => 'Any errors that occurred.'],
            ],
        ]));
    }

    protected static function resolveConvert(string $token): array
    {
        try {
            $entry = Booked::getInstance()->getWaitlist()->validateConversionToken($token);

            if (!$entry) {
                return self::errorResponse('token', 'Invalid or expired conversion token.', 'INVALID_TOKEN');
            }

            return ['success' => true, 'waitlistEntryId' => $entry->id, 'errors' => []];
        } catch (\Exception $e) {
            Craft::error('GraphQL convertBookedWaitlistEntry error: ' . $e->getMessage(), __METHOD__);
            return self::errorResponse(null, 'An internal error occurred. Please try again later.', 'INTERNAL_ERROR');
        }
    }

    protected static function errorResponse(?string $field, string $message, string $code): array
    {
        return ['success' => false, 'waitlistEntryId' => null, 'errors' => [['field' => $field, 'message' => $message, 'code' => $code]]];
    }
}
