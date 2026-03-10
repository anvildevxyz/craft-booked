<?php

namespace anvildev\booked\records;

use craft\db\ActiveRecord;

/**
 * Service extra/add-on element record. The 'id' column is a FK to elements.id.
 *
 * @property int $id
 * @property string $propagationMethod
 * @property string|null $description
 * @property float $price
 * @property int $duration
 * @property int $maxQuantity
 * @property bool $isRequired
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ServiceExtraRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%booked_service_extras}}';
    }
}
