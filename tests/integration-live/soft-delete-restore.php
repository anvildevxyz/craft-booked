<?php

/**
 * Trashing and restoring a booking, against a live install (#93).
 *
 * The unit suite cannot reach this: soft delete is Craft's own trash mechanism,
 * so proving that a trashed booking keeps its data — and that a hard delete
 * still removes the row — needs a real database with the foreign key in place.
 *
 * The claims under test, in the order that matters if they are wrong:
 *
 *   1. Trashing a booking preserves its row and every field on it. This is the
 *      bug: afterDelete() used to delete the row outright, so a restore
 *      produced an empty reservation.
 *   2. A trashed booking releases its slot, so it stops holding a seat against
 *      everyone else while it sits in the trash.
 *   3. Restoring brings the data back intact and reclaims the slot.
 *   4. A booking whose slot was taken while it was trashed still restores — it
 *      simply comes back without that slot, rather than failing or stealing it.
 *   5. A hard delete still removes the row, now via ON DELETE CASCADE.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/craft-booked/tests/integration-live/soft-delete-restore.php
 *
 * Seeds and cleans up after itself. Exits non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\Service;
use anvildev\booked\records\ReservationRecord;
use craft\db\Query;

const TAG = 'SOFTDEL';

$failures = 0;
$made = [];
$elements = Craft::$app->getElements();
$db = Craft::$app->getDb();

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

/** The reservation row straight from the table, bypassing the element layer. */
function row(int $id): ?array
{
    return (new Query())->from('{{%booked_reservations}}')->where(['id' => $id])->one() ?: null;
}

// ------------------------------------------------------------------ fixture
foreach (Reservation::find()->siteId('*')->status(null)->trashed(null)->all() as $old) {
    if (str_contains((string) $old->userEmail, strtolower(TAG))) {
        Craft::$app->getElements()->deleteElement($old, true);
    }
}

$service = Service::find()->siteId('*')->status(null)->one();
$employee = Employee::find()->siteId('*')->status(null)->one();
if (!$service || !$employee) {
    fwrite(STDERR, "Needs at least one service and one employee.\n");
    exit(1);
}

$date = (new DateTime('+21 days'))->format('Y-m-d');

function book(string $suffix, string $date, Service $service, Employee $employee, string $start = '11:00'): Reservation
{
    $r = new Reservation();
    $r->userName = TAG . ' ' . $suffix;
    $r->userEmail = strtolower(TAG) . "-{$suffix}@example.test";
    $r->userPhone = '+41 44 000 00 00';
    $r->notes = 'Original note for ' . $suffix;
    $r->bookingDate = $date;
    $r->startTime = $start;
    $r->endTime = (new DateTime($start))->modify('+1 hour')->format('H:i');
    $r->status = ReservationRecord::STATUS_CONFIRMED;
    $r->serviceId = $service->id;
    $r->employeeId = $employee->id;
    $r->quantity = 1;
    if (!Craft::$app->getElements()->saveElement($r, false)) {
        fwrite(STDERR, "seed {$suffix}: " . json_encode($r->getErrors()) . "\n");
        exit(1);
    }
    return $r;
}

echo "Trashing a booking\n";

$booking = book('a', $date, $service, $employee);
$made[] = $booking;
$id = (int) $booking->id;
$before = row($id);
check('a booking is seeded with its slot held', ($before['activeSlotKey'] ?? null) !== null, 'activeSlotKey=' . var_export($before['activeSlotKey'] ?? null, true));

$elements->deleteElement($booking);   // soft — Craft's default

