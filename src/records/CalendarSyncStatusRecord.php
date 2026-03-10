<?php

namespace anvildev\booked\records;

use craft\db\ActiveRecord;

/**
 * Tracks sync status, last sync time, errors, and webhook subscriptions per employee/provider.
 *
 * @property int $id
 * @property int $employeeId
 * @property string $provider
 * @property string $status
 * @property \DateTime|null $lastSyncAt
 * @property bool|null $lastSyncSuccess
 * @property string|null $lastSyncError
 * @property int $syncCount
 * @property int $errorCount
 * @property string|null $webhookSubscriptionId
 * @property \DateTime|null $webhookExpiresAt
 * @property string|null $webhookResourceId
 * @property string|null $webhookResourceUri
 */
class CalendarSyncStatusRecord extends ActiveRecord
{
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_SYNCING = 'syncing';
    public const STATUS_ERROR = 'error';

    public static function tableName(): string
    {
        return '{{%booked_calendar_sync_status}}';
    }

    public function rules(): array
    {
        return [
            [['employeeId', 'provider', 'status'], 'required'],
            [['employeeId', 'syncCount', 'errorCount'], 'integer'],
            [['provider', 'status'], 'string', 'max' => 20],
            [['status'], 'in', 'range' => [self::STATUS_DISCONNECTED, self::STATUS_CONNECTED, self::STATUS_SYNCING, self::STATUS_ERROR]],
            [['lastSyncSuccess'], 'boolean'],
            [['lastSyncError'], 'string'],
            [['webhookSubscriptionId', 'webhookResourceId'], 'string', 'max' => 255],
            [['webhookResourceUri'], 'string', 'max' => 500],
            [['lastSyncAt', 'webhookExpiresAt'], 'datetime'],
            [['employeeId', 'provider'], 'unique', 'targetAttribute' => ['employeeId', 'provider']],
        ];
    }
}
