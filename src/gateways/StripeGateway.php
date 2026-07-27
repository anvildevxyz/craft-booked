<?php

namespace anvildev\booked\gateways;

use anvildev\booked\Booked;
use anvildev\booked\contracts\PaymentGatewayInterface;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\models\Settings;
use anvildev\booked\payments\PaymentContext;
use anvildev\booked\payments\PaymentResult;
use anvildev\booked\payments\PaymentSession;
use anvildev\booked\payments\RefundResult;
use anvildev\booked\payments\WebhookEvent;
use anvildev\booked\records\PaymentRecord;
use craft\helpers\App;
use craft\web\Request;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe adapter — the launch gateway for direct (Commerce-free) payments.
 *
 * Uses Payment Intents + the Payment Element (SCA/3DS handled by Stripe).
 * Confirmation is webhook-driven ({@see verifyWebhook()}); {@see confirmPayment()}
 * is the server-side retrieval fallback. Amounts are in minor units throughout.
 * The `StripeClient` is injectable so it can be mocked in tests. See PRD §7.2.
 *
 * @since 1.4.0
 */
class StripeGateway implements PaymentGatewayInterface
{
    private ?StripeClient $client;

    public function __construct(?StripeClient $client = null)
    {
        $this->client = $client;
    }

    public function getHandle(): string
    {
        return 'stripe';
    }

    public function getDisplayName(): string
    {
        return 'Stripe';
    }

    public function createPayment(ReservationInterface $reservation, PaymentContext $context): PaymentSession
    {
        $intent = $this->client()->paymentIntents->create([
            'amount' => $context->amount,
            'currency' => strtolower($context->currency),
            'description' => $context->description,
            'metadata' => array_merge($context->metadata, [
                'reservationId' => $reservation->getId(),
            ]),
            'automatic_payment_methods' => ['enabled' => true],
        ], [
            // Idempotency: retried creates for the same reservation return the
            // same intent rather than double-charging. See PRD §7.7.
            'idempotency_key' => 'booked_pi_' . $reservation->getId(),
        ]);

        return new PaymentSession(
            $intent->id,
            self::mapStatus($intent->status),
            $intent->client_secret,
            null,
            $this->getFrontendConfig($reservation),
        );
    }

    public function confirmPayment(string $externalId): PaymentResult
    {
        $intent = $this->client()->paymentIntents->retrieve($externalId);
        $status = self::mapStatus($intent->status);

        return new PaymentResult(
            $status,
            $intent->id,
            (int) $intent->amount,
            $status === PaymentRecord::STATUS_PAID,
            $intent->toArray(),
        );
    }

    public function refund(PaymentRecord $payment, int $amount): RefundResult
    {
        try {
            $refund = $this->client()->refunds->create([
                'payment_intent' => $payment->externalId,
                'amount' => $amount,
            ], [
                'idempotency_key' => 'booked_re_' . $payment->id . '_' . $amount,
            ]);
        } catch (\Throwable $e) {
            return new RefundResult(false, 0, null, $e->getMessage());
        }

        return new RefundResult(
            $refund->status === 'succeeded' || $refund->status === 'pending',
            (int) $refund->amount,
            $refund->id,
            null,
            $refund->toArray(),
        );
    }

    public function verifyWebhook(Request $request): ?WebhookEvent
    {
        $secret = App::parseEnv($this->settings()->stripeWebhookSecret);
        $signature = $request->getHeaders()->get('stripe-signature');
        if (!$secret || !$signature) {
            return null;
        }

        try {
            $event = Webhook::constructEvent($request->getRawBody(), $signature, $secret);
        } catch (\Throwable) {
            // Unverifiable signature or bad payload — dropped + logged by caller.
            return null;
        }

        $object = $event->data->object ?? null;

        // Refund events (e.g. a refund issued in the Stripe dashboard): normalize
        // to the parent PaymentIntent + the absolute refunded total so the caller
        // can reconcile it against the local record. The charge object carries the
        // intent id and the running `amount_refunded`.
        if ($event->type === 'charge.refunded' && is_object($object)) {
            $intentId = $object->payment_intent ?? null;
            $refunded = isset($object->amount_refunded) ? (int) $object->amount_refunded : 0;
            $captured = isset($object->amount) ? (int) $object->amount : null;
            $status = ($captured !== null && $refunded >= $captured)
                ? PaymentRecord::STATUS_REFUNDED
                : PaymentRecord::STATUS_PARTIALLY_REFUNDED;

            return new WebhookEvent(
                $event->type,
                $event->id,
                is_string($intentId) ? $intentId : null,
                $status,
                $event->toArray(),
                $refunded,
            );
        }

        return new WebhookEvent(
            $event->type,
            $event->id,
            is_object($object) ? ($object->id ?? null) : null,
            is_object($object) && isset($object->status) ? self::mapStatus($object->status) : null,
            $event->toArray(),
        );
    }

    public function getFrontendConfig(ReservationInterface $reservation): array
    {
        return [
            'publishableKey' => App::parseEnv($this->settings()->stripePublishableKey),
        ];
    }

    public function supportsPartialRefunds(): bool
    {
        return true;
    }

    /** Map a Stripe PaymentIntent status to a {@see PaymentRecord} status. */
    public static function mapStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'succeeded' => PaymentRecord::STATUS_PAID,
            'requires_capture' => PaymentRecord::STATUS_AUTHORIZED,
            'canceled' => PaymentRecord::STATUS_FAILED,
            default => PaymentRecord::STATUS_PENDING,
        };
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient((string) App::parseEnv($this->settings()->stripeSecretKey));
    }

    private function settings(): Settings
    {
        return Booked::getInstance()->getSettings();
    }
}
