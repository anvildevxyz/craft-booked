<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\events\SmsEvent;
use anvildev\booked\helpers\PiiRedactor;
use anvildev\booked\queue\jobs\SendSmsJob;
use anvildev\booked\traits\RendersInLanguage;
use Craft;
use craft\base\Component;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;
use yii\base\Event;

/**
 * Sends booking-related SMS notifications via the Twilio API.
 * Messages are queued by default. Supports multi-language rendering,
 * E.164 phone normalization, and connection testing.
 */
class TwilioSmsService extends Component
{
    use RendersInLanguage;

    /** @event SmsEvent */
    public const EVENT_BEFORE_SEND_SMS = 'beforeSendSms';
    /** @event SmsEvent */
    public const EVENT_AFTER_SEND_SMS = 'afterSendSms';

    private ?Client $_client = null;

    public function send(string $to, string $message, array $options = []): bool
    {
        $settings = Booked::getInstance()->getSettings();

        if (!$settings->isSmsConfigured()) {
            Craft::warning('SMS sending attempted but SMS is not configured or not Pro edition', __METHOD__);
            return false;
        }

        $normalizedPhone = $this->normalizePhoneNumber($to, $settings->defaultCountryCode ?? 'US');
        if (!$normalizedPhone) {
            Craft::error('Invalid phone number format: ' . PiiRedactor::redactPhone($to), __METHOD__);
            return false;
        }

        if ($options['immediate'] ?? false) {
            return $this->sendImmediate($normalizedPhone, $message, $options);
        }

        Craft::$app->getQueue()->push(new SendSmsJob([
            'to' => $normalizedPhone,
            'body' => $message,
            'reservationId' => $options['reservationId'] ?? null,
            'messageType' => $options['messageType'] ?? 'general',
        ]));

        Craft::info('Queued SMS to ' . PiiRedactor::redactPhone($normalizedPhone) . ': ' . substr($message, 0, 50) . '...', __METHOD__);
        return true;
    }

    /**
     * @throws \Exception When throwOnError is true (default) and sending fails
     */
    public function sendImmediate(string $to, string $message, array $options = []): bool
    {
        $settings = Booked::getInstance()->getSettings();
        $throwOnError = $options['throwOnError'] ?? true;

        $beforeEvent = new SmsEvent([
            'to' => $to,
            'message' => $message,
            'messageType' => $options['messageType'] ?? 'general',
            'reservationId' => $options['reservationId'] ?? null,
        ]);
        $this->trigger(self::EVENT_BEFORE_SEND_SMS, $beforeEvent);

        if ($beforeEvent->handled) {
            return false;
        }

        $to = $beforeEvent->to;
        $message = $beforeEvent->message;

        $afterEventBase = [
            'to' => $to,
            'message' => $message,
            'messageType' => $options['messageType'] ?? 'general',
            'reservationId' => $options['reservationId'] ?? null,
        ];

        $client = $this->getClient();
        if (!$client) {
            $error = 'Twilio client not available - check your Account SID and Auth Token';
            Craft::error($error, __METHOD__);
            if ($throwOnError) {
                throw new \Exception($error);
            }
            return false;
        }

        try {
            $twilioMessage = $client->messages->create($to, [
                'from' => $settings->twilioPhoneNumber,
                'body' => $message,
            ]);

            Craft::info('SMS sent successfully to ' . PiiRedactor::redactPhone($to) . ", SID: {$twilioMessage->sid}", __METHOD__);
            $this->trigger(self::EVENT_AFTER_SEND_SMS, new SmsEvent($afterEventBase + ['success' => true]));
            return true;
        } catch (TwilioException $e) {
            $this->handleTwilioError($e, $to, $options);
            $errorMessage = $this->getHumanReadableTwilioError($e->getCode(), $e->getMessage(), $to);
            $this->trigger(self::EVENT_AFTER_SEND_SMS, new SmsEvent($afterEventBase + [
                'success' => false,
                'errorMessage' => $errorMessage,
            ]));
            if ($throwOnError) {
                throw new \Exception($errorMessage, $e->getCode(), $e);
            }
            return false;
        } catch (\Exception $e) {
            Craft::error("SMS send failed: " . $e->getMessage(), __METHOD__);
            $this->trigger(self::EVENT_AFTER_SEND_SMS, new SmsEvent($afterEventBase + [
                'success' => false,
                'errorMessage' => $e->getMessage(),
            ]));
            if ($throwOnError) {
                throw $e;
            }
            return false;
        }
    }

