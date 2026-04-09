<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

class m260409_100000_add_flexible_day_fields extends Migration
{
    public function safeUp(): bool
    {
        // Widen durationType from string(10) to string(20) to fit 'flexible_days' (13 chars)
        $this->alterColumn('{{%booked_services}}', 'durationType', $this->string(20)->notNull()->defaultValue('minutes'));

        if (!$this->db->columnExists('{{%booked_services}}', 'minDays')) {
            $this->addColumn('{{%booked_services}}', 'minDays', $this->integer()->null()->after('pricingMode'));
        }
        if (!$this->db->columnExists('{{%booked_services}}', 'maxDays')) {
            $this->addColumn('{{%booked_services}}', 'maxDays', $this->integer()->null()->after('minDays'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%booked_services}}', 'maxDays')) {
            $this->dropColumn('{{%booked_services}}', 'maxDays');
        }
        if ($this->db->columnExists('{{%booked_services}}', 'minDays')) {
            $this->dropColumn('{{%booked_services}}', 'minDays');
        }

        return true;
    }
}
