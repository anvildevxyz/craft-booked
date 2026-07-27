<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

/**
 * Add the `pendingPaymentTtlMinutes` setting column to booked_settings — how long
 * a direct-payment booking may stay pending before it's garbage-collected. Booked
 * persists settings column-per-attribute, so the column must exist to save/load.
 */
class m260727_000000_add_pending_payment_ttl extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%booked_settings}}';
        if (!$this->db->columnExists($table, 'pendingPaymentTtlMinutes')) {
            $this->addColumn(
                $table,
                'pendingPaymentTtlMinutes',
                $this->integer()->notNull()->defaultValue(30)->after('stripeWebhookSecret'),
            );
        }
        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%booked_settings}}';
        if ($this->db->columnExists($table, 'pendingPaymentTtlMinutes')) {
            $this->dropColumn($table, 'pendingPaymentTtlMinutes');
        }
        return true;
    }
}
