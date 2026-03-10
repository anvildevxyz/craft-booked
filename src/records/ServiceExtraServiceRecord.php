<?php

namespace anvildev\booked\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Junction table linking service extras to services (many-to-many).
 *
 * @property int $id
 * @property int $extraId
 * @property int $serviceId
 * @property int $sortOrder
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ServiceExtraServiceRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%booked_service_extras_services}}';
    }

    public function getExtra(): ActiveQueryInterface
    {
        return $this->hasOne(ServiceExtraRecord::class, ['id' => 'extraId']);
    }

    public function getService(): ActiveQueryInterface
    {
        return $this->hasOne(\craft\records\Element::class, ['id' => 'serviceId']);
    }
}
