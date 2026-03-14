<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

class m260314_000000_AddSessionNotesToReservations extends Migration
{
    public function safeUp(): bool
    {
        $table = $this->db->schema->getTableSchema('{{%booked_reservations}}');
        if ($table === null) {
            return true;
        }
        if (!isset($table->columns['sessionNotes'])) {
            $this->addColumn('{{%booked_reservations}}', 'sessionNotes', $this->text()->after('notes'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        $table = $this->db->schema->getTableSchema('{{%booked_reservations}}');
        if ($table !== null && isset($table->columns['sessionNotes'])) {
            $this->dropColumn('{{%booked_reservations}}', 'sessionNotes');
        }
        return true;
    }
}