    protected function getHumanReadableTwilioError(int $errorCode, string $originalMessage, string $to): string
    {
        $maskedTo = PiiRedactor::redactPhone($to);
        $messages = [
            20003 => 'Authentication failed - check your Account SID and Auth Token',
            20008 => 'You are using TEST CREDENTIALS which cannot send real SMS. Use your LIVE credentials from console.twilio.com (make sure Test Mode is OFF)',
            20404 => 'Account not found - verify your Account SID',
            21211 => "Invalid phone number format: {$maskedTo}",
            21212 => 'Invalid From phone number - verify your Twilio phone number',
            21214 => "Phone number {$maskedTo} cannot receive SMS",
            21217 => "Phone number {$maskedTo} is invalid for the destination region",
            21408 => 'Permission denied - your account may not have SMS permission',
            21608 => "Cannot send SMS to unverified number {$maskedTo} - Trial accounts can only send to verified numbers. Add this number in your Twilio console under 'Verified Caller IDs'",
            21610 => "Recipient {$maskedTo} has unsubscribed from your messages",
            21611 => 'Message body too long (max 1600 characters)',
            21612 => "The 'To' phone number {$maskedTo} is not a valid SMS destination",
            21614 => "Phone number {$maskedTo} is not a mobile number capable of receiving SMS",
            30003 => 'Unreachable destination handset',
            30004 => 'Message blocked by carrier',
            30005 => "Unknown destination: {$maskedTo}",
            30006 => 'Landline or unreachable carrier',
            30007 => 'Message filtered by Twilio as spam',
        ];

        return "Twilio Error ({$errorCode}): " . ($messages[$errorCode] ?? $originalMessage);
    }

    /**
     * Send booking confirmation, reminder, or cancellation SMS.
     * Returns false if the relevant setting is disabled or no phone number exists.
     */
    private function sendReservationSms(ReservationInterface $reservation, string $type, string $messageType): bool
    {
        $settings = Booked::getInstance()->getSettings();
        $settingKey = match ($type) {
            'confirmation' => 'smsConfirmationEnabled',
            'reminder' => 'smsRemindersEnabled',
            'cancellation' => 'smsCancellationEnabled',
            default => throw new \InvalidArgumentException("Unknown SMS type: {$type}"),
        };

        if (!($settings->$settingKey ?? false)) {
            Craft::info("SMS {$type} not enabled in settings, skipping for reservation #{$reservation->id}", __METHOD__);
            return false;
        }

        if (empty($reservation->userPhone)) {
            Craft::info("Skipping SMS {$type} for reservation #{$reservation->id}: no phone number", __METHOD__);
            return false;
        }

        $language = $this->getReservationLanguage($reservation);
        $message = $this->renderWithLanguage(
            fn() => $this->renderMessage(
                $this->getTemplate($type),
                $this->getReservationVariables($reservation)
            ),
            $language
        );

        Craft::info("Sending {$type} SMS to " . PiiRedactor::redactPhone($reservation->userPhone) . " for reservation #{$reservation->id}", __METHOD__);

        return $this->send($reservation->userPhone, $message, [
            'reservationId' => $reservation->id,
            'messageType' => $messageType,
            'immediate' => true,
        ]);
    }

    public function sendConfirmation(ReservationInterface $reservation): bool
    {
        Craft::info("TwilioSmsService::sendConfirmation called for reservation #{$reservation->id}", __METHOD__);
        return $this->sendReservationSms($reservation, 'confirmation', 'confirmation');
    }

    public function sendReminder(ReservationInterface $reservation, string $type): bool
    {
        return $this->sendReservationSms($reservation, 'reminder', 'reminder_' . $type);
    }

    public function sendCancellation(ReservationInterface $reservation): bool
    {
        return $this->sendReservationSms($reservation, 'cancellation', 'cancellation');
    }

    public function renderMessage(string $template, array $variables): string
    {
        $message = str_replace(
            array_map(fn($k) => '{{' . $k . '}}', array_keys($variables)),
            array_map('strval', array_values($variables)),
            $template
        );
        return trim(preg_replace('/\s+/', ' ', preg_replace('/\{\{[^}]+\}\}/', '', $message)));
    }

    public function getReservationVariables(ReservationInterface $reservation): array
    {
        $service = $reservation->getService();
        $employee = $reservation->getEmployee();
        $location = $reservation->getLocation();
        $formatter = Craft::$app->getFormatter();

        return [
            'service' => $service?->title ?? '',
            'date' => $formatter->asDate($reservation->bookingDate, 'short'),
            'dateMedium' => $formatter->asDate($reservation->bookingDate, 'medium'),
            'dateLong' => $formatter->asDate($reservation->bookingDate, 'long'),
            'dateFull' => $formatter->asDate($reservation->bookingDate, 'full'),
            'time' => $reservation->startTime,
            'endTime' => $reservation->endTime,
            'employee' => $employee?->title ?? '',
            'location' => $location?->title ?? '',
            'customerName' => $reservation->userName,
            'status' => $reservation->status,
            'confirmationCode' => $reservation->confirmationCode ?? '',
        ];
    }

