<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\records\SoftLockRecord;
use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use yii\db\ActiveQuery;

/**
 * Manages temporary slot reservations (soft locks) to prevent race conditions.
 *
 * When a customer begins the booking flow, a time-limited lock is placed on the
 * slot. This prevents double-booking when multiple users attempt to reserve the
 * same time concurrently. Locks expire automatically and are garbage-collected
 * on each new lock creation.
 */
class SoftLockService extends Component
{
    /** @return string|false Token if successful */
    public function createLock(array $data, int $durationMinutes = 5): string|false
    {
        if (empty($data['date']) || (empty($data['startTime']) && empty($data['endDate'])) || !isset($data['serviceId'])) {
            return false;
        }

        // Lock times live in a varchar column, so every comparison against
        // them is a string comparison. Seconds-carrying times ('10:00:00' from
        // a TIME column or an API client) sort AFTER their H:i twin ('10:00'),
        // which made a 09:00-10:00 hold overlap the 10:00 slot. Store H:i only.
        $data['startTime'] = self::normalizeTime($data['startTime'] ?? null);
        $data['endTime'] = self::normalizeTime($data['endTime'] ?? null);

        $this->deleteExpiredRecords();

        $employeeId = $data['employeeId'] ?? null;
        $quantity = max(1, (int)($data['quantity'] ?? 1));
        // Callers that know their own seat model (event locks) pass an
        // explicit capacity — possibly null for an uncapped event. Everyone
        // else gets the seats resolved here, under the mutex below.
        $hasExplicitCapacity = array_key_exists('capacity', $data);
        $capacity = $hasExplicitCapacity && $data['capacity'] !== null ? (int)$data['capacity'] : null;
        $endDate = $data['endDate'] ?? null;

        try {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        } catch (\Throwable) {
            $siteId = 1;
        }
        $lockKey = 'booked-softlock-' . $siteId . '-' . $data['date'] . '-' . ($endDate ?? $data['startTime']) . '-' . $data['serviceId'] . '-' . ($employeeId ?? 'any');
        $mutex = $this->getMutex();

        if (!$mutex->acquire($lockKey, 5)) {
            return false;
        }

        try {
            // Resolve the slot's remaining seats INSIDE the mutex: a booking
            // that lands between a caller's earlier read and this check would
            // otherwise make the capacity stale and grant a hold on a seat
            // that is already gone.
            if (!$hasExplicitCapacity) {
                $capacity = $endDate
                    ? $this->resolveRangeSeats($data['date'], $endDate, (int)$data['serviceId'], $employeeId, $data['locationId'] ?? null)
                    : $this->resolveSlotSeats($data['date'], (string)$data['startTime'], $data['endTime'], (int)$data['serviceId'], $employeeId);
            }

            // For multi-day locks, check date-range overlap instead of time-based overlap
            if ($endDate) {
                $isAlreadyLocked = $this->isDateRangeLocked(
                    $data['date'], $endDate, $data['serviceId'],
                    $employeeId, $data['locationId'] ?? null, $quantity, $capacity
                );
            } else {
                $isAlreadyLocked = $this->isLocked(
                    $data['date'], $data['startTime'], $data['serviceId'],
                    $employeeId, $data['endTime'] ?? null, $data['locationId'] ?? null,
                    null, $quantity, $capacity
                );
            }
            if ($isAlreadyLocked) {
                return false;
            }

            $token = bin2hex(random_bytes(16));
            $expiresAt = new DateTime('now', new DateTimeZone('UTC'));
            $expiresAt->modify("+{$durationMinutes} minutes");

            $record = $this->createRecord();
            $record->token = $token;
            $record->sessionHash = $this->getSessionHash();
            $record->serviceId = $data['serviceId'];
            $record->employeeId = $employeeId;
            $record->locationId = $data['locationId'] ?? null;
            $record->date = $data['date'];
            $record->startTime = $data['startTime'];
            $record->endTime = $data['endTime'] ?? null;
            $record->endDate = $endDate;
            $record->quantity = $quantity;
            $record->expiresAt = Db::prepareDateForDb($expiresAt);

            return $this->saveRecord($record) ? $token : false;
        } finally {
            $mutex->release($lockKey);
        }
    }

