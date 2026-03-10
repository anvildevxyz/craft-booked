<?php

namespace anvildev\booked\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $employeeId
 * @property string $provider
 * @property string $accessToken
 * @property string|null $refreshToken
 * @property string $expiresAt
 */
class CalendarTokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%booked_calendar_tokens}}';
    }

    public function beforeSave($insert): bool
    {
        if ($this->accessToken) {
            $this->accessToken = base64_encode(\Craft::$app->getSecurity()->encryptByKey($this->accessToken));
        }
        if ($this->refreshToken) {
            $this->refreshToken = base64_encode(\Craft::$app->getSecurity()->encryptByKey($this->refreshToken));
        }
        return parent::beforeSave($insert);
    }

    public function afterFind(): void
    {
        parent::afterFind();
        if ($this->accessToken) {
            try {
                $decoded = base64_decode($this->accessToken, true);
                $this->accessToken = $decoded !== false
                    ? \Craft::$app->getSecurity()->decryptByKey($decoded)
                    : \Craft::$app->getSecurity()->decryptByKey($this->accessToken);
            } catch (\Throwable) {
                // Token may not be encrypted yet (pre-migration data)
            }
        }
        if ($this->refreshToken) {
            try {
                $decoded = base64_decode($this->refreshToken, true);
                $this->refreshToken = $decoded !== false
                    ? \Craft::$app->getSecurity()->decryptByKey($decoded)
                    : \Craft::$app->getSecurity()->decryptByKey($this->refreshToken);
            } catch (\Throwable) {
                // Token may not be encrypted yet (pre-migration data)
            }
        }
    }
}
