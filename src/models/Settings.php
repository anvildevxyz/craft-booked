<?php

namespace anvildev\booked\models;

use anvildev\booked\records\SettingsRecord;
use Craft;
use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    private static ?self $cached = null;

    private static ?float $cachedAt = null;

    private const CACHE_TTL = 60;

    public ?int $id = null;

    // General
    public ?string $defaultCurrency = null;
    public int $softLockDurationMinutes = 5;
    public int $minimumAdvanceBookingHours = 0;
    public int $maximumAdvanceBookingDays = 90;
    public int $cancellationPolicyHours = 24;
    public bool $enableRateLimiting = true;
    public int $rateLimitPerEmail = 5;
    public int $rateLimitPerIp = 10;
    public bool $enableVirtualMeetings = false;
    public bool $enableAutoRefund = false;
    public array|string|null $defaultRefundTiers = null;

    // Security
    public bool $enableCaptcha = false;
    public ?string $captchaProvider = null;
    public ?string $recaptchaSiteKey = null;
    public ?string $recaptchaSecretKey = null;
    public ?string $hcaptchaSiteKey = null;
    public ?string $hcaptchaSecretKey = null;
    public ?string $turnstileSiteKey = null;
    public ?string $turnstileSecretKey = null;
    public float $recaptchaScoreThreshold = 0.5;
    public string $recaptchaAction = 'booking';
    public bool $enableHoneypot = true;
    public string $honeypotFieldName = 'website';
    public bool $enableIpBlocking = false;
    public ?string $blockedIps = null;
    public bool $enableTimeBasedLimits = true;
    public int $minimumSubmissionTime = 3;
    public bool $enableAuditLog = false;

    // Calendar
    public bool $googleCalendarEnabled = false;
    public ?string $googleCalendarClientId = null;
    public ?string $googleCalendarClientSecret = null;
    public ?string $googleCalendarWebhookUrl = null;
    public bool $outlookCalendarEnabled = false;
    public ?string $outlookCalendarClientId = null;
    public ?string $outlookCalendarClientSecret = null;
    public ?string $outlookCalendarWebhookUrl = null;

    // Virtual meetings
    public bool $zoomEnabled = false;
    public ?string $zoomAccountId = null;
    public ?string $zoomClientId = null;
    public ?string $zoomClientSecret = null;
    public bool $googleMeetEnabled = false;
    public bool $teamsEnabled = false;
    public ?string $teamsTenantId = null;
    public ?string $teamsClientId = null;
    public ?string $teamsClientSecret = null;

    // Notifications
    public bool $ownerNotificationEnabled = true;
    public ?string $ownerNotificationSubject = null;
    public ?string $ownerNotificationLanguage = null;
    public ?string $ownerEmail = null;
    public ?string $ownerName = null;
    public ?string $bookingConfirmationSubject = null;
    public ?string $reminderEmailSubject = null;
    public ?string $cancellationEmailSubject = null;
    public bool $emailRemindersEnabled = true;
    public int $emailReminderHoursBefore = 24;
    public bool $sendCancellationEmail = true;
    public bool $smsEnabled = false;
    public ?string $smsProvider = null;
    public ?string $twilioAccountSid = null;
    public ?string $twilioAuthToken = null;
    public ?string $twilioPhoneNumber = null;
    public bool $smsRemindersEnabled = false;
    public int $smsReminderHoursBefore = 24;
    public bool $smsConfirmationEnabled = false;
    public bool $smsCancellationEnabled = false;
    public ?string $smsConfirmationTemplate = null;
    public ?string $smsReminderTemplate = null;
    public ?string $smsCancellationTemplate = null;
    public int $smsMaxRetries = 3;
    public ?string $defaultCountryCode = 'US';

    // Commerce
    public bool $commerceEnabled = false;
    public ?int $commerceTaxCategoryId = null;
    public int $pendingCartExpirationHours = 48;
    public string $commerceCartUrl = 'shop/cart';
    public string $commerceCheckoutUrl = 'shop/checkout';

    // Webhooks
    public bool $webhooksEnabled = false;
    public int $webhookTimeout = 30;
    public bool $webhookLogEnabled = true;
    public int $webhookLogRetentionDays = 30;

    // Waitlist
    public bool $enableWaitlist = true;
    public int $waitlistExpirationDays = 30;
    public int $waitlistNotificationLimit = 10;
    public int $waitlistConversionMinutes = 30;
    public string $mutexDriver = 'auto';
    // Frontend
    public ?int $defaultTimeSlotLength = null;
    public ?string $bookingPageUrl = null;

    public function getEffectiveEmail(): ?string
    {
        if ($this->ownerEmail) {
            return $this->ownerEmail;
        }
        $email = Craft::$app->getProjectConfig()->get('email.fromEmail');
        return $email ? App::parseEnv($email) : (Craft::$app->getMailer()->fromEmail ?? null);
    }

    public function getEffectiveName(): ?string
    {
        if ($this->ownerName) {
            return $this->ownerName;
        }
        $name = Craft::$app->getProjectConfig()->get('email.fromName');
        return $name ? App::parseEnv($name) : (Craft::$app->getMailer()->fromName ?? null);
    }

    public function getEffectiveOwnerNotificationSubject(): string
    {
        return $this->ownerNotificationSubject ?: Craft::t('booked', 'emails.subject.ownerNotification');
    }

    public function getOwnerNotificationLanguageCode(): string
    {
        return $this->ownerNotificationLanguage ?: Craft::$app->getSites()->getPrimarySite()->language;
    }

    public function getEffectiveBookingConfirmationSubject(): string
    {
        return $this->bookingConfirmationSubject ?: Craft::t('booked', 'settings.attributeLabels.bookingConfirmation');
    }

    public function getEffectiveReminderEmailSubject(): string
    {
        return $this->reminderEmailSubject ?: Craft::t('booked', 'settings.attributeLabels.appointmentReminder');
    }

    public function getEffectiveCancellationEmailSubject(): string
    {
        return $this->cancellationEmailSubject ?: Craft::t('booked', 'settings.attributeLabels.bookingCancelled');
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        if (isset($values['defaultRefundTiers'])) {
            $values['defaultRefundTiers'] = $this->normalizeRefundTiers($values['defaultRefundTiers']);
        }

        parent::setAttributes($values, $safeOnly);
    }

    private function normalizeRefundTiers(mixed $param): ?array
    {
        if (empty($param)) {
            return null;
        }

        if (is_string($param)) {
            $param = json_decode($param, true);
        }

        if (!is_array($param)) {
            return null;
        }

        $tiers = [];
        foreach (array_values($param) as $row) {
            if (!is_array($row) || !isset($row['hoursBeforeStart'], $row['refundPercentage'])) {
                continue;
            }

            $tiers[] = [
                'hoursBeforeStart' => (int) $row['hoursBeforeStart'],
                'refundPercentage' => (int) $row['refundPercentage'],
            ];
        }

        return empty($tiers) ? null : $tiers;
    }

    public function rules(): array
    {
        return [
            [['defaultCurrency'], 'string', 'max' => 4],
            [['defaultCurrency'], 'match', 'pattern' => '/^(auto|[A-Z]{3})$/', 'message' => Craft::t('booked', 'settings.attributeLabels.currencyValidation'), 'skipOnEmpty' => true],
            [['softLockDurationMinutes', 'rateLimitPerEmail', 'rateLimitPerIp'], 'integer', 'min' => 1],
            [['minimumAdvanceBookingHours', 'maximumAdvanceBookingDays', 'cancellationPolicyHours'], 'integer', 'min' => 0],
            [['softLockDurationMinutes'], 'default', 'value' => 5],
            [['minimumAdvanceBookingHours'], 'default', 'value' => 0],
            [['maximumAdvanceBookingDays'], 'default', 'value' => 90],
            [['rateLimitPerEmail'], 'default', 'value' => 5],
            [['rateLimitPerIp'], 'default', 'value' => 10],
            [['enableRateLimiting', 'enableVirtualMeetings'], 'boolean'],
            [['enableCaptcha', 'enableHoneypot', 'enableIpBlocking', 'enableTimeBasedLimits', 'enableAuditLog'], 'boolean'],
            [['captchaProvider'], 'string'],
            [['captchaProvider'], 'required', 'when' => fn(self $model) => $model->enableCaptcha, 'message' => Craft::t('booked', 'A CAPTCHA provider is required when CAPTCHA is enabled.')],
            [['captchaProvider'], 'validateCaptchaKeys'],
            [['recaptchaSiteKey', 'recaptchaSecretKey', 'hcaptchaSiteKey', 'hcaptchaSecretKey', 'turnstileSiteKey', 'turnstileSecretKey'], 'string'],
            [['recaptchaScoreThreshold'], 'number', 'min' => 0, 'max' => 1],
            [['recaptchaAction'], 'string', 'max' => 100],
            [['honeypotFieldName'], 'string'],
            [['blockedIps'], 'string'],
            [['minimumSubmissionTime'], 'integer', 'min' => 0],
            [['googleCalendarEnabled', 'outlookCalendarEnabled'], 'boolean'],
            [['googleCalendarClientId', 'googleCalendarClientSecret', 'outlookCalendarClientId', 'outlookCalendarClientSecret'], 'string'],
            [['zoomEnabled', 'googleMeetEnabled', 'teamsEnabled'], 'boolean'],
            [['zoomAccountId', 'zoomClientId', 'zoomClientSecret'], 'string'],
            [['teamsTenantId', 'teamsClientId', 'teamsClientSecret'], 'string'],
            [['ownerNotificationEnabled', 'emailRemindersEnabled', 'smsEnabled', 'smsRemindersEnabled', 'sendCancellationEmail', 'smsConfirmationEnabled', 'smsCancellationEnabled'], 'boolean'],
            [['emailReminderHoursBefore', 'smsReminderHoursBefore', 'smsMaxRetries'], 'integer', 'min' => 0],
            [['smsConfirmationTemplate', 'smsReminderTemplate', 'smsCancellationTemplate', 'defaultCountryCode'], 'string'],
            [['ownerEmail'], 'email', 'skipOnEmpty' => true],
            [['ownerName', 'ownerNotificationSubject', 'ownerNotificationLanguage', 'bookingConfirmationSubject', 'reminderEmailSubject', 'cancellationEmailSubject'], 'string', 'skipOnEmpty' => true],
            [['smsProvider', 'twilioAccountSid', 'twilioAuthToken', 'twilioPhoneNumber'], 'string'],
            [['commerceEnabled'], 'boolean'],
            [['commerceTaxCategoryId'], 'integer', 'skipOnEmpty' => true],
            [['pendingCartExpirationHours'], 'integer', 'min' => 1, 'max' => 168],
            [['commerceCartUrl', 'commerceCheckoutUrl'], 'string', 'max' => 255],
            [['enableAutoRefund'], 'boolean'],
            [['defaultRefundTiers'], 'safe'],
            [['webhooksEnabled', 'webhookLogEnabled'], 'boolean'],
            [['webhookTimeout', 'webhookLogRetentionDays'], 'integer', 'min' => 1],
            [['webhookTimeout'], 'default', 'value' => 30],
            [['webhookLogRetentionDays'], 'default', 'value' => 30],
            [['enableWaitlist'], 'boolean'],
            [['waitlistExpirationDays', 'waitlistNotificationLimit', 'waitlistConversionMinutes'], 'integer', 'min' => 0],
            [['waitlistExpirationDays'], 'default', 'value' => 30],
            [['waitlistNotificationLimit'], 'default', 'value' => 10],
            [['defaultTimeSlotLength'], 'integer', 'min' => 5],
            [['bookingPageUrl'], 'url', 'skipOnEmpty' => true],
            [['mutexDriver'], 'in', 'range' => ['auto', 'file', 'db', 'redis']],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'defaultCurrency' => Craft::t('booked', 'settings.attributeLabels.defaultCurrency'),
            'softLockDurationMinutes' => Craft::t('booked', 'settings.attributeLabels.softLockDuration'),
            'enableRateLimiting' => Craft::t('booked', 'settings.attributeLabels.enableRateLimiting'),
            'rateLimitPerEmail' => Craft::t('booked', 'settings.attributeLabels.rateLimitEmail'),
            'rateLimitPerIp' => Craft::t('booked', 'settings.attributeLabels.rateLimitIp'),
            'enableVirtualMeetings' => Craft::t('booked', 'settings.attributeLabels.enableVirtualMeetings'),
            'googleCalendarEnabled' => Craft::t('booked', 'settings.attributeLabels.enableGoogleCalendar'),
            'googleCalendarClientId' => Craft::t('booked', 'settings.attributeLabels.googleCalendarClientId'),
            'googleCalendarClientSecret' => Craft::t('booked', 'settings.attributeLabels.googleCalendarClientSecret'),
            'outlookCalendarEnabled' => Craft::t('booked', 'settings.attributeLabels.enableOutlookCalendar'),
            'outlookCalendarClientId' => Craft::t('booked', 'settings.attributeLabels.outlookClientId'),
            'outlookCalendarClientSecret' => Craft::t('booked', 'settings.attributeLabels.outlookClientSecret'),
            'zoomEnabled' => Craft::t('booked', 'settings.attributeLabels.enableZoom'),
            'zoomAccountId' => Craft::t('booked', 'settings.attributeLabels.zoomAccountId'),
            'zoomClientId' => Craft::t('booked', 'settings.attributeLabels.zoomClientId'),
            'zoomClientSecret' => Craft::t('booked', 'settings.attributeLabels.zoomClientSecret'),
            'googleMeetEnabled' => Craft::t('booked', 'settings.attributeLabels.enableGoogleMeet'),
            'teamsEnabled' => Craft::t('booked', 'settings.attributeLabels.enableTeams'),
            'teamsTenantId' => Craft::t('booked', 'settings.attributeLabels.teamsTenantId'),
            'teamsClientId' => Craft::t('booked', 'settings.attributeLabels.teamsClientId'),
            'teamsClientSecret' => Craft::t('booked', 'settings.attributeLabels.teamsClientSecret'),
            'ownerNotificationEnabled' => Craft::t('booked', 'settings.attributeLabels.enableOwnerNotifications'),
            'ownerNotificationSubject' => Craft::t('booked', 'settings.attributeLabels.ownerNotificationSubject'),
            'ownerNotificationLanguage' => Craft::t('booked', 'settings.attributeLabels.ownerNotificationLanguage'),
            'ownerEmail' => Craft::t('booked', 'settings.attributeLabels.ownerEmail'),
            'ownerName' => Craft::t('booked', 'settings.attributeLabels.ownerName'),
            'bookingConfirmationSubject' => Craft::t('booked', 'settings.attributeLabels.confirmationSubject'),
            'emailRemindersEnabled' => Craft::t('booked', 'settings.attributeLabels.enableReminders'),
            'emailReminderHoursBefore' => Craft::t('booked', 'settings.attributeLabels.reminderHoursBefore'),
            'smsEnabled' => Craft::t('booked', 'settings.attributeLabels.enableSms'),
            'smsProvider' => Craft::t('booked', 'settings.attributeLabels.smsProvider'),
            'twilioAccountSid' => Craft::t('booked', 'settings.attributeLabels.twilioAccountSid'),
            'twilioAuthToken' => Craft::t('booked', 'settings.attributeLabels.twilioAuthToken'),
            'twilioPhoneNumber' => Craft::t('booked', 'settings.attributeLabels.twilioPhoneNumber'),
            'smsRemindersEnabled' => Craft::t('booked', 'settings.attributeLabels.enableSmsReminders'),
            'smsReminderHoursBefore' => Craft::t('booked', 'settings.attributeLabels.smsReminderHours'),
            'smsConfirmationEnabled' => Craft::t('booked', 'settings.attributeLabels.enableSmsConfirmations'),
            'smsCancellationEnabled' => Craft::t('booked', 'settings.attributeLabels.enableSmsCancellations'),
            'smsConfirmationTemplate' => Craft::t('booked', 'settings.attributeLabels.smsConfirmationTemplate'),
            'smsReminderTemplate' => Craft::t('booked', 'settings.attributeLabels.smsReminderTemplate'),
            'smsCancellationTemplate' => Craft::t('booked', 'settings.attributeLabels.smsCancellationTemplate'),
            'smsMaxRetries' => Craft::t('booked', 'settings.attributeLabels.smsMaxRetries'),
            'defaultCountryCode' => Craft::t('booked', 'settings.attributeLabels.defaultCountryCode'),
            'commerceEnabled' => Craft::t('booked', 'settings.attributeLabels.enableCommerce'),
            'commerceTaxCategoryId' => Craft::t('booked', 'settings.attributeLabels.commerceTaxCategory'),
            'commerceCartUrl' => Craft::t('booked', 'settings.attributeLabels.commerceCartUrl'),
            'commerceCheckoutUrl' => Craft::t('booked', 'settings.attributeLabels.commerceCheckoutUrl'),
            'defaultTimeSlotLength' => Craft::t('booked', 'settings.attributeLabels.defaultTimeSlotLength'),
            'enableCaptcha' => Craft::t('booked', 'settings.attributeLabels.enableCaptcha'),
            'captchaProvider' => Craft::t('booked', 'settings.attributeLabels.captchaProvider'),
            'recaptchaSiteKey' => Craft::t('booked', 'settings.attributeLabels.recaptchaSiteKey'),
            'recaptchaSecretKey' => Craft::t('booked', 'settings.attributeLabels.recaptchaSecretKey'),
            'hcaptchaSiteKey' => Craft::t('booked', 'settings.attributeLabels.hcaptchaSiteKey'),
            'hcaptchaSecretKey' => Craft::t('booked', 'settings.attributeLabels.hcaptchaSecretKey'),
            'turnstileSiteKey' => Craft::t('booked', 'settings.attributeLabels.turnstileSiteKey'),
            'turnstileSecretKey' => Craft::t('booked', 'settings.attributeLabels.turnstileSecretKey'),
            'enableHoneypot' => Craft::t('booked', 'settings.attributeLabels.enableHoneypot'),
            'honeypotFieldName' => Craft::t('booked', 'settings.attributeLabels.honeypotFieldName'),
            'enableIpBlocking' => Craft::t('booked', 'settings.attributeLabels.enableIpBlocking'),
            'blockedIps' => Craft::t('booked', 'settings.attributeLabels.blockedIps'),
            'enableTimeBasedLimits' => Craft::t('booked', 'settings.attributeLabels.enableTimeLimits'),
            'minimumSubmissionTime' => Craft::t('booked', 'settings.attributeLabels.minSubmissionTime'),
            'enableAuditLog' => Craft::t('booked', 'settings.attributeLabels.enableAuditLog'),
            'webhooksEnabled' => Craft::t('booked', 'settings.attributeLabels.enableWebhooks'),
            'webhookTimeout' => Craft::t('booked', 'settings.attributeLabels.webhookTimeout'),
            'webhookLogEnabled' => Craft::t('booked', 'settings.attributeLabels.enableWebhookLogging'),
            'webhookLogRetentionDays' => Craft::t('booked', 'settings.attributeLabels.webhookLogRetention'),
            'enableWaitlist' => Craft::t('booked', 'settings.attributeLabels.enableWaitlist'),
            'waitlistExpirationDays' => Craft::t('booked', 'settings.attributeLabels.waitlistExpiration'),
            'waitlistNotificationLimit' => Craft::t('booked', 'settings.attributeLabels.notificationLimit'),
            'waitlistConversionMinutes' => Craft::t('booked', 'settings.attributeLabels.waitlistConversionMinutes'),
            'mutexDriver' => Craft::t('booked', 'settings.attributeLabels.mutexDriver'),
        ];
    }

    public function safeAttributesForTab(string $tab): array
    {
        $map = [
            'booking' => [
                'softLockDurationMinutes', 'defaultTimeSlotLength',
                'minimumAdvanceBookingHours', 'maximumAdvanceBookingDays', 'cancellationPolicyHours',
                'bookingPageUrl', 'mutexDriver',
            ],
            'waitlist' => [
                'enableWaitlist', 'waitlistExpirationDays', 'waitlistNotificationLimit', 'waitlistConversionMinutes',
            ],
            'security' => [
                'enableCaptcha', 'captchaProvider', 'recaptchaSiteKey', 'recaptchaSecretKey',
                'recaptchaScoreThreshold', 'recaptchaAction',
                'hcaptchaSiteKey', 'hcaptchaSecretKey', 'turnstileSiteKey', 'turnstileSecretKey',
                'enableRateLimiting', 'rateLimitPerEmail', 'rateLimitPerIp',
                'enableHoneypot', 'honeypotFieldName',
                'enableIpBlocking', 'blockedIps', 'enableTimeBasedLimits', 'minimumSubmissionTime',
                'enableAuditLog',
            ],
            'calendar' => [
                'googleCalendarEnabled', 'googleCalendarClientId', 'googleCalendarClientSecret',
                'googleCalendarWebhookUrl', 'outlookCalendarEnabled', 'outlookCalendarClientId',
                'outlookCalendarClientSecret', 'outlookCalendarWebhookUrl',
            ],
            'meetings' => [
                'enableVirtualMeetings', 'zoomEnabled', 'zoomAccountId', 'zoomClientId', 'zoomClientSecret',
                'googleMeetEnabled', 'teamsEnabled', 'teamsTenantId', 'teamsClientId', 'teamsClientSecret',
            ],
            'notifications' => [
                'ownerNotificationEnabled', 'ownerEmail', 'ownerName', 'ownerNotificationSubject',
                'ownerNotificationLanguage', 'bookingConfirmationSubject', 'emailRemindersEnabled',
                'reminderEmailSubject', 'emailReminderHoursBefore', 'sendCancellationEmail',
                'cancellationEmailSubject',
            ],
            'commerce' => [
                'defaultCurrency', 'commerceEnabled', 'commerceTaxCategoryId', 'pendingCartExpirationHours',
                'commerceCartUrl', 'commerceCheckoutUrl', 'enableAutoRefund', 'defaultRefundTiers',
            ],
            'sms' => [
                'smsEnabled', 'smsProvider', 'twilioAccountSid', 'twilioAuthToken', 'twilioPhoneNumber',
                'defaultCountryCode', 'smsConfirmationEnabled', 'smsRemindersEnabled',
                'smsReminderHoursBefore', 'smsCancellationEnabled', 'smsConfirmationTemplate',
                'smsReminderTemplate', 'smsCancellationTemplate', 'smsMaxRetries',
            ],
            'webhooks' => [
                'webhooksEnabled', 'webhookTimeout', 'webhookLogEnabled', 'webhookLogRetentionDays',
            ],
        ];

        if (!isset($map[$tab])) {
            \Craft::warning("Unknown settings tab '{$tab}', rejecting.", __METHOD__);
            return [];
        }

        return $map[$tab];
    }

    public function validateCaptchaKeys(string $attribute): void
    {
        if (!$this->enableCaptcha || empty($this->captchaProvider)) {
            return;
        }

        $keyMap = [
            'recaptcha' => ['recaptchaSiteKey', 'recaptchaSecretKey'],
            'hcaptcha' => ['hcaptchaSiteKey', 'hcaptchaSecretKey'],
            'turnstile' => ['turnstileSiteKey', 'turnstileSecretKey'],
        ];

        $keys = $keyMap[$this->captchaProvider] ?? null;
        if (!$keys) {
            return;
        }

        [$siteKey, $secretKey] = $keys;
        if (empty($this->$siteKey) || empty($this->$secretKey)) {
            $this->addError($attribute, Craft::t('booked', 'Both site key and secret key are required for {provider}.', [
                'provider' => $this->captchaProvider,
            ]));
        }
    }

    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }

        $record = SettingsRecord::find()->one() ?? new SettingsRecord();

        foreach ($this->getAttributes() as $attribute => $value) {
            if (property_exists($record, $attribute) || $record->hasAttribute($attribute)) {
                if ($attribute === 'defaultRefundTiers' && is_array($value)) {
                    $record->$attribute = json_encode($value);
                } elseif (in_array($attribute, ['ownerEmail', 'ownerName']) && $value === '') {
                    $record->$attribute = null;
                } else {
                    $record->$attribute = $value;
                }
            }
        }

        if ($record->save()) {
            $this->id = $record->id;
            self::clearCache();
            return true;
        }

        $this->addErrors($record->getErrors());
        return false;
    }

    public static function loadSettings(): self
    {
        if (self::$cached !== null && self::$cachedAt !== null
            && (microtime(true) - self::$cachedAt) < self::CACHE_TTL) {
            return self::$cached;
        }

        $model = new self();

        try {
            if (!Craft::$app->getDb()->tableExists(SettingsRecord::tableName())) {
                return $model;
            }
        } catch (\Throwable) {
            return $model;
        }

        $record = SettingsRecord::find()->one();
        if ($record) {
            foreach ($model->getAttributes() as $attribute => $value) {
                if ($record->hasAttribute($attribute) || property_exists($record, $attribute)) {
                    $model->$attribute = $record->$attribute;
                }
            }
            $model->id = $record->id;

            $rawTiers = $record->defaultRefundTiers ?? null;
            if (is_string($rawTiers)) {
                $model->defaultRefundTiers = json_decode($rawTiers, true);
            }
        }

        self::$cached = $model;
        self::$cachedAt = microtime(true);

        return $model;
    }

    public static function clearCache(): void
    {
        self::$cached = null;
        self::$cachedAt = null;
    }

    public function isCommerceEnabled(): bool
    {
        return $this->commerceEnabled && Craft::$app->plugins->isPluginEnabled('commerce');
    }

    public function isGoogleCalendarConfigured(): bool
    {
        return $this->googleCalendarEnabled && !empty($this->googleCalendarClientId) && !empty($this->googleCalendarClientSecret);
    }

    public function isOutlookCalendarConfigured(): bool
    {
        return $this->outlookCalendarEnabled && !empty($this->outlookCalendarClientId) && !empty($this->outlookCalendarClientSecret);
    }

    public function isZoomConfigured(): bool
    {
        return $this->zoomEnabled && !empty($this->zoomAccountId) && !empty($this->zoomClientId) && !empty($this->zoomClientSecret);
    }

    public function isTeamsConfigured(): bool
    {
        return $this->teamsEnabled && !empty($this->teamsTenantId) && !empty($this->teamsClientId) && !empty($this->teamsClientSecret);
    }

    public function isSmsConfigured(): bool
    {
        return $this->smsEnabled && !empty($this->twilioAccountSid) && !empty($this->twilioAuthToken) && !empty($this->twilioPhoneNumber);
    }

    public function canUseCommerce(): bool
    {
        return $this->isCommerceEnabled();
    }

    public function canUseCalendarSync(): bool
    {
        return $this->isGoogleCalendarConfigured() || $this->isOutlookCalendarConfigured();
    }

    public function canUseGoogleMeet(): bool
    {
        return $this->googleMeetEnabled;
    }

    public function canUseVirtualMeetings(): bool
    {
        return $this->enableVirtualMeetings && ($this->isZoomConfigured() || $this->canUseGoogleMeet() || $this->isTeamsConfigured());
    }

    public function canUseWebhooks(): bool
    {
        return $this->webhooksEnabled;
    }
}
