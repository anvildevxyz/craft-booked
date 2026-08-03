<?php

/**
 * Exercises every subsystem whose third-party package moved in the lock refresh.
 *
 * A `composer update` that clears 84 advisories also moves 61 packages, and the
 * unit suite cannot speak for most of them: it skips anything needing a booted
 * Craft, so it never loads Guzzle, Twig, the Google or Twilio SDKs, or the
 * GraphQL engine at all. This does, against the real application.
 *
 * Each check goes as deep as it can without third-party credentials — building
 * the client, resolving the schema, rendering the template — which is where an
 * incompatible SDK surfaces. Anything needing a live account says so instead of
 * silently passing.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/craft-booked/tests/integration-live/dependency-surface.php
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\booked\Booked;
use craft\helpers\Json;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

function version(string $package): string
{
    $installed = \Composer\InstalledVersions::getVersion($package);
    return $installed ?? '(absent)';
}

// ─────────────────────────────────────────────────────────── GraphQL
// webonyx/graphql-php was replaced by Craft's fork, pixelandtonic/graphql-php.
// The plugin imports GraphQL\Type\Definition\* directly, so this is the change
// with the most obvious way to go wrong.
echo "GraphQL — " . version('pixelandtonic/graphql-php') . "\n";

check('the engine resolves under the forked package', class_exists(\GraphQL\Type\Definition\ObjectType::class));

$gqlTypes = [];
foreach (glob(dirname(__DIR__, 2) . '/src/gql/types/*.php') as $file) {
    $class = 'anvildev\\booked\\gql\\types\\' . basename($file, '.php');
    if (class_exists($class)) {
        $gqlTypes[] = $class;
    }
}
check('the plugin GraphQL types load', $gqlTypes !== [], count($gqlTypes) . ' type class(es)');

// Building a type is what actually touches the forked library.
$built = null;
$buildError = null;
try {
    $built = \anvildev\booked\gql\types\MutationError::getType();
} catch (\Throwable $e) {
    $buildError = $e->getMessage();
}
check(
    'a plugin type instantiates against the fork',
    $built !== null && $buildError === null,
    $buildError ?? get_debug_type($built),
);

// And the real thing: execute a query through Craft's GraphQL API.
$gqlResult = null;
$gqlError = null;
try {
    $schema = Craft::$app->getGql()->getPublicSchema();
    if ($schema === null) {
        $gqlError = 'no public schema on this install';
    } else {
        $gqlResult = Craft::$app->getGql()->executeQuery(
            $schema,
            '{ bookedServices { id title } }',
        );
    }
} catch (\Throwable $e) {
    $gqlError = get_class($e) . ': ' . $e->getMessage();
}

if ($gqlError !== null && str_contains($gqlError, 'no public schema')) {
    printf("  [SKIP] executing a query — %s\n", $gqlError);
} else {
    $errors = $gqlResult['errors'] ?? [];
    check(
        'a Booked query executes end to end',
        $gqlResult !== null && $errors === [],
        $gqlError ?? ($errors !== [] ? Json::encode(array_column($errors, 'message')) : 'returned ' . count($gqlResult['data']['bookedServices'] ?? []) . ' service(s)'),
    );
}

// ─────────────────────────────────────────────────────────── SMS / Twilio
echo "\nSMS — twilio/sdk " . version('twilio/sdk') . "\n";

check('the Twilio SDK loads', class_exists(\Twilio\Rest\Client::class));

$twilioClient = null;
$twilioError = null;
try {
    // Constructing with dummy credentials touches the SDK's whole init path
    // without contacting Twilio.
    $twilioClient = new \Twilio\Rest\Client('AC' . str_repeat('0', 32), str_repeat('0', 32));
    $probe = $twilioClient->messages;   // resolving a domain is where API drift shows
} catch (\Throwable $e) {
    $twilioError = get_class($e) . ': ' . $e->getMessage();
}
check('a client constructs and resolves its messages API', $twilioClient !== null && $twilioError === null, $twilioError ?? 'ok');

$sms = Booked::getInstance()->getTwilioSms();
check('the plugin SMS service resolves', $sms !== null, get_debug_type($sms));

// ─────────────────────────────────────────── Calendar sync — Google + Outlook
echo "\nCalendar — google/apiclient " . version('google/apiclient')
    . ", microsoft-graph " . version('microsoft/microsoft-graph')
    . ", oauth2-client " . version('league/oauth2-client') . "\n";

check('the Google API client loads', class_exists(\Google\Client::class));
$google = null;
$googleError = null;
try {
    $google = new \Google\Client();
    $google->setApplicationName('booked-dependency-probe');
    $google->setScopes([\Google\Service\Calendar::CALENDAR]);
    $service = new \Google\Service\Calendar($google);
} catch (\Throwable $e) {
    $googleError = get_class($e) . ': ' . $e->getMessage();
}
check('a Google Calendar service constructs', $google !== null && $googleError === null, $googleError ?? 'ok');

check('the Microsoft Graph client loads', class_exists(\Microsoft\Graph\Graph::class));
$graphError = null;
try {
    $graph = new \Microsoft\Graph\Graph();
    $graph->setAccessToken('probe-token');
} catch (\Throwable $e) {
    $graphError = get_class($e) . ': ' . $e->getMessage();
}
check('a Graph client constructs', $graphError === null, $graphError ?? 'ok');

check('the OAuth2 client loads', class_exists(\League\OAuth2\Client\Provider\GenericProvider::class));

foreach (['GoogleCalendarProvider', 'OutlookCalendarProvider'] as $provider) {
    $class = 'anvildev\\booked\\services\\calendar\\' . $provider;
    check("the plugin's {$provider} loads", class_exists($class));
}
check('the calendar sync service resolves', Booked::getInstance()->getCalendarSync() !== null);

// ─────────────────────────────────────────── Outbound HTTP — Guzzle, webhooks
echo "\nWebhooks / HTTP — guzzlehttp/guzzle " . version('guzzlehttp/guzzle') . "\n";

check('Guzzle loads', class_exists(\GuzzleHttp\Client::class));
$guzzleError = null;
try {
    // Craft's own client factory, which is what the plugin's webhook dispatch
    // and every integration goes through.
    $http = Craft::createGuzzleClient(['timeout' => 5]);
    // A URL that must answer 200. The site's own homepage 500s for reasons of
    // its own, and "any status" would have passed on that — proving only that
    // an exception wasn't thrown.
    $response = $http->get('https://craft-plugin-dev.ddev.site/admin/login', ['verify' => false, 'http_errors' => false]);
    $status = $response->getStatusCode();
} catch (\Throwable $e) {
    $guzzleError = get_class($e) . ': ' . $e->getMessage();
    $status = 0;
}
check('an outbound request round-trips', $guzzleError === null && $status === 200, $guzzleError ?? "HTTP {$status}");
check('the webhook service resolves', Booked::getInstance()->getWebhook() !== null);

// ─────────────────────────────────────────── Email — symfony/mailer + mime
echo "\nEmail — symfony/mailer " . version('symfony/mailer') . ", mime " . version('symfony/mime') . "\n";

$mailError = null;
$sent = false;
try {
    $sent = Craft::$app->getMailer()
        ->compose()
        ->setTo('depprobe@example.test')
        ->setSubject('Booked dependency probe')
        ->setTextBody('Sent to prove symfony/mailer and mime still compose and deliver.')
        ->send();
} catch (\Throwable $e) {
    $mailError = get_class($e) . ': ' . $e->getMessage();
}
check('a message composes and sends', $sent && $mailError === null, $mailError ?? 'delivered');

// ─────────────────────────────────────────── Twig — the templating engine
echo "\nTemplates — twig/twig " . version('twig/twig') . "\n";

$twigError = null;
$rendered = '';
try {
    $view = Craft::$app->getView();
    $view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);
    $rendered = $view->renderString('{{ "booked"|t("booked") }}|{{ 1 + 1 }}', []);
} catch (\Throwable $e) {
    $twigError = get_class($e) . ': ' . $e->getMessage();
}
check('Twig renders with the plugin translation category', $twigError === null && str_contains($rendered, '|2'), $twigError ?? $rendered);

// ─────────────────────────────────────────── Craft itself
echo "\nCraft — " . Craft::$app->getVersion() . "\n";
check('the plugin is installed and enabled', Craft::$app->getPlugins()->isPluginEnabled('booked'));
check('its element queries still run', \anvildev\booked\elements\Service::find()->siteId('*')->status(null)->count() >= 0);

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
