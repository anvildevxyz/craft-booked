<?php

namespace anvildev\booked\controllers;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\BookingHelpersTrait;
use anvildev\booked\controllers\traits\HandlesExceptionsTrait;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\helpers\PaymentTokenHelper;
use anvildev\booked\records\PaymentRecord;
use anvildev\booked\services\PaymentService;
use Craft;
use craft\helpers\App;
use craft\web\Controller;
use craft\web\Response;

/**
 * Direct (Commerce-free) payment endpoints.
 *
 * `create` authorizes with the reservation's confirmation token (no id
 * enumeration), computes the amount server-side, creates the gateway payment,
 * and returns front-end config + a signed payment token. `confirm` is a UX poll
 * only — the reservation is confirmed by the verified webhook, never here. See
 * PRD §7.3.
 */
class PaymentController extends Controller
{
    use JsonResponseTrait;
    use HandlesExceptionsTrait;
    use BookingHelpersTrait;

    /** Per-reservation `create` attempts allowed per minute (IP-independent). */
    private const PAYMENT_CREATE_PER_RESERVATION_LIMIT = 5;

    protected array|bool|int $allowAnonymous = ['create', 'confirm', 'webhook'];

    public $enableCsrfValidation = true;

    public function beforeAction($action): bool
    {
        // The webhook is a server-to-server callback from the gateway; it carries
        // no CSRF token and is authenticated by its signature instead.
        if ($action->id === 'webhook') {
            $this->enableCsrfValidation = false;
        }
        return parent::beforeAction($action);
    }

    /**
     * Gateway webhook — the source of truth for payment status. Verifies the
     * signature (via the gateway adapter), then idempotently marks the payment
     * paid and confirms the reservation. Unverifiable events are dropped + logged.
     */
    public function actionWebhook(string $gateway): Response
    {
        $this->requirePostRequest();

        $adapter = Booked::getInstance()->getPaymentGateways()->getGateway($gateway);
        if (!$adapter) {
            return $this->asJson(['received' => false])->setStatusCode(404);
        }

        $event = $adapter->verifyWebhook(Craft::$app->request);
        if ($event === null) {
            Craft::warning("Dropped unverifiable {$gateway} webhook", __METHOD__);
            return $this->asJson(['received' => false])->setStatusCode(400);
        }

        // Idempotent delivery: gateways re-send events (retries, at-least-once).
        // Drop a re-delivered event before touching any state. The TTL covers the
        // gateway's retry window; the status/absolute-amount guards below are the
        // durable backstop if the cache is evicted.
        $cache = Craft::$app->getCache();
        $dedupeKey = "booked:webhook:{$gateway}:{$event->eventId}";
        if ($cache->exists($dedupeKey)) {
            return $this->asJson(['received' => true, 'duplicate' => true]);
        }

        $payments = Booked::getInstance()->getPayments();
        if ($event->externalId) {
            $record = PaymentRecord::findOne(['externalId' => $event->externalId, 'gateway' => $gateway]);
            if ($record) {
                if ($event->status === PaymentRecord::STATUS_PAID) {
                    $payments->handleVerifiedPayment($record);
                } elseif (
                    in_array($event->status, [PaymentRecord::STATUS_REFUNDED, PaymentRecord::STATUS_PARTIALLY_REFUNDED], true)
                    && $event->refundedAmount !== null
                ) {
                    // A refund issued outside Booked (e.g. the Stripe dashboard).
                    $payments->applyRefundSync($record, $event->refundedAmount);
                }
            }
        }

        // Mark processed only after handling succeeded — a thrown handler leaves
        // the key unset so the gateway's retry can reprocess.
        $cache->set($dedupeKey, true, 60 * 60 * 72);

        // Always 200 a verified event so the gateway stops retrying.
        return $this->asJson(['received' => true]);
    }

    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // Stricter, separate bucket from booking submission (per-IP).
        if (!$this->checkRateLimit('booked_payment_throttle', 20)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $request = Craft::$app->request;
        $reservationId = (int) $request->getRequiredBodyParam('reservationId');
        $token = (string) $request->getRequiredBodyParam('token');

        // Second, tighter bucket keyed by the reservation itself (IP-independent):
        // blunts token-guessing that rotates IPs to hammer a single reservation.
        // `checkRateLimit` folds the IP into its key, so count directly here.
        $resThrottleKey = "booked_payment_create_res_{$reservationId}";
        $resAttempts = (int) (Craft::$app->getCache()->get($resThrottleKey) ?: 0);
        if ($resAttempts >= self::PAYMENT_CREATE_PER_RESERVATION_LIMIT) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }
        Craft::$app->getCache()->set($resThrottleKey, $resAttempts + 1, 60);

