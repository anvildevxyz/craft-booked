<?php

namespace anvildev\booked\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $reservationId
 * @property int $serviceExtraId
 * @property int $quantity
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ReservationExtraRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%booked_reservation_extras}}';
    }

    public function getExtra(): ActiveQueryInterface
    {
        return $this->hasOne(ServiceExtraRecord::class, ['id' => 'serviceExtraId']);
    }

    public function getTotalPrice(): float
    {
        $extra = $this->extra;
        return $extra ? (float) $extra->price * $this->quantity : 0.0;
    }
}
