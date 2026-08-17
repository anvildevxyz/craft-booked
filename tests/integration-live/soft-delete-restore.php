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
 *   5. A hard delete still removes the row — afterDelete() does it explicitly.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/craft-booked/tests/integration-live/soft-delete-restore.php
 *
 * Seeds and cleans up after itself. Exits non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\Booked;
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

$date = (new DateTime('+21 days'))->format('Y-m-d');

// Every check below is about `activeSlotKey`, which only a single-seat slot
// carries — a schedule granting several seats leaves it NULL on purpose, so a
// multi-seat pairing would fail this script for a reason that is not a defect.
// Pick the first pairing whose slot really does hold one booking rather than
// trusting whatever ->one() happens to return.
$service = null;
$employee = null;
$capacityService = Booked::getInstance()->getCapacity();

foreach (Employee::find()->siteId('*')->status(null)->all() as $candidateEmployee) {
    foreach (Service::find()->siteId('*')->status(null)->all() as $candidateService) {
        $seats = $capacityService->getCapacityForSlot($date, '11:00', $candidateEmployee->id, $candidateService->id);
        if ($seats === null || $seats <= 1) {
            [$service, $employee] = [$candidateService, $candidateEmployee];
            break 2;
        }
    }
}

if (!$service || !$employee) {
    fwrite(STDERR, "Needs at least one service and employee whose slot holds a single booking.\n");
    exit(1);
}

echo "  fixture: service #{$service->id}, employee #{$employee->id} (single-seat slot)\n";

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
check('the row is gone', $doomedRow === null, $doomedRow === null ? 'afterDelete removed it' : 'ROW STILL PRESENT');
check('…and so is the element', !(new Query())->from('{{%elements}}')->where(['id' => $doomedId])->exists());

// ------------------------- a reservation is not always a Craft element
// ReservationFactory returns a plain ActiveRecord when Commerce is absent, and
// that row has nothing in `elements`. A cascading id -> elements.id constraint
// was briefly added here and made every one of those inserts fail, so no
// booking could be created at all on a Commerce-free install. This is the
// check that catches it.
echo "\nBookings without Commerce (plain records)\n";

$viaFactory = \anvildev\booked\factories\ReservationFactory::create();
echo '    factory returns ' . get_class($viaFactory) . ' (element mode: '
    . (\anvildev\booked\factories\ReservationFactory::isElementMode() ? 'yes' : 'no') . ")\n";

$viaFactory->userName = TAG . ' factory';
$viaFactory->userEmail = strtolower(TAG) . '-factory@example.test';
$viaFactory->bookingDate = $date;
$viaFactory->startTime = '18:00';
$viaFactory->endTime = '19:00';
$viaFactory->status = ReservationRecord::STATUS_CONFIRMED;
$viaFactory->serviceId = $service->id;
$viaFactory->quantity = 1;

$saveError = null;
$saved = false;
try {
    $saved = $viaFactory->save(false);
} catch (\Throwable $e) {
    $saveError = get_class($e) . ': ' . $e->getMessage();
}
check(
    'a booking saves through the factory',
    $saved && $saveError === null,
    $saveError !== null ? substr($saveError, 0, 140) : 'id=' . $viaFactory->getId(),
);

if ($viaFactory->getId()) {
    $fRow = row((int) $viaFactory->getId());
    check('…and its row is there', $fRow !== null);
    // A plain record has no element to delete, so the row goes directly. (An
    // earlier version passed a blank Reservation to deleteElement(), which is
    // meaningless and threw from Craft's structure behaviour.)
    if ($viaFactory instanceof Reservation) {
        $elements->deleteElement($viaFactory, true);
    } else {
        Craft::$app->getDb()->createCommand()->delete('{{%booked_reservations}}', ['id' => $viaFactory->getId()])->execute();
    }
}

// ------------------------------------------------------------------ teardown
foreach ($made as $el) {
    $fresh = Reservation::find()->id($el->id)->siteId('*')->status(null)->trashed(null)->one();
    if ($fresh) {
        $elements->deleteElement($fresh, true);
    }
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