        $reservation = ReservationFactory::findById($reservationId);
        if (!$reservation || !hash_equals($reservation->getConfirmationToken(), $token)) {
            Booked::getInstance()->getAudit()->logAuthFailure('invalid_payment_token', ['reservationId' => $reservationId]);
            return $this->jsonError(Craft::t('booked', 'booking.unauthorized'), statusCode: 403);
        }

        $gateway = Booked::getInstance()->getPaymentGateways()->getGateway('stripe');
        if (!$gateway) {
            return $this->jsonError(Craft::t('booked', 'payment.gatewayUnavailable'), statusCode: 503);
        }

        try {
            $result = Booked::getInstance()->getPayments()->createForReservation($reservation, $gateway);
        } catch (\Throwable $e) {
            Craft::error('Payment create failed: ' . $e->getMessage(), __METHOD__);
            return $this->jsonError(Craft::t('booked', 'payment.createFailed'));
        }

        $session = $result['session'];

        return $this->jsonSuccess('', [
            'paymentToken' => $result['token'],
            'externalId' => $session->externalId,
            'clientSecret' => $session->clientSecret,
            'redirectUrl' => $session->redirectUrl,
            'gateway' => $gateway->getHandle(),
            'config' => $session->frontendConfig,
        ]);
    }

    public function actionConfirm(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $token = (string) Craft::$app->request->getRequiredBodyParam('paymentToken');
        $key = (string) App::parseEnv(Craft::$app->getConfig()->getGeneral()->securityKey);
        $parts = PaymentTokenHelper::verify($token, $key);
        if ($parts === null) {
            return $this->jsonError(Craft::t('booked', 'booking.unauthorized'), statusCode: 403);
        }

        $payment = PaymentRecord::findOne(['id' => $parts['paymentId']]);
        if (!$payment) {
            return $this->jsonError(Craft::t('booked', 'errors.bookingNotFound'), statusCode: 404);
        }

        // The token binds to a reservation uid — confirm it matches this payment's.
        $reservation = ReservationFactory::findById((int) $payment->reservationId);
        if (!$reservation || $reservation->getUid() !== $parts['reservationUid']) {
            return $this->jsonError(Craft::t('booked', 'booking.unauthorized'), statusCode: 403);
        }

        $gateway = Booked::getInstance()->getPaymentGateways()->getGateway((string) $payment->gateway);
        if (!$gateway) {
            return $this->jsonError(Craft::t('booked', 'payment.gatewayUnavailable'), statusCode: 503);
        }

        try {
            $result = $gateway->confirmPayment((string) $payment->externalId);
        } catch (\Throwable $e) {
            Craft::error('Payment confirm failed: ' . $e->getMessage(), __METHOD__);
            return $this->jsonError(Craft::t('booked', 'payment.createFailed'));
        }

        $payments = Booked::getInstance()->getPayments();
        if ($result->paid) {
            // Server-side retrieval is a trusted confirmation source (queried
            // from the gateway, not client say-so). Route it through the SAME
            // idempotent path as the webhook so the reservation is confirmed by
            // whichever wins the race — never suppressed.
            $payments->handleVerifiedPayment($payment);
        } elseif (!PaymentService::isFinalized((string) $payment->status) && $payment->status !== $result->status) {
            // Reflect a non-terminal status for UX without finalizing the record.
            $payment->status = $result->status;
            $payment->save(false);
        }

        return $this->jsonSuccess('', [
            'status' => $result->status,
            'paid' => $result->paid,
        ]);
    }
}
