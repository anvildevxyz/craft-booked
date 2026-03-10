<?php

namespace anvildev\booked\records;

use Craft;
use craft\db\ActiveRecord;

/**
 * Global plugin configuration. Settings are validated in the Settings model.
 *
 * @property int $id
 * @property string|null $defaultCurrency
 * @property int $softLockDurationMinutes
 * @property int $minimumAdvanceBookingHours
 * @property int $maximumAdvanceBookingDays
 * @property int $cancellationPolicyHours
 * @property bool $enableRateLimiting
 * @property bool $enableVirtualMeetings
 * @property int $rateLimitPerEmail
 * @property int $rateLimitPerIp
 * @property bool $googleCalendarEnabled
 * @property string|null $googleCalendarClientId
 * @property string|null $googleCalendarClientSecret
 * @property string|null $googleCalendarWebhookUrl
 * @property bool $outlookCalendarEnabled
 * @property string|null $outlookCalendarClientId
 * @property string|null $outlookCalendarClientSecret
 * @property string|null $outlookCalendarWebhookUrl
 * @property bool $zoomEnabled
 * @property string|null $zoomAccountId
 * @property string|null $zoomClientId
 * @property string|null $zoomClientSecret
 * @property bool $teamsEnabled
 * @property string|null $teamsTenantId
 * @property string|null $teamsClientId
 * @property string|null $teamsClientSecret
 * @property bool $googleMeetEnabled
 * @property bool $ownerNotificationEnabled
 * @property string|null $ownerNotificationSubject
 * @property string|null $ownerNotificationLanguage
 * @property string|null $ownerEmail
 * @property string|null $ownerName
 * @property string|null $bookingConfirmationSubject
 * @property string|null $reminderEmailSubject
 * @property string|null $cancellationEmailSubject
 * @property string|null $bookingPageUrl
 * @property bool $emailRemindersEnabled
 * @property int $emailReminderHoursBefore
 * @property bool $sendCancellationEmail
 * @property bool $smsEnabled
 * @property string|null $smsProvider
 * @property string|null $twilioAccountSid
 * @property string|null $twilioAuthToken
 * @property string|null $twilioPhoneNumber
 * @property bool $smsRemindersEnabled
 * @property int $smsReminderHoursBefore
 * @property bool $commerceEnabled
 * @property int|null $commerceTaxCategoryId
 * @property int $pendingCartExpirationHours
 * @property string $commerceCartUrl
 * @property string $commerceCheckoutUrl
 * @property bool $enableAutoRefund
 * @property string|null $defaultRefundTiers
 * @property int|null $defaultTimeSlotLength
 * @property bool $enableCaptcha
 * @property string|null $captchaProvider
 * @property string|null $recaptchaSiteKey
 * @property string|null $recaptchaSecretKey
 * @property string|null $hcaptchaSiteKey
 * @property string|null $hcaptchaSecretKey
 * @property string|null $turnstileSiteKey
 * @property string|null $turnstileSecretKey
 * @property bool $enableHoneypot
 * @property string $honeypotFieldName
 * @property bool $enableIpBlocking
 * @property string|null $blockedIps
 * @property bool $enableTimeBasedLimits
 * @property int $minimumSubmissionTime
 * @property bool $enableAuditLog
 * @property bool $enableWaitlist
 * @property int $waitlistExpirationDays
 * @property int $waitlistNotificationLimit
 * @property int $waitlistConversionMinutes
 * @property string $mutexDriver
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class SettingsRecord extends ActiveRecord
{
    private bool $_encrypted = false;

    /**
     * Fields that contain API secrets and must be encrypted at rest.
     */
    public const ENCRYPTED_FIELDS = [
        'googleCalendarClientSecret',
        'outlookCalendarClientSecret',
        'zoomClientSecret',
        'teamsClientSecret',
        'twilioAuthToken',
        'recaptchaSecretKey',
        'hcaptchaSecretKey',
        'turnstileSecretKey',
    ];

    public static function tableName(): string
    {
        return '{{%booked_settings}}';
    }

    public function rules(): array
    {
        return [
            [['id'], 'integer'],
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!$this->_encrypted) {
            foreach (self::ENCRYPTED_FIELDS as $field) {
                if ($this->$field) {
                    $this->$field = base64_encode(Craft::$app->getSecurity()->encryptByKey($this->$field));
                }
            }
            $this->_encrypted = true;
        }

        return parent::beforeSave($insert);
    }

    public function afterSave($insert, $changedAttributes): void
    {
        parent::afterSave($insert, $changedAttributes);
        $this->_encrypted = false;
    }

    public function afterFind(): void
    {
        parent::afterFind();
        $this->_encrypted = false;

        foreach (self::ENCRYPTED_FIELDS as $field) {
            if ($this->$field) {
                try {
                    $decoded = base64_decode($this->$field, true);
                    $this->$field = $decoded !== false
                        ? Craft::$app->getSecurity()->decryptByKey($decoded)
                        : Craft::$app->getSecurity()->decryptByKey($this->$field);
                } catch (\Throwable $e) {
                    Craft::warning("Failed to decrypt settings field '{$field}': {$e->getMessage()}. Value cleared to prevent double-encryption. Re-enter the value in settings.", __METHOD__);
                    $this->$field = null;
                }
            }
        }
    }
}
