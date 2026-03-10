<?php

namespace anvildev\booked\fields;

use anvildev\booked\elements\Service;
use Craft;
use craft\fields\BaseRelationField;

class BookedServices extends BaseRelationField
{
    public static function displayName(): string
    {
        return Craft::t('booked', 'field.bookedServices');
    }

    public static function elementType(): string
    {
        return Service::class;
    }

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('booked', 'field.addService');
    }
}
