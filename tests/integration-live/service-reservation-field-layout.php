<?php

/**
 * Exercises the Service -> field layout -> Reservation answers chain (#146)
 * against a real database.
 *
 * A reservation has no field layout of its own — Reservation::getFieldLayout()
 * resolves to whichever layout the booked Service owns, and Craft keys the
 * actual field values by handle via the customFields behavior, not by a
 * layout id stored on the row. None of that can be confirmed by reading the
 * source; it needs Craft::$app->getFields() and a real save/reload cycle.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/craft-booked/tests/integration-live/service-reservation-field-layout.php
 *
 * Seeds and cleans up after itself (a service, a field, a reservation).
 * Exits non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\Service;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

const TAG = 'FIELDLAYOUTTEST';

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
$field->name = TAG . ' Field';
$field->handle = 'fieldLayoutTestField';
if (!Craft::$app->getFields()->saveField($field)) {
    fwrite(STDERR, 'field save failed: ' . json_encode($field->getErrors()) . "\n");
    exit(1);
}

try {
    $service = new Service();
    $service->title = TAG . ' Service';
    $service->durationType = 'minutes';
    $service->duration = 30;

    $layout = new FieldLayout(['type' => Reservation::class]);
    $tab = new FieldLayoutTab(['name' => 'Questions', 'layout' => $layout]);
    $tab->setElements([new CustomField($field)]);
    $layout->setTabs([$tab]);
    $service->setReservationFieldLayout($layout);

    if (!Craft::$app->getElements()->saveElement($service, false)) {
        fwrite(STDERR, 'service save failed: ' . json_encode($service->getErrors()) . "\n");
        exit(1);
    }

    echo "Saved service #{$service->id} (reservationFieldLayoutId={$service->reservationFieldLayoutId})\n";

    $reloadedService = Service::find()->id($service->id)->siteId('*')->status(null)->one();
    check(
        'reservationFieldLayoutId survives a reload',
        $reloadedService->reservationFieldLayoutId === $service->reservationFieldLayoutId,
    );
    check('the reloaded layout keeps its tab', count($reloadedService->getReservationFieldLayout()->getTabs()) === 1);

    $reservation = new Reservation();
    $reservation->userName = TAG;
    $reservation->userEmail = strtolower(TAG) . '@example.test';
    $reservation->bookingDate = (new DateTime('+60 days'))->format('Y-m-d');
    $reservation->startTime = '09:00';
    $reservation->endTime = '09:30';
    $reservation->status = 'confirmed';
    $reservation->quantity = 1;
    $reservation->serviceId = $service->id;

    $resolvedLayout = $reservation->getFieldLayout();
    check('getFieldLayout() resolves via the booked service', $resolvedLayout !== null && count($resolvedLayout->getTabs()) === 1);

    $reservation->setFieldValue('fieldLayoutTestField', 'Answer One');

    if (!Craft::$app->getElements()->saveElement($reservation, false)) {
        fwrite(STDERR, 'reservation save failed: ' . json_encode($reservation->getErrors()) . "\n");
        exit(1);
    }

    echo "Saved reservation #{$reservation->id}\n";

    $reloadedReservation = Reservation::find()->id($reservation->id)->siteId('*')->status(null)->one();
    check(
        'the field value round-trips through a fresh load',
        $reloadedReservation->getFieldValue('fieldLayoutTestField') === 'Answer One',
        var_export($reloadedReservation->getFieldValue('fieldLayoutTestField'), true),
    );

    $unrelated = new Reservation();
    check('a reservation with no service resolves no field layout at all', $unrelated->getFieldLayout() === null);
} finally {
    $cleanupReservation = Reservation::find()->userEmail(strtolower(TAG) . '@example.test')->siteId('*')->status(null)->one();
    if ($cleanupReservation) {
        Craft::$app->getElements()->deleteElement($cleanupReservation, true);
    }
    $cleanupService = Service::find()->title(TAG . ' Service')->siteId('*')->status(null)->one();
    if ($cleanupService) {
        Craft::$app->getElements()->deleteElement($cleanupService, true);
    }
    Craft::$app->getFields()->deleteField($field);
    echo "cleaned up\n";
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
