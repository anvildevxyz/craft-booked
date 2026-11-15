<?php

/**
 * Exercises booked_create_booking / booked_update_reservation's fieldValues
 * parameter (#146) against a real database, through the actual MCP tool
 * methods rather than the lower-level BookingService pipeline those tools
 * sit on top of (covered separately by booking-form-pipeline.php).
 *
 * Needs "Allow MCP write operations" on — guardWrite() enforces this for
 * real, not just as metadata — so this toggles the setting for the
 * duration and restores it (and a real bookable service's
 * reservationFieldLayoutId) no matter how the script exits.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/craft-booked/tests/integration-live/mcp-reservation-field-values.php
 *
 * Needs a service titled "Private Lesson" with real availability on this
 * install (used elsewhere in this test suite). Self-cleaning. Exits
 * non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\Booked;
use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\Service;
use anvildev\booked\mcp\ReservationTools;
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

$service = Service::find()->title('Private Lesson')->siteId('*')->status(null)->one();
if ($service === null) {
    fwrite(STDERR, "No 'Private Lesson' service on this install.\n");
    exit(1);
}

$field = new PlainText();
$field->name = 'MCP Test Field';
$field->handle = 'mcpTestField';
if (!Craft::$app->getFields()->saveField($field)) {
    fwrite(STDERR, json_encode($field->getErrors()) . "\n");
    exit(1);
}

$originalLayoutId = $service->reservationFieldLayoutId;
$settings = Booked::getInstance()->getSettings();
$originalMcpWriteEnabled = $settings->mcpWriteEnabled;
$settings->mcpWriteEnabled = true;
$settings->save();

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

    $tools = new ReservationTools();
    $created = $tools->createBooking(
        serviceId: $service->id,
        bookingDate: (new DateTime('+75 days'))->format('Y-m-d'),
        startTime: '09:00',
        userName: 'MCP Field Probe',
        userEmail: 'mcp-field-probe@example.test',
        fieldValues: ['mcpTestField' => 'MCP Answer'],
    );

    check('createBooking succeeded', $created['success'] ?? false, json_encode($created));

    if ($created['success'] ?? false) {
        $id = $created['reservation']['id'];
        check(
            'Presenter output includes the field value',
            ($created['reservation']['fieldValues']['mcpTestField'] ?? null) === 'MCP Answer',
            json_encode($created['reservation']['fieldValues'] ?? null),
        );

        $updated = $tools->updateReservation(id: $id, fieldValues: ['mcpTestField' => 'MCP Updated Answer']);
        check('updateReservation succeeded', $updated['success'] ?? false, json_encode($updated));
        check(
            'updateReservation applied the new field value',
            ($updated['reservation']['fieldValues']['mcpTestField'] ?? null) === 'MCP Updated Answer',
            json_encode($updated['reservation']['fieldValues'] ?? null),
        );

        $reservation = Reservation::find()->id($id)->siteId('*')->status(null)->one();
        if ($reservation) {
            Craft::$app->getElements()->deleteElement($reservation, true);
        }
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
    $settings->mcpWriteEnabled = $originalMcpWriteEnabled;
    $settings->save();
    echo "restored the service's original reservationFieldLayoutId and mcpWriteEnabled, cleaned up the test field\n";
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
