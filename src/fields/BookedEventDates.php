<?php

namespace anvildev\booked\fields;

use anvildev\booked\elements\EventDate;
use Craft;
use craft\fields\BaseRelationField;

class BookedEventDates extends BaseRelationField
{
    public static function displayName(): string
    {
        return Craft::t('booked', 'field.bookedEventDates');
    }

    public static function elementType(): string
    {
        return EventDate::class;
    }

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('booked', 'field.addEventDate');
    }
}
