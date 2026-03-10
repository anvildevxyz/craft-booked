<?php

namespace anvildev\booked\gql\resolvers\elements;

use anvildev\booked\elements\db\EventDateQuery;
use anvildev\booked\elements\EventDate;
use craft\gql\base\ElementResolver;

class EventDateResolver extends ElementResolver
{
    private const ALLOWED_QUERY_PARAMS = [
        // Base element arguments
        'id', 'uid',
        // Element arguments
        'site', 'siteId', 'unique', 'preferSites', 'title', 'slug', 'uri',
        'search', 'searchTermOptions', 'relatedTo', 'notRelatedTo',
        'relatedToAssets', 'relatedToEntries', 'relatedToUsers',
        'relatedToCategories', 'relatedToTags', 'relatedToAll',
        'ref', 'fixedOrder', 'inReverse', 'dateCreated', 'dateUpdated',
        'offset', 'language', 'limit', 'orderBy', 'siteSettingsId',
        // Status/draft/revision arguments
        'status', 'archived', 'trashed',
        'drafts', 'draftOf', 'draftId', 'draftCreator', 'provisionalDrafts',
        'revisions', 'revisionOf', 'revisionId', 'revisionCreator',
        // EventDate-specific arguments
        'locationId', 'eventDate', 'endDate', 'startTime', 'endTime', 'enabled',
    ];

    public static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
    {
        $query = $source ?? EventDate::find()->siteId('*');

        if (!$query instanceof EventDateQuery) {
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

        return $query;
    }
}
