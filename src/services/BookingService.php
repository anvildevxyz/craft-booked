<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Reservation;
use anvildev\booked\events\AfterBookingCancelEvent;
use anvildev\booked\events\AfterBookingSaveEvent;
use anvildev\booked\events\AfterQuantityChangeEvent;
use anvildev\booked\events\BeforeBookingCancelEvent;
use anvildev\booked\events\BeforeBookingSaveEvent;
use anvildev\booked\events\BeforeQuantityChangeEvent;
use anvildev\booked\exceptions\BookingConflictException;
use anvildev\booked\exceptions\BookingException;
use anvildev\booked\exceptions\BookingNotFoundException;
use anvildev\booked\exceptions\BookingRateLimitException;
use anvildev\booked\exceptions\BookingValidationException;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\helpers\DateHelper;
use anvildev\booked\helpers\PiiRedactor;
use anvildev\booked\records\ReservationRecord;
use Craft;
use craft\base\Component;

/**
 * Booking orchestration service.
 * Delegates to BookingNotificationService (emails/calendar) and BookingValidationService (rate limits).
 */
class BookingService extends Component
{
    private ?BookingNotificationService $notificationService = null;
    private ?BookingValidationService $validationService = null;
    /** @var array<string, bool> Request-scoped LRU cache (max 100 entries, evicts oldest on overflow) */
    private array $employeeLessCache = [];

    public function clearEmployeeLessCache(): void
    {
        $this->employeeLessCache = [];
    }

    protected function getNotificationService(): BookingNotificationService
    {
        return $this->notificationService ??= Booked::getInstance()->bookingNotification;
    }

    protected function getValidationService(): BookingValidationService
    {
        return $this->validationService ??= Booked::getInstance()->bookingValidation;
    }

    /** @event BeforeBookingSaveEvent */
    public const EVENT_BEFORE_BOOKING_SAVE = 'beforeBookingSave';
    /** @event AfterBookingSaveEvent */
    public const EVENT_AFTER_BOOKING_SAVE = 'afterBookingSave';
    /** @event BeforeBookingCancelEvent */
    public const EVENT_BEFORE_BOOKING_CANCEL = 'beforeBookingCancel';
    /** @event AfterBookingCancelEvent */
    public const EVENT_AFTER_BOOKING_CANCEL = 'afterBookingCancel';
    /** @event BeforeQuantityChangeEvent */
    public const EVENT_BEFORE_QUANTITY_CHANGE = 'beforeQuantityChange';
    /** @event AfterQuantityChangeEvent */
    public const EVENT_AFTER_QUANTITY_CHANGE = 'afterQuantityChange';

    public function getReservationById(int $id): ?ReservationInterface
    {
        return ReservationFactory::find()->siteId('*')->id($id)->one();
    }

