<?php

namespace anvildev\booked\controllers;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\BookingHelpersTrait;
use anvildev\booked\controllers\traits\HandlesExceptionsTrait;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\helpers\PaymentTokenHelper;
use anvildev\booked\records\PaymentRecord;
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

        // Only payment-success events advance state; others are acked and ignored.
        if ($event->status === PaymentRecord::STATUS_PAID && $event->externalId) {
            $record = PaymentRecord::findOne(['externalId' => $event->externalId, 'gateway' => $gateway]);
            if ($record) {
                Booked::getInstance()->getPayments()->handleVerifiedPayment($record);
            }
        }

        // Always 200 a verified event so the gateway stops retrying.
        return $this->asJson(['received' => true]);
    }

    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // Stricter, separate bucket from booking submission.
        if (!$this->checkRateLimit('booked_payment_throttle', 20)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $request = Craft::$app->request;
        $reservationId = (int) $request->getRequiredBodyParam('reservationId');
        $token = (string) $request->getRequiredBodyParam('token');

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

        $result = $gateway->confirmPayment((string) $payment->externalId);
        // Reflect the gateway's status on the record for UX; reservation
        // confirmation is webhook-driven (source of truth), NOT done here.
        if ($payment->status !== $result->status) {
            $payment->status = $result->status;
            $payment->save(false);
        }

        return $this->jsonSuccess('', [
            'status' => $result->status,
            'paid' => $result->paid,
        ]);
    }
}
