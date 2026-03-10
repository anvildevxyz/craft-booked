<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\HandlesExceptionsTrait;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\EventDate;
use anvildev\booked\helpers\RefundTierHelper;
use Craft;
use craft\enums\PropagationMethod;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

class EventDatesController extends Controller
{
    use JsonResponseTrait;
    use HandlesExceptionsTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('booked-manageEvents');
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('booked/event-dates/_index', [
            'title' => Craft::t('booked', 'titles.eventDates'),
        ]);
    }

    public function actionNew(): Response
    {
        return $this->renderTemplate('booked/event-dates/_edit', [
            'eventDate' => null,
            'title' => Craft::t('booked', 'titles.newEventDate'),
        ]);
    }

    public function actionEdit(int $id): Response
    {
        $eventDate = EventDate::find()->siteId('*')->id($id)->one()
            ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.eventDateNotFound'));

        return $this->renderTemplate('booked/event-dates/_edit', [
            'eventDate' => $eventDate,
            'title' => Craft::t('booked', 'titles.editEventDate'),
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $id = $request->getBodyParam('id') ?: $request->getBodyParam('elementId');

        try {
            /** @var EventDate $eventDate */
            $eventDate = $id
                ? (EventDate::find()->siteId('*')->id($id)->one() ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.eventDateNotFound')))
                : new EventDate();

            // Event date (required)
            $eventDate->eventDate = $this->extractRequiredDate($request->getBodyParam('eventDate'), 'Event date is required');

            // End date (optional, for multi-day events)
            $endDateParam = $request->getBodyParam('endDate');
            $eventDate->endDate = $endDateParam ? $this->extractOptionalDate($endDateParam) : null;

            // Time fields (required)
            $eventDate->startTime = $this->extractRequiredTime($request->getBodyParam('startTime'), 'Start time is required');
            $eventDate->endTime = $this->extractRequiredTime($request->getBodyParam('endTime'), 'End time is required');

            $eventDate->title = $request->getBodyParam('title') ?: Craft::t('booked', 'eventDate.eventOnDate', ['date' => $eventDate->eventDate]);

            $locationId = $request->getBodyParam('locationId');
            if (is_array($locationId)) {
                $locationId = $locationId[0] ?? null;
            }
            $eventDate->locationId = ($locationId === '' || $locationId === null) ? null : (int)$locationId;

            $eventDate->description = $request->getBodyParam('description');
            $eventDate->capacity = $request->getBodyParam('capacity') ? (int)$request->getBodyParam('capacity') : null;
            $priceParam = $request->getBodyParam('price');
            $eventDate->price = ($priceParam !== '' && $priceParam !== null) ? (float)$priceParam : null;
            $eventDate->enabled = (bool)$request->getBodyParam('enabled', true);
            $eventDate->propagationMethod = PropagationMethod::tryFrom($request->getBodyParam('propagationMethod', 'none')) ?? PropagationMethod::None;

            $eventDate->allowCancellation = (bool)$request->getBodyParam('allowCancellation', false);
            $eventDate->allowRefund = (bool)$request->getBodyParam('allowRefund', false);

            $cancellationPolicyHours = $request->getBodyParam('cancellationPolicyHours');
            $eventDate->cancellationPolicyHours = ($cancellationPolicyHours !== '' && $cancellationPolicyHours !== null) ? (int)$cancellationPolicyHours : null;

            $enableWaitlist = $request->getBodyParam('enableWaitlist');
            $eventDate->enableWaitlist = ($enableWaitlist === '' || $enableWaitlist === null) ? null : (bool)$enableWaitlist;

            $refundTiersParam = $request->getBodyParam('refundTiers');
            $eventDate->refundTiers = $this->normalizeRefundTiers($refundTiersParam);

            if (!Craft::$app->elements->saveElement($eventDate)) {
                Craft::$app->session->setError(Craft::t('booked', 'messages.eventDateNotSaved'));
                return $this->renderTemplate('booked/event-dates/_edit', [
                    'eventDate' => $eventDate,
                    'title' => Craft::t('booked', $id ? 'titles.editEventDate' : 'titles.newEventDate'),
                ]);
            }

            Craft::$app->session->setNotice(Craft::t('booked', 'messages.eventDateSaved'));
            return $this->redirect('booked/cp/event-dates');
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $eventDate = EventDate::find()->siteId('*')->id(Craft::$app->request->getRequiredBodyParam('id'))->one()
            ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.eventDateNotFound'));

        try {
            if (Craft::$app->elements->deleteElement($eventDate)) {
                Craft::$app->session->setNotice(Craft::t('booked', 'messages.eventDateDeleted'));
            } else {
                Craft::$app->session->setError(Craft::t('booked', 'messages.eventDateNotDeleted'));
            }

            if (Craft::$app->request->getAcceptsJson()) {
                return $this->jsonSuccess(Craft::t('booked', 'messages.eventDateDeleted'));
            }

            return $this->redirectToPostedUrl();
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function actionGetEventDates(): Response
    {
        $this->requireAcceptsJson();

        try {
            $eventDateService = Booked::getInstance()->eventDate;
            $events = $eventDateService->getEventDates(
                Craft::$app->request->getParam('dateFrom'),
                Craft::$app->request->getParam('dateTo')
            );

            return $this->jsonSuccess('', [
                'events' => array_map(fn($event) => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'description' => $event->description,
                    'date' => $event->eventDate,
                    'startTime' => $event->startTime,
                    'endTime' => $event->endTime,
                    'capacity' => $event->capacity,
                    'bookedCount' => $eventDateService->getBookedCount($event->id),
                    'remainingCapacity' => $event->getRemainingCapacity(),
                    'isFullyBooked' => $event->isFullyBooked(),
                    'enabled' => $event->enabled,
                    'locationId' => $event->locationId,
                    'price' => $event->price,
                    'formattedDate' => $event->getFormattedDate(),
                    'formattedTimeRange' => $event->getFormattedTimeRange(),
                ], $events),
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    private function extractRequiredDate(mixed $param, string $errorMessage): string
    {
        if (!$param) {
            throw new \InvalidArgumentException($errorMessage);
        }
        $dateValue = is_array($param) ? ($param['date'] ?? '') : (string)$param;
        if (empty($dateValue)) {
            throw new \InvalidArgumentException($errorMessage);
        }
        $dateTime = DateTimeHelper::toDateTime($dateValue);
        if (!$dateTime) {
            throw new \InvalidArgumentException('Invalid date format');
        }
        return $dateTime->format('Y-m-d');
    }

    private function extractOptionalDate(mixed $param): ?string
    {
        $dateValue = is_array($param) ? ($param['date'] ?? '') : (string)$param;
        if (empty($dateValue)) {
            return null;
        }
        $dateTime = DateTimeHelper::toDateTime($dateValue);
        return $dateTime ? $dateTime->format('Y-m-d') : null;
    }

    private function extractRequiredTime(mixed $param, string $errorMessage): string
    {
        if (!$param) {
            throw new \InvalidArgumentException($errorMessage);
        }
        $value = is_array($param) ? (string)($param['time'] ?? '') : (string)$param;
        if (empty($value)) {
            throw new \InvalidArgumentException($errorMessage);
        }

        // Craft's timeField sends 12-hour format (e.g. "9:00 AM") — normalize to 24-hour HH:mm
        $parsed = \DateTime::createFromFormat('g:i A', $value)
            ?: \DateTime::createFromFormat('H:i', $value);
        if (!$parsed) {
            throw new \InvalidArgumentException('Invalid time format');
        }

        return $parsed->format('H:i');
    }

    private function normalizeRefundTiers(mixed $param): ?array
    {
        return RefundTierHelper::normalize($param);
    }
}
