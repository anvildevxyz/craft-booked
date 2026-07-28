<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\contracts\PaymentGatewayInterface;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\events\PaymentRefundedEvent;
use anvildev\booked\helpers\PaymentTokenHelper;
use anvildev\booked\models\Settings;
use anvildev\booked\payments\PaymentContext;
use anvildev\booked\payments\RefundResult;
use anvildev\booked\records\PaymentRecord;
use Craft;
use craft\base\Component;
use craft\helpers\App;
use RuntimeException;

/**
 * Orchestrates native (direct) payments: create a gateway payment for a
 * reservation, persist the record, and confirm status. Amounts are always
 * computed server-side (from the reservation total) in minor units — the client
 * never supplies an amount. See PRD §7.3.
 */
class PaymentService extends Component
{
    /**
     * Raised after a direct payment is successfully refunded (full or partial).
     * @event PaymentRefundedEvent
     */
    public const EVENT_PAYMENT_REFUNDED = 'paymentRefunded';

    /**
     * Create a payment for a reservation through the given gateway. Persists a
     * pending {@see PaymentRecord} and returns it plus the gateway session and a
     * signed token for the follow-up confirm call.
     *
     * @return array{record: PaymentRecord, session: \anvildev\booked\payments\PaymentSession, token: string}
     */
    public function createForReservation(ReservationInterface $reservation, PaymentGatewayInterface $gateway): array
    {
        $settings = Booked::getInstance()->getSettings();
        $currency = $settings->defaultCurrency && $settings->defaultCurrency !== 'auto'
            ? $settings->defaultCurrency
            : 'USD';
        $amount = self::toMinorUnits($reservation->getTotalPrice(), $currency);

        $context = new PaymentContext(
            $amount,
            $currency,
            'Booking #' . $reservation->getId(),
            null,
            ['reservationId' => (string) $reservation->getId()],
        );

        $session = $gateway->createPayment($reservation, $context);

        // Reuse the record for this gateway intent: Stripe's per-reservation
        // idempotency key returns the same PaymentIntent (same externalId) on
        // retry/refresh, so a repeated create must not insert a duplicate row
        // (which would make the paid booking read as unpaid). Never clobber a
        // status the webhook has already advanced.
        $record = PaymentRecord::findOne(['externalId' => $session->externalId, 'gateway' => $gateway->getHandle()])
            ?? new PaymentRecord();
        $record->reservationId = $reservation->getId();
        $record->gateway = $gateway->getHandle();
        $record->externalId = $session->externalId;
        if (empty($record->status)) {
            $record->status = PaymentRecord::STATUS_PENDING;
        }
        $record->amount = $amount;
        $record->currency = $currency;
        if ($record->refundedAmount === null) {
            $record->refundedAmount = 0;
        }
        $record->save(false);

        $token = PaymentTokenHelper::sign((string) $reservation->getUid(), (int) $record->id, self::securityKey());

        return ['record' => $record, 'session' => $session, 'token' => $token];
    }

    /**
     * Handle a verified inbound webhook. Returns whether the event was applied.
     * Idempotent: a payment already marked paid is a no-op, so replays never
     * re-confirm a reservation. The gateway's signature verification (the
     * caller drops null events) is the trust boundary. See PRD §7.7.
     */
    public function handleVerifiedPayment(PaymentRecord $record): bool
    {
        if (self::isFinalized((string) $record->status)) {
            return false; // already handled — idempotent
        }
        $record->status = PaymentRecord::STATUS_PAID;
        $record->save(false);
        $this->confirmReservation((int) $record->reservationId);
        return true;
    }

    /** Whether a payment status is already terminal (paid/refunded). */
    public static function isFinalized(string $status): bool
    {
        return in_array($status, [
            PaymentRecord::STATUS_PAID,
            PaymentRecord::STATUS_REFUNDED,
            PaymentRecord::STATUS_PARTIALLY_REFUNDED,
        ], true);
    }

