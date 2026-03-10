<?php

namespace anvildev\booked\gql\resolvers\elements;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationQueryInterface;
use anvildev\booked\factories\ReservationFactory;
use craft\gql\base\ElementResolver;

class ReservationResolver extends ElementResolver
{
    /**
     * Allowed query parameter names derived from ReservationArguments::getArguments().
     * Only these GraphQL arguments may be applied to the query object.
     */
    private const ALLOWED_QUERY_PARAMS = [
        // Base element arguments (craft\gql\base\Arguments)
        'id',
        'uid',
        // Element arguments (craft\gql\base\ElementArguments)
        'site',
        'siteId',
        'unique',
        'preferSites',
        'title',
        'slug',
        'uri',
        'search',
        'searchTermOptions',
        'relatedTo',
        'notRelatedTo',
        'relatedToAssets',
        'relatedToEntries',
        'relatedToUsers',
        'relatedToCategories',
        'relatedToTags',
        'relatedToAll',
        'ref',
        'fixedOrder',
        'inReverse',
        'dateCreated',
        'dateUpdated',
        'offset',
        'language',
        'limit',
        'orderBy',
        'siteSettingsId',
        // Status arguments (conditional on permissions)
        'status',
        'archived',
        'trashed',
        // Draft arguments (conditional on permissions)
        'drafts',
        'draftOf',
        'draftId',
        'draftCreator',
        'provisionalDrafts',
        // Revision arguments (conditional on permissions)
        'revisions',
        'revisionOf',
        'revisionId',
        'revisionCreator',
        // Reservation-specific arguments
        'bookingDate',
        'serviceId',
        'employeeId',
        'locationId',
        'userId',
    ];

    public static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
    {
        $query = $source ?? ReservationFactory::find();

        if (!$query instanceof ReservationQueryInterface) {
            return $query;
        }

        foreach ($arguments as $key => $value) {
            if (in_array($key, self::ALLOWED_QUERY_PARAMS, true)) {
                $query->$key($value);
            }
        }

        if (!isset($arguments['limit']) || $arguments['limit'] > 100) {
            $query->limit(100);
        }

        // Scope to current user's managed employees for non-admin users
        $user = \Craft::$app->getUser()->getIdentity();
        if ($user && !$user->admin) {
            Booked::getInstance()->getPermission()->scopeReservationQuery($query);
        } elseif (!$user) {
            // Unauthenticated — return no results
            $query->id(0);
        }

        return $query;
    }
}