    public function isLocked(string $date, string $startTime, int $serviceId, ?int $employeeId = null, ?string $slotEndTime = null, ?int $locationId = null, ?string $excludeToken = null, int $quantity = 1, ?int $capacity = null): bool
    {
        $query = $this->buildLockQuery($date, $startTime, $serviceId, $employeeId, $slotEndTime, $locationId);

        if ($excludeToken !== null && $excludeToken !== '') {
            $query->andWhere(['!=', 'token', $excludeToken]);
        }

        // When capacity is provided, compare total held quantity against it.
        // Only actual holds may refuse: with zero held seats this check must
        // stay silent, so a fully-booked or unschedulable slot is refused by
        // the capacity validation with its accurate message instead of a
        // false "temporarily reserved, try again later".
        if ($capacity !== null) {
            $heldQuantity = (int)$query->sum('quantity');
            $isLocked = $heldQuantity > 0 && ($heldQuantity + $quantity) > $capacity;
        } else {
            $isLocked = $query->exists();
        }

        if ($isLocked && $excludeToken && Craft::$app->getConfig()->getGeneral()->devMode) {
            $debugQuery = $this->buildLockQuery($date, $startTime, $serviceId, $employeeId, $slotEndTime, $locationId);

            $allLocks = $debugQuery->all();
            $lockInfo = array_map(
                fn($lock) => "lock_id=" . substr(hash('sha256', $lock->token), 0, 8) . " time={$lock->startTime}-{$lock->endTime} location={$lock->locationId} qty={$lock->quantity}",
                $allLocks,
            );
            Craft::debug("Slot is locked even after excluding lock_id=" . substr(hash('sha256', $excludeToken), 0, 8) . ". Existing locks: " . implode(', ', $lockInfo), __METHOD__);
        }

        return $isLocked;
    }

