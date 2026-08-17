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
 * @property string|null $endDate
 * @property string|null $startTime
 * @property string|null $endTime
 * @property string $status
 * @property string|null $notes
 * @property string|null $sessionNotes
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
    public const STATUS_NO_SHOW = 'no_show';

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
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW]],
            [['notes', 'sessionNotes', 'virtualMeetingUrl', 'virtualMeetingProvider', 'virtualMeetingId', 'googleEventId', 'outlookEventId'], 'string'],
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
     * Active employee bookings on a single-seat slot get a non-NULL key;
     * cancelled, employee-less and multi-seat bookings get NULL.
     *
     * The time is normalized to H:i first, and that is load-bearing. `startTime`
     * arrives as "14:00" on a booking built from a request and as "14:00:00" on
     * one read back from the TIME column, so interpolating it raw produced two
     * different keys for the same slot — and the unique index cannot see a
     * collision between strings that differ. Two confirmed bookings could
     * therefore hold the same employee at the same time, one of them simply
     * having been re-saved (a Control Panel edit is enough).
     *
     * A slot whose schedule grants several seats has to hold several active
     * bookings for one employee, so "one row per employee per slot" stops being
     * the invariant and the key is left NULL. Those slots are guarded the way
     * employee-less group slots have always been guarded: BookingService takes a
     * mutex on the slot and re-checks the remaining capacity inside it.
     */
    public function beforeSave($insert): bool
    {
        $this->activeSlotKey = ($this->status !== self::STATUS_CANCELLED && $this->employeeId !== null && $this->holdsOneSeat())
            ? $this->bookingDate . '|' . self::normalizeSlotTime($this->startTime) . '|' . $this->employeeId
            : null;

        return parent::beforeSave($insert);
    }

    /**
     * Whether this booking's slot is limited to a single seat, which is what the
     * unique index can express. Unknown capacity counts as one seat, so a lookup
     * that cannot resolve keeps the stricter guard rather than dropping it.
     */
    private function holdsOneSeat(): bool
    {
        $date = $this->bookingDate instanceof \DateTimeInterface
            ? $this->bookingDate->format('Y-m-d')
            : substr((string)$this->bookingDate, 0, 10);

        if ($date === '') {
            return true;
        }

        $plugin = \anvildev\booked\Booked::getInstance();
        if ($plugin === null) {
            return true;
        }

        // Whole-day and flexible-day bookings hold no time, so there is no slot
        // to size — their seats come from the day's capacity instead. Without
        // this branch every such booking took the single-seat key and the second
        // one on a date collided, whatever capacity the schedule granted.
        //
        // Both spellings of "no time" have to count: the column is NULL, but
        // ReservationModel::$startTime defaults to an empty string, so a booking
        // reaches this record as either depending on who built it.
        if (($this->startTime ?? '') === '') {
            $dayOfWeek = (int)(new \DateTime($date))->format('N');
            $capacity = $this->serviceId !== null
                ? $plugin->getScheduleResolver()->getCapacityForDay($this->serviceId, $this->employeeId, $date, $dayOfWeek)
                : null;

            return $capacity === null || $capacity <= 1;
        }

        $capacity = $plugin->getCapacity()->getCapacityForSlot(
            $date,
            self::normalizeSlotTime($this->startTime),
            $this->employeeId,
            $this->serviceId,
        );

        return $capacity === null || $capacity <= 1;
    }

    /**
     * The canonical H:i form used inside activeSlotKey, so the key is identical
     * however the reservation reached memory.
     */
    public static function normalizeSlotTime(?string $time): string
    {
        return substr((string) $time, 0, 5);
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
                self::STATUS_NO_SHOW => Craft::t('booked', 'status.noShow'),
            ];
        }

        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_CONFIRMED => 'Confirmed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_NO_SHOW => 'No Show',
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
