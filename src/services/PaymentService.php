<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\contracts\PaymentGatewayInterface;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\helpers\PaymentTokenHelper;
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
        $amount = self::toMinorUnits($reservation->getTotalPrice());

        $context = new PaymentContext(
            $amount,
            $currency,
            'Booking #' . $reservation->getId(),
            null,
            ['reservationId' => (string) $reservation->getId()],
        );

        $session = $gateway->createPayment($reservation, $context);

        $record = new PaymentRecord();
        $record->reservationId = $reservation->getId();
        $record->gateway = $gateway->getHandle();
        $record->externalId = $session->externalId;
        $record->status = PaymentRecord::STATUS_PENDING;
        $record->amount = $amount;
        $record->currency = $currency;
        $record->refundedAmount = 0;
        $record->save(false);

        $token = PaymentTokenHelper::sign((string) $reservation->getUid(), (int) $record->id, self::securityKey());

        return ['record' => $record, 'session' => $session, 'token' => $token];
    }

    /** Convert a decimal major-unit amount to integer minor units (assumes 2-decimal currency). */
    public static function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private static function securityKey(): string
    {
        return (string) App::parseEnv(Craft::$app->getConfig()->getGeneral()->securityKey);
    }
}
