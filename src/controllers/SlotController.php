<?php

namespace anvildev\booked\controllers;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\BookingHelpersTrait;
use anvildev\booked\controllers\traits\HandlesExceptionsTrait;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\EventDate;
use anvildev\booked\elements\Service;
use anvildev\booked\helpers\DateHelper;
use anvildev\booked\helpers\SiteHelper;
use anvildev\booked\services\MultiDayAvailabilityService;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\BadRequestHttpException;

/**
 * Frontend availability/slot endpoints: time slots, calendar data, event dates, soft locks.
 */
class SlotController extends Controller
{
    use JsonResponseTrait;
    use HandlesExceptionsTrait;
    use BookingHelpersTrait;

    protected array|bool|int $allowAnonymous = [
        'get-available-slots',
        'get-available-dates',
        'get-availability-calendar',
        'get-event-dates',
        'get-valid-end-dates',
        'create-lock',
        'create-multi-day-lock',
        'create-event-lock',
        'release-lock',
    ];

    public $enableCsrfValidation = true;

    private \anvildev\booked\services\AvailabilityService $availabilityService;

    public function init(): void
    {
        parent::init();
        $this->availabilityService = Booked::getInstance()->availability;
        $this->closeSession();
    }

    public function actionGetAvailableSlots(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_slots_throttle', 60)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $date = Craft::$app->request->getRequiredBodyParam('date');
        if (!$this->validateDate($date)) {
            throw new BadRequestHttpException(Craft::t('booked', 'booking.invalidDate'));
        }

        $quantity = $this->normalizeQuantity((int)(Craft::$app->request->getBodyParam('quantity') ?? 1));
        $employeeId = $this->normalizeId(Craft::$app->request->getBodyParam('employeeId'));
        $locationId = $this->normalizeId(Craft::$app->request->getBodyParam('locationId'));
        $serviceId = $this->normalizeId(Craft::$app->request->getBodyParam('serviceId'));
        $extrasDuration = max(0, (int)(Craft::$app->request->getBodyParam('extrasDuration') ?? 0));

        return $this->jsonSuccess('', [
            'slots' => $this->availabilityService->getAvailableSlots($date, $employeeId, $locationId, $serviceId, $quantity, null, null, $extrasDuration),
            'waitlistAvailable' => $this->isWaitlistAvailable($serviceId, $date, $employeeId),
        ]);
    }

    /**
     * Get available start dates for day-based (multi-day) services.
     */
    public function actionGetAvailableDates(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_dates_throttle', 30)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $request = Craft::$app->request;
        $serviceId = $this->normalizeId($request->getParam('serviceId'));
        if (!$serviceId) {
            throw new BadRequestHttpException(Craft::t('booked', 'errors.serviceRequired'));
        }

        $month = $request->getParam('month'); // YYYY-MM
        if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new BadRequestHttpException(Craft::t('booked', 'booking.invalidDate'));
        }

        $rangeStart = $month . '-01';
        $rangeEnd = (new \DateTime($rangeStart))->modify('last day of this month')->format('Y-m-d');

        $today = DateHelper::today();
        if ($rangeStart < $today) {
            $rangeStart = $today;
        }

        $employeeId = $this->normalizeId($request->getParam('employeeId'));
        $locationId = $this->normalizeId($request->getParam('locationId'));
        $quantity = $this->normalizeQuantity((int)($request->getParam('quantity') ?? 1));
        $extrasDuration = max(0, (int)($request->getParam('extrasDuration') ?? 0));

        $availableDates = Booked::getInstance()->getMultiDayAvailability()->getAvailableStartDates(
            $rangeStart,
            $rangeEnd,
            $serviceId,
            $employeeId,
            $locationId,
            $quantity,
            $extrasDuration,
        );

