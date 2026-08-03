<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\records\ReservationRecord;
use anvildev\booked\tests\Support\TestCase;

/**
 * Guards the soft-delete contract (#93) and the slot key it depends on.
 *
 * Trashing and restoring is Craft's own mechanism and needs a database, so the
 * behaviour itself is covered by tests/integration-live/soft-delete-restore.php.
 * What can be checked here is that the two things that made the bug possible
 * cannot come back: an unconditional row delete, and a slot key that varies with
 * how the reservation happened to be loaded.
 */
class ReservationSoftDeleteTest extends TestCase
{
    /**
     * "14:00" from a request and "14:00:00" read back from the TIME column have
     * to produce the same key, or the unique index guarding against
     * double-booking compares two strings that can never collide.
     */
    public function testSlotTimeNormalisesRegardlessOfSource(): void
    {
        $this->assertSame('14:00', ReservationRecord::normalizeSlotTime('14:00'));
        $this->assertSame('14:00', ReservationRecord::normalizeSlotTime('14:00:00'));
        $this->assertSame(
            ReservationRecord::normalizeSlotTime('09:30'),
            ReservationRecord::normalizeSlotTime('09:30:00'),
            'The same slot must yield one key however the reservation was loaded',
        );
    }

    public function testSlotTimeToleratesAnAbsentTime(): void
    {
        $this->assertSame('', ReservationRecord::normalizeSlotTime(null));
    }

    /**
     * The key is assembled in beforeSave(); if it ever interpolates startTime
     * directly again, the two spellings diverge and the index stops working.
     */
    public function testSlotKeyIsBuiltFromTheNormalisedTime(): void
    {
        $body = $this->sourceOfMethod(ReservationRecord::class, 'beforeSave');

        $this->assertStringContainsString('normalizeSlotTime($this->startTime)', $body);
        $this->assertStringNotContainsString("'|' . \$this->startTime . '|'", $body);
    }

    /**
     * A soft delete must leave the row alone — deleting it is what destroyed the
     * booking's data — and must release the slot so the trash stops holding a
     * seat. A hard delete needs no help: the id is a cascading foreign key.
     */
    public function testSoftDeleteKeepsTheRowAndReleasesTheSlot(): void
    {
        $body = $this->sourceOfMethod(\anvildev\booked\elements\Reservation::class, 'afterDelete');

        $this->assertStringContainsString('$this->hardDelete', $body, 'afterDelete() must distinguish a hard delete from a soft one');
        // The row may only be deleted on the hard-delete branch. Anywhere else
        // and a trashed booking loses its data again.
        [$hard, $soft] = explode('} else {', $body, 2);
        $this->assertStringContainsString('getRecord()?->delete()', $hard, 'a hard delete should still remove the row if the FK is not there yet');
        $this->assertStringNotContainsString('delete()', $soft, 'a soft delete must never remove the reservation row');
        $this->assertStringContainsString("'activeSlotKey' => null", $body, 'a trashed booking must release its slot');
    }

    public function testRestoreReclaimsTheSlotAndSurvivesLosingIt(): void
    {
        $body = $this->sourceOfMethod(\anvildev\booked\elements\Reservation::class, 'afterRestore');

        $this->assertStringContainsString('save(false)', $body, 'restoring must recompute the slot key');
        $this->assertStringContainsString(
            'IntegrityException',
            $body,
            'a slot taken while the booking was trashed must not make the restore fail',
        );
    }

    /**
     * A cascading id -> elements.id constraint looks like the tidy way to make
     * afterDelete() unnecessary, and was briefly added. It cannot be used here:
     * a reservation is only a Craft element when Commerce is enabled, so on a
     * Commerce-free install the row has no `elements` counterpart and the
     * constraint rejects every insert — no booking can be created at all.
     */
    public function testInstallDoesNotConstrainReservationsToElements(): void
    {
        $install = file_get_contents(dirname(__DIR__, 3) . '/src/migrations/Install.php');

        $this->assertStringNotContainsString(
            "addForeignKey(null, '{{%booked_reservations}}', 'id', '{{%elements}}'",
            $install,
            'Reservations must not be constrained to elements — they are plain records without Commerce',
        );
    }
}
