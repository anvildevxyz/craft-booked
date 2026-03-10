<?php

namespace anvildev\booked\records;

use Craft;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string|null $secret
 * @property array|string $events
 * @property array|string|null $headers
 * @property bool $enabled
 * @property int $retryCount
 * @property string $payloadFormat
 * @property int|null $siteId
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class WebhookRecord extends ActiveRecord
{
    private bool $_encrypted = false;

    public static function tableName(): string
    {
        return '{{%booked_webhooks}}';
    }

    public function beforeSave($insert): bool
    {
        if ($this->secret && !$this->_encrypted) {
            $this->secret = base64_encode(
                Craft::$app->getSecurity()->encryptByKey($this->secret)
            );
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
        if ($this->secret) {
            try {
                $decoded = base64_decode($this->secret, true);
                $this->secret = $decoded !== false
                    ? Craft::$app->getSecurity()->decryptByKey($decoded)
                    : Craft::$app->getSecurity()->decryptByKey($this->secret);
            } catch (\Throwable) {
                // Secret may not be encrypted yet (pre-migration data)
            }
        }
        $this->_encrypted = false;
    }

    public function rules(): array
    {
        return [
            [['name', 'url', 'events'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['url'], 'string', 'max' => 500],
            [['url'], 'url'],
            [['secret'], 'string'],
            [['payloadFormat'], 'string', 'max' => 20],
            [['payloadFormat'], 'default', 'value' => 'standard'],
            [['payloadFormat'], 'in', 'range' => ['standard', 'flat']],
            [['enabled'], 'boolean'],
            [['enabled'], 'default', 'value' => true],
            [['retryCount'], 'integer', 'min' => 0, 'max' => 10],
            [['retryCount'], 'default', 'value' => 3],
            [['siteId'], 'integer'],
        ];
    }

    public function getEventsArray(): array
    {
        if (is_array($this->events)) {
            return $this->events;
        }
        return is_string($this->events) ? (json_decode($this->events, true) ?? []) : [];
    }

    public function setEventsFromArray(array $events): void
    {
        $this->events = json_encode($events, JSON_THROW_ON_ERROR);
    }

    public function getHeadersArray(): array
    {
        if (is_array($this->headers)) {
            return $this->headers;
        }
        return is_string($this->headers) ? (json_decode($this->headers, true) ?? []) : [];
    }

    public function setHeadersFromArray(array $headers): void
    {
        $this->headers = json_encode($headers, JSON_THROW_ON_ERROR);
    }

    public function handlesEvent(string $event): bool
    {
        return in_array($event, $this->getEventsArray(), true);
    }

    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