        return $this->jsonSuccess('', [
            'availableDates' => $availableDates,
            'month' => $month,
        ]);
    }

    public function actionGetValidEndDates(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_dates_throttle', 30)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $request = Craft::$app->request;
        $serviceId = $this->normalizeId($request->getParam('serviceId'));
        if (!$serviceId) {
            throw new BadRequestHttpException(Craft::t('booked', 'errors.serviceRequired'));
        }

        $startDate = $request->getParam('startDate');
        if (!$startDate || !$this->validateDate($startDate)) {
            throw new BadRequestHttpException(Craft::t('booked', 'booking.invalidDate'));
        }

        $service = \anvildev\booked\elements\Service::find()->id($serviceId)->siteId('*')->one();
        if (!$service || !$service->isFlexibleDayService()) {
            throw new BadRequestHttpException('Service is not a flexible day service');
        }

        $minDays = $service->minDays ?? 1;
        $maxDays = $service->maxDays ?? 7;
        $employeeId = $this->normalizeId($request->getParam('employeeId'));
        $locationId = $this->normalizeId($request->getParam('locationId'));
        $quantity = $this->normalizeQuantity((int)($request->getParam('quantity') ?? 1));

        $multiDay = Booked::getInstance()->getMultiDayAvailability();
        $blackoutService = Booked::getInstance()->getBlackoutDate();
        $scheduleResolver = Booked::getInstance()->scheduleResolver;
        $bufferBefore = $service->bufferBefore ?? 0;
        $bufferAfter = $service->bufferAfter ?? 0;

        $validEndDates = [];
        for ($days = $minDays; $days <= $maxDays; $days++) {
            $candidateEnd = MultiDayAvailabilityService::calculateEndDate($startDate, $days);
            if ($multiDay->isStartDateAvailable(
                $startDate, $candidateEnd, $serviceId, $employeeId, $locationId,
                $quantity, $bufferBefore, $bufferAfter, $blackoutService, $scheduleResolver,
            )) {
                $validEndDates[] = $candidateEnd;
            } else {
                break;
            }
        }

        return $this->jsonSuccess('', [
            'validEndDates' => $validEndDates,
            'startDate' => $startDate,
            'minDays' => $minDays,
            'maxDays' => $maxDays,
        ]);
    }

    public function actionGetEventDates(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_event_dates_throttle', 60)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $request = Craft::$app->request;
        $dateFrom = $request->getParam('dateFrom');
        $dateTo = $request->getParam('dateTo');

        $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
        if ($dateFrom && !preg_match($datePattern, $dateFrom)) {
            return $this->jsonError(Craft::t('booked', 'booking.invalidDate'));
        }
        if ($dateTo && !preg_match($datePattern, $dateTo)) {
            return $this->jsonError(Craft::t('booked', 'booking.invalidDate'));
        }

        $site = SiteHelper::getSiteForRequest($request);
        $siteId = $site->id;

        $eventDateService = Booked::getInstance()->eventDate;
        $globalWaitlistEnabled = Booked::getInstance()->getSettings()->enableWaitlist;

        if ($dateFrom || $dateTo) {
            $events = $eventDateService->getEventDates($dateFrom, $dateTo, $siteId);
        } else {
            // Include fully booked events when waitlist is available so users can join
            $allFuture = $eventDateService->getEventDates(DateHelper::today(), null, $siteId);
            $events = array_values(array_filter($allFuture, function(EventDate $event) use ($globalWaitlistEnabled) {
                if ($event->isAvailable()) {
                    return true;
                }
                // Show fully booked events if waitlist is enabled for them
                $waitlistEnabled = $event->enableWaitlist ?? $globalWaitlistEnabled;
                return $waitlistEnabled && $event->isFullyBooked() && !$event->isSoftDeleted();
            }));
        }

        return $this->jsonSuccess('', [
            'hasEvents' => !empty($events),
            'eventDates' => array_map(function(EventDate $event) use ($globalWaitlistEnabled) {
                $waitlistEnabled = $event->enableWaitlist ?? $globalWaitlistEnabled;
                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'description' => $event->description,
                    'date' => $event->eventDate,
                    'startTime' => $event->startTime,
                    'endTime' => $event->endTime,
                    'capacity' => $event->capacity,
                    'remainingCapacity' => $event->getRemainingCapacity(),
                    'isFullyBooked' => $event->isFullyBooked(),
                    'waitlistEnabled' => $waitlistEnabled && $event->isFullyBooked(),
                    'locationId' => $event->locationId,
                    'price' => $event->price,
                    'formattedDate' => $event->getFormattedDate(),
                    'formattedTimeRange' => $event->getFormattedTimeRange(),
                ];
            }, $events),
        ]);
    }

    /**
     * Availability calendar data: which dates are bookable, blacked out, or have slots
     */
    public function actionGetAvailabilityCalendar(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_calendar_throttle', 30)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $request = Craft::$app->request;
        $startDate = $request->getParam('startDate', DateHelper::today());
        $endDate = $request->getParam('endDate', DateHelper::relativeDate('+90 days'));

        // Validate date formats
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new BadRequestHttpException(Craft::t('booked', 'booking.invalidDate'));
        }

        $current = \DateTime::createFromFormat('Y-m-d', $startDate);
        $end = \DateTime::createFromFormat('Y-m-d', $endDate);

        if (!$current || !$end) {
            throw new BadRequestHttpException(Craft::t('booked', 'booking.invalidDate'));
        }

        // Cap date range to 90 days to prevent DoS
        $maxEnd = (clone $current)->add(new \DateInterval('P90D'));
        if ($end > $maxEnd) {
            $end = $maxEnd;
            $endDate = $end->format('Y-m-d');
        }

        $employeeId = $request->getParam('employeeId') ?: $request->getParam('entryId');
        $locationId = $request->getParam('locationId');
        $serviceId = $request->getParam('serviceId');
        $quantity = $this->normalizeQuantity((int)($request->getParam('quantity') ?? 1));
        $extrasDuration = max(0, (int)($request->getParam('extrasDuration') ?? 0));

        $blackoutService = Booked::getInstance()->getBlackoutDate();
        $cache = Craft::$app->getCache();

        $site = SiteHelper::getSiteForRequest($request);
        $cacheKey = 'booked_avail_cal_' . md5(json_encode([
            $site->id, $startDate, $endDate, $employeeId, $locationId, $serviceId, $quantity, $extrasDuration,
        ]));

        $calendar = $cache->getOrSet($cacheKey, function() use ($current, $end, $employeeId, $locationId, $serviceId, $quantity, $extrasDuration, $blackoutService) {
            $calendar = [];

            while ($current <= $end) {
                $dateStr = $current->format('Y-m-d');
                $empId = $employeeId ? (int)$employeeId : null;
                $locId = $locationId ? (int)$locationId : null;

                $isBlackedOut = $blackoutService->isDateBlackedOut($dateStr, $empId, $locId);
                $hasSlots = !$isBlackedOut && !empty($this->availabilityService->getAvailableSlots(
                    $dateStr, $empId, $locId,
                    $serviceId ? (int)$serviceId : null,
                    $quantity,
                    null,
                    null,
                    $extrasDuration
                ));

                $calendar[$dateStr] = [
                    'hasAvailability' => $hasSlots,
                    'isBlackedOut' => $isBlackedOut,
                    'isBookable' => $hasSlots && !$isBlackedOut,
                ];

                $current->add(new \DateInterval('P1D'));
            }

            return $calendar;
        }, 300);

        return $this->jsonSuccess('', ['calendar' => $calendar]);
    }

    public function actionCreateLock(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_lock_throttle', 30)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $request = Craft::$app->request;
        $date = $request->getRequiredBodyParam('date');
        $startTime = $request->getRequiredBodyParam('startTime');
        $serviceId = $request->getRequiredBodyParam('serviceId');

        if (!$this->validateDate($date)) {
            return $this->jsonError(Craft::t('booked', 'booking.invalidDate'));
        }

        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
            return $this->jsonError(Craft::t('booked', 'errors.invalidTime'));
        }

        $service = Service::findOne($serviceId);
        if (!$service) {
            return $this->jsonError(Craft::t('booked', 'errors.serviceNotFound'));
        }

        $extrasDuration = max(0, (int)($request->getBodyParam('extrasDuration') ?? 0));

        try {
            $totalDuration = $service->duration + $extrasDuration;
            $start = new \DateTime($date . ' ' . $startTime);
            $endTime = (clone $start)->add(new \DateInterval('PT' . $totalDuration . 'M'))->format('H:i');
        } catch (\Throwable $e) {
            Craft::error("Error calculating end time for lock: " . $e->getMessage(), __METHOD__);
            return $this->jsonError(Craft::t('booked', 'errors.invalidTime'));
        }

        $durationMinutes = Booked::getInstance()->getSettings()->softLockDurationMinutes ?? 5;
        $employeeId = $request->getBodyParam('employeeId');
        $locationId = $request->getBodyParam('locationId');

        $quantity = max(1, (int)($request->getBodyParam('quantity') ?? 1));
        $capacity = $request->getBodyParam('capacity');
        $capacity = $capacity !== null ? max(1, (int)$capacity) : null;

        $token = Booked::getInstance()->getSoftLock()->createLock([
            'date' => $date,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'serviceId' => (int)$serviceId,
            'employeeId' => $employeeId ? (int)$employeeId : null,
            'locationId' => $locationId ? (int)$locationId : null,
            'quantity' => $quantity,
            'capacity' => $capacity,
        ], $durationMinutes);

        if ($token === false) {
            return $this->jsonError(Craft::t('booked', 'booking.slotReserved'));
        }

        return $this->jsonSuccess('', [
            'token' => $token,
            'expiresIn' => $durationMinutes * 60,
        ]);
    }

    public function actionCreateMultiDayLock(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_lock_throttle', 30)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $request = Craft::$app->request;
        $date = $request->getRequiredBodyParam('date');
        $endDate = $request->getRequiredBodyParam('endDate');
        $serviceId = $request->getRequiredBodyParam('serviceId');

        if (!$this->validateDate($date) || !$this->validateDate($endDate)) {
            return $this->jsonError(Craft::t('booked', 'booking.invalidDate'));
        }

        if ($endDate < $date) {
            return $this->jsonError(Craft::t('booked', 'booking.invalidDate'));
        }

        $durationMinutes = Booked::getInstance()->getSettings()->softLockDurationMinutes ?? 5;
        $employeeId = $this->normalizeId($request->getBodyParam('employeeId'));
        $locationId = $this->normalizeId($request->getBodyParam('locationId'));
        $quantity = max(1, (int)($request->getBodyParam('quantity') ?? 1));

        $token = Booked::getInstance()->getSoftLock()->createLock([
            'date' => $date,
            'endDate' => $endDate,
            'startTime' => null,
            'endTime' => null,
            'serviceId' => (int)$serviceId,
            'employeeId' => $employeeId ? (int)$employeeId : null,
            'locationId' => $locationId ? (int)$locationId : null,
            'quantity' => $quantity,
        ], $durationMinutes);

        if ($token === false) {
            return $this->jsonError(Craft::t('booked', 'booking.slotReserved'));
        }

        return $this->jsonSuccess('', [
            'token' => $token,
            'expiresIn' => $durationMinutes * 60,
        ]);
    }

    public function actionCreateEventLock(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_lock_throttle', 30)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $eventDateId = Craft::$app->request->getRequiredBodyParam('eventDateId');
        $eventDate = EventDate::find()->id($eventDateId)->siteId('*')->one();
        if (!$eventDate) {
            return $this->jsonError(Craft::t('booked', 'errors.eventNotFound'));
        }

        $durationMinutes = Booked::getInstance()->getSettings()->softLockDurationMinutes ?? 5;

        $token = Booked::getInstance()->getSoftLock()->createLock([
            'date' => $eventDate->eventDate,
            'startTime' => $eventDate->startTime,
            'endTime' => $eventDate->endTime,
            'serviceId' => 0,
            'employeeId' => null,
            'locationId' => $eventDate->locationId,
        ], $durationMinutes);

        if ($token === false) {
            return $this->jsonError(Craft::t('booked', 'booking.slotReserved'));
        }

        return $this->jsonSuccess('', [
            'token' => $token,
            'expiresIn' => $durationMinutes * 60,
        ]);
    }

    public function actionReleaseLock(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_lock_throttle', 30)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $token = Craft::$app->request->getBodyParam('token');
        if (!$token) {
            return $this->jsonError(Craft::t('booked', 'slot.noTokenProvided'));
        }

        $softLockService = Booked::getInstance()->getSoftLock();
        return $this->jsonSuccess('', [
            'released' => $softLockService->releaseLock($token, $softLockService->getSessionHash()),
        ]);
    }

    protected function isWaitlistAvailable(?int $serviceId, string $date, ?int $employeeId = null): bool
    {
        $waitlistEnabled = Booked::getInstance()->getSettings()->enableWaitlist;

        $service = $serviceId ? Service::findOne($serviceId) : null;
        if ($service && $service->enableWaitlist !== null) {
            $waitlistEnabled = $service->enableWaitlist;
        }

        if (!$waitlistEnabled) {
            return false;
        }
        if (!$service) {
            return true;
        }

        $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) {
            return false;
        }

        return Booked::getInstance()->scheduleResolver->hasScheduleForDay(
            $serviceId, $employeeId, $date, (int)$dateObj->format('N')
        );
    }
}
