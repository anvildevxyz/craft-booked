<?php

namespace anvildev\booked\console\controllers;

use anvildev\booked\Booked;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\models\Settings;
use Craft;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

class ConfigController extends Controller
{
    public $defaultAction = 'validate';

    private int $passed = 0;
    private int $warnings = 0;
    private int $errors = 0;

    public function actionValidate(): int
    {
        $settings = Settings::loadSettings();

        $this->stdout("\nConfiguration Validation\n", Console::BOLD);
        $this->stdout("═══════════════════════════════════\n\n");

        $this->validateSettings($settings);
        $this->validateGeneral($settings);
        $this->validateNotifications($settings);
        $this->validateIntegrations($settings);
        $this->validateSecurity($settings);
        $this->showEffectiveValues($settings);

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

    private function validateSettings(Settings $settings): void
    {
        $this->heading('Settings Validation');

        if ($settings->id === null) {
            $this->fail('Plugin settings not saved — save settings in the CP first');
            return;
        }

        $this->pass('Plugin settings saved');

        if ($settings->validate()) {
            $this->pass('All settings pass validation rules');
        } else {
            foreach ($settings->getErrors() as $attribute => $messages) {
                foreach ($messages as $message) {
                    $this->fail("{$attribute}: {$message}");
                }
            }
        }
    }

    private function validateGeneral(Settings $settings): void
    {
        $this->heading('General');

        $settings->maximumAdvanceBookingDays < 1
            ? $this->warn('Maximum advance booking days is 0 — customers cannot book future dates')
            : $this->pass("Advance booking window: {$settings->maximumAdvanceBookingDays} days");

        if ($settings->minimumAdvanceBookingHours > 0) {
            $this->pass("Minimum advance notice: {$settings->minimumAdvanceBookingHours} hour(s)");
        }

        $this->pass($settings->cancellationPolicyHours > 0
            ? "Cancellation policy: {$settings->cancellationPolicyHours} hour(s) before appointment"
            : 'Cancellation policy: no time limit');

        $this->pass("Soft lock duration: {$settings->softLockDurationMinutes} min");
    }

    private function validateNotifications(Settings $settings): void
    {
        $this->heading('Notifications');

        $email = $settings->getEffectiveEmail();
        !empty($email)
            ? $this->pass("Owner email: {$email}")
            : $this->fail('No owner email — confirmation and notification emails will fail');

        $this->pass($settings->emailRemindersEnabled
            ? "Email reminders: {$settings->emailReminderHoursBefore}h before appointment"
            : 'Email reminders: disabled');

        if ($settings->smsEnabled) {
            $settings->isSmsConfigured()
                ? $this->pass('SMS: enabled and configured')
                : $this->fail('SMS: enabled but Twilio credentials incomplete');

            if ($settings->smsConfirmationEnabled && !$settings->isSmsConfigured()) {
                $this->fail('SMS confirmations enabled but Twilio not configured');
            }
            if ($settings->smsRemindersEnabled && !$settings->isSmsConfigured()) {
                $this->fail('SMS reminders enabled but Twilio not configured');
            }
        }

        if ($settings->webhooksEnabled) {
            $webhooks = Booked::getInstance()->getWebhook()->getAllWebhooks();
            if (empty($webhooks)) {
                $this->warn('Webhooks enabled but no endpoints configured');
            } else {
                $enabled = count(array_filter($webhooks, fn($w) => $w->enabled));
                $total = count($webhooks);
                $enabled === 0
                    ? $this->warn("Webhooks: {$total} configured but none enabled")
                    : $this->pass("Webhooks: {$enabled}/{$total} enabled");
            }
        }
    }

    private function validateIntegrations(Settings $settings): void
    {
        $this->heading('Integrations');

        $checks = [
            ['googleCalendarEnabled', 'isGoogleCalendarConfigured', 'Google Calendar'],
            ['outlookCalendarEnabled', 'isOutlookCalendarConfigured', 'Outlook Calendar'],
            ['zoomEnabled', 'isZoomConfigured', 'Zoom'],
        ];

        foreach ($checks as [$enabledProp, $configuredMethod, $label]) {
            if ($settings->$enabledProp) {
                $settings->$configuredMethod()
                    ? $this->pass("{$label}: configured")
                    : $this->fail("{$label}: enabled but credentials incomplete");
            }
        }

        if ($settings->googleMeetEnabled) {
            !$settings->googleCalendarEnabled
                ? $this->warn('Google Meet enabled but Google Calendar is disabled — Meet links require calendar integration')
                : $this->pass('Google Meet: enabled');
        }

        if ($settings->commerceEnabled) {
            Craft::$app->plugins->isPluginEnabled('commerce')
                ? $this->pass('Commerce: enabled and installed')
                : $this->fail('Commerce: enabled in settings but Commerce plugin is not installed/enabled');
        }

        if (!$settings->googleCalendarEnabled && !$settings->outlookCalendarEnabled && !$settings->zoomEnabled && !$settings->googleMeetEnabled && !$settings->commerceEnabled) {
            $this->pass('No integrations enabled');
        }
    }

    private function validateSecurity(Settings $settings): void
    {
        $this->heading('Security');

        $isProduction = !Craft::$app->getConfig()->getGeneral()->devMode;

        // CSRF is always enforced
        $this->pass('CSRF validation: always enabled');

        // Rate limiting
        if ($settings->enableRateLimiting) {
            $this->pass("Rate limiting: {$settings->rateLimitPerEmail}/email/h, {$settings->rateLimitPerIp}/IP/h");
        } elseif ($isProduction) {
            $this->fail('Rate limiting disabled in production');
        } else {
            $this->warn('Rate limiting: disabled (dev mode)');
        }

        // CAPTCHA
        if ($settings->enableCaptcha) {
            !empty($settings->captchaProvider)
                ? $this->pass("CAPTCHA: {$settings->captchaProvider}")
                : $this->fail('CAPTCHA enabled but no provider selected');
        } elseif ($isProduction) {
            $this->warn('CAPTCHA: disabled — consider enabling for production');
        }

        if ($settings->enableHoneypot) {
            $this->pass("Honeypot: enabled (field: {$settings->honeypotFieldName})");
        }
    }

    private function showEffectiveValues(Settings $settings): void
    {
        $this->heading('Effective Values');

        $this->stdout("  Owner email:     " . ($settings->getEffectiveEmail() ?? '(not set)') . "\n");
        $this->stdout("  Owner name:      " . ($settings->getEffectiveName() ?? '(not set)') . "\n");
        $this->stdout("  Booking window:  {$settings->minimumAdvanceBookingHours}h – {$settings->maximumAdvanceBookingDays} days\n");
        $this->stdout("  Cancel policy:   " . ($settings->cancellationPolicyHours > 0 ? "{$settings->cancellationPolicyHours}h before" : 'anytime') . "\n");
        $this->stdout("  Soft lock:       {$settings->softLockDurationMinutes} min\n");
        $this->stdout("  Mode:            " . (ReservationFactory::isElementMode() ? 'Element (Commerce)' : 'ActiveRecord') . "\n\n");
    }

    private function heading(string $label): void
    {
        $this->stdout("{$label}\n", Console::BOLD);
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
