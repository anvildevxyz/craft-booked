<?php

namespace anvildev\booked\controllers;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\BookingHelpersTrait;
use anvildev\booked\controllers\traits\HandlesExceptionsTrait;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\Service;
use anvildev\booked\helpers\SiteHelper;
use anvildev\booked\models\forms\BookingForm;
use anvildev\booked\records\ReservationRecord;
use anvildev\booked\services\BookingSecurityService;
use anvildev\booked\services\BookingService;
use Craft;
use craft\web\Controller;
use craft\web\Response;

/**
 * Frontend booking creation with security checks and optional Commerce integration.
 */
class BookingController extends Controller
{
    use JsonResponseTrait;
    use HandlesExceptionsTrait;
    use BookingHelpersTrait;

    protected array|bool|int $allowAnonymous = ['create-booking'];
    public $enableCsrfValidation = true;

    private BookingService $bookingService;
    private BookingSecurityService $securityService;

    public function init(): void
    {
        parent::init();
        $this->bookingService = Booked::getInstance()->booking;
        $this->securityService = Booked::getInstance()->bookingSecurity;
    }

    public function actionCreateBooking(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->request;

        // Resolve site context for multi-site support (action URLs bypass Craft's site routing)
        SiteHelper::getSiteForRequest($request);

        $form = new BookingForm();
        $ipAddress = $request->userIP ?? null;

        // Populate form - support both internal and JS/frontend field names
        $form->userName = $request->getBodyParam('customerName') ?? $request->getBodyParam('userName');
        $form->userEmail = $request->getBodyParam('customerEmail') ?? $request->getBodyParam('userEmail');
        $form->userPhone = $request->getBodyParam('customerPhone') ?? $request->getBodyParam('userPhone');
        $form->userTimezone = $request->getBodyParam('userTimezone') ?: Craft::$app->getTimeZone();
        $form->bookingDate = $request->getBodyParam('date') ?? $request->getBodyParam('bookingDate');
        $form->endDate = $request->getBodyParam('endDate');
        if ($form->endDate && $form->bookingDate && $form->endDate < $form->bookingDate) {
            $form->endDate = null;
        }
        $form->startTime = $request->getBodyParam('time') ?? $request->getBodyParam('startTime');
        $form->endTime = $request->getBodyParam('endTime') ?? $request->getBodyParam('end_time');
        $form->notes = $request->getBodyParam('notes') ?? $request->getBodyParam('customerNotes');
        $form->serviceId = $request->getBodyParam('serviceId') ? (int)$request->getBodyParam('serviceId') : null;
        $form->employeeId = $request->getBodyParam('employeeId') ? (int)$request->getBodyParam('employeeId') : null;
        $form->locationId = $request->getBodyParam('locationId') ? (int)$request->getBodyParam('locationId') : null;
        $form->quantity = $request->getBodyParam('quantity') ? (int)$request->getBodyParam('quantity') : 1;
        $form->eventDateId = $request->getBodyParam('eventDateId') ? (int)$request->getBodyParam('eventDateId') : null;

        $honeypotFieldName = $this->securityService->getHoneypotFieldName();
        $honeypotValue = $honeypotFieldName ? $request->getBodyParam($honeypotFieldName) : null;
        if ($honeypotFieldName) {
            $form->honeypot = $honeypotValue;
        }

        $form->captchaToken = $request->getBodyParam('captchaToken');
        $form->smsEnabled = Booked::getInstance()->getSettings()->smsEnabled;

        $securityResult = $this->securityService->validateRequest($ipAddress, $form->captchaToken, $honeypotValue);
        if (!$securityResult['valid']) {
            // Honeypot: return fake success so bots think the submission worked
            if (($securityResult['errorType'] ?? null) === BookingSecurityService::RESULT_SPAM_DETECTED) {
                if ($request->getAcceptsJson()) {
                    return $this->asJson(['success' => true, 'message' => Craft::t('booked', 'booking.confirmed')]);
                }
                Craft::$app->session->setNotice(Craft::t('booked', 'booking.confirmed'));
                return $this->redirectToPostedUrl();
            }

            if ($request->getAcceptsJson()) {
                $errorType = $securityResult['errorType'] ?? null;
                $isRateLimit = in_array($errorType, [BookingSecurityService::RESULT_RATE_LIMITED, BookingSecurityService::RESULT_IP_BLOCKED], true);
                return $this->jsonError($securityResult['error'], statusCode: $isRateLimit ? 429 : 200);
            }
            Craft::$app->session->setError($securityResult['error']);
            return $this->redirectToPostedUrl();
        }

        $extrasParam = $request->getBodyParam('extras');
        if (is_array($extrasParam)) {
            $form->extras = $extrasParam;
        }

        $softLockToken = $request->getBodyParam('softLockToken');

        // Auto-calculate end time from service duration + extras duration
        if (!$form->eventDateId && !$form->endTime && $form->startTime && $form->serviceId && $form->bookingDate && !$form->endDate) {
            $service = Service::findOne($form->serviceId);
            if ($service) {
                try {
                    $totalDuration = $service->duration;
                    if (!empty($form->extras) && is_array($form->extras)) {
                        $totalDuration += Booked::getInstance()->serviceExtra->calculateExtrasDuration($form->extras);
                    }
                    $start = new \DateTime($form->bookingDate . ' ' . $form->startTime);
                    $form->endTime = (clone $start)->add(new \DateInterval('PT' . $totalDuration . 'M'))->format('H:i');
                } catch (\Throwable $e) {
                    Craft::error("Error calculating end time: " . $e->getMessage(), __METHOD__);
                }
            }
        }

        if (!$form->validate()) {
            Craft::error("Booking validation failed: " . json_encode($form->getErrors()) . " | serviceId: {$form->serviceId}, employeeId: {$form->employeeId}, date: " . ($form->bookingDate ?? ''), __METHOD__);
            if ($request->getAcceptsJson()) {
                return $this->jsonError(Craft::t('booked', 'booking.validationError'), 'validation', $form->getErrors());
            }
            Craft::$app->session->setError(Craft::t('booked', 'booking.validateInput'));
            return $this->redirectToPostedUrl($form);
        }

        $data = $form->getReservationData();
        if ($softLockToken) {
            $data['softLockToken'] = $softLockToken;
        }

        // Calculate total price (service + extras + event date)
        $settings = Booked::getInstance()->getSettings();
        $service = $form->serviceId ? Service::findOne($form->serviceId) : null;
        $totalPrice = $service ? (float)$service->price : 0;

        if (!empty($form->extras) && is_array($form->extras)) {
            $serviceExtraService = Booked::getInstance()->serviceExtra;
            foreach ($form->extras as $extraId => $qty) {
                $extra = $serviceExtraService->getExtraById((int)$extraId);
                if ($extra && (int)$qty > 0) {
                    $totalPrice += (float)$extra->price * (int)$qty;
                }
            }
        }

        if ($eventDateId = $data['eventDateId'] ?? null) {
            $eventDate = \anvildev\booked\elements\EventDate::find()->siteId('*')->id($eventDateId)->one();
            if ($eventDate && $eventDate->price) {
                $totalPrice += (float)$eventDate->price * $form->quantity;
            }
        }

        $useCommerce = $settings->canUseCommerce() && $totalPrice > 0;
        $addToCartOnly = $request->getBodyParam('addToCart') === '1';

        if ($useCommerce) {
            $data['status'] = ReservationRecord::STATUS_PENDING;
        }

        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser) {
            $data['userId'] = $currentUser->id;
        }

