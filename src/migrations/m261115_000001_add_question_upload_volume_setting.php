<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

/**
 * Adds the volume setting (#146) that Assets-type reservation questions
 * upload into. Stored as a volume UID, matching Craft's own convention for a
 * config-resolvable reference to a volume.
 */
class m261115_000001_add_question_upload_volume_setting extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%booked_settings}}', 'questionUploadVolumeUid')) {
            $this->addColumn('{{%booked_settings}}', 'questionUploadVolumeUid', $this->string()->null()->after('defaultTimeSlotLength'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%booked_settings}}', 'questionUploadVolumeUid')) {
            $this->dropColumn('{{%booked_settings}}', 'questionUploadVolumeUid');
        }

        return true;
    }
}
