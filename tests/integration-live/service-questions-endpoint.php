<?php

/**
 * Exercises the field-layout data booked/api/v1/services/questions (#146)
 * builds its response from — the endpoint the public wizard calls right
 * after a service is selected — against a real database, with one field of
 * each type the wizard step knows how to draw.
 *
 * actionGetServiceQuestions() itself is a craft\web\Controller action; this
 * console script runs under craft\console\Application (no web request to
 * dispatch through), so this calls BookingDataController::wizardFieldType()
 * and reads the same FieldLayout::getCustomFieldElements() the action reads,
 * rather than invoking the action itself. The action was live-verified
 * through a real browser HTTP request during development; this guards the
 * data-assembly logic that response is built from.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/craft-booked/tests/integration-live/service-questions-endpoint.php
 *
 * Seeds its own fields and a temporary field layout on a real service
 * (whichever one it finds first, enabled, on the primary site) and restores
 * that service's original field layout and settings regardless of outcome.
 * Exits non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\Service;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Dropdown;
use craft\fields\Lightswitch;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

const TAG = 'QUESTIONSENDPOINTTEST';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

$service = Service::find()->siteId('*')->status(null)->one();
if ($service === null) {
    fwrite(STDERR, "No service exists on this install.\n");
    exit(1);
}

$textField = new PlainText();
$textField->name = TAG . ' Text';
$textField->handle = 'questionsEndpointTextField';
$dropdownField = new Dropdown();
$dropdownField->name = TAG . ' Dropdown';
$dropdownField->handle = 'questionsEndpointDropdownField';
$dropdownField->options = [
    ['label' => 'Option A', 'value' => 'a'],
    ['label' => 'Option B', 'value' => 'b'],
];
$waiverField = new Lightswitch();
$waiverField->name = TAG . ' Waiver';
$waiverField->handle = 'questionsEndpointWaiverField';

foreach ([$textField, $dropdownField, $waiverField] as $field) {
    if (!Craft::$app->getFields()->saveField($field)) {
        fwrite(STDERR, json_encode($field->getErrors()) . "\n");
        exit(1);
    }
}

$originalLayoutId = $service->reservationFieldLayoutId;

try {
    $layout = new FieldLayout(['type' => Reservation::class]);
    $tab = new FieldLayoutTab(['name' => 'Questions', 'layout' => $layout]);
    $textElement = new CustomField($textField);
    $dropdownElement = new CustomField($dropdownField);
    $waiverElement = new CustomField($waiverField);
    $waiverElement->required = true;
    $tab->setElements([$textElement, $dropdownElement, $waiverElement]);
    $layout->setTabs([$tab]);
    $service->setReservationFieldLayout($layout);
    if (!Craft::$app->getElements()->saveElement($service, false)) {
        fwrite(STDERR, json_encode($service->getErrors()) . "\n");
        exit(1);
    }

    $controllerRef = (new ReflectionClass(\anvildev\booked\controllers\BookingDataController::class))->newInstanceWithoutConstructor();
    $wizardFieldType = new ReflectionMethod($controllerRef, 'wizardFieldType');
    $wizardFieldType->setAccessible(true);

    $byHandle = [];
    foreach ($service->getReservationFieldLayout()->getCustomFieldElements() as $layoutElement) {
        $field = $layoutElement->getField();
        $type = $wizardFieldType->invoke($controllerRef, $field);
        if ($type === null) {
            continue;
        }

        $question = [
            'handle' => $field->handle,
            'label' => $field->name,
            'required' => $layoutElement->required,
            'type' => $type,
        ];
        if ($field instanceof \craft\fields\BaseOptionsField) {
            $question['options'] = array_map(
                static fn(array $option): array => ['label' => $option['label'], 'value' => $option['value']],
                $field->options,
            );
        }

        $byHandle[$field->handle] = $question;
    }

    check('all three question fields were found on the layout', count($byHandle) === 3, json_encode(array_keys($byHandle)));

    check('the text field is included as type text', ($byHandle['questionsEndpointTextField']['type'] ?? null) === 'text');
    check(
        'the dropdown field is included with its options',
        ($byHandle['questionsEndpointDropdownField']['type'] ?? null) === 'dropdown'
            && ($byHandle['questionsEndpointDropdownField']['options'] ?? null) === [
                ['label' => 'Option A', 'value' => 'a'],
                ['label' => 'Option B', 'value' => 'b'],
            ],
        json_encode($byHandle['questionsEndpointDropdownField'] ?? null),
    );
    check(
        'the lightswitch field is included and marked required',
        ($byHandle['questionsEndpointWaiverField']['type'] ?? null) === 'lightswitch'
            && ($byHandle['questionsEndpointWaiverField']['required'] ?? null) === true,
        json_encode($byHandle['questionsEndpointWaiverField'] ?? null),
    );
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
    foreach ([$textField, $dropdownField, $waiverField] as $field) {
        Craft::$app->getFields()->deleteField($field);
    }
    echo "restored the service's original reservationFieldLayoutId, cleaned up the test fields\n";
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
