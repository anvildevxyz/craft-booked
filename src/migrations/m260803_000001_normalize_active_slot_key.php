<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * Canonicalise `activeSlotKey` to the H:i form.
 *
 * The key is `date|time|employeeId` and guards against double-booking through a
 * unique index. It was built by interpolating `startTime` as-is, which is
 * "14:00" for a booking created from a request and "14:00:00" for one read back
 * from the TIME column. Two spellings of the same slot are two different keys,
 * so the index never saw the collision and the same employee could be booked
 * twice at the same time — one of the two only needing to have been re-saved.
 *
 * ReservationRecord now normalises before writing. Existing rows keep whichever
 * spelling they were last saved with, so they are rewritten here.
 */
class m260803_000001_normalize_active_slot_key extends Migration
{
    private const TABLE = '{{%booked_reservations}}';

    public function safeUp(): bool
    {
        $rows = (new Query())
            ->select(['id', 'bookingDate', 'startTime', 'employeeId', 'activeSlotKey'])
            ->from(self::TABLE)
            ->where(['not', ['activeSlotKey' => null]])
            ->all($this->db);

        // Group by the key each row *should* have, so a group with more than one
        // member is a real double booking that the broken key was hiding.
        $byKey = [];
        foreach ($rows as $row) {
            $date = substr((string) $row['bookingDate'], 0, 10);
            $time = substr((string) $row['startTime'], 0, 5);
            $byKey[$date . '|' . $time . '|' . $row['employeeId']][] = $row;
        }

        $rewritten = 0;
        foreach ($byKey as $key => $group) {
            if (count($group) > 1) {
                // Leave these alone. Rewriting them would violate the unique
                // index, and picking which booking loses its slot is not a
                // decision a migration should make silently.
                $ids = implode(', ', array_column($group, 'id'));
                echo "    > WARNING: reservations {$ids} are booked into the same slot ({$key}).\n";
                echo "      Their slot keys are left as they are; resolve the conflict, then re-save them.\n";
                continue;
            }

            $row = $group[0];
            if ($row['activeSlotKey'] !== $key) {
                $this->update(self::TABLE, ['activeSlotKey' => $key], ['id' => $row['id']]);
                $rewritten++;
            }
        }

        if ($rewritten > 0) {
            echo "    > normalised {$rewritten} slot key(s)\n";
        }

        return true;
    }

    public function safeDown(): bool
    {
        // The normalised form is the correct one; there is nothing to undo.
        return true;
    }
}
