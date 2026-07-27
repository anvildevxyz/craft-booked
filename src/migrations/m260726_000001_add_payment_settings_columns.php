<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

/**
 * Add the direct-payment settings columns to booked_settings: the payment mode
 * and Stripe credentials. Booked persists settings column-per-attribute, so
 * these must exist for the new settings to save/load. See PRD §7.6.
 */
class m260726_000001_add_payment_settings_columns extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%booked_settings}}';
        if (!$this->db->columnExists($table, 'paymentMode')) {
            $this->addColumn($table, 'paymentMode', $this->string(20)->null()->after('defaultCountryCode'));
        }
        if (!$this->db->columnExists($table, 'stripePublishableKey')) {
            $this->addColumn($table, 'stripePublishableKey', $this->text()->null()->after('paymentMode'));
        }
        if (!$this->db->columnExists($table, 'stripeSecretKey')) {
            $this->addColumn($table, 'stripeSecretKey', $this->text()->null()->after('stripePublishableKey'));
        }
        if (!$this->db->columnExists($table, 'stripeWebhookSecret')) {
            $this->addColumn($table, 'stripeWebhookSecret', $this->text()->null()->after('stripeSecretKey'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%booked_settings}}';
        foreach (['stripeWebhookSecret', 'stripeSecretKey', 'stripePublishableKey', 'paymentMode'] as $column) {
            if ($this->db->columnExists($table, $column)) {
                $this->dropColumn($table, $column);
            }
        }
        return true;
    }
}
