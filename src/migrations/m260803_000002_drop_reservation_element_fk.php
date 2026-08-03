<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Drop the `booked_reservations.id` -> `elements.id` constraint added in #93.
 *
 * It assumed every reservation is a Craft element. Only half of them are:
 * {@see \anvildev\booked\factories\ReservationFactory::isElementMode()} returns
 * `isCommerceEnabled()`, so an install without Commerce — which is most of them,
 * direct Stripe payments existing precisely so Commerce is not required — stores
 * reservations as plain ActiveRecords with no row in `elements` at all. The
 * constraint rejected every one of those inserts, which meant no bookings could
 * be created on those installs.
 *
 * Nothing is lost by removing it. Reservation::afterDelete() already deletes the
 * row itself on a hard delete, which is the job the cascade was doing; the rest
 * of the soft-delete fix — preserving the row, releasing the slot, reclaiming it
 * on restore — never depended on the constraint.
 */
class m260803_000002_drop_reservation_element_fk extends Migration
{
    private const TABLE = '{{%booked_reservations}}';

    public function safeUp(): bool
    {
        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            return true;
        }

        $elements = $this->db->getSchema()->getRawTableName(Table::ELEMENTS);

        // The constraint was created with a generated name, so it has to be
        // found by what it points at rather than by what it is called.
        foreach ($schema->foreignKeys as $name => $fk) {
            if (($fk[0] ?? null) === $elements && ($fk['id'] ?? null) === 'id') {
                echo "    > dropping foreign key {$name}\n";
                $this->dropForeignKey((string) $name, self::TABLE);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Restoring it would reintroduce the bug on every Commerce-free install.
        return true;
    }
}
