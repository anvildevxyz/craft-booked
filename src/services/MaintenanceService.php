<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\records\CalendarInviteRecord;
use anvildev\booked\records\OAuthStateTokenRecord;
use anvildev\booked\records\PaymentRecord;
use anvildev\booked\records\ReservationRecord;
use Craft;
use craft\base\Component;
use yii\db\Query;

/**
 * Runs periodic cleanup tasks for the booking system.
 *
 * Registered with Craft's garbage collection to automatically purge
 * expired soft locks, stale waitlist entries, old webhook logs,
 * orphaned pending Commerce reservations, expired OAuth state tokens,
 * and expired calendar invites.
 */
class MaintenanceService extends Component
{
    public function runAll(): array
    {
        return [
            'expiredSoftLocks' => $this->cleanupExpiredSoftLocks(),
            'expiredWaitlist' => $this->cleanupExpiredWaitlist(),
            'webhookLogs' => $this->cleanupOldWebhookLogs(),
            'stalePendingReservations' => $this->cleanupStalePendingReservations(),
            'stalePendingPayments' => $this->cleanupStalePendingPayments(),
            'expiredOAuthTokens' => $this->cleanupExpiredOAuthTokens(),
            'expiredCalendarInvites' => $this->cleanupExpiredCalendarInvites(),
        ];
    }

    public function cleanupExpiredSoftLocks(): int
    {
        try {
            return Booked::getInstance()->getSoftLock()->cleanupExpiredLocks();
        } catch (\Throwable $e) {
            Craft::error("Failed to cleanup soft locks: {$e->getMessage()}", __METHOD__);
            return 0;
        }
    }

    public function cleanupExpiredWaitlist(): int
    {
        try {
            return Booked::getInstance()->getWaitlist()->cleanupExpired();
        } catch (\Throwable $e) {
            Craft::error("Failed to cleanup waitlist: {$e->getMessage()}", __METHOD__);
            return 0;
        }
    }

    public function cleanupOldWebhookLogs(int $days = 30): int
    {
        try {
            $settings = Booked::getInstance()->getSettings();
            if (!$settings->webhooksEnabled || !$settings->webhookLogEnabled) {
                return 0;
            }
            return Booked::getInstance()->getWebhook()->cleanupOldLogs($days);
        } catch (\Throwable $e) {
            Craft::error("Failed to cleanup webhook logs: {$e->getMessage()}", __METHOD__);
            return 0;
        }
    }

    public function cleanupStalePendingReservations(?int $hours = null): int
    {
        $settings = Booked::getInstance()->getSettings();
        if (!$settings->canUseCommerce()) {
            return 0;
        }

        $hours ??= $settings->pendingCartExpirationHours;

        try {
            $cutoff = new \DateTime("-{$hours} hours");

            $rows = (new Query())
                ->select(['r.id AS reservationId', 'link.orderId'])
                ->from(['r' => '{{%booked_reservations}}'])
                ->innerJoin(['link' => '{{%booked_order_reservations}}'], '[[link.reservationId]] = [[r.id]]')
                ->where(['r.status' => ReservationRecord::STATUS_PENDING])
                ->andWhere(['<=', 'r.dateCreated', $cutoff->format('Y-m-d H:i:s')])
                ->all();

            if (!$rows) {
                return 0;
            }

            $cancelled = 0;

            foreach ($rows as $row) {
                $order = class_exists(\craft\commerce\elements\Order::class)
                    ? \craft\commerce\elements\Order::findOne($row['orderId'])
                    : null;

                if ($order === null) {
                    // Order was purged — cancel orphaned reservation and remove link
                    $this->cancelStaleReservation((int) $row['reservationId'], 'Linked Commerce order was purged');
                    $this->removeOrderLink((int) $row['reservationId']);
                    $cancelled++;
                // DateTime objects support direct comparison via >, <, == operators in PHP
                } elseif (!$order->isCompleted && (!$order->dateUpdated || $order->dateUpdated <= $cutoff)) {
                    // Cart is stale — cancel the reservation
                    $this->cancelStaleReservation((int) $row['reservationId'], "Commerce cart inactive for more than {$hours} hours");
                    $cancelled++;
                }
            }

            if ($cancelled > 0) {
                Craft::info("Cancelled {$cancelled} stale pending Commerce reservations", __METHOD__);
            }

            return $cancelled;
        } catch (\Throwable $e) {
            Craft::error("Failed to cleanup stale pending reservations: {$e->getMessage()}", __METHOD__);
            return 0;
        }
    }

