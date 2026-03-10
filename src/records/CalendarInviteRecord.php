<?php

namespace anvildev\booked\records;

use anvildev\booked\elements\Employee;
use craft\db\ActiveRecord;
use craft\helpers\StringHelper;

/**
 * Stores secure invite tokens for frontend calendar OAuth flow.
 *
 * @property int $id
 * @property int $employeeId
 * @property string $provider
 * @property string $token
 * @property string $email
 * @property string $expiresAt
 * @property string|null $usedAt
 * @property int $createdBy
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class CalendarInviteRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%booked_calendar_invites}}';
    }

    public static function createInvite(
        int $employeeId,
        string $provider,
        string $email,
        int $createdBy,
        int $expiresInHours = 72,
    ): self {
        // Invalidate any existing unused invites for this employee/provider
        $now = new \DateTime();
        $nowStr = $now->format('Y-m-d H:i:s');

        self::updateAll(
            ['usedAt' => $nowStr],
            ['and', ['employeeId' => $employeeId], ['provider' => $provider], ['usedAt' => null]]
        );

        $record = new self();
        $record->employeeId = $employeeId;
        $record->provider = $provider;
        $record->token = StringHelper::randomString(64);
        $record->email = $email;
        $record->expiresAt = (clone $now)->modify("+{$expiresInHours} hours")->format('Y-m-d H:i:s');
        $record->createdBy = $createdBy;
        $record->dateCreated = $nowStr;
        $record->dateUpdated = $nowStr;
        $record->save();

        return $record;
    }

    public static function findValid(string $token): ?self
    {
        /** @var static|null */
        return self::find()
            ->where(['token' => $token, 'usedAt' => null])
            ->andWhere(['>', 'expiresAt', (new \DateTime())->format('Y-m-d H:i:s')])
            ->one();
    }

    public static function findPending(int $employeeId, string $provider): ?self
    {
        /** @var static|null */
        return self::find()
            ->where(['employeeId' => $employeeId, 'provider' => $provider, 'usedAt' => null])
            ->andWhere(['>', 'expiresAt', (new \DateTime())->format('Y-m-d H:i:s')])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();
    }

    public function markUsed(): bool
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $this->usedAt = $now;
        $this->dateUpdated = $now;
        return $this->save();
    }

    public function getEmployee(): ?Employee
    {
        return Employee::find()->siteId('*')->id($this->employeeId)->one();
    }

    public function isExpired(): bool
    {
        return new \DateTime($this->expiresAt) < new \DateTime();
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public static function cleanupExpired(): int
    {
        return self::deleteAll(['<', 'expiresAt', (new \DateTime())->modify('-7 days')->format('Y-m-d H:i:s')]);
    }
}
