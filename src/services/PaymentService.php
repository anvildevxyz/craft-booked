<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\contracts\PaymentGatewayInterface;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\helpers\PaymentTokenHelper;
use anvildev\booked\models\Settings;
use anvildev\booked\payments\PaymentContext;
use anvildev\booked\records\PaymentRecord;
use Craft;
use craft\base\Component;
use craft\helpers\App;

/**
 * Orchestrates native (direct) payments: create a gateway payment for a
 * reservation, persist the record, and confirm status. Amounts are always
 * computed server-side (from the reservation total) in minor units — the client
 * never supplies an amount. See PRD §7.3.
 */
class PaymentService extends Component
{
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

    /** Confirm a pending reservation (direct mode) and fire the usual notifications. */
    private function confirmReservation(int $reservationId): void
    {
        $record = \anvildev\booked\records\ReservationRecord::findOne($reservationId);
        // Only a still-pending reservation may be confirmed. A late or replayed
        // webhook must NOT resurrect a reservation that was cancelled or expired
        // (nor re-fire notifications on an already-confirmed one).
        if (!$record || $record->status !== \anvildev\booked\records\ReservationRecord::STATUS_PENDING) {
            return;
        }
        $record->status = \anvildev\booked\records\ReservationRecord::STATUS_CONFIRMED;
        $record->save(false);

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
