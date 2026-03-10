<?php

namespace anvildev\booked\records;

use Craft;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $userName
 * @property string $userEmail
 * @property string|null $userPhone
 * @property int|null $userId
 * @property string|null $userTimezone
 * @property string $bookingDate
 * @property string $startTime
 * @property string $endTime
 * @property string $status
 * @property string|null $notes
 * @property bool $notificationSent
 * @property string $confirmationToken
 * @property int|null $employeeId
 * @property int|null $locationId
 * @property int|null $serviceId
 * @property int|null $eventDateId
 * @property int|null $siteId
 * @property int $quantity
 * @property string|null $virtualMeetingUrl
 * @property string|null $virtualMeetingProvider
 * @property string|null $virtualMeetingId
 * @property string|null $googleEventId
 * @property string|null $outlookEventId
 * @property bool $emailReminder24hSent
 * @property bool $emailReminder1hSent
 * @property bool $smsReminder24hSent
 * @property bool $smsConfirmationSent
 * @property \DateTime|null $smsConfirmationSentAt
 * @property bool $smsCancellationSent
 * @property string|null $smsDeliveryStatus
 * @property string|null $activeSlotKey
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ReservationRecord extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function tableName(): string
    {
        return '{{%booked_reservations}}';
    }

    public function rules(): array
    {
        return [
            [['userName', 'userEmail', 'bookingDate', 'startTime', 'endTime', 'confirmationToken'], 'required'],
            [['userEmail'], 'email'],
            [['userName', 'userEmail', 'userPhone'], 'string', 'max' => 255],
            [['userTimezone'], 'string', 'max' => 50],
            [['bookingDate'], 'date', 'format' => 'php:Y-m-d'],
            [['startTime', 'endTime'], 'match', 'pattern' => '/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_CANCELLED]],
            [['notes', 'virtualMeetingUrl', 'virtualMeetingProvider', 'virtualMeetingId', 'googleEventId', 'outlookEventId'], 'string'],
            [['notificationSent', 'emailReminder24hSent', 'emailReminder1hSent', 'smsReminder24hSent', 'smsConfirmationSent', 'smsCancellationSent'], 'boolean'],
            [['smsDeliveryStatus'], 'string', 'max' => 20],
            [['confirmationToken'], 'string', 'max' => 64],
            [['confirmationToken'], 'unique'],
            [['status'], 'default', 'value' => self::STATUS_CONFIRMED],
            [['notificationSent'], 'default', 'value' => false],
            [['employeeId', 'locationId', 'serviceId', 'eventDateId', 'siteId', 'quantity', 'userId'], 'integer'],
            [['quantity'], 'default', 'value' => 1],
        ];
    }

    /**
     * Computes activeSlotKey for the unique double-booking constraint.
     * Active employee bookings get a non-NULL key; cancelled and employee-less bookings get NULL.
     */
    public function beforeSave($insert): bool
    {
        $this->activeSlotKey = ($this->status !== self::STATUS_CANCELLED && $this->employeeId !== null)
            ? $this->bookingDate . '|' . $this->startTime . '|' . $this->employeeId
            : null;

        return parent::beforeSave($insert);
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        if (class_exists(Craft::class)) {
            return [
                self::STATUS_PENDING => Craft::t('booked', 'status.pending'),
                self::STATUS_CONFIRMED => Craft::t('booked', 'status.confirmed'),
                self::STATUS_CANCELLED => Craft::t('booked', 'status.cancelled'),
            ];
        }

        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_CONFIRMED => 'Confirmed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public static function generateConfirmationToken(): string
    {
        $maxAttempts = 10;
        $attempt = 0;
        do {
            $token = bin2hex(random_bytes(32));
            $attempt++;
        } while (self::find()->where(['confirmationToken' => $token])->exists() && $attempt < $maxAttempts);

        if ($attempt >= $maxAttempts) {
            throw new \RuntimeException('Failed to generate unique confirmation token');
        }

        return $token;
    }
}
