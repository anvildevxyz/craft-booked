<?php

namespace anvildev\booked\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $webhookId
 * @property string $event
 * @property int|null $reservationId
 * @property string $url
 * @property array|string|null $requestHeaders
 * @property string|null $requestBody
 * @property int|null $responseCode
 * @property string|null $responseBody
 * @property bool $success
 * @property string|null $errorMessage
 * @property int|null $duration
 * @property int $attempt
 * @property \DateTime $dateCreated
 */
class WebhookLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%booked_webhook_logs}}';
    }

    public function rules(): array
    {
        return [
            [['webhookId', 'event', 'url', 'success'], 'required'],
            [['webhookId', 'reservationId', 'responseCode', 'duration', 'attempt'], 'integer'],
            [['event'], 'string', 'max' => 50],
            [['url'], 'string', 'max' => 500],
            [['success'], 'boolean'],
            [['attempt'], 'default', 'value' => 1],
        ];
    }

    public function getWebhook(): ?WebhookRecord
    {
        return WebhookRecord::findOne($this->webhookId);
    }

    public function getRedactedRequestBody(): ?string
    {
        if ($this->requestBody === null) {
            return null;
        }
        return \anvildev\booked\Booked::getInstance()->getWebhook()->redactPayloadForLog($this->requestBody);
    }

    public function getFormattedDuration(): string
    {
        if ($this->duration === null) {
            return '-';
        }
        return $this->duration < 1000 ? $this->duration . 'ms' : round($this->duration / 1000, 2) . 's';
    }

    public function getStatusLabel(): string
    {
        if ($this->success) {
            return 'Success';
        }
        return $this->responseCode !== null ? "Failed ({$this->responseCode})" : 'Failed';
    }
}
