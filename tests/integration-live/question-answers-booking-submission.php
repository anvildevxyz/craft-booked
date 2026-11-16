<?php
/**
 * Live verification for #146 Phase 6: BookingService::populateReservation()
 * — the exact method the public wizard's `booked/api/v1/bookings` POST runs
 * through — carries `fieldValues` onto a saved Reservation for all three of
 * the wizard questions step's distinct answer shapes (plain text, a
 * lightswitch boolean, and an Assets relation), and they read back correctly
 * after a real save/reload. This is the one live script in this directory
 * that exercises an Assets-type question answer end to end; the others
 * (`booking-form-pipeline.php`, `graphql-reservation-field-values.php`,
 * `mcp-reservation-field-values.php`) only cover a scalar field.
 *
 * Calls populateReservation() directly via reflection (it's private; real
 * callers only reach it through createReservation()/updateReservation()),
 * then saves itself — bypassing createReservation()'s employee/availability
 * orchestration on purpose: that machinery is unrelated to #146 and already
 * covered elsewhere, and this script's fixture service has no schedule.
 *
 * Seeds and cleans up its own fields, service, asset and reservation.
 *
 * Run: ddev exec php plugins/craft-booked/tests/integration-live/question-answers-booking-submission.php
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\Booked;
use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\Service;
use craft\elements\Asset;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets as AssetsField;
use craft\fields\Lightswitch;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

const TAG = 'Q146UPLOADTEST';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

$volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
if (!$volume) {
    fwrite(STDERR, "No volume exists to upload a test asset into — skipping.\n");
    exit(1);
}

$textField = new PlainText();
$textField->name = TAG . ' Size';
$textField->handle = 'q146uploadTestSize';

$switchField = new Lightswitch();
$switchField->name = TAG . ' Waiver';
$switchField->handle = 'q146uploadTestWaiver';

$assetsField = new AssetsField();
$assetsField->name = TAG . ' Upload';
$assetsField->handle = 'q146uploadTestUpload';
$assetsField->restrictLocation = true;
$assetsField->restrictedLocationSource = 'volume:' . $volume->uid;

foreach ([$textField, $switchField, $assetsField] as $field) {
    if (!Craft::$app->getFields()->saveField($field)) {
        fwrite(STDERR, json_encode($field->getErrors()) . "\n");
        exit(1);
    }
}

$tempPath = tempnam(sys_get_temp_dir(), 'q146') . '.mp4';
file_put_contents($tempPath, 'fake mp4 content for #146 live verification');

$asset = new Asset();
$asset->tempFilePath = $tempPath;
$asset->setFilename(TAG . '.mp4');
$asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)?->id;
$asset->setVolumeId($volume->id);
$asset->setScenario(Asset::SCENARIO_CREATE);
if (!Craft::$app->getElements()->saveElement($asset)) {
    fwrite(STDERR, json_encode($asset->getErrors()) . "\n");
    exit(1);
}
echo "Created asset #{$asset->id}\n";

try {
    $service = new Service();
    $service->title = TAG . ' Service';
    $service->durationType = 'minutes';
    $service->duration = 30;

    $layout = new FieldLayout(['type' => Reservation::class]);
    $tab = new FieldLayoutTab(['name' => 'Questions', 'layout' => $layout]);
    $tab->setElements([new CustomField($textField), new CustomField($switchField), new CustomField($assetsField)]);
    $layout->setTabs([$tab]);
    $service->setReservationFieldLayout($layout);

    if (!Craft::$app->getElements()->saveElement($service, false)) {
        fwrite(STDERR, json_encode($service->getErrors()) . "\n");
        exit(1);
    }
    echo "Saved service #{$service->id}\n";

    $bookingService = Booked::getInstance()->booking;
    $populate = new ReflectionMethod($bookingService, 'populateReservation');
    $populate->setAccessible(true);

    $data = [
        'serviceId' => $service->id,
        'userName' => TAG,
        'userEmail' => strtolower(TAG) . '@example.test',
        'bookingDate' => (new DateTime('+71 days'))->format('Y-m-d'),
        'startTime' => '09:00',
        'endTime' => '09:30',
        'fieldValues' => [
            $textField->handle => 'Large',
            $switchField->handle => true,
            $assetsField->handle => [$asset->id],
        ],
    ];

    $reservation = new Reservation();
    $populate->invoke(
        $bookingService,
        $reservation,
        $data,
        $data['userEmail'],
        $data['bookingDate'],
        $data['startTime'],
        $data['endTime'],
        null, // employeeId
        null, // locationId
        $data['serviceId'],
        null, // eventDateId
    );

    check('the populated reservation saves', Craft::$app->getElements()->saveElement($reservation, false), json_encode($reservation->getErrors()));
    echo "Created reservation #{$reservation->id}\n";

    // Re-fetch fresh (not the in-memory object) to prove the values round-trip
    // through actual storage, not just an unsaved in-request model state.
    $fresh = Reservation::find()->id($reservation->id)->status(null)->one();
    check('the saved reservation reloads', $fresh !== null);

    $text = $fresh->getFieldValue($textField->handle);
    $switch = $fresh->getFieldValue($switchField->handle);
    /** @var Asset|null $uploadedAsset */
    $uploadedAsset = $fresh->getFieldValue($assetsField->handle)->one();

    check("text answer round-trips as 'Large'", $text === 'Large', var_export($text, true));
    check('lightswitch answer round-trips as true', $switch === true, var_export($switch, true));
    check(
        'assets answer resolves to the uploaded asset',
        $uploadedAsset !== null && $uploadedAsset->id === $asset->id,
        var_export($uploadedAsset?->id, true),
    );

    Craft::$app->getElements()->deleteElement($fresh, true);
} finally {
    $cleanupService = Service::find()->title(TAG . ' Service')->siteId('*')->status(null)->one();
    if ($cleanupService) {
        Craft::$app->getElements()->deleteElement($cleanupService, true);
    }
    Craft::$app->getElements()->deleteElement($asset, true);
    Craft::$app->getFields()->deleteField($textField);
    Craft::$app->getFields()->deleteField($switchField);
    Craft::$app->getFields()->deleteField($assetsField);
    @unlink($tempPath);
    echo "cleaned up\n";
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
