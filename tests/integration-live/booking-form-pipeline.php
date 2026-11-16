<?php

/**
 * Exercises the full BookingForm -> BookingService::populateReservation()
 * pipeline for custom field answers (#146) against a real install.
 *
 * BookingController::actionCreateBooking() requires real slot availability
 * (schedules, employees) to get past the availability check at all, which
 * this script doesn't set up — the goal here is the data pipeline, not the
 * booking flow end to end. It calls populateReservation() directly (it's
 * private; BookingService itself only reaches it through createReservation()/
 * updateReservation(), both of which require an available slot), which is
 * exactly what every real caller — the wizard, the REST alias, MCP — shares.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/craft-booked/tests/integration-live/booking-form-pipeline.php
 *
 * Seeds and cleans up its own service/field. Exits non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\Booked;
use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\Service;
use anvildev\booked\models\forms\BookingForm;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

const TAG = 'PIPELINETEST';

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
$field->handle = 'pipelineTestField';
if (!Craft::$app->getFields()->saveField($field)) {
    fwrite(STDERR, json_encode($field->getErrors()) . "\n");
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
        fwrite(STDERR, json_encode($service->getErrors()) . "\n");
        exit(1);
    }

    echo "Saved service #{$service->id}\n";

    $form = new BookingForm();
    $form->userName = TAG;
    $form->userEmail = strtolower(TAG) . '@example.test';
    $form->bookingDate = (new DateTime('+71 days'))->format('Y-m-d');
    $form->startTime = '09:00';
    $form->endTime = '09:30';
    $form->serviceId = $service->id;
    $form->fieldValues = ['pipelineTestField' => 'Pipeline Answer'];

    check('the form validates with fieldValues set', $form->validate(), json_encode($form->getErrors()));

    $data = $form->getReservationData();
    check('getReservationData() includes fieldValues', ($data['fieldValues'] ?? null) === ['pipelineTestField' => 'Pipeline Answer']);

    $bookingService = Booked::getInstance()->booking;
    $ref = new ReflectionMethod($bookingService, 'populateReservation');
    $ref->setAccessible(true);

    $reservation = new Reservation();
    $ref->invoke(
        $bookingService,
        $reservation,
        $data,
        $data['userEmail'],
        $data['bookingDate'],
        $data['startTime'],
        $data['endTime'],
        $data['employeeId'],
        $data['locationId'],
        $data['serviceId'],
        $data['eventDateId'],
    );

    check(
        'populateReservation() applies the field value via the data array',
        $reservation->getFieldValue('pipelineTestField') === 'Pipeline Answer',
        var_export($reservation->getFieldValue('pipelineTestField'), true),
    );
} finally {
    $cleanupService = Service::find()->title(TAG . ' Service')->siteId('*')->status(null)->one();
    if ($cleanupService) {
        Craft::$app->getElements()->deleteElement($cleanupService, true);
    }
    Craft::$app->getFields()->deleteField($field);
    echo "cleaned up\n";
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
