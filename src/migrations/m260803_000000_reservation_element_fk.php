<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;

/**
 * Tie `booked_reservations.id` to `elements.id` with ON DELETE CASCADE (#93).
 *
 * Reservation::afterDelete() used to delete the row itself, which is wrong for
 * Craft's default soft delete: the element went to the trash while its data was
 * destroyed, so restoring produced an empty booking. Stopping that delete is
 * only safe once the database removes the row on a *hard* delete instead, which
 * is what this constraint does.
 *
 * Order matters. This migration must land before the element stops deleting the
 * row, or a hard delete in between leaves an orphaned reservation behind.
 */
class m260803_000000_reservation_element_fk extends Migration
{
    private const TABLE = '{{%booked_reservations}}';

    public function safeUp(): bool
    {
        // Rows whose element has already gone cannot satisfy the constraint.
        // They are unreachable anyway — a reservation is only ever resolved
        // through its element — so this clears dead data, not live bookings.
        $orphans = (new Query())
            ->select(['r.id'])
            ->from(['r' => self::TABLE])
            ->leftJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[r.id]]')
            ->where(['e.id' => null])
            ->column($this->db);

        if ($orphans !== []) {
            echo '    > deleting ' . count($orphans) . " reservation row(s) with no element\n";
            $this->delete(self::TABLE, ['id' => $orphans]);
        }

        if (!$this->elementForeignKeyExists()) {
            $this->addForeignKey(null, self::TABLE, 'id', Table::ELEMENTS, 'id', 'CASCADE', null);
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Deliberately not dropping the constraint: without it, and with
        // afterDelete() no longer removing the row, a hard delete would orphan
        // reservations. Rolling this back on its own would reintroduce the bug.
        return true;
    }

    /**
     * Whether `id` already references `elements.id`. Re-reads the schema so a
     * cached copy from earlier in the migration run can't answer for it.
     */
    private function elementForeignKeyExists(): bool
    {
        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            return false;
        }

        $elements = $this->db->getSchema()->getRawTableName(Table::ELEMENTS);

        foreach ($schema->foreignKeys as $fk) {
            // Shape: [0 => '<referenced table>', '<local col>' => '<foreign col>']
            if (($fk[0] ?? null) === $elements && ($fk['id'] ?? null) === 'id') {
                return true;
            }
        }

        return false;
    }
}
