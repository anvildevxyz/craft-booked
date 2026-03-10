<?php

namespace anvildev\booked\records;

use craft\db\ActiveRecord;

/**
 * Stores individual event date/time occurrences for event-based bookings.
 * Linked to elements table via the id column.
 *
 * IMPORTANT: Do NOT declare public properties - Yii's ActiveRecord uses
 * magic methods (__get/__set) to track dirty attributes.
 *
 * @property int $id
 * @property int|null $locationId
 * @property string $eventDate
 * @property string|null $endDate
 * @property string $startTime
 * @property string $endTime
 * @property string|null $title
 * @property string|null $description
 * @property int|null $capacity
 * @property bool $allowCancellation
 * @property int|null $cancellationPolicyHours
 * @property bool $allowRefund
 * @property string|null $refundTiers
 * @property bool|null $enableWaitlist
 * @property float|null $price
 * @property string $propagationMethod
 * @property bool $enabled
 * @property string|null $deletedAt
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class EventDateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%booked_event_dates}}';
    }

    public function rules(): array
    {
        return [
            [['eventDate', 'startTime', 'endTime'], 'required'],
            [['locationId'], 'integer'],
            [['eventDate', 'endDate'], 'date', 'format' => 'php:Y-m-d'],
            [['startTime', 'endTime'], 'match', 'pattern' => '/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            [['title'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['capacity'], 'integer', 'min' => 1],
            [['cancellationPolicyHours'], 'integer', 'min' => 0],
            [['enabled'], 'boolean'],
        ];
    }
}
