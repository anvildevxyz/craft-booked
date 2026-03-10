<?php

namespace anvildev\booked\controllers;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\controllers\traits\BookingHelpersTrait;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\helpers\DateHelper;
use anvildev\booked\helpers\IcsHelper;
use anvildev\booked\services\AvailabilityService;
use anvildev\booked\services\BookingService;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

/**
 * Frontend management of existing bookings: token-based lookup, cancellation,
 * rescheduling, ICS download, and customer portal.
 */
class BookingManagementController extends Controller
{
    use JsonResponseTrait;
    use BookingHelpersTrait;

    protected array|bool|int $allowAnonymous = [
        'manage-booking',
        'cancel-booking-by-token',
        'download-ics',
        'reduce-quantity',
        'increase-quantity',
    ];

    public $enableCsrfValidation = true;

    private BookingService $bookingService;
    private AvailabilityService $availabilityService;

    public function init(): void
    {
        parent::init();
        $this->bookingService = Booked::getInstance()->booking;
        $this->availabilityService = Booked::getInstance()->availability;
    }

    /**
     * Cancel a booking by ID (requires confirmation token to prevent IDOR)
     */
    public function actionCancelBooking(): Response
    {
        $this->requirePostRequest();

        $id = (int) Craft::$app->request->getRequiredBodyParam('id');
        $token = (string) Craft::$app->request->getRequiredBodyParam('token');
        $reason = substr(strip_tags(Craft::$app->request->getBodyParam('reason', '')), 0, 500);

        $reservation = ReservationFactory::find()->id($id)->one();

        if (!$reservation || !hash_equals($reservation->getConfirmationToken(), $token)) {
            Craft::warning("Unauthorized booking cancellation attempt for ID: {$id}", __METHOD__);
            Booked::getInstance()->getAudit()->logAuthFailure('invalid_cancel_token', ['reservationId' => $id]);
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->jsonError(Craft::t('booked', 'booking.unauthorized'));
            }
            throw new \yii\web\ForbiddenHttpException(Craft::t('booked', 'booking.unauthorized'));
        }

        $success = $this->bookingService->cancelReservation($id, $reason);

        if (Craft::$app->request->getAcceptsJson()) {
            return $success
                ? $this->jsonSuccess(Craft::t('booked', 'booking.cancelSuccess'))
                : $this->jsonError(Craft::t('booked', 'booking.cancelFailed'));
        }

