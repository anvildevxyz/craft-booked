<?php

namespace anvildev\booked\migrations;

use anvildev\booked\elements\Reservation;
use anvildev\booked\records\ReservationRecord;
use Craft;
use craft\db\Migration;
use craft\db\Query;

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
        $ids = (new Query())
            ->select(['id'])
            ->from(ReservationRecord::tableName())
            ->column();

        foreach (array_chunk($ids, 100) as $chunk) {
            $batch = Reservation::find()
                ->siteId('*')
                ->id($chunk)
                ->all();

            foreach ($batch as $reservation) {
                $search->indexElementAttributes($reservation);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261110_000000_index_reservation_search cannot be reverted.\n";

        return false;
    }
}
