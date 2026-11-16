<?php
/**
 * Live verification for #146: the plugin's global "Upload Volume" setting
 * can point at a volume an Assets question field's own "Sources" setting
 * doesn't include. When that happens, Craft accepts the upload and creates
 * the asset, but can never resolve the relation back onto the reservation —
 * the customer's answer silently disappears with no error anywhere. Found by
 * live browser testing: a real submission returned 200/"Booking Successful"
 * with every other answer type persisted correctly, but the uploaded video's
 * relation read back as null.
 *
 * actionUploadQuestionFile() now checks the field's `sources` against the
 * configured volume and rejects the upload up front instead of accepting
 * one that's guaranteed to be lost.
 *
 * Needs a real HTTP round-trip (session-bound CSRF token + multipart file
 * upload), which can't be faked by calling the controller action directly —
 * drives the real endpoint with curl against the live site, same approach as
 * payments.sh.
 *
 * Seeds its own field/service; restores the Settings volume and cleans up
 * regardless of outcome.
 *
 * Run: ddev exec php plugins/craft-booked/tests/integration-live/question-upload-volume-sources-mismatch.php
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
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

const TAG = 'Q146SRCMISMATCH';
const BASE_URL = 'https://craft-plugin-dev.ddev.site';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

$volumes = Craft::$app->getVolumes()->getAllVolumes();
if (count($volumes) < 2) {
    fwrite(STDERR, "Needs at least 2 volumes on this install to prove a mismatch — skipping.\n");
    exit(1);
}

$settings = Booked::getInstance()->getSettings();
$originalVolumeUid = $settings->questionUploadVolumeUid;

// The plugin's upload volume setting points at the first volume; the
// field's own Sources are restricted to the second — a deliberate mismatch.
$configuredVolume = $volumes[0];
$fieldSourceVolume = $volumes[1];

$field = new AssetsField();
$field->name = TAG . ' Upload';
$field->handle = 'q146srcMismatchUpload';
$field->sources = ['volume:' . $fieldSourceVolume->uid];
$field->allowUploads = true;

if (!Craft::$app->getFields()->saveField($field)) {
    fwrite(STDERR, json_encode($field->getErrors()) . "\n");
    exit(1);
}

$cookieJar = tempnam(sys_get_temp_dir(), 'q146cookie');
$tempFile = null;
$setupError = null;

try {
    $settings->questionUploadVolumeUid = $configuredVolume->uid;
    if (!$settings->save()) {
        throw new \RuntimeException('Failed to save settings: ' . json_encode($settings->getErrors()));
    }

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
        throw new \RuntimeException('Failed to save service: ' . json_encode($service->getErrors()));
    }
    echo "Saved service #{$service->id} (upload volume = {$configuredVolume->name}, field sources = {$fieldSourceVolume->name})\n";

    // GET the wizard page to establish a session + grab a CSRF token, same
    // as a real browser would before its first POST.
    $ch = curl_init(BASE_URL . '/wizard/service?serviceId=' . $service->id);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!preg_match('/<script type="application\/json" data-booked-config>(.+?)<\/script>/s', (string)$html, $m)) {
        throw new \RuntimeException("Could not find the wizard's config script block — is the route/template still named the same?");
    }
    $config = json_decode($m[1], true);
    $csrfName = $config['api']['csrf']['name'] ?? null;
    $csrfToken = $config['api']['csrf']['value'] ?? null;
    if (!$csrfName || !$csrfToken) {
        throw new \RuntimeException("Could not extract a CSRF token from the wizard's config block.");
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'q146upload') . '.jpg';
    file_put_contents($tempFile, 'fake jpg content for the sources-mismatch check');

    $ch = curl_init(BASE_URL . '/booked/api/v1/services/questions/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_POSTFIELDS => [
            $csrfName => $csrfToken,
            'serviceId' => (string)$service->id,
            'handle' => $field->handle,
            'file' => new CURLFile($tempFile, 'image/jpeg', 'q146-src-mismatch.jpg'),
        ],
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    check('the HTTP request itself succeeded', $response !== false, $curlError);
    $body = json_decode((string)$response, true) ?? [];

    check('the mismatched upload is rejected, not accepted', ($body['success'] ?? null) === false, "status={$status} body={$response}");
    check(
        'the error names the sources/volume mismatch',
        str_contains(strtolower($body['message'] ?? ''), 'source'),
        $body['message'] ?? '(no message)',
    );

    // An upload that was correctly rejected must not have created an asset —
    // that would be the exact "silently lost answer" failure mode this guards against.
    $orphan = Asset::find()->filename('q146-src-mismatch*')->one();
    check('no asset was created by the rejected upload', $orphan === null, $orphan ? "found asset #{$orphan->id}" : '');
} catch (\Throwable $e) {
    $setupError = $e->getMessage();
} finally {
    $settings->questionUploadVolumeUid = $originalVolumeUid;
    $settings->save();

    $cleanupService = Service::find()->title(TAG . ' Service')->siteId('*')->status(null)->one();
    if ($cleanupService) {
        Craft::$app->getElements()->deleteElement($cleanupService, true);
    }
    Craft::$app->getFields()->deleteField($field);
    if ($tempFile) {
        @unlink($tempFile);
    }
    @unlink($cookieJar);
    echo "cleaned up\n";
}

if ($setupError !== null) {
    fwrite(STDERR, "Setup/request error: {$setupError}\n");
    exit(1);
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
