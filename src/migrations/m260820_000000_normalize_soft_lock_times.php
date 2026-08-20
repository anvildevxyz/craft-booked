<?php

namespace anvildev\booked\migrations;

use anvildev\booked\services\SoftLockService;
use craft\db\Migration;
use craft\db\Query;

/**
 * Canonicalise soft lock times to the H:i form.
 *
 * Lock times live in a varchar column, so every check against them is a
 * string comparison. Seconds-carrying rows ('10:00:00', written from event
 * dates' TIME column or by API clients) sort AFTER their H:i twin ('10:00'),
 * which made a 09:00–10:00 hold overlap the 10:00 slot beside it.
 *
 * SoftLockService now normalises before writing. Live rows keep whichever
 * spelling they were created with — and can keep blocking the wrong slot for
 * up to the hold's max lifetime after deploy — so they are rewritten here.
 */
class m260820_000000_normalize_soft_lock_times extends Migration
{
    private const TABLE = '{{%booked_soft_locks}}';

    public function safeUp(): bool
    {
        $rows = (new Query())
            ->select(['id', 'startTime', 'endTime'])
            ->from(self::TABLE)
            ->all($this->db);

        foreach ($rows as $row) {
            $startTime = SoftLockService::normalizeTime($row['startTime']);
            $endTime = SoftLockService::normalizeTime($row['endTime']);
            if ($startTime !== $row['startTime'] || $endTime !== $row['endTime']) {
                $this->update(self::TABLE, [
                    'startTime' => $startTime,
                    'endTime' => $endTime,
                ], ['id' => $row['id']], updateTimestamp: false);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        // The H:i form is also valid input for every consumer; nothing to undo.
        return true;
    }
}