    public function testConnection(): array
    {
        $settings = Booked::getInstance()->getSettings();
        $details = [];

        foreach (['twilioAccountSid' => 'Account SID', 'twilioAuthToken' => 'Auth Token', 'twilioPhoneNumber' => 'Phone Number'] as $field => $label) {
            if (empty($settings->$field)) {
                return ['success' => false, 'message' => "Twilio {$label} is not configured.", 'details' => $details];
            }
        }

        $details['accountSid'] = substr($settings->twilioAccountSid, 0, 8) . '...' . substr($settings->twilioAccountSid, -4);
        $details['phoneNumber'] = $settings->twilioPhoneNumber;

        try {
            $client = new Client($settings->twilioAccountSid, $settings->twilioAuthToken);

            try {
                $account = $client->api->v2010->accounts($settings->twilioAccountSid)->fetch();
                $details['accountName'] = $account->friendlyName;
                $details['accountStatus'] = $account->status;
                $details['accountType'] = $account->type;

                if ($account->status !== 'active') {
                    return ['success' => false, 'message' => "Twilio account is not active (status: {$account->status}). Please check your Twilio console.", 'details' => $details];
                }

                try {
                    $incomingNumbers = $client->incomingPhoneNumbers->read(['phoneNumber' => $settings->twilioPhoneNumber], 1);

                    if (!empty($incomingNumbers)) {
                        $capabilities = $incomingNumbers[0]->capabilities;
                        $get = fn($key) => is_object($capabilities) ? ($capabilities->$key ?? false) : ($capabilities[$key] ?? false);
                        $hasSms = $get('sms');

                        $details['phoneNumberStatus'] = 'verified';
                        $details['phoneNumberCapabilities'] = [
                            'sms' => $hasSms,
                            'mms' => $get('mms'),
                            'voice' => $get('voice'),
                        ];

                        if (!$hasSms) {
                            return ['success' => false, 'message' => "The phone number {$settings->twilioPhoneNumber} does not have SMS capability enabled. Please enable SMS for this number in your Twilio console.", 'details' => $details];
                        }
                    } else {
                        $details['phoneNumberStatus'] = 'not_verified';
                        $details['phoneNumberNote'] = 'Could not verify phone number ownership, but credentials are valid.';
                    }
                } catch (TwilioException $e) {
                    $details['phoneNumberStatus'] = 'lookup_skipped';
                }

                if ($account->type === 'Trial') {
                    $details['warning'] = 'Trial accounts can only send SMS to verified phone numbers. Add recipient numbers in your Twilio console under Verified Caller IDs.';
                }

                return ['success' => true, 'message' => 'Twilio connection successful!', 'details' => $details];
            } catch (TwilioException $e) {
                if ($e->getCode() === 20008) {
                    $details['credentialType'] = 'test';
                    return ['success' => false, 'message' => "You are using Twilio TEST CREDENTIALS. Test credentials cannot send real SMS messages.\n\nTo send actual SMS notifications, you need to use your LIVE credentials:\n\n1. Go to console.twilio.com\n2. Make sure you're NOT in 'Test Mode' (check top of page)\n3. Copy your LIVE Account SID and Auth Token\n4. Your Live Account SID starts with 'AC' and is on the main dashboard\n\nTest credentials are only for development and cannot access the full API.", 'details' => $details];
                }
                throw $e;
            }
        } catch (TwilioException $e) {
            $details['errorCode'] = $e->getCode();
            $troubleshooting = match (true) {
                $e->getCode() === 20003 => 'Authentication failed. Please check that your Account SID and Auth Token are correct.',
                $e->getCode() === 20008 => "You are using Twilio TEST CREDENTIALS which cannot send real SMS. Please use your LIVE credentials from console.twilio.com (make sure you're not in Test Mode).",
                $e->getCode() === 20404 => 'Account not found. The Account SID may be incorrect.',
                str_contains($e->getMessage(), 'authenticate') => 'Authentication failed. Double-check your Account SID and Auth Token in the Twilio console.',
                default => '',
            };
            return ['success' => false, 'message' => $troubleshooting ?: "Twilio error ({$e->getCode()}): {$e->getMessage()}", 'details' => $details];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Connection error: ' . $e->getMessage(), 'details' => $details];
        }
    }

