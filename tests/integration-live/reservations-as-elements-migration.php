<?php

/**
 * Runs m261109_000000_reservations_as_elements against synthetic legacy rows,
 * against a live database (#88 / PR #149).
 *
 * The migration is one-way (safeDown() refuses to revert) and the unit suite
 * cannot reach it at all: before this PR, a reservation was only a Craft
 * element when Commerce was enabled, so a Commerce-free install's bookings
 * were plain ActiveRecord rows with nothing in `elements`. Proving the
 * two-phase id-parking shift — and the id remap across booked_payments,
 * booked_order_reservations and booked_reservation_extras — actually works
 * needs a real database, not a reading of the code.
 *
 * `booked_reservations.id -> elements.id` already exists on this install (the
 * migration has already run for real here), so that constraint is exactly
 * what makes a legacy orphan row impossible to create normally. This script
 * drops it for the duration of the test to reproduce the pre-upgrade state,
 * seeds synthetic orphan rows under that window, runs the real migration
 * class, and restores the constraint in a `finally` regardless of outcome —
 * MySQL's DDL implicit-commits mean the migration's own transaction cannot be
 * trusted to undo a partial failure, so cleanup here is by tag, not rollback.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/craft-booked/tests/integration-live/reservations-as-elements-migration.php
 *
 * Seeds and cleans up after itself. Exits non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\ServiceExtra;
use anvildev\booked\migrations\m261109_000000_reservations_as_elements;
use craft\db\Query;
use craft\helpers\StringHelper;

const TAG = 'MIGTEST';
const RESERVATIONS = '{{%booked_reservations}}';

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

/** The generated constraint name, or null when the column carries no foreign key. */
function foreignKeyName(\craft\db\Connection $db, string $table, string $column): ?string
{
    $schema = $db->getTableSchema($table, true);
    if ($schema === null) {
        return null;
    }
    foreach ($schema->foreignKeys as $name => $definition) {
        if (array_key_exists($column, array_slice($definition, 1, null, true))) {
            return (string) $name;
        }
    }
    return null;
}

function orphanCount(\craft\db\Connection $db): int
{
    return (int) (new Query())
        ->from(['r' => RESERVATIONS])
        ->leftJoin(['e' => '{{%elements}}'], '[[e.id]] = [[r.id]] AND [[e.type]] = :type', [':type' => Reservation::class])
        ->where(['e.id' => null])
        ->count('r.id', $db);
}

$db = Craft::$app->getDb();

$fkName = foreignKeyName($db, RESERVATIONS, 'id');
check('the elements foreign key exists before the test', $fkName !== null, (string) $fkName);
if ($fkName === null) {
    fwrite(STDERR, "Cannot simulate a pre-upgrade install: booked_reservations.id already has no elements FK.\n");
    exit(1);
}

$baselineOrphans = orphanCount($db);
check('no orphans exist before seeding', $baselineOrphans === 0, "found {$baselineOrphans}");

$now = (new DateTime())->format('Y-m-d H:i:s');
$baseId = 90000001;
$orphanIds = [$baseId, $baseId + 1, $baseId + 2];
$tokens = [];
$primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