    /** @return SoftLockRecord[] */
    public function getActiveSoftLocksForDate(string $date, int $serviceId, ?string $excludeToken = null): array
    {
        $query = $this->getRecordQuery()
            ->where(['date' => $date, 'serviceId' => $serviceId])
            ->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))]);

        if ($excludeToken !== null && $excludeToken !== '') {
            $query->andWhere(['!=', 'token', $excludeToken]);
        }

        return $query->all();
    }

    public function releaseLock(string $token, ?string $sessionHash = null): bool
    {
        $record = $this->getRecordByToken($token);
        if (!$record) {
            return false;
        }

        // If the lock has a session hash, verify it matches
        if ($record->sessionHash && !hash_equals($record->sessionHash, $sessionHash ?? '')) {
            Craft::warning("Soft lock release denied: session mismatch for token (lock_id=" . substr(hash('sha256', $token), 0, 8) . ")", __METHOD__);
            return false;
        }

        return (bool)$this->deleteRecord($record);
    }

    /**
     * Extend a held lock, re-issuing its TTL from now — up to a hard lifetime ceiling.
     *
     * Verifies the session hash (like {@see releaseLock()}) and refuses to
     * resurrect a lock that is missing or already expired. Crucially, the new
     * expiry is clamped to the lock's creation time plus $maxLifetimeMinutes, so
     * a client can renew while it finishes checkout but can never hold a slot
     * indefinitely and starve real bookings. Returns the new UTC expiry on success.
     *
     * @param string $token
     * @param int $durationMinutes
     * @param string|null $sessionHash
     * @param int $maxLifetimeMinutes Hard ceiling on total lifetime, measured from the lock's creation
     * @return DateTime|false New expiry on success, false if the lock is gone, expired, capped out, or the session mismatches
     */
    public function extendLock(string $token, int $durationMinutes = 5, ?string $sessionHash = null, int $maxLifetimeMinutes = 30): DateTime|false
    {
        $record = $this->getRecordByToken($token);
        if (!$record) {
            return false;
        }

        if ($record->sessionHash && !hash_equals($record->sessionHash, $sessionHash ?? '')) {
            Craft::warning("Soft lock extend denied: session mismatch for token (lock_id=" . substr(hash('sha256', $token), 0, 8) . ")", __METHOD__);
            return false;
        }

        // Refuse to resurrect an already-expired lock — the slot may be gone.
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $currentExpiry = new DateTime((string)$record->expiresAt, new DateTimeZone('UTC'));
        if ($currentExpiry <= $now) {
            return false;
        }

        // Hard ceiling: a lock can be renewed, but never past creation + max lifetime.
        // Once the ceiling is reached the client must re-acquire, which re-checks
        // availability — so nobody can sit on a slot forever by extending on a timer.
        $maxExpiry = (new DateTime((string)$record->dateCreated, new DateTimeZone('UTC')))
            ->modify("+{$maxLifetimeMinutes} minutes");
        if ($maxExpiry <= $now) {
            Craft::info("Soft lock extend denied: lifetime ceiling reached (lock_id=" . substr(hash('sha256', $token), 0, 8) . ")", __METHOD__);
            return false;
        }

        $newExpiry = (clone $now)->modify("+{$durationMinutes} minutes");
        if ($newExpiry > $maxExpiry) {
            $newExpiry = $maxExpiry;
        }
        $record->expiresAt = Db::prepareDateForDb($newExpiry);

        return $this->saveRecord($record) ? $newExpiry : false;
    }

    public function cleanupExpiredLocks(): int
    {
        return $this->deleteExpiredRecords();
    }

    public function countExpiredLocks(): int
    {
        return (int)$this->getRecordQuery()
            ->where(['<=', 'expiresAt', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))])
            ->count();
    }

    public function getSessionHash(): string
    {
        $request = Craft::$app->getRequest();
        $sessionId = !$request->getIsConsoleRequest()
            ? (Craft::$app->getSession()->getId() ?: '')
            : 'console_' . getmypid();
        try {
            $salt = Craft::$app->getConfig()->getGeneral()->securityKey ?? '';
        } catch (\Throwable) {
            $salt = '';
        }
        return hash('sha256', $salt . '|' . $sessionId);
    }

    /**
     * A time as the lock table stores it: 'HH:MM', or null when absent.
     *
     * Single-digit hours are zero-padded too — the public booking form
     * accepts '9:05:00' (see ValidationHelper::TIME_FORMAT_PATTERN), and a
     * bare prefix cut would turn that into the malformed bound '9:05:' that
     * string-compares wrong against every stored 'HH:MM' value.
     */
    public static function normalizeTime(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return $time;
        }

        return str_pad($parts[0], 2, '0', STR_PAD_LEFT) . ':' . str_pad(substr($parts[1], 0, 2), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Whether other customers' holds leave too few seats for this booking.
     *
     * This is the one entry point for slot-shaped hold checks: it resolves
     * the slot's remaining seats itself, so a caller can never regress to
     * the all-or-nothing hold by forgetting to pass a capacity — the exact
     * shape of bug #117.
     *
     * @param string $date Slot date (Y-m-d)
     * @param string $startTime Slot start time
     * @param string|null $endTime Slot end time, or null for a timeless booking (all-or-nothing hold)
     * @param int $serviceId
     * @param int|null $employeeId
     * @param int|null $locationId
     * @param int $quantity Seats the booking wants
     * @param string|null $excludeToken The caller's own hold
     */
    public function isSlotBlockedByHolds(string $date, string $startTime, ?string $endTime, int $serviceId, ?int $employeeId, ?int $locationId, int $quantity, ?string $excludeToken = null): bool
    {
        $capacity = $this->resolveSlotSeats($date, $startTime, $endTime, $serviceId, $employeeId);

        return $this->isLocked($date, $startTime, $serviceId, $employeeId, $endTime, $locationId, $excludeToken, $quantity, $capacity);
    }

    /**
     * Whether other customers' range holds leave too few seats for this
     * multi-day booking. See {@see isSlotBlockedByHolds()}.
     *
     * @param string $startDate Range start (Y-m-d)
     * @param string $endDate Range end (Y-m-d)
     * @param int $serviceId
     * @param int|null $employeeId
     * @param int|null $locationId
     * @param int $quantity Seats the booking wants
     * @param string|null $excludeToken The caller's own hold
     */
    public function isRangeBlockedByHolds(string $startDate, string $endDate, int $serviceId, ?int $employeeId, ?int $locationId, int $quantity, ?string $excludeToken = null): bool
    {
        $capacity = $this->resolveRangeSeats($startDate, $endDate, $serviceId, $employeeId, $locationId);

        return $this->isDateRangeLocked($startDate, $endDate, $serviceId, $employeeId, $locationId, $quantity, $capacity, $excludeToken);
    }

    /**
     * Minutes until the last blocking hold lapses — the honest retry hint
     * for the "temporarily reserved" refusal. The configured hold duration
     * is only a floor for NEW holds; the blocking hold may have been renewed
     * or may be about to expire, so its stored expiry is what counts.
     *
     * @param array $data The same shape createLock() takes (date, startTime, endTime, endDate, serviceId, employeeId, locationId)
     * @param int $fallbackMinutes Reported when no blocking hold is found (it may have lapsed since the refusal)
     * @param string|null $excludeToken The caller's own hold
     */
    public function getRetryAfterMinutes(array $data, int $fallbackMinutes, ?string $excludeToken = null): int
    {
        $endDate = $data['endDate'] ?? null;
        if ($endDate) {
            $query = $this->buildRangeLockQuery($data['date'], $endDate, (int)$data['serviceId'], $data['employeeId'] ?? null, $data['locationId'] ?? null, $excludeToken);
        } else {
            $query = $this->buildLockQuery($data['date'], (string)$data['startTime'], (int)$data['serviceId'], $data['employeeId'] ?? null, $data['endTime'] ?? null, $data['locationId'] ?? null);
            if ($excludeToken !== null && $excludeToken !== '') {
                $query->andWhere(['!=', 'token', $excludeToken]);
            }
        }

        $latestExpiry = $query->max('expiresAt');
        if (!$latestExpiry) {
            return max(1, $fallbackMinutes);
        }

        $secondsLeft = (new DateTime((string)$latestExpiry, new DateTimeZone('UTC')))->getTimestamp() - time();

        return max(1, (int)ceil($secondsLeft / 60));
    }

    /**
     * Remaining seats on a slot, resolved for the hold checks.
     *
     * @return int|null Seats, or null for an uncapped or timeless slot (all-or-nothing hold)
     */
    protected function resolveSlotSeats(string $date, string $startTime, ?string $endTime, int $serviceId, ?int $employeeId): ?int
    {
        // A timeless booking has no end time to count seats against.
        if ($endTime === null) {
            return null;
        }

        return Booked::getInstance()->getCapacity()->getAvailableCapacity($date, $startTime, $endTime, $employeeId, $serviceId);
    }

    /**
     * Remaining seats across a date range (tightest day), resolved for the
     * hold checks.
     *
     * @return int|null Seats, or null when the range is unconstrained
     */
    protected function resolveRangeSeats(string $startDate, string $endDate, int $serviceId, ?int $employeeId, ?int $locationId): ?int
    {
        return Booked::getInstance()->getMultiDayAvailability()->getRemainingCapacityForRange($startDate, $endDate, $serviceId, $employeeId, $locationId);
    }

    private function buildLockQuery(string $date, string $startTime, int $serviceId, ?int $employeeId, ?string $endTime, ?int $locationId): ActiveQuery
    {
        // Match the stored H:i format — see createLock() on why seconds break
        // the varchar comparisons below.
        $startTime = self::normalizeTime($startTime);
        $endTime = self::normalizeTime($endTime);

        $query = $this->getRecordQuery()
            ->where(['date' => $date, 'serviceId' => $serviceId])
            ->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))]);

        if ($employeeId !== null) {
            $query->andWhere(['or', ['employeeId' => $employeeId], ['employeeId' => null]]);
        }
        // When employeeId is null (any available), do NOT filter by employeeId
        // so we check ALL locks (employee-specific and employee-less) for this slot
        if ($locationId !== null) {
            $query->andWhere(['locationId' => $locationId]);
        }

        if ($endTime !== null) {
            // Overlap detection: match locks that overlap the requested time range.
            // Also match locks with null endTime that share the same startTime.
            $query->andWhere(['or',
                ['and', ['<', 'startTime', $endTime], ['>', 'endTime', $startTime]],
                ['and', ['endTime' => null], ['startTime' => $startTime]],
            ]);
        } else {
            $query->andWhere(['startTime' => $startTime]);
        }

        return $query;
    }

    public function isDateRangeLocked(string $startDate, string $endDate, int $serviceId, ?int $employeeId, ?int $locationId, int $quantity = 1, ?int $capacity = null, ?string $excludeToken = null): bool
    {
        $query = $this->buildRangeLockQuery($startDate, $endDate, $serviceId, $employeeId, $locationId, $excludeToken);

        // Holds block only when their PEAK per-day load leaves no room. A
        // global sum would charge holds that merely touch the range against
        // every one of its days — two holds on disjoint edges of a long
        // range would then refuse a customer the middle days can seat. And
        // as in isLocked(), zero held seats never refuse: full or
        // unschedulable ranges get their accurate message downstream.
        if ($capacity !== null) {
            /** @var SoftLockRecord[] $locks */
            $locks = $query->all();
            $peakHeld = $this->peakDailyHeldQuantity($locks, $startDate, $endDate);
            return $peakHeld > 0 && ($peakHeld + $quantity) > $capacity;
        }

        return $query->exists();
    }

    /**
     * The largest per-day sum of held seats across the days of a range.
     *
     * @param SoftLockRecord[] $locks Active range locks touching the range
     * @param string $startDate Range start (Y-m-d)
     * @param string $endDate Range end (Y-m-d)
     */
    private function peakDailyHeldQuantity(array $locks, string $startDate, string $endDate): int
    {
        $peak = 0;
        $current = new DateTime($startDate);
        $end = new DateTime($endDate);
        while ($current <= $end) {
            $day = $current->format('Y-m-d');
            $held = 0;
            foreach ($locks as $lock) {
                if ($lock->date <= $day && ($lock->endDate ?? $lock->date) >= $day) {
                    $held += max(1, (int)$lock->quantity);
                }
            }
            $peak = max($peak, $held);
            $current->modify('+1 day');
        }

        return $peak;
    }

    private function buildRangeLockQuery(string $startDate, string $endDate, int $serviceId, ?int $employeeId, ?int $locationId, ?string $excludeToken = null): ActiveQuery
    {
        $query = $this->getRecordQuery()
            ->where(['serviceId' => $serviceId])
            ->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))])
            ->andWhere(['not', ['endDate' => null]])
            ->andWhere(['<=', 'date', $endDate])
            ->andWhere(['>=', 'endDate', $startDate]);

        if ($employeeId !== null) {
            $query->andWhere(['or', ['employeeId' => $employeeId], ['employeeId' => null]]);
        }
        if ($locationId !== null) {
            $query->andWhere(['locationId' => $locationId]);
        }
        if ($excludeToken !== null) {
            $query->andWhere(['not', ['token' => $excludeToken]]);
        }

        return $query;
    }

    protected function createRecord()
    {
        return new SoftLockRecord();
    }

    protected function getRecordQuery(): ActiveQuery
    {
        return SoftLockRecord::find();
    }

    /** @return SoftLockRecord|null */
    public function getRecordByToken(string $token)
    {
        return SoftLockRecord::findOne(['token' => $token]);
    }

    protected function saveRecord($record): bool
    {
        return $record->save();
    }

    protected function deleteRecord($record): int
    {
        return $record->delete();
    }

    protected function deleteExpiredRecords(): int
    {
        return SoftLockRecord::deleteAll(['<=', 'expiresAt', Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))]);
    }

    protected function getMutex(): \yii\mutex\Mutex
    {
        return Booked::getInstance()->mutex->get();
    }
}
