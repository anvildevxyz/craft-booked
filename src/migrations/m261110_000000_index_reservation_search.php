<?php

namespace anvildev\booked\migrations;

use anvildev\booked\elements\Reservation;
use Craft;
use craft\db\Migration;

/**
 * Puts existing reservations into Craft's search index.
 *
 * The bookings screen now searches through that index. Bookings saved as
 * elements are indexed on save, but rows that were attached to elements by
 * m261109_000000_reservations_as_elements never went through a save, so the
 * index cannot find them by name, email or phone until this runs.
 */
class m261110_000000_index_reservation_search extends Migration
{
    public function safeUp(): bool
    {
        $search = Craft::$app->getSearch();

        foreach (Reservation::find()->siteId('*')->each(100) as $reservation) {
            $search->indexElementAttributes($reservation);
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261110_000000_index_reservation_search cannot be reverted.\n";

        return false;
    }
}