        if ($success) {
            Craft::$app->session->setNotice(Craft::t('booked', 'booking.cancelled'));
        } else {
            Craft::$app->session->setError(Craft::t('booked', 'booking.cancelFailed'));
        }
        return $this->redirectToPostedUrl();
    }

    /**
     * View/manage booking details by token. Handles cancel and reschedule POST actions.
     * URL: /booking/manage/{token} or ?token={token}
     */
    public function actionManageBooking(?string $token = null): Response
    {
        // Accept token from route param or query string
        if ($token === null) {
            $token = Craft::$app->request->getQueryParam('token');
        }

        if (!$token) {
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->jsonError(Craft::t('booked', 'errors.bookingNotFound'));
            }
            throw new NotFoundHttpException(Craft::t('booked', 'errors.bookingNotFound'));
        }

        $reservation = ReservationFactory::findByToken($token);
        if (!$reservation) {
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->jsonError(Craft::t('booked', 'errors.bookingNotFound'));
            }
            throw new NotFoundHttpException(Craft::t('booked', 'errors.bookingNotFound'));
        }

        if (Craft::$app->request->getIsPost()) {
            $action = Craft::$app->request->getBodyParam('action');
            if ($action === 'cancel') {
                return $this->handleCancelAction($reservation);
            }
            if ($action === 'reschedule') {
                return $this->handleRescheduleAction($reservation);
            }
        }

        Craft::$app->getResponse()->getHeaders()->set('Referrer-Policy', 'no-referrer');

        if (Craft::$app->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'id' => $reservation->getId(),
                'status' => $reservation->getStatus(),
                'statusLabel' => $reservation->getStatusLabel(),
                'customerName' => $reservation->getUserName(),
                'customerEmail' => $reservation->getUserEmail(),
                'customerPhone' => $reservation->getUserPhone(),
                'bookingDate' => $reservation->getBookingDate(),
                'startTime' => $reservation->getStartTime(),
                'endTime' => $reservation->getEndTime(),
                'quantity' => $reservation->getQuantity(),
                'notes' => $reservation->getNotes(),
                'formattedDateTime' => $reservation->getFormattedDateTime(),
                'canCancel' => $reservation->canBeCancelled(),
                'isPast' => $this->isBookingPast($reservation),
                'isEventBooking' => $reservation->getEventDateId() !== null,
                'eventDateId' => $reservation->getEventDateId(),
                'serviceName' => $reservation->getService()?->title,
                'employeeName' => $reservation->getEmployee()?->title,
                'locationName' => $reservation->getLocation()?->title,
            ]);
        }

        return $this->renderTemplate('booked/manage-booking', [
            'reservation' => $reservation,
            'canCancel' => $reservation->canBeCancelled(),
            'isPast' => $this->isBookingPast($reservation),
        ]);
    }

    /**
     * Customer portal: all bookings for logged-in user
     */
    public function actionMyBookings(): Response
    {
        $this->requireLogin();

        $bookings = ReservationFactory::find()
            ->forCurrentUser()
            ->orderBy(['bookingDate' => SORT_DESC, 'startTime' => SORT_DESC])
            ->all();

        $now = new \DateTime();
        $upcomingBookings = [];
        $pastBookings = [];

        foreach ($bookings as $booking) {
            $bookingDateTime = DateHelper::parseDateTime($booking->getBookingDate(), $booking->getStartTime());
            if ($bookingDateTime && $bookingDateTime->getTimestamp() >= $now->getTimestamp()) {
                $upcomingBookings[] = $booking;
            } else {
                $pastBookings[] = $booking;
            }
        }

        return $this->renderTemplate('booked/frontend/my-bookings', [
            'user' => Craft::$app->getUser()->getIdentity(),
            'upcomingBookings' => $upcomingBookings,
            'pastBookings' => $pastBookings,
            'allBookings' => $bookings,
        ]);
    }

    /**
     * Direct URL cancellation by token
     * URL: /booking/cancel/{token}
     */
    public function actionCancelBookingByToken(string $token): Response
    {
        $reservation = ReservationFactory::findByToken($token);
        if (!$reservation) {
            throw new NotFoundHttpException(Craft::t('booked', 'errors.bookingNotFound'));
        }

        if (!$reservation->canBeCancelled()) {
            Craft::$app->session->setError(Craft::t('booked', 'booking.cannotCancel'));

            if (Craft::$app->request->getAcceptsJson()) {
                return $this->jsonError(Craft::t('booked', 'booking.cannotCancel'));
            }

            Craft::$app->getResponse()->getHeaders()->set('Referrer-Policy', 'no-referrer');
            return $this->renderTemplate('booked/manage-booking', [
                'reservation' => $reservation,
                'canCancel' => false,
                'isPast' => $this->isBookingPast($reservation),
            ]);
        }

        if (!Craft::$app->request->getIsPost()) {
            Craft::$app->getResponse()->getHeaders()->set('Referrer-Policy', 'no-referrer');
            return $this->renderTemplate('booked/cancel-confirmation', [
                'reservation' => $reservation,
            ]);
        }

        $reason = substr(strip_tags(Craft::$app->request->getBodyParam('reason', 'Cancelled by user')), 0, 500);
        $success = $this->bookingService->cancelReservation($reservation->getId(), $reason);

        if ($success) {
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->jsonSuccess(Craft::t('booked', 'booking.cancelSuccess'));
            }
            Craft::$app->session->setNotice(Craft::t('booked', 'booking.cancelSuccess'));
            return $this->renderTemplate('booked/cancelled', ['reservation' => $reservation]);
        }

        if (Craft::$app->request->getAcceptsJson()) {
            return $this->jsonError(Craft::t('booked', 'booking.cancelFailed'));
        }
        Craft::$app->session->setError(Craft::t('booked', 'booking.failedToCancel'));
        return $this->redirect($reservation->getManagementUrl());
    }

    public function actionDownloadIcs(string $token): Response
    {
        $reservation = ReservationFactory::findByToken($token);
        if (!$reservation) {
            throw new NotFoundHttpException(Craft::t('booked', 'errors.bookingNotFound'));
        }

        $response = Craft::$app->getResponse();
        $response->content = IcsHelper::generate($reservation);
        $response->setDownloadHeaders('booking-' . $reservation->getId() . '.ics', 'text/calendar; charset=utf-8');
        return $response;
    }

    public function actionReduceQuantity(): Response
    {
        $this->requirePostRequest();

        $id = (int) Craft::$app->request->getRequiredBodyParam('id');
        $token = (string) Craft::$app->request->getRequiredBodyParam('token');
        $reduceBy = max(1, min(10000, (int) Craft::$app->request->getRequiredBodyParam('reduceBy')));
        $reason = substr(strip_tags(Craft::$app->request->getBodyParam('reason', '')), 0, 500);

        $reservation = ReservationFactory::find()->id($id)->one();

        if (!$reservation || !hash_equals($reservation->getConfirmationToken(), $token)) {
            Craft::warning("Unauthorized quantity reduction attempt for ID: {$id}", __METHOD__);
            Booked::getInstance()->getAudit()->logAuthFailure('invalid_reduce_token', ['reservationId' => $id]);
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->jsonError(Craft::t('booked', 'booking.unauthorized'));
            }
            throw new \yii\web\ForbiddenHttpException(Craft::t('booked', 'booking.unauthorized'));
        }

        $success = $this->bookingService->reduceQuantity($id, $reduceBy, $reason);

        if (Craft::$app->request->getAcceptsJson()) {
            return $success
                ? $this->jsonSuccess(Craft::t('booked', 'booking.quantityReduceSuccess'))
                : $this->jsonError(Craft::t('booked', 'booking.quantityReduceFailed'));
        }

        if ($success) {
            Craft::$app->session->setNotice(Craft::t('booked', 'booking.quantityReduceSuccess'));
        } else {
            Craft::$app->session->setError(Craft::t('booked', 'booking.quantityReduceFailed'));
        }
        return $this->redirectToPostedUrl();
    }

    public function actionIncreaseQuantity(): Response
    {
        $this->requirePostRequest();

        $id = (int) Craft::$app->request->getRequiredBodyParam('id');
        $token = (string) Craft::$app->request->getRequiredBodyParam('token');
        $increaseBy = max(1, min(10000, (int) Craft::$app->request->getRequiredBodyParam('increaseBy')));

        $reservation = ReservationFactory::find()->id($id)->one();

        if (!$reservation || !hash_equals($reservation->getConfirmationToken(), $token)) {
            Craft::warning("Unauthorized quantity increase attempt for ID: {$id}", __METHOD__);
            Booked::getInstance()->getAudit()->logAuthFailure('invalid_increase_token', ['reservationId' => $id]);
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->jsonError(Craft::t('booked', 'booking.unauthorized'));
            }
            throw new \yii\web\ForbiddenHttpException(Craft::t('booked', 'booking.unauthorized'));
        }

        try {
            $success = $this->bookingService->increaseQuantity($id, $increaseBy);
        } catch (\anvildev\booked\exceptions\BookingException $e) {
            if (Craft::$app->request->getAcceptsJson()) {
                return $this->jsonError($e->getMessage());
            }
            Craft::$app->session->setError($e->getMessage());
            return $this->redirectToPostedUrl();
        }

        if (Craft::$app->request->getAcceptsJson()) {
            return $success
                ? $this->jsonSuccess(Craft::t('booked', 'booking.quantityIncreaseSuccess'))
                : $this->jsonError(Craft::t('booked', 'booking.quantityIncreaseFailed'));
        }

        if ($success) {
            Craft::$app->session->setNotice(Craft::t('booked', 'booking.quantityIncreaseSuccess'));
        } else {
            Craft::$app->session->setError(Craft::t('booked', 'booking.quantityIncreaseFailed'));
        }
        return $this->redirectToPostedUrl();
    }

    private function handleCancelAction(ReservationInterface $reservation): Response
    {
        $isJson = Craft::$app->getRequest()->getAcceptsJson();

        if (!$reservation->canBeCancelled()) {
            if ($isJson) {
                return $this->jsonError(Craft::t('booked', 'booking.cannotCancel'));
            }
            Craft::$app->session->setError(Craft::t('booked', 'booking.cannotCancel'));
            return $this->redirectToPostedUrl();
        }

        $reason = substr(strip_tags(Craft::$app->request->getBodyParam('reason', Craft::t('booked', 'booking.cancelReasonDefault'))), 0, 500);
        $success = $this->bookingService->cancelReservation($reservation->getId(), $reason);

        if ($isJson) {
            return $success
                ? $this->jsonSuccess(Craft::t('booked', 'booking.cancelSuccessShort'))
                : $this->jsonError(Craft::t('booked', 'booking.failedToCancel'));
        }

        if ($success) {
            Craft::$app->session->setNotice(Craft::t('booked', 'booking.cancelSuccessShort'));
        } else {
            Craft::$app->session->setError(Craft::t('booked', 'booking.failedToCancel'));
        }
        return $this->redirectToPostedUrl();
    }

    private function handleRescheduleAction(ReservationInterface $reservation): Response
    {
        $isJson = Craft::$app->getRequest()->getAcceptsJson();

        // Guard: past bookings
        if ($this->isBookingPast($reservation)) {
            $msg = Craft::t('booked', 'booking.cannotReschedulePast');
            return $isJson ? $this->jsonError($msg) : $this->_redirectWithError($msg);
        }

        // Guard: only active bookings can be rescheduled
        $status = $reservation->getStatus();
        if (in_array($status, ['cancelled', 'completed'], true)) {
            $msg = Craft::t('booked', 'booking.cannotRescheduleStatus');
            return $isJson ? $this->jsonError($msg) : $this->_redirectWithError($msg);
        }

        $newDate = Craft::$app->request->getBodyParam('newDate');
        $newStartTime = Craft::$app->request->getBodyParam('newStartTime');
        $newEndTime = Craft::$app->request->getBodyParam('newEndTime');

        // Validate date format
        if (!$newDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
            $msg = Craft::t('booked', 'booking.invalidDateFormat');
            return $isJson ? $this->jsonError($msg) : $this->_redirectWithError($msg);
        }

        // Validate time formats
        if (!$newStartTime || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $newStartTime)) {
            $msg = Craft::t('booked', 'booking.invalidTimeFormat');
            return $isJson ? $this->jsonError($msg) : $this->_redirectWithError($msg);
        }
        if (!$newEndTime || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $newEndTime)) {
            $msg = Craft::t('booked', 'booking.invalidTimeFormat');
            return $isJson ? $this->jsonError($msg) : $this->_redirectWithError($msg);
        }

        if (!$this->availabilityService->isSlotAvailable(
            $newDate, $newStartTime, $newEndTime,
            $reservation->getEmployeeId(), $reservation->getLocationId(),
            $reservation->getServiceId(), $reservation->getQuantity()
        )) {
            $msg = Craft::t('booked', 'booking.slotNotAvailable');
            return $isJson ? $this->jsonError($msg) : $this->_redirectWithError($msg);
        }

        try {
            $updated = $this->bookingService->updateReservation($reservation->getId(), [
                'bookingDate' => $newDate,
                'startTime' => $newStartTime,
                'endTime' => $newEndTime,
            ]);
        } catch (\anvildev\booked\exceptions\BookingException $e) {
            $msg = $e->getMessage();
            return $isJson ? $this->jsonError($msg) : $this->_redirectWithError($msg);
        }

        if ($isJson) {
            return $this->jsonSuccess(Craft::t('booked', 'booking.rescheduleSuccess'), [
                'reservation' => [
                    'formattedDateTime' => $updated->getFormattedDateTime(),
                    'status' => $updated->getStatusLabel(),
                ],
            ]);
        }

        Craft::$app->session->setNotice(Craft::t('booked', 'booking.rescheduleSuccess'));
        return $this->redirectToPostedUrl();
    }

    private function _redirectWithError(string $msg): Response
    {
        Craft::$app->session->setError($msg);
        return $this->redirectToPostedUrl();
    }

    private function isBookingPast(ReservationInterface $reservation): bool
    {
        $bookingDateTime = DateHelper::parseDateTime($reservation->getBookingDate(), $reservation->getStartTime());
        return $bookingDateTime && $bookingDateTime->getTimestamp() < (new \DateTime())->getTimestamp();
    }
}
