<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

class m260314_000000_AddSessionNotesToReservations extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn('{{%booked_reservations}}', 'sessionNotes', $this->text()->after('notes'));
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%booked_reservations}}', 'sessionNotes');
        return true;
    }
}