    /**
     * Garbage-collect abandoned **direct-payment** bookings: reservations left
     * `pending` past the configured TTL whose Stripe checkout was never completed.
     * Cancelling them releases the held capacity. Complements
     * {@see cleanupStalePendingReservations} (which is Commerce-only) — this covers
     * the direct-mode path, where there is no Commerce order to key off.
     *
     * A reservation with a paid/refunded payment record is *never* cancelled, so a
     * webhook that lands right at the TTL boundary can't lose a booking the
     * customer actually paid for. No-op outside direct mode.
     */
    public function cleanupStalePendingPayments(?int $minutes = null): int
    {
        $settings = Booked::getInstance()->getSettings();
        if (!$settings->isDirectPayment()) {
            return 0;
        }

        $minutes = max(1, (int) ($minutes ?? $settings->pendingPaymentTtlMinutes));

        try {
            $cutoff = (new \DateTime("-{$minutes} minutes"))->format('Y-m-d H:i:s');

            // Reservations that HAVE been paid must be excluded even if their
            // confirm lost a race — never cancel a paid booking.
            $paidReservationIds = (new Query())
                ->select('reservationId')
                ->from('{{%booked_payments}}')
                ->where(['status' => [
                    PaymentRecord::STATUS_PAID,
                    PaymentRecord::STATUS_PARTIALLY_REFUNDED,
                    PaymentRecord::STATUS_REFUNDED,
                ]])
                ->column();

            $query = (new Query())
                ->select('id')
                ->from('{{%booked_reservations}}')
                ->where(['status' => ReservationRecord::STATUS_PENDING])
                ->andWhere(['<=', 'dateCreated', $cutoff]);
            if ($paidReservationIds) {
                $query->andWhere(['not in', 'id', $paidReservationIds]);
            }

            $cancelled = 0;
            foreach ($query->column() as $id) {
                $this->cancelStaleReservation((int) $id, "Direct payment not completed within {$minutes} minutes");
                $cancelled++;
            }

            return $cancelled;
        } catch (\Throwable $e) {
            Craft::error("Failed to cleanup stale pending payments: {$e->getMessage()}", __METHOD__);
            return 0;
        }
    }

    private function cancelStaleReservation(int $reservationId, string $reason): void
    {
        $existing = (new Query())
            ->select(['notes'])
            ->from('{{%booked_reservations}}')
            ->where(['id' => $reservationId])
            ->scalar();

        $reason = mb_substr(strip_tags($reason), 0, 500);

        $updatedNotes = $existing
            ? $existing . "\n---\n" . $reason
            : $reason;

        Craft::$app->db->createCommand()
            ->update(
                '{{%booked_reservations}}',
                ['status' => ReservationRecord::STATUS_CANCELLED, 'activeSlotKey' => null, 'notes' => $updatedNotes],
                ['id' => $reservationId],
            )
            ->execute();

        Booked::getInstance()->getAudit()->logCancellation($reservationId, 'system (maintenance)', $reason, 'service');
    }

    private function removeOrderLink(int $reservationId): void
    {
        Craft::$app->db->createCommand()
            ->delete('{{%booked_order_reservations}}', ['reservationId' => $reservationId])
            ->execute();
    }

    public function cleanupExpiredOAuthTokens(): int
    {
        try {
            return OAuthStateTokenRecord::cleanupExpired();
        } catch (\Throwable $e) {
            Craft::error("Failed to cleanup OAuth state tokens: {$e->getMessage()}", __METHOD__);
            return 0;
        }
    }

    public function cleanupExpiredCalendarInvites(): int
    {
        try {
            return CalendarInviteRecord::cleanupExpired();
        } catch (\Throwable $e) {
            Craft::error("Failed to cleanup calendar invites: {$e->getMessage()}", __METHOD__);
            return 0;
        }
    }

    public function getStats(): array
    {
        try {
            return ['expiredSoftLocks' => Booked::getInstance()->getSoftLock()->countExpiredLocks()];
        } catch (\Throwable) {
            return ['expiredSoftLocks' => 'N/A'];
        }
    }
}
