<?php

/**
 * Live integration check: the service list reports which services have add-ons.
 *
 * `BookingDataController::actionGetServices()` filtered on
 * `booked_service_extras.enabled`. ServiceExtra is an element, so its enabled
 * state lives on `elements` and that column has never existed — the query threw
 * on every request, was swallowed into a warning, and every service came back
 * reporting no add-ons. The wizard therefore never flagged a service as having
 * extras, and the failure was invisible outside the log.
 *
 * Only a real database can catch this: the column is missing, so the query is
 * fine to build and fails on execution. The unit suite never runs it.
 *
 * Usage (from the Craft project root, DDEV):
 *   ddev exec php plugins/craft-booked/tests/integration-live/service-list-extras.php
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\elements\Service;
use anvildev\booked\elements\ServiceExtra;
use anvildev\booked\helpers\ElementQueryHelper;
use craft\helpers\StringHelper;

$db = Craft::$app->getDb();
$elements = Craft::$app->getElements();
$prefix = 'EXTRASLIST';

$pass = 0;
$fail = 0;
$ok = function(string $m) use (&$pass) {
    echo "  \u{2713} {$m}\n";
    $pass++;
};
$bad = function(string $m) use (&$fail) {
    echo "  \u{2717} {$m}\n";
    $fail++;
};

$purge = function() use ($elements, $prefix) {
    foreach ([Service::class, ServiceExtra::class] as $cls) {
        foreach ($cls::find()->siteId('*')->status(null)->trashed(null)->all() as $el) {
            if (str_starts_with((string)$el->title, $prefix)) {
                $elements->deleteElement($el, true);
            }
        }
    }
};

/**
 * The set of services carrying an enabled add-on, exactly as
 * BookingDataController::actionGetServices() computes it.
 */
$servicesWithExtras = function(array $serviceIds, int $siteId) use ($db): array {
    $enabledExtraIds = ElementQueryHelper::forSite(
        ServiceExtra::find()->status('enabled')->unique(),
        $siteId
    )->ids();

    if (empty($enabledExtraIds)) {
        return [];
    }

    return (new \craft\db\Query())
        ->select(['serviceId'])
        ->distinct()
        ->from('{{%booked_service_extras_services}}')
        ->where(['serviceId' => $serviceIds, 'extraId' => $enabledExtraIds])
        ->column();
};

$purge();
echo "Service list — add-on flag\n";

try {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    $withExtra = new Service();
    $withExtra->title = "{$prefix} With";
    $withExtra->duration = 60;
    $elements->saveElement($withExtra);

    $withoutExtra = new Service();
    $withoutExtra->title = "{$prefix} Without";
    $withoutExtra->duration = 60;
    $elements->saveElement($withoutExtra);

    $extra = new ServiceExtra();
    $extra->title = "{$prefix} Addon";
    $extra->price = 5.0;
    $extra->duration = 15;
    $elements->saveElement($extra);

    $db->createCommand()->insert('{{%booked_service_extras_services}}', [
        'extraId' => $extra->id,
        'serviceId' => $withExtra->id,
        'sortOrder' => 0,
        'dateCreated' => date('Y-m-d H:i:s'),
        'dateUpdated' => date('Y-m-d H:i:s'),
        'uid' => StringHelper::UUID(),
    ])->execute();

    $ids = [$withExtra->id, $withoutExtra->id];

    // The bug: this threw, so the caller's catch left the set empty and every
    // service looked add-on free.
    $found = $servicesWithExtras($ids, $siteId);

    in_array($withExtra->id, $found)
        ? $ok('a service with an enabled add-on is flagged')
        : $bad('the service with an add-on was not flagged — the query found nothing');

    !in_array($withoutExtra->id, $found)
        ? $ok('a service without add-ons is not flagged')
        : $bad('a service with no add-ons was flagged');

    // Disabling the extra must drop the flag — proof the status filter is real
    // and not simply absent.
    $extra->enabled = false;
    $extra->enabledForSite = false;
    $elements->saveElement($extra);

    $foundAfter = $servicesWithExtras($ids, $siteId);
    !in_array($withExtra->id, $foundAfter)
        ? $ok('a disabled add-on stops flagging its service')
        : $bad('a disabled add-on still flagged its service');

    $extra->enabled = true;
    $extra->enabledForSite = true;
    $elements->saveElement($extra);

    in_array($withExtra->id, $servicesWithExtras($ids, $siteId))
        ? $ok('re-enabling the add-on flags it again')
        : $bad('re-enabling the add-on did not restore the flag');

    // Pin the reason the original query could never work.
    $columns = array_map(
        fn($c) => is_array($c) ? ($c['Field'] ?? '') : $c,
        $db->createCommand('SHOW COLUMNS FROM {{%booked_service_extras}}')->queryAll(),
    );
    !in_array('enabled', $columns, true)
        ? $ok('booked_service_extras still has no `enabled` column, so status must come from the element')
        : $bad('booked_service_extras now HAS an `enabled` column — revisit how status is read');
} catch (Throwable $e) {
    $bad('threw: ' . $e->getMessage());
} finally {
    $purge();
}

echo "\n" . str_repeat('=', 40) . "\n";
echo "passed {$pass}, failed {$fail}\n";
exit($fail === 0 ? 0 : 1);