$after = row($id);
check('the row survives the trash', $after !== null, $after === null ? 'ROW DELETED — the booking lost its data' : 'row present');
check(
    '…with its data intact',
    ($after['userEmail'] ?? null) === $booking->userEmail && ($after['notes'] ?? null) === 'Original note for a',
    'email=' . var_export($after['userEmail'] ?? null, true) . ' notes=' . var_export($after['notes'] ?? null, true),
);
check('…and the element is in the trash', (new Query())->from('{{%elements}}')->where(['id' => $id])->andWhere(['not', ['dateDeleted' => null]])->exists());
check(
    '…and it no longer holds its slot',
    ($after['activeSlotKey'] ?? null) === null,
    'activeSlotKey=' . var_export($after['activeSlotKey'] ?? null, true),
);

// ------------------------------------------------------ the seat is free now
$rival = book('rival', $date, $service, $employee);
$made[] = $rival;
$rivalKey = row((int) $rival->id)['activeSlotKey'] ?? null;
check(
    'someone else can take the released slot',
    $rivalKey !== null,
    $rivalKey !== null ? 'rival holds ' . $rivalKey : 'the trashed booking was still holding it',
);
// Free it again so the restore below has its slot back.
$elements->deleteElement($rival, true);

echo "\nRestoring it\n";

$trashed = Reservation::find()->id($id)->siteId('*')->status(null)->trashed()->one();
check('the trashed booking is findable', $trashed !== null);

if ($trashed) {
    $elements->restoreElement($trashed);
    $restored = row($id);

    check('the data is still intact after restore', ($restored['userEmail'] ?? null) === $booking->userEmail, 'email=' . var_export($restored['userEmail'] ?? null, true));
    check('…notes survived', ($restored['notes'] ?? null) === 'Original note for a', var_export($restored['notes'] ?? null, true));
    check('…the element is out of the trash', (new Query())->from('{{%elements}}')->where(['id' => $id, 'dateDeleted' => null])->exists());
    check(
        '…and the slot is reclaimed',
        ($restored['activeSlotKey'] ?? null) !== null,
        'activeSlotKey=' . var_export($restored['activeSlotKey'] ?? null, true),
    );
}

echo "\nRestoring into a slot someone else took\n";

$contested = book('b', $date, $service, $employee, '14:00');
$made[] = $contested;
$contestedId = (int) $contested->id;
$elements->deleteElement($contested);

// Take the slot while it sits in the trash.
$squatter = book('squatter', $date, $service, $employee, '14:00');
$made[] = $squatter;
check('the vacated slot can be taken', (row((int) $squatter->id)['activeSlotKey'] ?? null) !== null);

$trashedContested = Reservation::find()->id($contestedId)->siteId('*')->status(null)->trashed()->one();
$restoredOk = $trashedContested !== null && $elements->restoreElement($trashedContested);
check(
    'the booking still restores',
    $restoredOk,
    $restoredOk ? 'restored' : 'REFUSED; essentials errors: ' . json_encode($trashedContested?->getErrors()),
);
$contestedRow = row($contestedId);
check('…with its data', ($contestedRow['userEmail'] ?? null) === $contested->userEmail);
check(
    '…but without stealing the slot',
    ($contestedRow['activeSlotKey'] ?? null) === null,
    'activeSlotKey=' . var_export($contestedRow['activeSlotKey'] ?? null, true),
);
check(
    '…and the other booking keeps it',
    (row((int) $squatter->id)['activeSlotKey'] ?? null) !== null,
);

echo "\nHard delete still removes the row\n";

$doomed = book('c', $date, $service, $employee, '16:00');
$doomedId = (int) $doomed->id;
$elements->deleteElement($doomed, true);   // hard
$doomedRow = row($doomedId);
check('the row is gone via ON DELETE CASCADE', $doomedRow === null, $doomedRow === null ? 'row deleted with the element' : 'ROW STILL PRESENT — the cascade did not fire');
check('…and so is the element', !(new Query())->from('{{%elements}}')->where(['id' => $doomedId])->exists());

// ------------------------------------------------------------------ teardown
foreach ($made as $el) {
    $fresh = Reservation::find()->id($el->id)->siteId('*')->status(null)->trashed(null)->one();
    if ($fresh) {
        $elements->deleteElement($fresh, true);
    }
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
