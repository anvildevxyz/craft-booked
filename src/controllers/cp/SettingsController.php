<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\models\Settings;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use Money\Currencies\ISOCurrencies;

class SettingsController extends Controller
{
    use JsonResponseTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('booked-manageSettings');
        return true;
    }

    public function actionBooking(): Response
    {
        return $this->renderSettingsTemplate('booked/settings/booking', 'booking', 'selectedSettingsSubnavItem');
    }

    public function actionWaitlist(): Response
    {
        return $this->renderSettingsTemplate('booked/settings/waitlist', 'waitlist', 'selectedSettingsSubnavItem');
    }

    public function actionSecurity(): Response
    {
        return $this->renderSettingsTemplate('booked/settings/security', 'security', 'selectedSettingsSubnavItem');
    }

    public function actionCalendar(): Response
    {
        return $this->renderSettingsTemplate('booked/settings/calendar', 'calendar');
    }

    public function actionMeetings(): Response
    {
        return $this->renderSettingsTemplate('booked/settings/meetings', 'meetings');
    }

    public function actionNotifications(): Response
    {
        return $this->renderSettingsTemplate('booked/settings/notifications', 'notifications');
    }

    public function actionCommerce(): Response
    {
        $currencyOptions = [
            ['label' => Craft::t('booked', 'settings.booking.autoDetectCurrency'), 'value' => 'auto'],
        ];
        foreach (new ISOCurrencies() as $currency) {
            $currencyOptions[] = ['label' => $currency->getCode(), 'value' => $currency->getCode()];
        }

        return $this->renderTemplate('booked/settings/commerce', [
            'selectedSubnavItem' => 'commerce',
            'settings' => Settings::loadSettings(),
            'currencyOptions' => $currencyOptions,
        ]);
    }

    public function actionSms(): Response
    {
        return $this->renderSettingsTemplate('booked/settings/sms', 'sms', 'selectedSettingsSubnavItem');
    }

    public function actionWebhooks(): Response
    {
        return $this->renderSettingsTemplate('booked/settings/webhooks', 'webhooks', 'selectedSettingsSubnavItem');
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $postedSettings = Craft::$app->request->getBodyParam('settings', []);
        $tab = Craft::$app->request->getBodyParam('tab', '');
        $settings = Settings::loadSettings();

        // Only allow attributes for the submitted tab
        $safeAttributes = $settings->safeAttributesForTab($tab);
        $filteredSettings = array_intersect_key($postedSettings, array_flip($safeAttributes));
        $settings->setAttributes($filteredSettings);

        if ($settings->validate() && $settings->save()) {
            Booked::getInstance()->getAudit()->logSettingsChange(
                Craft::$app->getUser()->getIdentity()->email ?? 'unknown',
                array_keys($filteredSettings)
            );
            Craft::$app->session->setNotice(Craft::t('booked', 'settings.saved'));
        } else {
            Craft::$app->session->setError($settings->hasErrors()
                ? Craft::t('booked', 'settings.validationErrors', ['errors' => implode(', ', $settings->getFirstErrors())])
                : Craft::t('booked', 'settings.notSaved')
            );
        }

        return $this->redirectToPostedUrl();
    }

    public function actionTestTwilio(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Settings::loadSettings();
        if (!$settings->smsEnabled) {
            return $this->jsonError('SMS notifications are not enabled. Please enable SMS first.');
        }

        $result = Booked::getInstance()->getTwilioSms()->testConnection();

        if ($result['success']) {
            $message = "✓ {$result['message']}\n\n"
                . "Account Status: {$result['details']['accountStatus']}\n"
                . "Account Type: {$result['details']['accountType']}\n";

            if (!empty($result['details']['phoneNumberCapabilities'])) {
                $message .= "SMS Capable: " . ($result['details']['phoneNumberCapabilities']['sms'] ? 'Yes' : 'No') . "\n";
            }
            if (!empty($result['details']['warning'])) {
                $message .= "\n⚠️ WARNING: {$result['details']['warning']}";
            }
            return $this->jsonSuccess($message);
        }

        $message = "✗ {$result['message']}";
        if (!empty($result['details']['errorCode'])) {
            $message .= "\nError Code: {$result['details']['errorCode']}";
        }
        $message .= "\n\n🔧 TROUBLESHOOTING:\n"
            . "1. Verify your Account SID and Auth Token at console.twilio.com\n"
            . "2. Make sure your Twilio account is active and not suspended\n"
            . "3. Check that the phone number belongs to this account\n"
            . "4. For trial accounts, verify recipient numbers in Twilio console";

        return $this->jsonError($message);
    }

    public function actionTestCalendarConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $provider = $request->getBodyParam('provider');
        $clientId = $request->getBodyParam('clientId');
        $clientSecret = $request->getBodyParam('clientSecret');

        if (!in_array($provider, ['google', 'outlook'], true)) {
            return $this->jsonError('Invalid provider specified.');
        }
        if (empty($clientId) || empty($clientSecret)) {
            return $this->jsonError('Client ID and Client Secret are required.');
        }

        try {
            return $provider === 'google'
                ? $this->testGoogleConnection($clientId, $clientSecret)
                : $this->testOutlookConnection($clientId, $clientSecret);
        } catch (\GuzzleHttp\Exception\ConnectException) {
            return $this->jsonError('Could not connect to ' . ($provider === 'google' ? 'Google' : 'Microsoft') . ' servers. Please check your internet connection.');
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Craft::error('Calendar connection test failed: ' . $e->getMessage(), __METHOD__);
            $code = $e->getResponse()?->getStatusCode();
            return $this->jsonError('Connection failed' . ($code ? " (HTTP {$code})" : '') . '. Check your credentials and try again.');
        } catch (\Exception $e) {
            Craft::error('Calendar connection test failed: ' . $e->getMessage(), __METHOD__);
            return $this->jsonError('Connection failed. Check your credentials and try again.');
        }
    }

    public function actionTestTeams(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Settings::loadSettings();
        if (empty($settings->teamsTenantId) || empty($settings->teamsClientId) || empty($settings->teamsClientSecret)) {
            return $this->jsonError('Teams credentials are not fully configured. Please fill in Tenant ID, Client ID, and Client Secret.');
        }

        try {
            $data = json_decode(
                (new \GuzzleHttp\Client())->post(
                    "https://login.microsoftonline.com/{$settings->teamsTenantId}/oauth2/v2.0/token",
                    ['form_params' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $settings->teamsClientId,
                        'client_secret' => $settings->teamsClientSecret,
                        'scope' => 'https://graph.microsoft.com/.default',
                    ]]
                )->getBody()->getContents(),
                true
            );

            $expiresIn = (string) ($data['expires_in'] ?? '');
            return !empty($data['access_token'])
                ? $this->jsonSuccess("✓ Teams connection successful!\n\nToken expires in: {$expiresIn} seconds")
                : $this->jsonError('Unexpected response from Microsoft. Please check your credentials.');
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            $error = (string) ($body['error'] ?? 'Unknown error');
            $errorDescription = (string) ($body['error_description'] ?? '');

            return $this->jsonError(
                "✗ Teams connection failed!\n\nError: {$error}"
                . ($errorDescription ? "\nDetails: " . strtok($errorDescription, "\r\n") : '')
                . "\n\n🔧 TROUBLESHOOTING:\n"
                . "1. Verify your Tenant ID, Client ID, and Client Secret in Azure Portal\n"
                . "2. Ensure the app has 'OnlineMeetings.ReadWrite.All' application permission\n"
                . "3. Grant admin consent for the permission in Azure Portal → API permissions\n"
                . "4. Check that the client secret has not expired"
            );
        } catch (\Exception $e) {
            Craft::error('Teams connection test failed: ' . $e->getMessage(), __METHOD__);
            return $this->jsonError('Connection failed. Check your credentials and try again.');
        }
    }

    public function actionTestZoom(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Settings::loadSettings();
        if (empty($settings->zoomAccountId) || empty($settings->zoomClientId) || empty($settings->zoomClientSecret)) {
            return $this->jsonError('Zoom credentials are not fully configured. Please fill in Account ID, Client ID, and Client Secret.');
        }

        try {
            $data = json_decode(
                (new \GuzzleHttp\Client())->post('https://zoom.us/oauth/token', [
                    'form_params' => ['grant_type' => 'account_credentials', 'account_id' => $settings->zoomAccountId],
                    'auth' => [$settings->zoomClientId, $settings->zoomClientSecret],
                ])->getBody()->getContents(),
                true
            );

            $expiresIn = (string) ($data['expires_in'] ?? '');
            return !empty($data['access_token'])
                ? $this->jsonSuccess("✓ Zoom connection successful!\n\nToken expires in: {$expiresIn} seconds")
                : $this->jsonError("Unexpected response from Zoom. Please check your credentials.");
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            $errorMessage = (string) ($body['error'] ?? 'Unknown error');
            $errorReason = (string) ($body['reason'] ?? '');

            $troubleshooting = $errorMessage === 'invalid_client'
                ? "\n\n🔧 TROUBLESHOOTING:\n"
                    . "1. Make sure you created a 'Server-to-Server OAuth' app (NOT 'OAuth' app)\n"
                    . "2. Go to marketplace.zoom.us → Develop → Build App\n"
                    . "3. Click on your app → App Credentials\n"
                    . "4. Copy Account ID, Client ID, Client Secret EXACTLY\n"
                    . "5. Make sure the app is ACTIVATED (check the Activation tab)\n"
                    . "6. Check for extra spaces when pasting credentials"
                : '';

            return $this->jsonError(
                "✗ Zoom connection failed!\n\nError: {$errorMessage}"
                . ($errorReason ? "\nReason: {$errorReason}" : '')
                . $troubleshooting
            );
        } catch (\Exception $e) {
            Craft::error('Zoom connection test failed: ' . $e->getMessage(), __METHOD__);
            return $this->jsonError('Connection failed. Check your credentials and try again.');
        }
    }

    private function renderSettingsTemplate(string $template, string $subnavItem, string $subnavKey = 'selectedSubnavItem'): Response
    {
        return $this->renderTemplate($template, [
            $subnavKey => $subnavItem,
            'settings' => Settings::loadSettings(),
        ]);
    }

    private function testGoogleConnection(string $clientId, string $clientSecret): Response
    {
        if (!str_ends_with($clientId, '.apps.googleusercontent.com')) {
            return $this->jsonError('Invalid Google Client ID format. It should end with ".apps.googleusercontent.com"');
        }

        $data = json_decode(
            (new \GuzzleHttp\Client())->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'authorization_code',
                    'code' => 'test_invalid_code',
                    'redirect_uri' => 'https://localhost/callback',
                ],
                'http_errors' => false,
            ])->getBody()->getContents(),
            true
        );

        $error = $data['error'] ?? '';
        return match (true) {
            in_array($error, ['invalid_grant', 'redirect_uri_mismatch'], true) =>
                $this->jsonSuccess('Credentials are valid! You can now connect employee calendars.'),
            $error === 'invalid_client' =>
                $this->jsonError('Invalid Client ID or Client Secret. Please check your Google Cloud Console credentials.'),
            $error === 'unauthorized_client' =>
                $this->jsonError('Unauthorized client. Make sure the OAuth consent screen is configured and the app is not in test mode, or add test users.'),
            default =>
                $this->jsonError('Unexpected error: ' . ($data['error_description'] ?? $error ?: 'Unknown error')),
        };
    }

    private function testOutlookConnection(string $clientId, string $clientSecret): Response
    {
        $data = json_decode(
            (new \GuzzleHttp\Client())->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
                'form_params' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'authorization_code',
                    'code' => 'test_invalid_code',
                    'redirect_uri' => 'https://localhost/callback',
                    'scope' => 'https://graph.microsoft.com/.default',
                ],
                'http_errors' => false,
            ])->getBody()->getContents(),
            true
        );

        $error = $data['error'] ?? '';
        $errorDescription = $data['error_description'] ?? '';

        return match (true) {
            $error === 'invalid_grant' =>
                $this->jsonSuccess('Credentials are valid! You can now connect employee calendars.'),
            ($error === 'invalid_client' || $error === 'unauthorized_client') && str_contains($errorDescription, 'AADSTS7000215') =>
                $this->jsonError('Invalid Client Secret. The secret may have expired or is incorrect.'),
            ($error === 'invalid_client' || $error === 'unauthorized_client') && str_contains($errorDescription, 'AADSTS700016') =>
                $this->jsonError('Application not found. Check your Client ID (Application ID) in Azure Portal.'),
            $error === 'invalid_client' || $error === 'unauthorized_client' =>
                $this->jsonError('Invalid Client ID or Client Secret. Please check your Azure Portal credentials.'),
            $error === 'invalid_request' && str_contains($errorDescription, 'code') =>
                $this->jsonSuccess('Credentials appear valid! You can now connect employee calendars.'),
            $error === 'invalid_request' =>
                $this->jsonError('Invalid request: ' . $errorDescription),
            default =>
                $this->jsonError('Unexpected error: ' . ($errorDescription ?: $error ?: 'Unknown error')),
        };
    }
}
