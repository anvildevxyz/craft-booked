<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\exceptions\BookingValidationException;
use anvildev\booked\helpers\PiiRedactor;
use anvildev\booked\queue\jobs\SendWaitlistNotificationJob;
use anvildev\booked\records\WaitlistRecord;
use Craft;
use craft\base\Component;

/**
 * Manages the customer waitlist for fully-booked services.
 * Handles adding entries, notifying customers when slots open, and cleanup.
 */
class WaitlistService extends Component
{
    /**
     * @throws \Exception If the waitlist feature is not enabled
     * @throws BookingValidationException If the email address is invalid
     */
    public function addToWaitlist(array $data): ?WaitlistRecord
    {
        return $this->createWaitlistEntry($data, [
            'serviceId' => (int)$data['serviceId'],
            'employeeId' => $data['employeeId'] ?? null,
            'locationId' => $data['locationId'] ?? null,
            'preferredDate' => ($data['preferredDate'] ?? null) ?: null,
            'preferredTimeStart' => ($data['preferredTimeStart'] ?? null) ?: null,
            'preferredTimeEnd' => ($data['preferredTimeEnd'] ?? null) ?: null,
        ]);
    }

    /**
     * Calculate a tier-based priority (lower value = higher priority).
     *
     * Tier 0: Authenticated user with a preferred date (most specific)
     * Tier 1: Authenticated user without a preferred date
     * Tier 2: Guest with a preferred date
     * Tier 3: Guest without a preferred date (least specific)
     *
     * Within the same tier, entries are ordered by dateCreated (FIFO).
     */
    protected function calculatePriority(array $data): int
    {
        $hasUser = !empty($data['userId']);
        $hasPreferredDate = !empty($data['preferredDate']);

        if ($hasUser && $hasPreferredDate) {
            return 0;
        }
        if ($hasUser) {
            return 1;
        }
        if ($hasPreferredDate) {
            return 2;
        }

        return 3;
    }

    /**
     * Shared factory for creating waitlist entries (service or event).
     *
     * @param array $data Common data including userName, userEmail, userPhone, userId, notes
     * @param array $specificFields Type-specific fields to set on the record (e.g. serviceId, eventDateId)
     *
     * @throws \Exception If the waitlist feature is not enabled
     * @throws BookingValidationException If the email address is invalid
     */
    private function createWaitlistEntry(array $data, array $specificFields): ?WaitlistRecord
    {
        $settings = Booked::getInstance()->getSettings();

        if (!$settings->enableWaitlist) {
            throw new \Exception(Craft::t('booked', 'waitlist.service.notEnabled'));
        }

        $email = $data['userEmail'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BookingValidationException(
                Craft::t('booked', 'waitlist.invalidEmail'),
                ['userEmail' => [Craft::t('booked', 'waitlist.invalidEmail')]]
            );
        }

        $record = new WaitlistRecord();

        // Set type-specific fields (serviceId, eventDateId, employeeId, locationId, etc.)
        foreach ($specificFields as $field => $value) {
            $record->$field = $value;
        }

        // Set common fields
        $record->userName = $data['userName'];
        $record->userEmail = $data['userEmail'];
        $record->userPhone = $data['userPhone'] ?? null;
        $record->userId = $data['userId'] ?? null;
        $record->priority = $this->calculatePriority($data);
        $record->status = WaitlistRecord::STATUS_ACTIVE;
        $record->notes = $data['notes'] ?? null;

        if ($settings->waitlistExpirationDays > 0) {
            $record->expiresAt = (new \DateTime("+{$settings->waitlistExpirationDays} days"))->format('Y-m-d H:i:s');
        }

        if (!$record->save()) {
            Craft::error('Failed to save waitlist entry: ' . json_encode($record->getErrors()), __METHOD__);
            return null;
        }

        Craft::info("Added waitlist entry #{$record->id} for " . PiiRedactor::redactEmail($record->userEmail), __METHOD__);
        return $record;
    }

    public function checkAndNotifyWaitlist(
        int $serviceId,
        ?string $date,
        ?string $startTime,
        ?string $endTime,
        ?int $employeeId = null,
        ?int $locationId = null,
    ): void {
        if (!Booked::getInstance()->getSettings()->enableWaitlist) {
            return;
        }

        $query = WaitlistRecord::find()->where([
            'serviceId' => $serviceId,
            'status' => WaitlistRecord::STATUS_ACTIVE,
        ]);

        if ($employeeId) {
            $query->andWhere(['or', ['employeeId' => $employeeId], ['employeeId' => null]]);
        }
        if ($locationId) {
            $query->andWhere(['or', ['locationId' => $locationId], ['locationId' => null]]);
        }

        if ($date !== null) {
            $query->andWhere(['or', ['preferredDate' => $date], ['preferredDate' => null]]);
        }

        $entries = $query
            ->orderBy(['priority' => SORT_ASC, 'dateCreated' => SORT_ASC])
            ->limit(Booked::getInstance()->getSettings()->waitlistNotificationLimit)
            ->all();

        if (empty($entries)) {
            Craft::info("No waitlist entries to notify for service #{$serviceId} on {$date}", __METHOD__);
            return;
        }

        Craft::info("Notifying " . count($entries) . " waitlist entries for service #{$serviceId} on {$date}", __METHOD__);

        foreach ($entries as $entry) {
            $this->notifyEntry($entry, $date, $startTime, $endTime);
        }
    }