        try {
            $reservation = $this->bookingService->createReservation($data);

            if ($useCommerce) {
                $commerceService = Booked::getInstance()->getCommerce();
                $addedToCart = $commerceService->addReservationToCart($reservation);

                if (!$addedToCart) {
                    Craft::error("Failed to add reservation #{$reservation->getId()} to Commerce cart", __METHOD__);
                }

                $cart = \craft\commerce\Plugin::getInstance()->getCarts()->getCart();
                $checkoutUrl = \craft\helpers\UrlHelper::siteUrl($settings->commerceCheckoutUrl);
                $cartUrl = \craft\helpers\UrlHelper::siteUrl($settings->commerceCartUrl);
                $redirectUrl = $addToCartOnly ? $cartUrl : $checkoutUrl;

                if ($request->getAcceptsJson()) {
                    return $this->jsonSuccess(Craft::t('booked', 'booking.addedToCart'), [
                        'reservation' => [
                            'id' => $reservation->getId(),
                            'formattedDateTime' => $reservation->getFormattedDateTime(),
                            'status' => $reservation->getStatusLabel(),
                            'price' => $reservation->getTotalPrice(),
                        ],
                        'commerce' => [
                            'addedToCart' => $addedToCart,
                            'cartUrl' => $cartUrl,
                            'checkoutUrl' => $checkoutUrl,
                            'cartItemCount' => $cart ? count($cart->lineItems) : 0,
                        ],
                        'redirectToCheckout' => !$addToCartOnly,
                        'redirectUrl' => $redirectUrl,
                    ]);
                }
                Craft::$app->session->setNotice(Craft::t('booked', 'booking.addedToCart'));
                return $this->redirect($redirectUrl);
            }

            if ($request->getAcceptsJson()) {
                return $this->jsonSuccess(Craft::t('booked', 'booking.created'), [
                    'reservation' => [
                        'id' => $reservation->getId(),
                        'formattedDateTime' => $reservation->getFormattedDateTime(),
                        'status' => $reservation->getStatusLabel(),
                    ],
                ]);
            }
            Craft::$app->session->setNotice(Craft::t('booked', 'booking.confirmed'));
            return $this->redirectToPostedUrl();
        } catch (\Throwable $e) {
            return $this->handleException($e, $form);
        }
    }
}