    public function getUpcomingReservations(int $limit = 10): array
    {
        return ReservationFactory::find()
            ->siteId('*')
            ->andWhere(['>=', 'booked_reservations.bookingDate', DateHelper::today()])
            ->andWhere(['!=', 'booked_reservations.status', ReservationRecord::STATUS_CANCELLED])
            ->orderBy(['booked_reservations.bookingDate' => SORT_ASC, 'booked_reservations.startTime' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    protected function createReservationModel(): ReservationInterface
    {
        return ReservationFactory::create();
    }

    /**
     * Create a new booking (simplified array keys wrapper for createReservation).
     *
     * @throws BookingException|BookingConflictException|BookingRateLimitException|BookingValidationException
     */
    public function createBooking(array $data): ReservationInterface
    {
        return $this->createReservation([
            'userName' => $data['customerName'] ?? '',
            'userEmail' => $data['customerEmail'] ?? '',
            'bookingDate' => $data['date'] ?? '',
            'startTime' => $data['time'] ?? '',
            'endDate' => $data['endDate'] ?? null,
            'serviceId' => $data['serviceId'] ?? null,
            'employeeId' => $data['employeeId'] ?? null,
            'locationId' => $data['locationId'] ?? null,
            'quantity' => $data['quantity'] ?? 1,
            'notes' => $data['notes'] ?? null,
            'softLockToken' => $data['softLockToken'] ?? null,
            'extras' => $data['extras'] ?? [],
        ]);
    }

    /**
     * Create a new reservation with mutex locking and database transaction.
     *
     * @throws BookingRateLimitException|BookingConflictException|BookingValidationException
     */
    public function createReservation(array $data): ReservationInterface
    {
        // Clear per-request slot cache to ensure fresh availability data
        $this->getAvailabilityService()->clearSlotCache();

        $userEmail = $data['userEmail'] ?? '';
        $eventDateId = $data['eventDateId'] ?? null;
        $eventDate = null;
        if ($eventDateId) {
            [$eventDate, $data] = $this->prepareEventBookingData($data);
        }

        $isMultiDay = !empty($data['endDate']);

        if (!$eventDateId && !$isMultiDay && (empty($data['bookingDate']) || empty($data['startTime']))) {
            throw new BookingValidationException(Craft::t('booked', 'booking.missingParameters'));
        }

        if (!$eventDateId && $isMultiDay && empty($data['bookingDate'])) {
            throw new BookingValidationException(Craft::t('booked', 'booking.missingParameters'));
        }

        // Reject bookings for past dates
        if (!$eventDateId && !empty($data['bookingDate'])) {
            $timezone = new \DateTimeZone($data['userTimezone'] ?? Craft::$app->getTimeZone());
            $todayStr = (new \DateTime('today', $timezone))->format('Y-m-d');
            if ($data['bookingDate'] < $todayStr) {
                throw new BookingValidationException(Craft::t('booked', 'booking.pastDate'));
            }
        }

        $this->checkRateLimits($userEmail, $data);

        $bookingDate = $data['bookingDate'] ?? '';
        $startTime = $isMultiDay ? null : ($data['startTime'] ?? '');
        $employeeId = $data['employeeId'] ?? null;
        $locationId = $data['locationId'] ?? null;
        $serviceId = $data['serviceId'] ?? null;
        $softLockToken = $data['softLockToken'] ?? null;

        $lockKey = $eventDateId
            ? "booked-event-booking-{$eventDateId}"
            : ($isMultiDay
                ? "booked-multiday-{$bookingDate}-" . ($employeeId ?? 'any') . "-{$serviceId}"
                : $this->buildSlotLockKey($bookingDate, $startTime, $employeeId, $serviceId));
        $mutex = $this->getMutex();

        if (!$mutex->acquire($lockKey, 10)) {
            Craft::warning("Could not acquire booking lock for {$bookingDate}", __METHOD__);
            throw new BookingConflictException(Craft::t('booked', 'booking.systemBusy'));
        }

        $employeeLockKey = null;

        try {
            $transaction = $this->getDb()->beginTransaction();

            try {
                // Re-fetch and re-check event capacity inside mutex to prevent race-condition overbooking
                if ($eventDate) {
                    $eventDate = \anvildev\booked\elements\EventDate::find()
                        ->id($eventDate->id)
                        ->siteId('*')
                        ->status(null)
                        ->one();
                    if (!$eventDate) {
                        throw new BookingNotFoundException(
                            Craft::t('booked', 'booking.eventNotFound')
                        );
                    }
                    $this->validateEventCapacity($eventDate, max(1, (int)($data['quantity'] ?? 1)));
                }

                // Compute extras duration for slot generation consistency
                $extrasDuration = 0;
                if (!empty($data['extras']) && is_array($data['extras']) && $serviceId) {
                    $extrasDuration = Booked::getInstance()->serviceExtra->calculateExtrasDuration($data['extras']);
                }

                $endTime = $isMultiDay ? null : $this->calculateEndTime($data, $bookingDate, $startTime, $serviceId, $extrasDuration);
                $endTime = $endTime ?: null;

                // Soft lock check (skip for event-based bookings — capacity handled separately)
                if (!$eventDateId && $serviceId !== null) {
                    $endDate = $data['endDate'] ?? null;
                    if ($isMultiDay && $endDate) {
                        if (Booked::getInstance()->getSoftLock()->isDateRangeLocked($bookingDate, $endDate, $serviceId, $employeeId, $locationId, max(1, (int)($data['quantity'] ?? 1)), null, $softLockToken)) {
                            Craft::warning("Booking blocked by multi-day soft lock: date={$bookingDate}-{$endDate}, service={$serviceId}, employee={$employeeId}, location={$locationId}", __METHOD__);
                            throw new BookingConflictException(Craft::t('booked', 'booking.slotReserved'));
                        }
                    } elseif (!$isMultiDay && Booked::getInstance()->getSoftLock()->isLocked($bookingDate, $startTime, $serviceId, $employeeId, $endTime, $locationId, $softLockToken)) {
                        Craft::warning("Booking blocked by soft lock: date={$bookingDate}, time={$startTime}-{$endTime}, service={$serviceId}, employee={$employeeId}, location={$locationId}", __METHOD__);
                        throw new BookingConflictException(Craft::t('booked', 'booking.slotReserved'));
                    }
                }

                $reservation = $this->createReservationModel();
                $this->populateReservation($reservation, $data, $userEmail, $bookingDate, $startTime, $endTime, $employeeId, $locationId, $serviceId, $eventDateId);

                $reservation->quantity = max(1, (int)($data['quantity'] ?? 1));

                if ($reservation->employeeId === null && !$eventDateId) {
                    if ($this->isEmployeeLessService($serviceId, $bookingDate)) {
                        Craft::debug("Employee-less service booking - employeeId remains null", __METHOD__);
                    } elseif (!$isMultiDay) {
                        $this->autoAssignEmployee($reservation, $softLockToken, $extrasDuration);
                    } elseif ($isMultiDay) {
                        // Multi-day bookings require explicit employee selection when employees exist
                        throw new BookingValidationException(Craft::t('booked', 'booking.employeeRequired'));
                    }
                }

                // After autoAssignEmployee assigns the employee, acquire an employee-specific lock
                // to prevent two concurrent "any available" requests from double-booking the same employee
                $skipSlotValidation = false;
                if ($reservation->employeeId && !$employeeId && !$eventDateId && !$isMultiDay) {
                    $employeeLockKey = "booked-employee-lock-{$bookingDate}-{$startTime}-{$reservation->employeeId}-" . ($serviceId ?? 'any');
                    if (!$mutex->acquire($employeeLockKey, 10)) {
                        Craft::warning("Could not acquire employee-specific lock for employee {$reservation->employeeId}", __METHOD__);
                        throw new BookingConflictException(Craft::t('booked', 'booking.slotNoLongerAvailable'));
                    }

                    // Re-check availability after acquiring employee-specific lock,
                    // bypassing cache to get fresh DB state and prevent double-booking.
                    if (!$this->getAvailabilityService()->isSlotAvailable(
                        $bookingDate,
                        $startTime,
                        $reservation->endTime,
                        $reservation->employeeId,
                        $locationId,
                        $serviceId,
                        $reservation->quantity,
                        $softLockToken,
                        $extrasDuration,
                        bypassCache: true,
                    )) {
                        throw new BookingConflictException(Craft::t('booked', 'booking.slotNoLongerAvailable'));
                    }

                    // Already validated with fresh DB state — skip redundant slot validation
                    $skipSlotValidation = true;
                }

                $this->validateEmployeeService($reservation);

                if (!$skipSlotValidation && $isMultiDay && !$eventDateId) {
                    $endDate = $data['endDate'] ?? null;
                    $service = $serviceId ? \anvildev\booked\elements\Service::find()->id($serviceId)->siteId('*')->one() : null;
                    $bufferBefore = $service->bufferBefore ?? 0;
                    $bufferAfter = $service->bufferAfter ?? 0;
                    if ($endDate && !Booked::getInstance()->getMultiDayAvailability()->isStartDateAvailable(
                        $bookingDate, $endDate, $serviceId, $employeeId, $locationId,
                        $reservation->quantity, $bufferBefore, $bufferAfter,
                        Booked::getInstance()->getBlackoutDate(),
                        Booked::getInstance()->scheduleResolver,
                    )) {
                        throw new BookingConflictException(Craft::t('booked', 'booking.slotNoLongerAvailable'));
                    }
                } elseif (!$skipSlotValidation && !$isMultiDay) {
                    $this->validateSlotAvailability($reservation, $eventDateId, $softLockToken, $extrasDuration);
                }

                // Before-save event
                $beforeSaveEvent = new BeforeBookingSaveEvent([
                    'reservation' => $reservation,
                    'bookingData' => $data,
                    'source' => $data['source'] ?? 'web',
                    'isNew' => true,
                ]);
                $this->trigger(self::EVENT_BEFORE_BOOKING_SAVE, $beforeSaveEvent);

                if (!$beforeSaveEvent->isValid) {
                    $errorMessage = (string) ($beforeSaveEvent->errorMessage ?? $beforeSaveEvent->data['errorMessage'] ?? Craft::t('booked', 'booking.cancelledByHandler'));
                    Craft::warning("Booking cancelled by event handler: {$errorMessage}", __METHOD__);
                    throw new BookingValidationException($errorMessage);
                }

                $this->saveReservationWithExtras($reservation, $data);

                Craft::info(
                    "Reservation created: ID {$reservation->id} | Date: {$reservation->bookingDate} {$reservation->startTime}-{$reservation->endTime} | Quantity: {$reservation->quantity} | Email: " . PiiRedactor::redactEmail($reservation->userEmail),
                    __METHOD__
                );

                $transaction->commit();

                $this->trigger(self::EVENT_AFTER_BOOKING_SAVE, new AfterBookingSaveEvent([
                    'reservation' => $reservation,
                    'isNew' => true,
                    'success' => true,
                ]));

                // Atomically check and record IP booking AFTER successful commit
                $ipAddress = $data['ipAddress'] ?? null;
                if ($ipAddress === null && !$this->getRequestService()->getIsConsoleRequest()) {
                    $ipAddress = $this->getRequestService()->getUserIP();
                }
                if ($ipAddress) {
                    if (!$this->getValidationService()->checkAndRecordIpBooking($ipAddress)) {
                        Craft::info("IP rate limit recorded for {$ipAddress} post-commit", __METHOD__);
                    }
                }

                $this->processPostBookingActions($reservation, $softLockToken);

                return $reservation;
            } catch (\yii\db\IntegrityException $e) {
                $transaction->rollBack();
                if (str_contains($e->getMessage(), 'idx_unique_active_booking')) {
                    Craft::warning("Booking conflict: Slot already booked (race condition prevented): {$bookingDate} {$startTime}-{$endTime}", __METHOD__);
                    throw new BookingConflictException(Craft::t('booked', 'booking.slotJustBooked'));
                }
                Craft::error('Database integrity error: ' . $e->getMessage(), __METHOD__);
                throw new BookingException(Craft::t('booked', 'booking.databaseError'));
            } catch (BookingRateLimitException | BookingConflictException | BookingValidationException | BookingException $e) {
                $transaction->rollBack();
                throw $e;
            } catch (\Throwable $e) {
                $transaction->rollBack();
                Craft::error('Booking creation failed: ' . $e->getMessage(), __METHOD__);
                throw new BookingException(Craft::t('booked', 'booking.unexpectedError', ['message' => $e->getMessage()]));
            }
        } finally {
            if ($employeeLockKey) {
                $mutex->release($employeeLockKey);
            }
            $mutex->release($lockKey);
            Craft::debug("Released booking lock for {$bookingDate}", __METHOD__);
        }
    }

    private function calculateEndTime(array $data, string $bookingDate, string $startTime, ?int $serviceId, int $extrasDuration = 0): string
    {
        $endTime = $data['endTime'] ?? '';
        if (empty($endTime) && $serviceId) {
            $service = $this->getServiceById($serviceId);
            if ($service) {
                $totalDuration = $service->duration + $extrasDuration;
                $start = new \DateTime("{$bookingDate} {$startTime}");
                $end = (clone $start)->modify("+{$totalDuration} minutes");

                // If the end time crosses midnight (different date), clamp to '24:00'
                // to prevent string-based time comparisons from breaking overlap detection.
                if ($end->format('Y-m-d') !== $start->format('Y-m-d')) {
                    $endTime = '24:00';
                } else {
                    $endTime = $end->format('H:i');
                }
            }
        }

        if ($endTime === '') {
            throw new BookingValidationException(Craft::t('booked', 'booking.endTimeRequired'));
        }

        return $endTime;
    }

    private function buildSlotLockKey(string $date, string $time, ?int $employeeId, ?int $serviceId): string
    {
        // When employeeId is known, serialize all bookings per employee per day to
        // prevent overlapping-time double bookings (e.g. 10:00-11:00 and 10:30-11:00).
        if ($employeeId !== null) {
            return "booked-employee-day-lock-{$date}-{$employeeId}";
        }

        // Auto-assign path: lock by exact time + service since employee is unknown.
        return "booked-booking-{$date}-{$time}-any-" . ($serviceId ?? 'any');
    }

    private function populateReservation(
        ReservationInterface $reservation,
        array $data,
        string $userEmail,
        string $bookingDate,
        ?string $startTime,
        ?string $endTime,
        ?int $employeeId,
        ?int $locationId,
        ?int $serviceId,
        ?int $eventDateId,
    ): void {
        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser) {
            $reservation->userId = $currentUser->id;
            Craft::debug("Linking reservation to user ID: {$currentUser->id}", __METHOD__);
        }
        $reservation->userName = $data['userName'] ?? ($currentUser ? ($currentUser->fullName ?? $currentUser->username) : '');
        $reservation->userEmail = $userEmail ?: ($currentUser?->email ?? '');
        $reservation->userPhone = $data['userPhone'] ?? null;
        $reservation->userTimezone = $data['userTimezone'] ?? $this->detectUserTimezone();
        $reservation->bookingDate = $bookingDate;
        $reservation->endDate = $data['endDate'] ?? null;
        $reservation->startTime = $startTime;
        $reservation->endTime = $endTime;
        $allowedStatuses = [ReservationRecord::STATUS_CONFIRMED];
        if (Booked::getInstance()->isCommerceEnabled()) {
            $allowedStatuses[] = ReservationRecord::STATUS_PENDING;
        }
        $reservation->status = in_array($data['status'] ?? null, $allowedStatuses, true)
            ? $data['status']
            : ReservationRecord::STATUS_CONFIRMED;
        $reservation->notes = $data['notes'] ?? null;
        $reservation->employeeId = $employeeId;
        $reservation->locationId = $locationId;
        $reservation->serviceId = $serviceId;
        $reservation->eventDateId = $eventDateId;

        // Capture the originating site for email language and management URLs.
        // Element-based reservations use bookingSiteId (element siteId is fixed to primary site);
        // ActiveRecord-based models use siteId directly.
        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        if ($reservation instanceof \anvildev\booked\elements\Reservation) {
            $reservation->bookingSiteId = $currentSiteId;
        } else {
            $reservation->siteId = $currentSiteId;
        }
    }

    private function isEmployeeLessService(?int $serviceId, string $bookingDate): bool
    {
        if (!$serviceId) {
            return false;
        }

        $cacheKey = $serviceId . '-' . $bookingDate;
        if (isset($this->employeeLessCache[$cacheKey])) {
            return $this->employeeLessCache[$cacheKey];
        }

        $activeSchedule = Booked::getInstance()->getScheduleAssignment()
            ->getActiveScheduleForServiceOnDate($serviceId, $bookingDate);

        if ($activeSchedule === null) {
            if (count($this->employeeLessCache) >= 100) {
                array_shift($this->employeeLessCache);
            }
            return $this->employeeLessCache[$cacheKey] = false;
        }

        $employeesForService = Employee::find()->siteId('*')->serviceId($serviceId)->enabled()->count();
        Craft::debug("Service {$serviceId} has service-level schedule with " .
            ($employeesForService === 0 ? "no employees - employee-less booking" : "{$employeesForService} employees - employee-based booking"), __METHOD__);

        if (count($this->employeeLessCache) >= 100) {
            array_shift($this->employeeLessCache);
        }
        return $this->employeeLessCache[$cacheKey] = ($employeesForService === 0);
    }

    /**
     * @throws BookingConflictException
     */
    private function autoAssignEmployee(ReservationInterface $reservation, ?string $softLockToken, int $extrasDuration = 0): void
    {
        $employees = Employee::find()->siteId('*');
        if ($reservation->serviceId) {
            $employees->serviceId($reservation->serviceId);
        }
        $employees = $employees->all();
        // Intentional random shuffle for load distribution: prevents the first employee in DB order
        // from receiving disproportionate bookings when multiple employees are available.
        shuffle($employees);

        $employeeIds = array_map(fn($e) => $e->id, $employees);
        Craft::debug("Trying to find available employee for {$reservation->startTime}. Employees: [" . implode(',', $employeeIds) . "]", __METHOD__);

        foreach ($employees as $employee) {
            $isAvailable = $this->getAvailabilityService()->isSlotAvailable(
                $reservation->bookingDate, $reservation->startTime, $reservation->endTime,
                $employee->id, $reservation->locationId, $reservation->serviceId,
                $reservation->quantity, $softLockToken, $extrasDuration
            );
            Craft::debug("  Employee {$employee->id} ({$employee->title}): " . ($isAvailable ? 'AVAILABLE' : 'NOT AVAILABLE'), __METHOD__);
            if ($isAvailable) {
                $reservation->employeeId = $employee->id;
                Craft::debug("Assigned employee {$reservation->employeeId} ({$employee->title}) for 'Any available' booking at {$reservation->startTime}", __METHOD__);
                return;
            }
        }

        Craft::error("No available employee found for slot: {$reservation->bookingDate} {$reservation->startTime}-{$reservation->endTime}", __METHOD__);
        throw new BookingConflictException(Craft::t('booked', 'booking.insufficientCapacity'));
    }

    /**
     * @throws BookingValidationException
     */
    private function validateEmployeeService(ReservationInterface $reservation): void
    {
        if ($reservation->employeeId && $reservation->serviceId) {
            $employee = Employee::find()->siteId('*')->id($reservation->employeeId)->one();
            if ($employee && !$employee->hasService($reservation->serviceId)) {
                throw new BookingValidationException(Craft::t('booked', 'booking.employeeCannotService'));
            }
        }
    }

    /**
     * @throws BookingConflictException
     */
    private function validateSlotAvailability(ReservationInterface $reservation, ?int $eventDateId, ?string $softLockToken, int $extrasDuration = 0): void
    {
        if ($eventDateId) {
            Craft::debug("Skipping slot availability check for event date booking (eventDateId={$eventDateId})", __METHOD__);
            return;
        }

        if (!$this->getAvailabilityService()->isSlotAvailable(
            $reservation->bookingDate, $reservation->startTime, $reservation->endTime,
            $reservation->employeeId, $reservation->locationId, $reservation->serviceId,
            $reservation->quantity, $softLockToken, $extrasDuration,
            bypassCache: true,
        )) {
            Craft::error("Attempted to book unavailable slot: {$reservation->bookingDate} {$reservation->startTime}-{$reservation->endTime} (quantity: {$reservation->quantity})", __METHOD__);
            throw new BookingConflictException(Craft::t('booked', 'booking.insufficientCapacity'));
        }
    }

    /**
     * @throws BookingValidationException|BookingException
     */
    private function saveReservationWithExtras(ReservationInterface $reservation, array $data): void
    {
        // Validate required extras BEFORE saving the reservation
        if (!empty($data['extras']) && is_array($data['extras'])) {
            if ($reservation->serviceId) {
                $missingRequired = Booked::getInstance()->serviceExtra->validateRequiredExtras($reservation->serviceId, $data['extras']);
                if (!empty($missingRequired)) {
                    throw new BookingValidationException(
                        Craft::t('booked', 'booking.requiredExtrasMissing', ['extras' => implode(', ', $missingRequired)])
                    );
                }
            }
        }

        if (!$reservation->save()) {
            Craft::error('Failed to save reservation: ' . json_encode($reservation->getErrors()), __METHOD__);
            throw new BookingValidationException(Craft::t('booked', 'booking.validationFailed'), $reservation->getErrors());
        }

        if (!empty($data['extras']) && is_array($data['extras'])) {
            if (!Booked::getInstance()->serviceExtra->saveExtrasForReservation($reservation->id, $data['extras'])) {
                Craft::error('Failed to save reservation extras', __METHOD__);
                throw new BookingException(Craft::t('booked', 'booking.extrasSaveFailed'));
            }

            Craft::debug("Saved " . count($data['extras']) . " extras for reservation #{$reservation->id}", __METHOD__);
        }
    }

    /**
     * @return array{\anvildev\booked\elements\EventDate, array}
     */
    private function prepareEventBookingData(array $data): array
    {
        $eventDate = Booked::getInstance()->eventDate->getEventDateById($data['eventDateId']);
        if (!$eventDate) {
            throw new BookingValidationException(Craft::t('booked', 'validation.eventDateNotFound'));
        }
        if (!$eventDate->isAvailable()) {
            throw new BookingValidationException(Craft::t('booked', 'validation.eventNotAvailable'));
        }

        $data['bookingDate'] = $eventDate->eventDate;
        $data['startTime'] = $eventDate->startTime;
        $data['endTime'] = $eventDate->endTime;
        $data['locationId'] = ($data['locationId'] ?? null) !== null
            ? $data['locationId']
            : ($eventDate->locationId ?? null);

        return [$eventDate, $data];
    }

    /**
     * Re-check event capacity inside the mutex to prevent race-condition overbooking.
     */
    private function validateEventCapacity(\anvildev\booked\elements\EventDate $eventDate, int $quantity): void
    {
        $remainingCapacity = $eventDate->getRemainingCapacity();
        if ($remainingCapacity !== null && $remainingCapacity <= 0) {
            throw new BookingConflictException(Craft::t('booked', 'booking.eventFullyBooked'));
        }

        if ($remainingCapacity !== null && $quantity > $remainingCapacity) {
            throw new BookingValidationException(Craft::t('booked', 'booking.quantityExceedsCapacity', ['capacity' => $remainingCapacity]));
        }
    }

    /**
     * @throws BookingRateLimitException|BookingValidationException
     */
    private function checkRateLimits(string $userEmail, array $data): void
    {
        $settings = Booked::getInstance()->getSettings();

        if ($settings->enableRateLimiting || $settings->enableIpBlocking) {
            $ipAddress = $data['ipAddress'] ?? null;
            if ($ipAddress === null && !$this->getRequestService()->getIsConsoleRequest()) {
                $ipAddress = $this->getRequestService()->getUserIP();
            }

            $rateLimitResult = $this->getValidationService()->checkAllRateLimits($userEmail, $ipAddress);
            if (!$rateLimitResult['allowed']) {
                $reason = $rateLimitResult['reason'];
                $message = $reason === 'email_rate_limit'
                    ? Craft::t('booked', 'booking.rateLimitEmail')
                    : Craft::t('booked', 'booking.rateLimitIP');
                Craft::warning("Booking blocked: {$reason}", __METHOD__);
                Booked::getInstance()->getAudit()->logRateLimit($reason, ['email' => PiiRedactor::redactEmail($userEmail)]);
                throw new BookingRateLimitException($message);
            }
        }

        $serviceId = $data['serviceId'] ?? null;
        $requestedBookingDate = $data['bookingDate'] ?? '';
        if ($serviceId && $userEmail) {
            $service = $this->getServiceById($serviceId);
            if ($service?->customerLimitEnabled && $service->customerLimitCount) {
                if (!$this->getValidationService()->checkCustomerBookingLimit($userEmail, $service, $requestedBookingDate)) {
                    Craft::warning("Booking blocked: Customer limit exceeded for " . PiiRedactor::redactEmail($userEmail) . " on service {$serviceId} for date {$requestedBookingDate}", __METHOD__);
                    throw new BookingValidationException(Craft::t('booked', 'booking.customerLimit'));
                }
            }
        }
    }

    private function processPostBookingActions(ReservationInterface $reservation, ?string $softLockToken): void
    {
        $googleMeetCreated = false;
        $service = $reservation->getService();

        if ($service?->virtualMeetingProvider) {
            $meetingData = Booked::getInstance()->getVirtualMeeting()->createMeeting($reservation, $service->virtualMeetingProvider);

            if ($meetingData) {
                $reservation->virtualMeetingUrl = $meetingData['url'] ?? null;
                $reservation->virtualMeetingId = $meetingData['id'] ?? null;
                $reservation->virtualMeetingProvider = $meetingData['provider'] ?? null;
                if (!$reservation->save(false)) {
                    Craft::error("Failed to save virtual meeting details to reservation #{$reservation->id}: " . implode(', ', $reservation->getFirstErrors()), __METHOD__);
                } else {
                    Craft::debug("Created virtual meeting for reservation #{$reservation->id}: {$meetingData['url']}", __METHOD__);
                }
                $googleMeetCreated = ($service->virtualMeetingProvider === 'google');
            }
        }

        if ($softLockToken) {
            $softLock = Booked::getInstance()->getSoftLock();
            $softLock->releaseLock($softLockToken, $softLock->getSessionHash());
        }

        if ($reservation->status !== ReservationRecord::STATUS_PENDING) {
            $ns = $this->getNotificationService();
            $ns->queueBookingEmail($reservation->id, 'confirmation', null, 512);
            $ns->queueOwnerNotification($reservation->id, 512);

            if ($googleMeetCreated) {
                Craft::debug("Skipping calendar sync for reservation #{$reservation->id} - Google Meet already created calendar event", __METHOD__);
            } else {
                $ns->queueCalendarSync($reservation->id);
            }

            $ns->queueSmsConfirmation($reservation);
        }
    }

    // Rate limits are not re-checked on reschedule — the customer already passed
    // them when creating the original booking. Re-checking would penalize legitimate
    // rescheduling of existing bookings.
    public function updateReservation(int $id, array $data): ReservationInterface
    {
        $reservation = $this->getReservationById($id);
        if (!$reservation) {
            Craft::error('Reservation not found with ID: ' . $id, __METHOD__);
            throw new BookingNotFoundException('Reservation not found.');
        }

        $bookingDate = $data['bookingDate'] ?? $reservation->bookingDate;
        $startTime = $data['startTime'] ?? $reservation->startTime;
        $employeeId = $data['employeeId'] ?? $reservation->employeeId;
        $serviceId = $data['serviceId'] ?? $reservation->serviceId;
        $isReschedule = isset($data['bookingDate']) || isset($data['startTime']) || isset($data['endTime'])
            || isset($data['employeeId']) || isset($data['locationId']);

        // Use the same slot-based lock key as createReservation so reschedules
        // and new bookings targeting the same slot properly contend.
        $lockKey = $isReschedule
            ? $this->buildSlotLockKey($bookingDate, $startTime, $employeeId, $serviceId)
            : "booked-booking-update-{$id}";
        $mutex = $this->getMutex();

        if (!$mutex->acquire($lockKey, 10)) {
            Craft::warning("Could not acquire update lock for reservation {$id}", __METHOD__);
            throw new BookingConflictException(Craft::t('booked', 'booking.systemBusy'));
        }

        try {
            $transaction = $this->getDb()->beginTransaction();

            try {
                $oldStatus = $reservation->status;

                // Validate status before assignment — pending is reserved for Commerce payment flows
                if (isset($data['status'])) {
                    $allowedStatuses = [
                        ReservationRecord::STATUS_CONFIRMED,
                        ReservationRecord::STATUS_CANCELLED,
                    ];
                    if (!in_array($data['status'], $allowedStatuses, true)) {
                        throw new BookingValidationException(
                            Craft::t('booked', 'booking.invalidStatus'),
                            ['status' => [Craft::t('booked', 'booking.invalidStatus')]],
                        );
                    }
                }

                foreach (['userName', 'userEmail', 'userPhone', 'bookingDate', 'startTime', 'endTime', 'employeeId', 'locationId', 'serviceId', 'status', 'notes'] as $field) {
                    if (isset($data[$field])) {
                        $reservation->$field = $data[$field];
                    }
                }

                if ($isReschedule) {
                    if (!$this->getAvailabilityService()->isSlotAvailable(
                        $reservation->bookingDate,
                        $reservation->startTime,
                        $reservation->endTime,
                        $reservation->employeeId,
                        $reservation->locationId,
                        $reservation->serviceId,
                        $reservation->quantity,
                        excludeReservationId: $reservation->id,
                    )) {
                        Craft::error('Attempted to update reservation to unavailable slot', __METHOD__);
                        throw new BookingConflictException(Craft::t('booked', 'booking.slotNotAvailable'));
                    }
                }

                if (!$reservation->save()) {
                    Craft::error('Failed to update reservation: ' . json_encode($reservation->getErrors()), __METHOD__);
                    throw new BookingValidationException(Craft::t('booked', 'booking.updateFailed'), $reservation->getErrors());
                }

                if ($oldStatus !== $reservation->status) {
                    Booked::getInstance()->getAudit()->logStatusChange($reservation->id, $oldStatus, $reservation->status);
                    $this->getNotificationService()->queueBookingEmail($reservation->id, 'status_change', $oldStatus, 512);
                }

                $transaction->commit();

                $this->trigger(self::EVENT_AFTER_BOOKING_SAVE, new AfterBookingSaveEvent([
                    'reservation' => $reservation,
                    'isNew' => false,
                    'success' => true,
                ]));

                return $reservation;
            } catch (BookingConflictException | BookingValidationException | BookingException $e) {
                $transaction->rollBack();
                throw $e;
            } catch (\Throwable $e) {
                $transaction->rollBack();
                Craft::error('Reservation update failed: ' . $e->getMessage(), __METHOD__);
                throw new BookingException(Craft::t('booked', 'booking.unexpectedError', ['message' => $e->getMessage()]));
            }
        } finally {
            $mutex->release($lockKey);
            Craft::debug("Released update lock for reservation {$id}", __METHOD__);
        }
    }

    /**
     * @throws BookingNotFoundException|BookingValidationException
     */
    public function cancelReservation(int $id, string $reason = ''): bool
    {
        $reservation = $this->getReservationById($id);
        if (!$reservation) {
            throw new BookingNotFoundException(Craft::t('booked', 'booking.notFound'));
        }
        if (!$this->canCancelReservation($reservation)) {
            throw new BookingValidationException(Craft::t('booked', 'booking.cannotCancel'));
        }

        $lockKey = $reservation->eventDateId
            ? "booked-event-booking-{$reservation->eventDateId}"
            : $this->buildSlotLockKey($reservation->bookingDate, $reservation->startTime, $reservation->employeeId, $reservation->serviceId);
        $mutex = $this->getMutex();

        if (!$mutex->acquire($lockKey, 10)) {
            Craft::warning("Could not acquire cancel lock for reservation {$id}", __METHOD__);
            throw new BookingConflictException(Craft::t('booked', 'booking.systemBusy'));
        }

        try {
            return $this->executeCancellation($reservation, $reason);
        } finally {
            $mutex->release($lockKey);
        }
    }

    public function reduceQuantity(int $id, int $reduceBy, string $reason = ''): bool
    {
        if ($reduceBy < 1) {
            return false;
        }

        $reservation = $this->getReservationById($id);
        if (!$reservation || !$reservation->canBeCancelled()) {
            return false;
        }

        $currentQuantity = $reservation->getQuantity();
        if ($reduceBy >= $currentQuantity) {
            return false; // Use cancelReservation() for full cancellation
        }

        $newQuantity = $currentQuantity - $reduceBy;

        $lockKey = $reservation->eventDateId
            ? "booked-event-booking-{$reservation->eventDateId}"
            : $this->buildSlotLockKey($reservation->bookingDate, $reservation->startTime, $reservation->employeeId, $reservation->serviceId);
        $mutex = $this->getMutex();

        if (!$mutex->acquire($lockKey, 10)) {
            Craft::warning("Could not acquire lock for quantity reduction on reservation {$id}", __METHOD__);
            return false;
        }

        try {
            $beforeEvent = new BeforeQuantityChangeEvent([
                'reservation' => $reservation,
                'previousQuantity' => $currentQuantity,
                'reduceBy' => $reduceBy,
                'newQuantity' => $newQuantity,
                'reason' => $reason,
                'isNew' => false,
            ]);
            $this->trigger(self::EVENT_BEFORE_QUANTITY_CHANGE, $beforeEvent);

            if (!$beforeEvent->isValid) {
                return false;
            }

            $originalTotalPrice = $reservation->getTotalPrice();

            $transaction = Craft::$app->getDb()->beginTransaction();
            try {
                $reservation->quantity = $newQuantity;
                $reservation->recalculateTotals();
                if ($reason) {
                    $reservation->notes = ($reservation->notes ? $reservation->notes . "\n\n" : '')
                        . Craft::t('booked', 'booking.quantityReduced', [
                            'from' => $currentQuantity,
                            'to' => $newQuantity,
                            'reason' => $reason,
                        ]);
                }

                if (!$reservation->save()) {
                    $transaction->rollBack();
                    return false;
                }

                Booked::getInstance()->getAudit()->logQuantityChange(
                    $id,
                    Craft::$app->user->identity?->email ?? 'system',
                    "Quantity reduced from {$currentQuantity} to {$newQuantity}: {$reason}",
                    'reduction'
                );

                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            $this->trigger(self::EVENT_AFTER_QUANTITY_CHANGE, new AfterQuantityChangeEvent([
                'reservation' => $reservation,
                'previousQuantity' => $currentQuantity,
                'reduceBy' => $reduceBy,
                'newQuantity' => $newQuantity,
                'reason' => $reason,
                'originalTotalPrice' => $originalTotalPrice,
                'isNew' => false,
            ]));

            // Notify event waitlist about newly available capacity
            if ($reservation->eventDateId) {
                try {
                    Booked::getInstance()->waitlist->checkAndNotifyEventWaitlist($reservation->eventDateId);
                } catch (\Throwable $e) {
                    Craft::error("Failed to notify event waitlist after quantity reduction: " . $e->getMessage(), __METHOD__);
                }
            }

            // Notify service waitlist about newly available slot
            if ($reservation->serviceId) {
                try {
                    Booked::getInstance()->waitlist->checkAndNotifyWaitlist(
                        $reservation->serviceId, $reservation->bookingDate,
                        $reservation->startTime, $reservation->endTime,
                        $reservation->employeeId, $reservation->locationId
                    );
                } catch (\Throwable $e) {
                    Craft::error("Failed to notify waitlist after quantity reduction: " . $e->getMessage(), __METHOD__);
                }
            }

            return true;
        } finally {
            $mutex->release($lockKey);
        }
    }

    public function increaseQuantity(int $id, int $increaseBy): bool
    {
        if ($increaseBy < 1) {
            return false;
        }

        $reservation = $this->getReservationById($id);
        if (!$reservation) {
            return false;
        }

        $currentQuantity = $reservation->getQuantity();
        $newQuantity = $currentQuantity + $increaseBy;

        // Sanity bound to prevent integer overflow and unreasonable quantities
        if ($newQuantity > 10000 || $newQuantity < $currentQuantity) {
            return false;
        }

        $lockKey = $reservation->eventDateId
            ? "booked-event-booking-{$reservation->eventDateId}"
            : $this->buildSlotLockKey($reservation->bookingDate, $reservation->startTime, $reservation->employeeId, $reservation->serviceId);
        $mutex = $this->getMutex();

        if (!$mutex->acquire($lockKey, 10)) {
            Craft::warning("Could not acquire lock for quantity increase on reservation #{$id}", __METHOD__);
            return false;
        }

        try {
            $beforeEvent = new BeforeQuantityChangeEvent([
                'reservation' => $reservation,
                'previousQuantity' => $currentQuantity,
                'increaseBy' => $increaseBy,
                'newQuantity' => $newQuantity,
                'isNew' => false,
            ]);
            $this->trigger(self::EVENT_BEFORE_QUANTITY_CHANGE, $beforeEvent);

            if (!$beforeEvent->isValid) {
                return false;
            }

            // Validate capacity before increasing quantity
            if ($reservation->eventDateId) {
                $eventDate = Booked::getInstance()->eventDate->getEventDateById($reservation->eventDateId);
                if ($eventDate) {
                    $this->validateEventCapacity($eventDate, $newQuantity);
                }
            } elseif ($reservation->serviceId) {
                if (!$this->getAvailabilityService()->isSlotAvailable(
                    $reservation->bookingDate,
                    $reservation->startTime,
                    $reservation->endTime,
                    $reservation->employeeId,
                    $reservation->locationId,
                    $reservation->serviceId,
                    $newQuantity,
                    null,
                    0,
                    $reservation->getId(),
                )) {
                    Craft::error("Quantity increase blocked — insufficient capacity for reservation #{$id}: requested {$newQuantity}", __METHOD__);
                    throw new BookingConflictException(Craft::t('booked', 'booking.insufficientCapacity'));
                }
            }

            $originalTotalPrice = $reservation->getTotalPrice();

            $transaction = Craft::$app->getDb()->beginTransaction();
            try {
                $reservation->quantity = $newQuantity;
                $reservation->recalculateTotals();

                if (!$reservation->save()) {
                    $transaction->rollBack();
                    Craft::error("Failed to save quantity increase for reservation #{$id}", __METHOD__);
                    return false;
                }

                Booked::getInstance()->getAudit()->logQuantityChange(
                    $id,
                    Craft::$app->user->identity?->email ?? 'system',
                    "Quantity increased from {$currentQuantity} to {$newQuantity}",
                    'increase'
                );

                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            $this->trigger(self::EVENT_AFTER_QUANTITY_CHANGE, new AfterQuantityChangeEvent([
                'reservation' => $reservation,
                'previousQuantity' => $currentQuantity,
                'increaseBy' => $increaseBy,
                'newQuantity' => $newQuantity,
                'originalTotalPrice' => $originalTotalPrice,
                'isNew' => false,
            ]));

            Craft::info("Increased quantity on reservation #{$id} from {$currentQuantity} to {$newQuantity}", __METHOD__);
            return true;
        } finally {
            $mutex->release($lockKey);
        }
    }

    private function executeCancellation(ReservationInterface $reservation, string $reason): bool
    {
        $id = $reservation->getId();

        $beforeCancelEvent = new BeforeBookingCancelEvent([
            'reservation' => $reservation,
            'reason' => $reason,
            'cancelledBy' => Craft::$app->user->identity?->email ?? 'system',
            'sendNotification' => true,
            'isNew' => false,
        ]);
        $this->trigger(self::EVENT_BEFORE_BOOKING_CANCEL, $beforeCancelEvent);

        if (!$beforeCancelEvent->isValid) {
            Craft::warning("Cancellation prevented by event handler: " . ($beforeCancelEvent->errorMessage ?? $beforeCancelEvent->data['errorMessage'] ?? 'Cancellation was prevented by event handler'), __METHOD__);
            return false;
        }

        $wasPaid = $reservation->getTotalPrice() > 0 && $reservation->status !== ReservationRecord::STATUS_PENDING;

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $reservation->status = ReservationRecord::STATUS_CANCELLED;
            if ($reason) {
                $reservation->notes = ($reservation->notes ? $reservation->notes . "\n\n" : '') . Craft::t('booked', 'booking.cancellationReason', ['reason' => $reason]);
            }

            if (!$reservation->save()) {
                $transaction->rollBack();
                return false;
            }

            Booked::getInstance()->getAudit()->logCancellation($id, Craft::$app->user->identity?->email ?? 'system', $reason, 'service');

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->trigger(self::EVENT_AFTER_BOOKING_CANCEL, new AfterBookingCancelEvent([
            'reservation' => $reservation,
            'wasPaid' => $wasPaid,
            'shouldRefund' => $wasPaid,
            'reason' => $reason,
            'success' => true,
            'isNew' => false,
        ]));

        if ($beforeCancelEvent->sendNotification) {
            $this->getNotificationService()->queueBookingEmail($reservation->id, 'cancellation', null, 512);
            $this->getNotificationService()->queueSmsCancellation($reservation);
        }

        // Notify waitlist about newly available slot
        if ($reservation->serviceId) {
            try {
                Booked::getInstance()->waitlist->checkAndNotifyWaitlist(
                    $reservation->serviceId, $reservation->bookingDate,
                    $reservation->startTime, $reservation->endTime,
                    $reservation->employeeId, $reservation->locationId
                );
            } catch (\Throwable $e) {
                Craft::error("Failed to notify waitlist: " . $e->getMessage(), __METHOD__);
            }
        }

        // Notify event waitlist about newly available capacity
        if ($reservation->eventDateId) {
            try {
                Booked::getInstance()->waitlist->checkAndNotifyEventWaitlist($reservation->eventDateId);
            } catch (\Throwable $e) {
                Craft::error("Failed to notify event waitlist: " . $e->getMessage(), __METHOD__);
            }
        }

        return true;
    }

    protected function canCancelReservation(ReservationInterface $reservation): bool
    {
        return $reservation->canBeCancelled();
    }

    protected function getServiceById(int $id): ?\anvildev\booked\elements\Service
    {
        return \anvildev\booked\elements\Service::find()->siteId('*')->id($id)->one();
    }

    private function detectUserTimezone(): string
    {
        $defaultTimezone = Craft::$app->getTimeZone();

        if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'test') {
            return $defaultTimezone;
        }

        try {
            $session = Craft::$app->session;
            $tz = $session->has('userTimezone') ? $session->get('userTimezone') : null;
            if ($tz !== null && @timezone_open($tz) !== false) {
                return $tz;
            }
            return $defaultTimezone;
        } catch (\Exception $e) {
            Craft::warning("Could not access session for timezone: " . $e->getMessage(), __METHOD__);
            return $defaultTimezone;
        }
    }

    protected function getAvailabilityService(): AvailabilityService
    {
        return Booked::getInstance()->getAvailability();
    }

    protected function getDb(): \craft\db\Connection
    {
        return Craft::$app->getDb();
    }

    protected function getRequestService(): \yii\web\Request|\yii\console\Request
    {
        return Craft::$app->getRequest();
    }

    protected function getMutex(): \yii\mutex\Mutex
    {
        return Booked::getInstance()->mutex->get();
    }

    /**
     * @return array{totalBookings: int, confirmedBookings: int, pendingBookings: int, todayBookings: int, thisMonthBookings: int}
     */
    public function getBookingStats(): array
    {
        $today = DateHelper::today();
        $thisMonth = (new \DateTime())->format('Y-m-01');
        $nextMonth = (new \DateTime())->modify('+1 month')->format('Y-m-01');

        $permissionService = Booked::getInstance()->getPermission();
        $employeeIds = $permissionService->getStaffEmployeeIds();

        $query = (new \craft\db\Query())
            ->from('{{%booked_reservations}}')
            ->select([
                'totalBookings' => 'COUNT(*)',
                'confirmedBookings' => 'SUM(CASE WHEN [[status]] = :confirmed THEN 1 ELSE 0 END)',
                'pendingBookings' => 'SUM(CASE WHEN [[status]] = :pending THEN 1 ELSE 0 END)',
                'todayBookings' => 'SUM(CASE WHEN [[bookingDate]] = :today AND [[status]] != :cancelled THEN 1 ELSE 0 END)',
                'thisMonthBookings' => 'SUM(CASE WHEN [[bookingDate]] >= :thisMonth AND [[bookingDate]] < :nextMonth AND [[status]] != :cancelled THEN 1 ELSE 0 END)',
            ])
            ->params([
                ':confirmed' => ReservationRecord::STATUS_CONFIRMED,
                ':pending' => ReservationRecord::STATUS_PENDING,
                ':cancelled' => ReservationRecord::STATUS_CANCELLED,
                ':today' => $today,
                ':thisMonth' => $thisMonth,
                ':nextMonth' => $nextMonth,
            ]);

        if ($employeeIds !== null) {
            if (empty($employeeIds)) {
                $query->andWhere('0=1');
            } else {
                $query->andWhere(['employeeId' => $employeeIds]);
            }
        }

        $row = $query->one();

        return [
            'totalBookings' => (int)($row['totalBookings'] ?? 0),
            'confirmedBookings' => (int)($row['confirmedBookings'] ?? 0),
            'pendingBookings' => (int)($row['pendingBookings'] ?? 0),
            'todayBookings' => (int)($row['todayBookings'] ?? 0),
            'thisMonthBookings' => (int)($row['thisMonthBookings'] ?? 0),
        ];
    }
}