    public function notifyEntry(WaitlistRecord $entry, ?string $date = null, ?string $startTime = null, ?string $endTime = null): void
    {
        $entry->status = WaitlistRecord::STATUS_NOTIFIED;
        $entry->save(false);

        // Generate a conversion token so the notification email can include a booking link
        $conversionToken = $this->createConversionToken($entry->id);

        Craft::$app->queue->push(new SendWaitlistNotificationJob(array_filter([
            'waitlistId' => $entry->id,
            'date' => $date,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'conversionToken' => $conversionToken,
        ], fn($v) => $v !== null)));

        Craft::info("Queued waitlist notification for entry #{$entry->id} (" . PiiRedactor::redactEmail($entry->userEmail) . ")", __METHOD__);
    }

    public function manualNotify(int $entryId): bool
    {
        $entry = WaitlistRecord::findOne($entryId);
        if (!$entry?->canBeNotified()) {
            return false;
        }
        $this->notifyEntry($entry);
        return true;
    }

    public function cancelEntry(int $entryId): bool
    {
        $entry = WaitlistRecord::findOne($entryId);
        if (!$entry) {
            return false;
        }
        $entry->status = WaitlistRecord::STATUS_CANCELLED;
        return $entry->save(false);
    }

    public function cleanupExpired(): int
    {
        // First, mark overdue active entries as expired
        WaitlistRecord::updateAll(
            ['status' => WaitlistRecord::STATUS_EXPIRED],
            [
                'and',
                ['status' => WaitlistRecord::STATUS_ACTIVE],
                ['<', 'expiresAt', (new \DateTime())->format('Y-m-d H:i:s')],
                ['not', ['expiresAt' => null]],
            ]
        );

        // Then delete all expired entries
        $count = WaitlistRecord::deleteAll(['status' => WaitlistRecord::STATUS_EXPIRED]);

        if ($count > 0) {
            Craft::info("Cleaned up {$count} expired waitlist entries", __METHOD__);
        }
        return $count;
    }

    /** @return array<string, int> */
    public function getStats(): array
    {
        // Single GROUP BY query instead of separate COUNT per status
        $rows = (new \yii\db\Query())
            ->select(['status', 'cnt' => 'COUNT(*)'])
            ->from(WaitlistRecord::tableName())
            ->groupBy('status')
            ->all();

        $stats = [
            'active' => 0,
            'notified' => 0,
            'converted' => 0,
            'expired' => 0,
            'cancelled' => 0,
        ];

        $total = 0;
        foreach ($rows as $row) {
            $status = $row['status'];
            $count = (int) $row['cnt'];
            if (isset($stats[$status])) {
                $stats[$status] = $count;
            }
            $total += $count;
        }

        $stats['total'] = $total;
        return $stats;
    }

    /** @return WaitlistRecord[] */
    public function getActiveEntriesForService(int $serviceId): array
    {
        return WaitlistRecord::find()
            ->where(['serviceId' => $serviceId, 'status' => WaitlistRecord::STATUS_ACTIVE])
            ->orderBy(['priority' => SORT_ASC, 'dateCreated' => SORT_ASC])
            ->all();
    }

    public function isOnWaitlist(string $email, int $serviceId): bool
    {
        return WaitlistRecord::find()
            ->where(['userEmail' => $email, 'serviceId' => $serviceId, 'status' => WaitlistRecord::STATUS_ACTIVE])
            ->exists();
    }

    /**
     * @throws \Exception If the waitlist feature is not enabled
     * @throws BookingValidationException If the email address is invalid
     */
    public function addToEventWaitlist(array $data): ?WaitlistRecord
    {
        return $this->createWaitlistEntry($data, [
            'eventDateId' => (int)$data['eventDateId'],
        ]);
    }

