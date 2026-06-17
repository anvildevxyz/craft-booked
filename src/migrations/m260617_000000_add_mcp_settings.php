<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

/**
 * Adds the default-off MCP authorization settings: writes (and, separately,
 * refunds) over the MCP tool surface must be explicitly enabled by an admin.
 */
class m260617_000000_add_mcp_settings extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%booked_settings}}', 'mcpWriteEnabled')) {
            $this->addColumn('{{%booked_settings}}', 'mcpWriteEnabled', $this->boolean()->notNull()->defaultValue(false)->after('defaultTimeSlotLength'));
        }
        if (!$this->db->columnExists('{{%booked_settings}}', 'mcpAllowRefunds')) {
            $this->addColumn('{{%booked_settings}}', 'mcpAllowRefunds', $this->boolean()->notNull()->defaultValue(false)->after('mcpWriteEnabled'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%booked_settings}}', 'mcpAllowRefunds')) {
            $this->dropColumn('{{%booked_settings}}', 'mcpAllowRefunds');
        }
        if ($this->db->columnExists('{{%booked_settings}}', 'mcpWriteEnabled')) {
            $this->dropColumn('{{%booked_settings}}', 'mcpWriteEnabled');
        }

        return true;
    }
}
