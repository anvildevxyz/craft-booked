<?php

namespace anvildev\booked\records;

use craft\db\ActiveRecord;

/**
 * Stores secure state tokens for OAuth flows to prevent CSRF attacks.
 *
 * @property int $id
 * @property string $token
 * @property int $employeeId
 * @property string $provider
 * @property string $createdAt
 * @property string $expiresAt
 */
class OAuthStateTokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%booked_oauth_state_tokens}}';
    }

    public static function createToken(int $employeeId, string $provider): self
    {
        $record = new self();
        $record->token = \craft\helpers\StringHelper::UUID();
        $record->employeeId = $employeeId;
        $record->provider = $provider;
        $record->createdAt = (new \DateTime())->format('Y-m-d H:i:s');
        $record->expiresAt = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');
        $record->save();

        return $record;
    }

    /**
     * Peek at a state token without consuming it.
     *
     * @return array{employeeId: int, provider: string}|null
     */
    public static function peek(string $token): ?array
    {
        $record = self::findValidToken($token);

        return $record ? ['employeeId' => $record->employeeId, 'provider' => $record->provider] : null;
    }

    /**
     * Verify and consume (delete) a state token.
     *
     * @return array{employeeId: int, provider: string}|null
     */
    public static function verifyAndConsume(string $token): ?array
    {
        $record = self::findValidToken($token);
        if (!$record) {
            return null;
        }

        $data = ['employeeId' => $record->employeeId, 'provider' => $record->provider];
        $record->delete();

        return $data;
    }

    public static function cleanupExpired(): int
    {
        return self::deleteAll(['<', 'expiresAt', (new \DateTime())->format('Y-m-d H:i:s')]);
    }

    private static function findValidToken(string $token): ?self
    {
        /** @var static|null */
        return self::find()
            ->where(['token' => $token])
            ->andWhere(['>', 'expiresAt', (new \DateTime())->format('Y-m-d H:i:s')])
            ->one();
    }
}
