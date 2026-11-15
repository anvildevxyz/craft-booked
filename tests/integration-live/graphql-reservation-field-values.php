<?php

/**
 * Exercises createBookedReservation/updateBookedReservation's fieldValues
 * input (#146) against a real database, through the actual resolver methods
 * (ReservationMutations::resolveCreate()/resolveUpdate() are protected, so
 * this reflects into them directly rather than standing up a full GraphQL
 * schema and permission scope just to reach the same code).
 *
 * There's no custom JSON scalar registered for this plugin's schema, so
 * fieldValues travels as a JSON object string on both sides — encoded out
 * via ReservationInterface's resolver, decoded in via
 * ReservationMutations::decodeFieldValues(), the same convention
 * ScheduleInterface's workingHours already uses one-way.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/craft-booked/tests/integration-live/graphql-reservation-field-values.php
 *
 * Needs a service titled "Private Lesson" with real availability on this
 * install (used elsewhere in this test suite). Self-cleaning. Exits
 * non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\Service;
use anvildev\booked\gql\interfaces\elements\ReservationInterface as GqlReservationInterface;
use anvildev\booked\gql\mutations\ReservationMutations;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

$field = new PlainText();
$field->name = 'GQL Test Field';
$field->handle = 'gqlTestField';
if (!Craft::$app->getFields()->saveField($field)) {
    fwrite(STDERR, json_encode($field->getErrors()) . "\n");
    exit(1);
}

$service = Service::find()->title('Private Lesson')->siteId('*')->status(null)->one();
if ($service === null) {
    fwrite(STDERR, "No 'Private Lesson' service on this install.\n");
    exit(1);
}

$originalLayoutId = $service->reservationFieldLayoutId;

$resolveCreate = new ReflectionMethod(ReservationMutations::class, 'resolveCreate');
$resolveCreate->setAccessible(true);
$resolveUpdate = new ReflectionMethod(ReservationMutations::class, 'resolveUpdate');
$resolveUpdate->setAccessible(true);

try {
    $layout = new FieldLayout(['type' => Reservation::class]);
    $tab = new FieldLayoutTab(['name' => 'Questions', 'layout' => $layout]);
    $tab->setElements([new CustomField($field)]);
    $layout->setTabs([$tab]);
    $service->setReservationFieldLayout($layout);
    if (!Craft::$app->getElements()->saveElement($service, false)) {
        fwrite(STDERR, json_encode($service->getErrors()) . "\n");
        exit(1);
    }

    $createResult = $resolveCreate->invoke(null, [
        'serviceId' => $service->id,
        'bookingDate' => (new DateTime('+80 days'))->format('Y-m-d'),
        'startTime' => '09:00',
        'userName' => 'GQL Field Probe',
        'userEmail' => 'gql-field-probe@example.test',
        'fieldValues' => json_encode(['gqlTestField' => 'GQL Answer']),
    ]);

    check('resolveCreate succeeded', $createResult['success'] ?? false, json_encode($createResult['errors'] ?? null));

    if ($createResult['success'] ?? false) {
        $reservation = $createResult['reservation'];
        check(
            'the saved reservation has the field value set',
            $reservation->getFieldValue('gqlTestField') === 'GQL Answer',
            var_export($reservation->getFieldValue('gqlTestField'), true),
        );

        $fieldDefs = GqlReservationInterface::getFieldDefinitions();
        $resolved = ($fieldDefs['fieldValues']['resolve'])($reservation);
        check(
            "ReservationInterface's fieldValues GraphQL field resolves correctly",
            json_decode($resolved, true) === ['gqlTestField' => 'GQL Answer'],
            $resolved,
        );

        $updateResult = $resolveUpdate->invoke(
            null,
            (int) $reservation->id,
            $reservation->getConfirmationToken(),
            ['fieldValues' => json_encode(['gqlTestField' => 'GQL Updated Answer'])],
        );
        check('resolveUpdate succeeded', $updateResult['success'] ?? false, json_encode($updateResult['errors'] ?? null));
        check(
            'resolveUpdate applied the new field value',
            ($updateResult['reservation']->getFieldValue('gqlTestField') ?? null) === 'GQL Updated Answer',
            var_export($updateResult['reservation']->getFieldValue('gqlTestField') ?? null, true),
        );

        Craft::$app->getElements()->deleteElement($reservation, true);
    }
} finally {
    $testLayoutId = $service->reservationFieldLayoutId;
    Craft::$app->getDb()->createCommand()->update(
        '{{%booked_services}}',
        ['reservationFieldLayoutId' => $originalLayoutId],
        ['id' => $service->id],
    )->execute();
    if ($testLayoutId !== null && $testLayoutId !== $originalLayoutId) {
        Craft::$app->getFields()->deleteLayoutById($testLayoutId);
    }
    Craft::$app->getFields()->deleteField($field);
    echo "restored the service's original reservationFieldLayoutId, cleaned up the test field\n";
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