    public function checkAndNotifyEventWaitlist(int $eventDateId): void
    {
        if (!Booked::getInstance()->getSettings()->enableWaitlist) {
            return;
        }

        $eventDate = Booked::getInstance()->getEventDate()->getEventDateById($eventDateId);
        if (!$eventDate) {
            return;
        }

        $remaining = $eventDate->getRemainingCapacity();
        if ($remaining !== null && $remaining <= 0) {
            Craft::info("No remaining capacity for event date #{$eventDateId}, skipping waitlist notify", __METHOD__);
            return;
        }

        $entries = WaitlistRecord::find()
            ->where(['eventDateId' => $eventDateId, 'status' => WaitlistRecord::STATUS_ACTIVE])
            ->orderBy(['priority' => SORT_ASC, 'dateCreated' => SORT_ASC])
            ->limit(Booked::getInstance()->getSettings()->waitlistNotificationLimit)
            ->all();

        if (empty($entries)) {
            Craft::info("No waitlist entries to notify for event date #{$eventDateId}", __METHOD__);
            return;
        }

        Craft::info("Notifying " . count($entries) . " waitlist entries for event date #{$eventDateId}", __METHOD__);

        foreach ($entries as $entry) {
            $this->notifyEntry($entry, $eventDate->eventDate, $eventDate->startTime, $eventDate->endTime);
        }
    }

    public function isOnEventWaitlist(string $email, int $eventDateId): bool
    {
        return WaitlistRecord::find()
            ->where(['userEmail' => $email, 'eventDateId' => $eventDateId, 'status' => WaitlistRecord::STATUS_ACTIVE])
            ->exists();
    }

    /**
     * Create a conversion token for a waitlist entry.
     * Only entries with STATUS_NOTIFIED can receive a conversion token.
     */
    public function createConversionToken(int $waitlistEntryId): ?string
    {
        $entry = WaitlistRecord::findOne($waitlistEntryId);
        if (!$entry || $entry->status !== WaitlistRecord::STATUS_NOTIFIED) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $settings = Booked::getInstance()->getSettings();
        $expiryMinutes = $settings->waitlistConversionMinutes ?? 30;

        $expiresAt = new \DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone()));
        $expiresAt->modify("+{$expiryMinutes} minutes");

        $entry->conversionToken = $token;
        $entry->conversionExpiresAt = $expiresAt->format('Y-m-d H:i:s');
        if (!$entry->save(false)) {
            Craft::error("Failed to save conversion token for waitlist entry #{$entry->id}", __METHOD__);
            return null;
        }

        Craft::info("Created conversion token for waitlist entry #{$entry->id}, expires at {$entry->conversionExpiresAt}", __METHOD__);

        return $token;
    }

    /**
     * Validate a conversion token. Returns the waitlist entry if valid and not expired.
     * If the token has expired, the entry is reset to active and the next person is notified.
     */
    public function validateConversionToken(string $token): ?WaitlistRecord
    {
        /** @var WaitlistRecord|null $entry */
        $entry = WaitlistRecord::find()
            ->where(['conversionToken' => $token])
            ->andWhere(['status' => WaitlistRecord::STATUS_NOTIFIED])
            ->one();

        if (!$entry) {
            return null;
        }

        if ($entry->conversionExpiresAt) {
            $expiresAt = new \DateTime($entry->conversionExpiresAt);
            $now = new \DateTime();
            if ($now > $expiresAt) {
                Craft::info("Conversion token expired for waitlist entry #{$entry->id}, cascading to next", __METHOD__);

                $entry->conversionToken = null;
                $entry->conversionExpiresAt = null;
                $entry->status = WaitlistRecord::STATUS_ACTIVE;
                $entry->save(false);

                $this->cascadeToNextWaitlistEntry($entry);
                return null;
            }
        }

        return $entry;
    }

    /**
     * Mark waitlist entry as converted after successful booking.
     */
    public function completeConversion(int $waitlistEntryId): void
    {
        $entry = WaitlistRecord::findOne($waitlistEntryId);
        if ($entry) {
            $entry->status = WaitlistRecord::STATUS_CONVERTED;
            $entry->conversionToken = null;
            $entry->conversionExpiresAt = null;
            $entry->save(false);

            Craft::info("Waitlist entry #{$entry->id} converted to booking", __METHOD__);
        }
    }

    /**
     * When a conversion token expires, notify the next person on the waitlist.
     */
    private function cascadeToNextWaitlistEntry(WaitlistRecord $expiredEntry): void
    {
        if ($expiredEntry->eventDateId) {
            $this->checkAndNotifyEventWaitlist($expiredEntry->eventDateId);
        } elseif ($expiredEntry->serviceId) {
            $this->checkAndNotifyWaitlist(
                $expiredEntry->serviceId,
                $expiredEntry->preferredDate ?: null,
                $expiredEntry->preferredTimeStart ?: null,
                $expiredEntry->preferredTimeEnd ?: null,
                $expiredEntry->employeeId,
                $expiredEntry->locationId
            );
        }
    }
}
