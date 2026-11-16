<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Add `reservationFieldLayoutId` to booked_services (#146).
 *
 * A service's custom booking questions live in a Craft field layout owned
 * by the service; the answers live on the reservation that booked it, via
 * Reservation::getFieldLayout() resolving to this same layout. The column
 * only has to point at it — field values themselves need no schema change,
 * Craft keys them by field handle regardless of which element holds them.
 */
class m261115_000000_add_service_reservation_field_layout extends Migration
{
    private const TABLE = '{{%booked_services}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'reservationFieldLayoutId')) {
            $this->addColumn(self::TABLE, 'reservationFieldLayoutId', $this->integer()->null());
            $this->addForeignKey(null, self::TABLE, 'reservationFieldLayoutId', Table::FIELDLAYOUTS, 'id', 'SET NULL', null);
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::TABLE, 'reservationFieldLayoutId')) {
            // The column can't be dropped while its foreign key still references it.
            $fkName = $this->foreignKeyName(self::TABLE, 'reservationFieldLayoutId');
            if ($fkName !== null) {
                $this->dropForeignKey($fkName, self::TABLE);
            }
            $this->dropColumn(self::TABLE, 'reservationFieldLayoutId');
        }

        return true;
    }

    /**
     * The generated constraint name, or null when the column carries no foreign key.
     */
    private function foreignKeyName(string $table, string $column): ?string
    {
        $schema = $this->db->getTableSchema($table, true);
        if ($schema === null) {
            return null;
        }

        foreach ($schema->foreignKeys as $name => $definition) {
            if (array_key_exists($column, array_slice($definition, 1, null, true))) {
                return (string) $name;
            }
        }

        return null;
    }
}