    /**
     * Refund a direct (Commerce-free) payment, honoring the reservation's refund
     * policy. `$amount` is in the currency's minor units; `null` refunds the
     * maximum the policy currently allows (capped at the remaining refundable).
     *
     * The refund policy is a hard ceiling: an explicit amount above it — or above
     * what's still refundable — is rejected *before* any gateway call, so the
     * customer is never over-refunded. Idempotent by construction: each call only
     * ever adds up to the remaining refundable, and a fully-refunded payment
     * refuses further refunds.
     *
     * On a gateway failure the record is left untouched and the failed
     * {@see RefundResult} is returned (caller surfaces `->error`). On success the
     * record's `refundedAmount`/`status` advance and {@see EVENT_PAYMENT_REFUNDED}
     * fires. Commerce-mode refunds go through {@see RefundService} instead.
     *
     * @param int|null $amount Minor units; null = policy-allowed maximum.
     * @throws RuntimeException on a guard violation. Its message is a translation
     *                          key (e.g. `payment.refundExceedsPolicy`); the caller
     *                          renders it with `Craft::t('booked', $e->getMessage())`.
     */
    public function refund(ReservationInterface $reservation, ?int $amount = null): RefundResult
    {
        $mutex = Craft::$app->getMutex();
        $mutexKey = 'booked:refund:' . $reservation->getId();
        if (!$mutex->acquire($mutexKey, 10)) {
            throw new RuntimeException('payment.refundBusy');
        }

        try {
            /** @var PaymentRecord|null $record */
            $record = PaymentRecord::find()
                ->where(['reservationId' => $reservation->getId()])
                ->orderBy(['dateCreated' => SORT_DESC])
                ->one();

            if (!$record || !in_array($record->status, [
                PaymentRecord::STATUS_PAID,
                PaymentRecord::STATUS_PARTIALLY_REFUNDED,
            ], true)) {
                throw new RuntimeException('payment.refundNoPayment');
            }

            $captured = (int) $record->amount;
            $alreadyRefunded = (int) ($record->refundedAmount ?? 0);
            $pct = Booked::getInstance()->getRefundPolicy()->calculateRefundPercentage($reservation);
            $requested = self::resolveRefundAmount($captured, $alreadyRefunded, $pct, $amount);

            $gateway = Booked::getInstance()->getPaymentGateways()->getGateway((string) $record->gateway);
            if (!$gateway) {
                throw new RuntimeException('payment.gatewayUnavailable');
            }

            $result = $gateway->refund($record, $requested);
            if (!$result->success) {
                return $result; // record untouched; caller surfaces $result->error
            }

            $totalRefunded = $alreadyRefunded + $result->refundedAmount;
            $record->refundedAmount = $totalRefunded;
            $record->status = $totalRefunded >= $captured
                ? PaymentRecord::STATUS_REFUNDED
                : PaymentRecord::STATUS_PARTIALLY_REFUNDED;
            $record->save(false);

            if ($this->hasEventHandlers(self::EVENT_PAYMENT_REFUNDED)) {
                $event = new PaymentRefundedEvent();
                $event->reservationId = (int) $record->reservationId;
                $event->record = $record;
                $event->amount = $result->refundedAmount;
                $event->totalRefunded = $totalRefunded;
                $this->trigger(self::EVENT_PAYMENT_REFUNDED, $event);
            }

            return $result;
        } finally {
            $mutex->release($mutexKey);
        }
    }

    /**
     * Reconcile a refund observed via webhook — e.g. one issued directly in the
     * gateway dashboard, which never went through {@see refund()}. Sets the
     * record's *absolute* refunded total + status. Idempotent: re-delivering the
     * same webhook writes the same value, so it never compounds.
     */
    public function applyRefundSync(PaymentRecord $record, int $refundedAmount): void
    {
        $captured = (int) $record->amount;
        $refundedAmount = max(0, min($refundedAmount, $captured));

        if ($refundedAmount <= (int) ($record->refundedAmount ?? 0)) {
            return;
        }

        $status = $refundedAmount >= $captured
            ? PaymentRecord::STATUS_REFUNDED
            : PaymentRecord::STATUS_PARTIALLY_REFUNDED;

        $record->refundedAmount = $refundedAmount;
        $record->status = $status;
        $record->save(false);

        if ($this->hasEventHandlers(self::EVENT_PAYMENT_REFUNDED)) {
            $event = new PaymentRefundedEvent();
            $event->reservationId = (int) $record->reservationId;
            $event->record = $record;
            $event->amount = $refundedAmount;
            $event->totalRefunded = $refundedAmount;
            $this->trigger(self::EVENT_PAYMENT_REFUNDED, $event);
        }
    }

    /** Confirm a pending reservation (direct mode) and fire the usual notifications. */
    private function confirmReservation(int $reservationId): void
    {
        $affected = Craft::$app->db->createCommand()
            ->update(
                '{{%booked_reservations}}',
                ['status' => \anvildev\booked\records\ReservationRecord::STATUS_CONFIRMED],
                ['id' => $reservationId, 'status' => \anvildev\booked\records\ReservationRecord::STATUS_PENDING],
            )
            ->execute();
        if ($affected < 1) {
            return;
        }

        try {
            $ns = Booked::getInstance()->bookingNotification;
            $ns->queueBookingEmail($reservationId, 'confirmation', null, 512);
            $ns->queueOwnerNotification($reservationId, 512);
            $ns->queueCalendarSync($reservationId);
            $reservation = \anvildev\booked\factories\ReservationFactory::findById($reservationId);
            if ($reservation) {
                $ns->queueSmsConfirmation($reservation);
            }
        } catch (\Throwable $e) {
            Craft::error("Failed to queue notifications for direct-paid reservation #{$reservationId}: " . $e->getMessage(), __METHOD__);
        }
    }

