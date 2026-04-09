<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

class m260409_000000_add_multi_day_fields extends Migration
{
    public function safeUp(): bool
    {
        // Service fields
        if (!$this->db->columnExists('{{%booked_services}}', 'durationType')) {
            $this->addColumn('{{%booked_services}}', 'durationType', $this->string(10)->notNull()->defaultValue('minutes')->after('duration'));
        }
        if (!$this->db->columnExists('{{%booked_services}}', 'pricingMode')) {
            $this->addColumn('{{%booked_services}}', 'pricingMode', $this->string(10)->notNull()->defaultValue('flat')->after('price'));
        }
        if (!$this->db->columnExists('{{%booked_services}}', 'minDays')) {
            $this->addColumn('{{%booked_services}}', 'minDays', $this->integer()->null()->after('pricingMode'));
        }
        if (!$this->db->columnExists('{{%booked_services}}', 'maxDays')) {
            $this->addColumn('{{%booked_services}}', 'maxDays', $this->integer()->null()->after('minDays'));
        }

        // Reservation fields
        if (!$this->db->columnExists('{{%booked_reservations}}', 'endDate')) {
            $this->addColumn('{{%booked_reservations}}', 'endDate', $this->date()->null()->after('bookingDate'));
        }

        // Make startTime/endTime nullable for full-day bookings
        $this->alterColumn('{{%booked_reservations}}', 'startTime', $this->time()->null());
        $this->alterColumn('{{%booked_reservations}}', 'endTime', $this->time()->null());

        // Soft locks endDate column
        if (!$this->db->columnExists('{{%booked_soft_locks}}', 'endDate')) {
            $this->addColumn('{{%booked_soft_locks}}', 'endDate', $this->date()->null()->after('date'));
        }

        // Make soft lock startTime/endTime nullable for multi-day locks
        $this->alterColumn('{{%booked_soft_locks}}', 'startTime', $this->string(10)->null());
        $this->alterColumn('{{%booked_soft_locks}}', 'endTime', $this->string(10)->null());

        // Index for date range overlap queries (may already exist on fresh installs)
        try {
            $this->createIndex(
                'idx_reservations_date_range',
                '{{%booked_reservations}}',
                ['bookingDate', 'endDate', 'employeeId', 'status'],
            );
        } catch (\Throwable) {
            // Index already exists (fresh install)
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropIndexIfExists('{{%booked_reservations}}', 'idx_reservations_date_range');

        // Restore soft lock startTime/endTime to NOT NULL before dropping endDate
        $this->alterColumn('{{%booked_soft_locks}}', 'startTime', $this->string(10)->notNull());
        $this->alterColumn('{{%booked_soft_locks}}', 'endTime', $this->string(10)->notNull());

        if ($this->db->columnExists('{{%booked_soft_locks}}', 'endDate')) {
            $this->dropColumn('{{%booked_soft_locks}}', 'endDate');
        }

        // Restore reservation startTime/endTime to NOT NULL before dropping endDate
        $this->alterColumn('{{%booked_reservations}}', 'startTime', $this->time()->notNull());
        $this->alterColumn('{{%booked_reservations}}', 'endTime', $this->time()->notNull());

        if ($this->db->columnExists('{{%booked_reservations}}', 'endDate')) {
            $this->dropColumn('{{%booked_reservations}}', 'endDate');
        }
        if ($this->db->columnExists('{{%booked_services}}', 'maxDays')) {
            $this->dropColumn('{{%booked_services}}', 'maxDays');
        }
        if ($this->db->columnExists('{{%booked_services}}', 'minDays')) {
            $this->dropColumn('{{%booked_services}}', 'minDays');
        }
        if ($this->db->columnExists('{{%booked_services}}', 'pricingMode')) {
            $this->dropColumn('{{%booked_services}}', 'pricingMode');
        }
        if ($this->db->columnExists('{{%booked_services}}', 'durationType')) {
            $this->dropColumn('{{%booked_services}}', 'durationType');
        }

        return true;
    }
}
