<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

/**
 * Native payment records for direct (Commerce-free) payment mode. Amounts are
 * stored in minor units (integer). See PRD §7.5.
 */
class m260726_000000_add_payments_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%booked_payments}}')) {
            return true;
        }

        $this->createTable('{{%booked_payments}}', [
            'id' => $this->primaryKey(),
            'reservationId' => $this->integer()->notNull(),
            'gateway' => $this->string(64)->notNull(),
            'externalId' => $this->string(255)->null(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'amount' => $this->integer()->notNull()->defaultValue(0),
            'currency' => $this->string(3)->notNull(),
            'refundedAmount' => $this->integer()->notNull()->defaultValue(0),
            'payload' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%booked_payments}}', 'reservationId');
        $this->createIndex(null, '{{%booked_payments}}', 'externalId');
        $this->addForeignKey(null, '{{%booked_payments}}', 'reservationId', '{{%booked_reservations}}', 'id', 'CASCADE', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%booked_payments}}');
        return true;
    }
}
