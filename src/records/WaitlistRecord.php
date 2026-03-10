<?php

namespace anvildev\booked\records;

use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $serviceId
 * @property int|null $eventDateId
 * @property int|null $employeeId
 * @property int|null $locationId
 * @property string|null $preferredDate
 * @property string|null $preferredTimeStart
 * @property string|null $preferredTimeEnd
 * @property string $userName
 * @property string $userEmail
 * @property string|null $userPhone
 * @property int|null $userId
 * @property int $priority
 * @property string|null $notifiedAt
 * @property string|null $expiresAt
 * @property string $status
 * @property string|null $conversionToken
 * @property string|null $conversionExpiresAt
 * @property string|null $notes
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class WaitlistRecord extends ActiveRecord
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_NOTIFIED = 'notified';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public static function tableName(): string
    {
        return '{{%booked_waitlist}}';
    }

    public function rules(): array
    {
        return [
            [['userName', 'userEmail'], 'required'],
            [['serviceId', 'eventDateId', 'employeeId', 'locationId', 'userId', 'priority'], 'integer'],
            ['serviceId', 'required', 'when' => fn($model) => $model->eventDateId === null,
                'message' => 'Either serviceId or eventDateId is required.', ],
            ['eventDateId', 'required', 'when' => fn($model) => $model->serviceId === null,
                'message' => 'Either serviceId or eventDateId is required.', ],
            [['userName', 'userEmail', 'userPhone'], 'string', 'max' => 255],
            [['userEmail'], 'email'],
            [['preferredDate'], 'date', 'format' => 'php:Y-m-d'],
            [['preferredTimeStart', 'preferredTimeEnd'], 'match', 'pattern' => '/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            [['status'], 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_NOTIFIED, self::STATUS_CONVERTED, self::STATUS_EXPIRED, self::STATUS_CANCELLED]],
            [['status'], 'default', 'value' => self::STATUS_ACTIVE],
            [['priority'], 'default', 'value' => 0],
            [['notes'], 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_NOTIFIED => 'Notified',
            self::STATUS_CONVERTED => 'Converted',
            self::STATUS_EXPIRED => 'Expired',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public function getStatusLabel(): string
    {
        return self::getStatuses()[$this->status] ?? $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isNotified(): bool
    {
        return $this->status === self::STATUS_NOTIFIED;
    }

    /** Active entries can be notified, and notified entries can be re-notified. */
    public function canBeNotified(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            || $this->status === self::STATUS_NOTIFIED;
    }

    public function getService(): ?Service
    {
        return $this->serviceId ? Service::find()->id($this->serviceId)->siteId('*')->one() : null;
    }

    public function getEmployee(): ?Employee
    {
        return $this->employeeId ? Employee::find()->id($this->employeeId)->siteId('*')->one() : null;
    }

    public function getLocation(): ?Location
    {
        return $this->locationId ? Location::find()->id($this->locationId)->siteId('*')->one() : null;
    }

    public function getEventDate(): ?\anvildev\booked\elements\EventDate
    {
        return $this->eventDateId
            ? \anvildev\booked\elements\EventDate::find()->id($this->eventDateId)->siteId('*')->one()
            : null;
    }
}
