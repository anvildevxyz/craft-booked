<?php

namespace anvildev\booked\records;

use craft\db\ActiveRecord;

/**
 * Temporary slot locks to prevent race conditions during concurrent booking attempts.
 *
 * @property int $id
 * @property string $token
 * @property string|null $sessionHash
 * @property int $serviceId
 * @property int|null $employeeId
 * @property int|null $locationId
 * @property string $date
 * @property string $startTime
 * @property string $endTime
 * @property int $quantity
 * @property string $expiresAt
 */
class SoftLockRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%booked_soft_locks}}';
    }
}
