<?php

namespace anvildev\booked\console\controllers;

use anvildev\booked\Booked;
use anvildev\booked\models\Settings;
use anvildev\booked\records\WebhookLogRecord;
use Craft;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Health check diagnostics for the Booked plugin
 */
class DoctorController extends Controller
{
    public bool $ping = false;

    private int $passed = 0;
    private int $warnings = 0;
    private int $errors = 0;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'ping'];
    }

    public function actionIndex(): int
    {
        $this->stdout("\nBooked Health Check\n", Console::BOLD);
        $this->stdout("═══════════════════════════════════\n\n");

        $this->checkDatabase();
        $this->checkSettings();
        $this->checkEmail();
        $this->checkData();

        $settings = Settings::loadSettings();
        $this->checkGoogleCalendar($settings);
        $this->checkOutlookCalendar($settings);
        $this->checkZoom($settings);
        $this->checkTeams($settings);
        $this->checkTwilioSms($settings);
        $this->checkCaptcha($settings);
        $this->checkWebhooks($settings);
        $this->checkQueue();

        $this->stdout("═══════════════════════════════════\n");
        $this->stdout("Result: ");
        $this->stdout("{$this->passed} passed", Console::FG_GREEN);
        if ($this->warnings > 0) {
            $this->stdout(", {$this->warnings} warning" . ($this->warnings !== 1 ? 's' : ''), Console::FG_YELLOW);
        }
        if ($this->errors > 0) {
            $this->stdout(", {$this->errors} error" . ($this->errors !== 1 ? 's' : ''), Console::FG_RED);
        }
        $this->stdout("\n\n");

        return $this->errors > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function checkDatabase(): void
    {
        $this->heading('Database');

        $tables = [
            'booked_settings', 'booked_reservations', 'booked_blackout_dates',
            'booked_event_dates', 'booked_services', 'booked_service_extras',
            'booked_service_extras_services', 'booked_employees', 'booked_locations',
            'booked_schedules', 'booked_employee_schedule_assignments',
            'booked_service_schedule_assignments', 'booked_employee_managers',
            'booked_soft_locks', 'booked_reservation_extras', 'booked_calendar_tokens',
            'booked_calendar_sync_status', 'booked_calendar_invites',
            'booked_oauth_state_tokens', 'booked_webhooks', 'booked_webhook_logs', 'booked_waitlist',
        ];

        $db = Craft::$app->getDb();
        $missing = array_filter($tables, fn($t) => !$db->tableExists("{{%{$t}}}"));
        $total = count($tables);

        empty($missing)
            ? $this->pass("All {$total} tables present")
            : $this->fail(($total - count($missing)) . "/{$total} tables present — missing: " . implode(', ', $missing));
    }

    private function checkSettings(): void
    {
        $this->heading('Settings');

        $settings = Settings::loadSettings();

        if ($settings->id === null) {
            $this->fail('Plugin settings not found in database — save settings in the CP');
            return;
        }

        $this->pass('Plugin settings configured');

        $email = $settings->getEffectiveEmail();
        !empty($email) ? $this->pass("Owner email: {$email}") : $this->fail('No owner email configured');

        $name = $settings->getEffectiveName();
        !empty($name) ? $this->pass("Owner name: {$name}") : $this->warn('No owner name configured');
    }

    private function checkEmail(): void
    {
        $this->heading('Email');

        try {
            Craft::$app->getMailer() ? $this->pass('Craft mailer configured') : $this->fail('Craft mailer not available');
        } catch (\Throwable $e) {
            $this->fail('Craft mailer error: ' . $e->getMessage());
        }
    }

    private function checkData(): void
    {
        $this->heading('Data');

        $hints = [
            'Services' => 'no services configured — bookings won\'t work',
            'Employees' => 'no employees configured',
            'Locations' => 'no locations configured',
            'Schedules' => 'no working hours defined',
        ];

        $classes = [
            'Services' => \anvildev\booked\elements\Service::class,
            'Employees' => \anvildev\booked\elements\Employee::class,
            'Locations' => \anvildev\booked\elements\Location::class,
            'Schedules' => \anvildev\booked\elements\Schedule::class,
        ];

        foreach ($classes as $label => $class) {
            $count = $class::find()->siteId('*')->count();
            $count > 0 ? $this->pass("{$label}: {$count}") : $this->warn("{$label}: 0 — {$hints[$label]}");
        }
    }

    private function checkCalendarIntegration(Settings $settings, string $enabledProp, string $clientIdProp, string $clientSecretProp, string $label, string $clientMethod): void
    {
        if (!$settings->$enabledProp) {
            return;
        }

        $this->heading($label, true);

        empty($settings->$clientIdProp) ? $this->fail('Client ID not configured') : $this->pass('Client ID configured');
        empty($settings->$clientSecretProp) ? $this->fail('Client Secret not configured') : $this->pass('Client Secret configured');

        if (!empty($settings->$clientIdProp) && !empty($settings->$clientSecretProp)) {
            try {
                Booked::getInstance()->getCalendarSync()->$clientMethod();
                $this->pass("{$label} Client initializes successfully");
            } catch (\Throwable $e) {
                $this->fail("{$label} Client error: " . $e->getMessage());
            }
        }
    }

    private function checkGoogleCalendar(Settings $settings): void
    {
        $this->checkCalendarIntegration($settings, 'googleCalendarEnabled', 'googleCalendarClientId', 'googleCalendarClientSecret', 'Google Calendar', 'getGoogleClient');
    }

    private function checkOutlookCalendar(Settings $settings): void
    {
        $this->checkCalendarIntegration($settings, 'outlookCalendarEnabled', 'outlookCalendarClientId', 'outlookCalendarClientSecret', 'Outlook Calendar', 'getOutlookClient');
    }

    private function checkCredentialIntegration(Settings $settings, string $enabledProp, string $label, array $credentialChecks, ?callable $pingFn = null): void
    {
        if (!$settings->$enabledProp) {
            return;
        }

        $this->heading($label, true);

        $missing = [];
        foreach ($credentialChecks as $propLabel => $prop) {
            if (empty($settings->$prop)) {
                $missing[] = $propLabel;
            }
        }

        if (empty($missing)) {
            $this->pass('Credentials configured');
        } else {
            $this->fail('Missing: ' . implode(', ', $missing));
            return;
        }

        if ($this->ping && $pingFn) {
            $pingFn();
        }
    }

    private function checkZoom(Settings $settings): void
    {
        $this->checkCredentialIntegration($settings, 'zoomEnabled', 'Zoom', [
            'Account ID' => 'zoomAccountId',
            'Client ID' => 'zoomClientId',
            'Client Secret' => 'zoomClientSecret',
        ], function() use ($settings) {
            try {
                $response = (new \GuzzleHttp\Client())->post('https://zoom.us/oauth/token', [
                    'form_params' => ['grant_type' => 'account_credentials', 'account_id' => $settings->zoomAccountId],
                    'auth' => [$settings->zoomClientId, $settings->zoomClientSecret],
                    'timeout' => 10,
                ]);
                $data = json_decode($response->getBody()->getContents(), true);
                isset($data['access_token']) ? $this->pass('API connection verified') : $this->fail('Unexpected token response');
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $this->fail('API error: ' . ($e->getResponse()->getBody()->getContents() ?: $e->getMessage()));
            } catch (\Throwable $e) {
                $this->fail('Connection failed: ' . $e->getMessage());
            }
        });
    }

    private function checkTeams(Settings $settings): void
    {
        $this->checkCredentialIntegration($settings, 'teamsEnabled', 'Microsoft Teams', [
            'Tenant ID' => 'teamsTenantId',
            'Client ID' => 'teamsClientId',
            'Client Secret' => 'teamsClientSecret',
        ], function() use ($settings) {
            try {
                $response = (new \GuzzleHttp\Client())->post(
                    "https://login.microsoftonline.com/{$settings->teamsTenantId}/oauth2/v2.0/token",
                    [
                        'form_params' => [
                            'grant_type' => 'client_credentials',
                            'client_id' => $settings->teamsClientId,
                            'client_secret' => $settings->teamsClientSecret,
                            'scope' => 'https://graph.microsoft.com/.default',
                        ],
                        'timeout' => 10,
                    ],
                );
                $data = json_decode($response->getBody()->getContents(), true);
                isset($data['access_token']) ? $this->pass('API connection verified') : $this->fail('Unexpected token response');
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $this->fail('API error: ' . ($e->getResponse()->getBody()->getContents() ?: $e->getMessage()));
            } catch (\Throwable $e) {
                $this->fail('Connection failed: ' . $e->getMessage());
            }
        });
    }

    private function checkTwilioSms(Settings $settings): void
    {
        $this->checkCredentialIntegration($settings, 'smsEnabled', 'Twilio SMS', [
            'Account SID' => 'twilioAccountSid',
            'Auth Token' => 'twilioAuthToken',
            'Phone Number' => 'twilioPhoneNumber',
        ], function() use ($settings) {
            $result = Booked::getInstance()->getTwilioSms()->testConnection();
            if ($result['success']) {
                $details = $result['details'] ?? [];
                $type = $details['accountType'] ?? '';
                $phone = $details['phoneNumber'] ?? $settings->twilioPhoneNumber;
                $this->pass(($type ? "Account active ({$type})" : 'Account active') . " — {$phone}");
            } else {
                $this->fail($result['message']);
            }
        });
    }

    private function checkCaptcha(Settings $settings): void
    {
        if (!$settings->enableCaptcha) {
            return;
        }

        $this->heading('CAPTCHA', true);

        if (empty($settings->captchaProvider)) {
            $this->fail('No CAPTCHA provider selected');
            return;
        }

        $this->pass("Provider: {$settings->captchaProvider}");

        $keyPairs = [
            'recaptcha' => ['recaptchaSiteKey', 'recaptchaSecretKey'],
            'hcaptcha' => ['hcaptchaSiteKey', 'hcaptchaSecretKey'],
            'turnstile' => ['turnstileSiteKey', 'turnstileSecretKey'],
        ];

        if (!isset($keyPairs[$settings->captchaProvider])) {
            $this->fail("Unknown provider: {$settings->captchaProvider}");
            return;
        }

        [$siteKeyProp, $secretKeyProp] = $keyPairs[$settings->captchaProvider];

        if (!empty($settings->$siteKeyProp) && !empty($settings->$secretKeyProp)) {
            $this->pass('Site key and secret key configured');
        } else {
            $missing = array_filter([
                empty($settings->$siteKeyProp) ? 'site key' : null,
                empty($settings->$secretKeyProp) ? 'secret key' : null,
            ]);
            $this->fail('Missing: ' . implode(', ', $missing));
        }
    }

    private function checkWebhooks(Settings $settings): void
    {
        if (!$settings->webhooksEnabled) {
            return;
        }

        $this->heading('Webhooks', true);

        $webhooks = Booked::getInstance()->getWebhook()->getAllWebhooks();

        if (empty($webhooks)) {
            $this->warn('No webhooks configured');
            return;
        }

        $enabled = count(array_filter($webhooks, fn($w) => $w->enabled));
        $total = count($webhooks);
        $this->pass("{$total} webhook" . ($total !== 1 ? 's' : '') . " configured ({$enabled} enabled, " . ($total - $enabled) . " disabled)");

        $recentLogs = WebhookLogRecord::find()
            ->where(['>=', 'dateCreated', (new \DateTime('-7 days'))->format('Y-m-d H:i:s')])
            ->all();

        if (!empty($recentLogs)) {
            $failures = count(array_filter($recentLogs, fn($log) => !$log->success));
            $logCount = count($recentLogs);

            $failures > 0
                ? $this->warn("Recent deliveries: {$failures}/{$logCount} failed (" . round(($failures / $logCount) * 100) . "%) in last 7 days")
                : $this->pass("Recent deliveries: {$logCount} successful in last 7 days");
        }
    }

    private function checkQueue(): void
    {
        $this->heading('Queue');

        try {
            $queue = Craft::$app->getQueue();
            if ($queue instanceof \craft\queue\QueueInterface) {
                $info = $queue->getJobInfo();
                $waiting = count($info);
                $this->pass("Queue available — {$waiting} waiting job" . ($waiting !== 1 ? 's' : ''));
            } else {
                $this->pass('Queue available');
            }
        } catch (\Throwable $e) {
            $this->fail('Queue error: ' . $e->getMessage());
        }
    }

    private function heading(string $label, bool $enabled = false): void
    {
        $this->stdout($label . ($enabled ? ' [enabled]' : '') . "\n", Console::BOLD);
    }

    private function pass(string $message): void
    {
        $this->stdout("  ✓ {$message}\n", Console::FG_GREEN);
        $this->passed++;
    }

    private function warn(string $message): void
    {
        $this->stdout("  ! {$message}\n", Console::FG_YELLOW);
        $this->warnings++;
    }

    private function fail(string $message): void
    {
        $this->stdout("  ✗ {$message}\n", Console::FG_RED);
        $this->errors++;
    }
}