    // Reservation-level computed payment statuses (superset of PaymentRecord's).
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_FREE = 'free';

    /**
     * The reservation's payment status, resolved from whichever mode is active —
     * a Commerce order or the {@see PaymentRecord} table — so CP columns,
     * conditions, GraphQL and exports read one method with no per-mode branching.
     * See PRD §7.5.
     */
    public function getStatusForReservation(ReservationInterface $reservation): string
    {
        $mode = Booked::getInstance()->getSettings()->getPaymentMode();
        $total = $reservation->getTotalPrice();
        $recordStatus = null;
        $commerceOrderPaid = null;

        if ($mode === Settings::PAYMENT_MODE_DIRECT) {
            $record = PaymentRecord::find()
                ->where(['reservationId' => $reservation->getId()])
                ->orderBy(['dateCreated' => SORT_DESC])
                ->one();
            $recordStatus = $record?->status;
        } elseif ($mode === Settings::PAYMENT_MODE_COMMERCE) {
            $order = Booked::getInstance()->commerce->getOrderByReservationId((int) $reservation->getId());
            $commerceOrderPaid = $order ? $order->getIsPaid() : null;
        }

        return self::resolveStatus($mode, $total, $recordStatus, $commerceOrderPaid);
    }

    /**
     * Pure status resolution (no I/O), given the gathered inputs. A zero total is
     * always `free`; direct mode reflects the latest payment record (or `unpaid`);
     * commerce mode reflects the order's paid state.
     */
    public static function resolveStatus(string $mode, float $total, ?string $recordStatus, ?bool $commerceOrderPaid): string
    {
        if ($total <= 0.0) {
            return self::STATUS_FREE;
        }
        if ($mode === Settings::PAYMENT_MODE_DIRECT) {
            return $recordStatus ?? self::STATUS_UNPAID;
        }
        if ($mode === Settings::PAYMENT_MODE_COMMERCE) {
            if ($commerceOrderPaid === null) {
                return self::STATUS_UNPAID;
            }
            return $commerceOrderPaid ? PaymentRecord::STATUS_PAID : PaymentRecord::STATUS_PENDING;
        }
        return self::STATUS_FREE; // mode 'none'
    }

    /**
     * Pure refund-amount resolution (no I/O). Validates a requested refund against
     * the refund-policy ceiling (a percentage of the captured amount, net of prior
     * refunds) and the remaining refundable, returning the minor-unit amount to
     * send to the gateway. `$requested` null = the policy-allowed maximum.
     *
     * Mirrors {@see resolveStatus} — the I/O-free core that {@see refund} wraps —
     * so the money math is unit-testable without a DB or gateway.
     *
     * @throws RuntimeException with a translation-key message on any violation.
     */
    public static function resolveRefundAmount(int $captured, int $alreadyRefunded, int $policyPercent, ?int $requested): int
    {
        $remaining = $captured - $alreadyRefunded;
        if ($remaining <= 0) {
            throw new RuntimeException('payment.refundAlreadyFull');
        }
        $policyCeiling = max(0, (int) floor($captured * $policyPercent / 100) - $alreadyRefunded);

        $amount = $requested ?? min($remaining, $policyCeiling);
        if ($amount <= 0) {
            throw new RuntimeException('payment.refundPolicyZero');
        }
        if ($amount > $remaining) {
            throw new RuntimeException('payment.refundExceedsRemaining');
        }
        if ($amount > $policyCeiling) {
            throw new RuntimeException('payment.refundExceedsPolicy');
        }
        return $amount;
    }

    /**
     * Currencies Stripe treats as zero-decimal — their "minor unit" IS the major
     * unit, so amounts must NOT be multiplied by 100 (else a 100x overcharge).
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
        'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** Convert a decimal major-unit amount to integer minor units for the currency. */
    public static function toMinorUnits(float $amount, string $currency): int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }
        return (int) round($amount * 100);
    }

    /**
     * Convert integer minor units back to a decimal major-unit amount for the
     * currency — the inverse of {@see toMinorUnits}, used to render stored payment
     * amounts (which are always minor units) in reports and exports.
     */
    public static function fromMinorUnits(int $minorUnits, string $currency): float
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (float) $minorUnits;
        }
        return $minorUnits / 100;
    }

    private static function securityKey(): string
    {
        return (string) App::parseEnv(Craft::$app->getConfig()->getGeneral()->securityKey);
    }
}