    public function normalizePhoneNumber(string $phone, string $defaultCountryCode = 'US'): ?string
    {
        $cleaned = preg_replace('/[^\d+]/', '', $phone);

        // Already valid E.164 format: + followed by 1-15 digits, starting with non-zero
        if (preg_match('/^\+[1-9]\d{6,14}$/', $cleaned)) {
            return $cleaned;
        }

        // Heuristic fallback for non-E.164 numbers
        Craft::warning('Phone number is not in E.164 format, applying heuristic normalization: ' . PiiRedactor::redactPhone($phone), __METHOD__);

        $cleaned = ltrim($cleaned, '+');

        $countryCodes = [
            'US' => '1', 'CA' => '1', 'GB' => '44', 'DE' => '49', 'FR' => '33', 'CH' => '41',
            'AT' => '43', 'IT' => '39', 'ES' => '34', 'NL' => '31', 'BE' => '32', 'AU' => '61', 'NZ' => '64',
        ];

        // Countries where the leading zero is part of the number (not a trunk prefix)
        $keepLeadingZero = ['IT'];

        if (str_starts_with($cleaned, '00')) {
            $cleaned = substr($cleaned, 2);
        }
        if (str_starts_with($cleaned, '0') && !in_array($defaultCountryCode, $keepLeadingZero, true)) {
            $cleaned = substr($cleaned, 1);
        }
        if (strlen($cleaned) <= 10) {
            $cleaned = ($countryCodes[$defaultCountryCode] ?? '1') . $cleaned;
        }

        $result = (strlen($cleaned) >= 7 && strlen($cleaned) <= 15) ? '+' . $cleaned : null;

        // Validate the final result is valid E.164
        if ($result !== null && !preg_match('/^\+[1-9]\d{6,14}$/', $result)) {
            Craft::warning('Heuristic normalization produced invalid E.164 number: ' . PiiRedactor::redactPhone($result), __METHOD__);
            return null;
        }

        return $result;
    }

    protected function getClient(): ?Client
    {
        if ($this->_client !== null) {
            return $this->_client;
        }

        $settings = Booked::getInstance()->getSettings();
        if (!$settings->isSmsConfigured()) {
            return null;
        }

        try {
            return $this->_client = new Client($settings->twilioAccountSid, $settings->twilioAuthToken);
        } catch (\Exception $e) {
            Craft::error("Failed to create Twilio client: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    protected function handleTwilioError(TwilioException $e, string $to, array $options): void
    {
        $context = json_encode([
            'to' => PiiRedactor::redactPhone($to),
            'reservationId' => $options['reservationId'] ?? null,
            'messageType' => $options['messageType'] ?? null,
            'errorCode' => $e->getCode(),
        ]);
        Craft::error("Twilio Error ({$e->getCode()}): {$e->getMessage()} - Context: {$context}", __METHOD__);

        match ($e->getCode()) {
            21211 => Craft::warning('Invalid phone number: ' . PiiRedactor::redactPhone($to), __METHOD__),
            21608 => Craft::warning('Unverified destination (trial account): ' . PiiRedactor::redactPhone($to), __METHOD__),
            21610 => Craft::info('Message blocked - recipient unsubscribed: ' . PiiRedactor::redactPhone($to), __METHOD__),
            30003, 30004, 30005 => Craft::warning('Message delivery issue for: ' . PiiRedactor::redactPhone($to), __METHOD__),
            default => null,
        };
    }

    public function getTemplate(string $type): string
    {
        $settings = Booked::getInstance()->getSettings();
        $settingKey = 'sms' . ucfirst($type) . 'Template';
        return $settings->$settingKey ?? Craft::t('booked', "sms.{$type}");
    }

    /**
     * Render the SMS body for a reservation and message type.
     *
     * Used by SendSmsJob for deferred rendering when body was not pre-rendered
     * at queue time (e.g., from BookingNotificationService::pushSmsJob).
     */
    public function renderSmsBody(ReservationInterface $reservation, string $type): string
    {
        $language = $this->getReservationLanguage($reservation);

        return $this->renderWithLanguage(
            fn() => $this->renderMessage(
                $this->getTemplate($type),
                $this->getReservationVariables($reservation)
            ),
            $language
        );
    }

    public function sendTestSms(string $phone): array
    {
        $settings = Booked::getInstance()->getSettings();

        if (!$settings->isSmsConfigured()) {
            return ['success' => false, 'message' => Craft::t('booked', 'sms.notConfigured')];
        }

        $normalizedPhone = $this->normalizePhoneNumber($phone, $settings->defaultCountryCode ?? 'US');
        if (!$normalizedPhone) {
            return ['success' => false, 'message' => Craft::t('booked', 'sms.invalidPhoneNumber')];
        }

        try {
            $success = $this->sendImmediate($normalizedPhone, Craft::t('booked', 'sms.testMessage'), [
                'messageType' => 'test',
            ]);
        } catch (\Throwable $e) {
            Craft::error("Test SMS failed: " . $e->getMessage(), __METHOD__);
            return ['success' => false, 'message' => Craft::t('booked', 'sms.testFailed') . ': ' . $e->getMessage()];
        }

        return [
            'success' => $success,
            'message' => Craft::t('booked', $success ? 'sms.testSuccess' : 'sms.testFailed', $success ? ['phone' => $normalizedPhone] : []),
        ];
    }
}