try {
    echo "Dropping the elements FK to simulate a pre-upgrade install\n";
    $db->createCommand()->dropForeignKey($fkName, RESERVATIONS)->execute();

    // A real, pre-existing element uid, reused below to force a uid collision
    // on the first orphan — the migration must notice and give that orphan's
    // new elements row a fresh uid instead, and keep booked_reservations.uid
    // in sync with whichever uid actually won.
    $collidingUid = (new Query())->select(['uid'])->from('{{%elements}}')->limit(1)->scalar();
    check('an existing element uid is available to collide with', is_string($collidingUid) && $collidingUid !== '');

    echo "Seeding synthetic legacy (ActiveRecord-only) reservations: " . implode(', ', $orphanIds) . "\n";
    foreach ($orphanIds as $i => $id) {
        $token = TAG . '-' . StringHelper::UUID();
        $tokens[$id] = $token;
        $db->createCommand()->insert(RESERVATIONS, [
            'id' => $id,
            'userName' => TAG . " user {$i}",
            'userEmail' => strtolower(TAG) . "-{$i}@example.test",
            'bookingDate' => (new DateTime('+' . (10 + $i) . ' days'))->format('Y-m-d'),
            'startTime' => '10:00:00',
            'endTime' => '11:00:00',
            'status' => 'confirmed',
            'quantity' => 1,
            // The third orphan carries an invalid siteId, to prove remap() falls
            // back to the primary site rather than failing the whole row.
            'siteId' => $i === 2 ? 999999 : $primarySiteId,
            'confirmationToken' => $token,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            // The first orphan collides on purpose (see $collidingUid above).
            'uid' => $i === 0 && is_string($collidingUid) ? $collidingUid : StringHelper::UUID(),
        ])->execute();
    }

    // A payment on the first orphan — reservationId carries the only FK this
    // table needs, so no other fixture is required.
    $db->createCommand()->insert('{{%booked_payments}}', [
        'reservationId' => $orphanIds[0],
        'gateway' => 'stripe',
        'status' => 'paid',
        'amount' => 5000,
        'currency' => 'usd',
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();
    echo "  payment seeded on #{$orphanIds[0]}\n";

    // An order link on the second orphan — orderId carries no foreign key at
    // all on this table (confirmed against Install.php), so any int will do.
    $db->createCommand()->insert('{{%booked_order_reservations}}', [
        'orderId' => 777000111,
        'reservationId' => $orphanIds[1],
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();
    echo "  order link seeded on #{$orphanIds[1]}\n";

    // An extra on the third orphan, only if this install has a real
    // ServiceExtra to point serviceExtraId at (it carries a hard FK to elements).
    $extra = ServiceExtra::find()->siteId('*')->status(null)->one();
    if ($extra !== null) {
        $db->createCommand()->insert('{{%booked_reservation_extras}}', [
            'reservationId' => $orphanIds[2],
            'serviceExtraId' => $extra->id,
            'quantity' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        echo "  extra seeded on #{$orphanIds[2]} (serviceExtraId={$extra->id})\n";
    } else {
        echo "  skipping the extras fixture — no ServiceExtra exists on this install\n";
    }

    $seededOrphans = orphanCount($db);
    check('seeding created exactly 3 orphans', $seededOrphans === 3, "found {$seededOrphans}");

    // A real, untouched reservation (if any), to prove the migration leaves
    // everything outside the orphan set alone.
    $untouchedBefore = (new Query())
        ->from(RESERVATIONS)
        ->where(['not', ['id' => $orphanIds]])
        ->orderBy(['id' => SORT_ASC])
        ->limit(1)
        ->one();

    echo "\nRunning the real migration\n";
    $migration = new m261109_000000_reservations_as_elements();
    $migrationResult = $migration->up(true);
    check('the migration reports success', $migrationResult !== false, var_export($migrationResult, true));

    echo "\nAssertions\n";

    check('no orphans remain after the migration', orphanCount($db) === 0, (string) orphanCount($db));

    $remapped = [];
    foreach ($orphanIds as $oldId) {
        $stillAtOldId = (new Query())->from(RESERVATIONS)->where(['id' => $oldId])->exists();
        check("orphan #{$oldId} no longer occupies its old id", !$stillAtOldId);

        $row = (new Query())->from(RESERVATIONS)->where(['confirmationToken' => $tokens[$oldId]])->one();
        check("orphan #{$oldId} is findable by its unchanged confirmation token", $row !== null);
        if ($row === null) {
            continue;
        }
        $newId = (int) $row['id'];
        $remapped[$oldId] = $newId;

        check(
            "#{$oldId} -> #{$newId} has a matching Reservation element",
            (new Query())->from('{{%elements}}')->where(['id' => $newId, 'type' => Reservation::class])->exists(),
        );

        if ($oldId === $orphanIds[0] && is_string($collidingUid)) {
            $elementUid = (new Query())->select(['uid'])->from('{{%elements}}')->where(['id' => $newId])->scalar();
            check(
                'the colliding uid was not reused for the new element',
                $elementUid !== $collidingUid,
                (string) $elementUid,
            );
            check(
                "booked_reservations.uid stays in sync with its element's uid",
                $row['uid'] === $elementUid,
                "reservations.uid={$row['uid']} elements.uid={$elementUid}",
            );
        }
        check(
            "#{$oldId} -> #{$newId} has an elements_sites row",
            (new Query())->from('{{%elements_sites}}')->where(['elementId' => $newId])->exists(),
        );
        check("#{$oldId} -> #{$newId} kept its status", $row['status'] === 'confirmed', (string) $row['status']);
    }

    if (isset($remapped[$orphanIds[2]])) {
        $fallbackSiteId = (new Query())->select(['siteId'])->from(RESERVATIONS)->where(['id' => $remapped[$orphanIds[2]]])->scalar();
        check(
            'the invalid-siteId orphan fell back to the primary site',
            (int) $fallbackSiteId === $primarySiteId,
            "siteId={$fallbackSiteId}",
        );
    }

    $paymentRow = isset($remapped[$orphanIds[0]])
        ? (new Query())->from('{{%booked_payments}}')->where(['reservationId' => $remapped[$orphanIds[0]]])->one()
        : null;
    check('the payment followed its reservation to the new id', $paymentRow !== null);
    check(
        'the payment no longer points at the old id',
        !(new Query())->from('{{%booked_payments}}')->where(['reservationId' => $orphanIds[0]])->exists(),
    );

    $orderRow = isset($remapped[$orphanIds[1]])
        ? (new Query())->from('{{%booked_order_reservations}}')->where(['reservationId' => $remapped[$orphanIds[1]]])->one()
        : null;
    check('the order link followed its reservation to the new id', $orderRow !== null);
    check(
        'the order link no longer points at the old id',
        !(new Query())->from('{{%booked_order_reservations}}')->where(['reservationId' => $orphanIds[1]])->exists(),
    );

    if ($extra !== null && isset($remapped[$orphanIds[2]])) {
        $extraRow = (new Query())->from('{{%booked_reservation_extras}}')->where(['reservationId' => $remapped[$orphanIds[2]]])->one();
        check('the extra followed its reservation to the new id', $extraRow !== null);
        check(
            'the extra no longer points at the old id',
            !(new Query())->from('{{%booked_reservation_extras}}')->where(['reservationId' => $orphanIds[2]])->exists(),
        );
    }

    if ($untouchedBefore !== null) {
        $untouchedAfter = (new Query())->from(RESERVATIONS)->where(['id' => $untouchedBefore['id']])->one();
        check(
            'an unrelated existing reservation kept its id and data',
            $untouchedAfter !== null
                && $untouchedAfter['confirmationToken'] === $untouchedBefore['confirmationToken']
                && $untouchedAfter['userEmail'] === $untouchedBefore['userEmail'],
        );
    } else {
        echo "  (no pre-existing reservation on this install to check for collateral changes)\n";
    }

    $restoredFkName = foreignKeyName($db, RESERVATIONS, 'id');
    check('the elements foreign key is back in place after the migration', $restoredFkName !== null);

    echo "\nRunning the migration a second time (idempotency)\n";
    $secondRun = (new m261109_000000_reservations_as_elements())->up(true);
    check('a second run is a no-op, not an error', $secondRun !== false, var_export($secondRun, true));
    check('no orphans after a second run', orphanCount($db) === 0);
} finally {
    echo "\nCleaning up\n";

    $newIds = array_values($remapped ?? []);
    if ($newIds !== []) {
        // Cascades onto elements_sites, booked_reservations, booked_payments
        // and booked_reservation_extras via their ON DELETE CASCADE.
        $db->createCommand()->delete('{{%elements}}', ['id' => $newIds])->execute();
    }
    // booked_order_reservations carries no foreign key, so it never cascades.
    $db->createCommand()->delete('{{%booked_order_reservations}}', ['reservationId' => array_merge($orphanIds, $newIds)])->execute();
    // Belt and suspenders: remove any synthetic row left at its original id,
    // in case the migration failed before remapping it.
    $db->createCommand()->delete(RESERVATIONS, ['id' => $orphanIds])->execute();
    $db->createCommand()->delete('{{%booked_payments}}', ['reservationId' => $orphanIds])->execute();
    $db->createCommand()->delete('{{%booked_reservation_extras}}', ['reservationId' => $orphanIds])->execute();

    if (foreignKeyName($db, RESERVATIONS, 'id') === null) {
        $db->createCommand()->addForeignKey(null, RESERVATIONS, 'id', '{{%elements}}', 'id', 'CASCADE', null)->execute();
        echo "  restored the elements foreign key (the migration under test had not reached that step)\n";
    }

    $finalOrphans = orphanCount($db);
    check('no orphans remain after cleanup', $finalOrphans === 0, (string) $finalOrphans);
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
