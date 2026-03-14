<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\controllers\traits\HandlesExceptionsTrait;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\EventDate;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\Service;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\helpers\CsvHelper;
use anvildev\booked\helpers\FormFieldHelper;
use anvildev\booked\records\ReservationRecord;
use anvildev\booked\services\BookingService;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

class BookingsController extends Controller
{
    use JsonResponseTrait;
    use HandlesExceptionsTrait;

    private BookingService $bookingService;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        in_array($action->id, ['index', 'view', 'edit', 'export'], true)
            ? $this->requirePermission('booked-viewBookings')
            : $this->requirePermission('booked-manageBookings');

        return true;
    }

    public function init(): void
    {
        parent::init();
        $this->bookingService = Booked::getInstance()->booking;
    }

    public function actionIndex(): Response
    {
        $permissionService = Booked::getInstance()->getPermission();
        $user = Craft::$app->getUser()->getIdentity();
        $canManage = $user->admin || $user->can('booked-manageBookings');
        $request = Craft::$app->request;

        $status = $request->getParam('status');
        $search = $request->getParam('search');
        $serviceId = $request->getParam('serviceId');
        $employeeId = $request->getParam('employeeId');
        $locationId = $request->getParam('locationId');
        $eventDateId = $request->getParam('eventDateId');

        $sortRaw = $request->getParam('sort');
        $sort = in_array($sortRaw, ['bookingDate', 'userName', 'startTime', 'status', 'dateCreated'], true) ? $sortRaw : 'bookingDate';
        $dir = $request->getParam('dir') === 'asc' ? 'asc' : 'desc';
        $sortDir = $dir === 'asc' ? SORT_ASC : SORT_DESC;

        $query = ReservationFactory::find()->orderBy([
            $sort => $sortDir,
            ($sort !== 'bookingDate' ? 'bookingDate' : 'startTime') => $sort !== 'bookingDate' ? SORT_DESC : $sortDir,
        ]);
        $permissionService->scopeReservationQuery($query);

        if ($status && $status !== '*') {
            $query->status($status);
        }

        // Apply entity filters via shared pattern
        foreach (['serviceId' => $serviceId, 'employeeId' => $employeeId, 'locationId' => $locationId, 'eventDateId' => $eventDateId] as $method => $val) {
            if ($val) {
                $query->$method((int) $val);
            }
        }

        if ($search) {
            $conditions = ['or', ['like', 'userName', $search], ['like', 'userEmail', $search], ['like', 'userPhone', $search]];

            if (ctype_digit($search)) {
                $conditions[] = ['booked_reservations.id' => (int) $search];
            }

            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $search, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
                $conditions[] = ['bookingDate' => sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1])];
            } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $search, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                $conditions[] = ['bookingDate' => $search];
            }

            $query->andWhere($conditions);
        }

        $page = max(1, (int)($request->getParam('page') ?? 1));
        $limitParam = (int)($request->getParam('limit') ?? 50);
        $limit = in_array($limitParam, [10, 20, 50, 100, 200], true) ? $limitParam : 50;
        $offset = ($page - 1) * $limit;
        $totalCount = (int)(clone $query)->count();
        $reservations = $query->withRelations()->offset($offset)->limit($limit)->all();

        $staffEmployeeIds = $permissionService->getStaffEmployeeIds();
        if ($staffEmployeeIds !== null) {
            $employees = Employee::find()->siteId('*')->id($staffEmployeeIds)->orderBy('title')->all();
            $scopedServiceIds = $scopedLocationIds = [];
            foreach ($employees as $emp) {
                $scopedServiceIds = array_merge($scopedServiceIds, $emp->getServiceIds());
                if ($emp->locationId) {
                    $scopedLocationIds[] = $emp->locationId;
                }
            }
            $serviceOptions = ($ids = array_unique($scopedServiceIds)) ? Service::find()->id($ids)->orderBy('title')->all() : [];
            $locationOptions = ($ids = array_unique($scopedLocationIds)) ? Location::find()->siteId('*')->id($ids)->orderBy('title')->all() : [];
        } else {
            $employees = Employee::find()->siteId('*')->orderBy('title')->all();
            $serviceOptions = Service::find()->orderBy('title')->all();
            $locationOptions = Location::find()->siteId('*')->orderBy('title')->all();
        }

        return $this->renderTemplate('booked/bookings/_index-activerecord', [
            'title' => Craft::t('booked', 'titles.bookings'),
            'reservations' => $reservations,
            'statuses' => Reservation::getStatuses(),
            'currentStatus' => $status ?: '*',
            'search' => $search,
            'currentServiceId' => $serviceId,
            'currentEmployeeId' => $employeeId,
            'currentLocationId' => $locationId,
            'currentEventDateId' => $eventDateId,
            'serviceOptions' => $serviceOptions,
            'employeeOptions' => $employees,
            'locationOptions' => $locationOptions,
            'eventDateOptions' => $this->getScopedEventDates(),
            'canManage' => $canManage,
            'sort' => $sort,
            'dir' => $dir,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => (int)ceil($totalCount / $limit) ?: 1,
                'totalCount' => $totalCount,
                'limit' => $limit,
                'first' => $totalCount > 0 ? $offset + 1 : 0,
                'last' => min($offset + $limit, $totalCount),
            ],
        ]);
    }

    public function actionView(int $id): Response
    {
        return $this->actionEdit($id);
    }

    public function actionEdit(?int $id = null): Response
    {
        $user = Craft::$app->getUser()->getIdentity();
        $canManage = $user->admin || $user->can('booked-manageBookings');

        if ($id) {
            $reservation = $this->findScopedReservation($id)
                ?? throw new NotFoundHttpException('Booking not found');
        } else {
            if (!$canManage) {
                throw new \yii\web\ForbiddenHttpException('User is not authorized to perform this action.');
            }
            $reservation = ReservationFactory::create([
                'siteId' => Craft::$app->request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id,
            ]);
        }

        $settings = Booked::getInstance()->getSettings();

        $order = null;
        if ($id && ReservationFactory::isElementMode()) {
            try {
                $orderId = (new \craft\db\Query())
                    ->select('orderId')
                    ->from('{{%commerce_lineitems}}')
                    ->where(['purchasableId' => $id])
                    ->scalar();
                if ($orderId) {
                    $order = \craft\commerce\elements\Order::find()->id($orderId)->one();
                }
            } catch (\Exception) {
            }
        }

        $canEditSessionNotes = $user->admin;
        if (!$canEditSessionNotes && $reservation->employeeId) {
            $employees = Booked::getInstance()->getPermission()->getEmployeesForCurrentUser();
            $canEditSessionNotes = collect($employees)->contains('id', $reservation->employeeId);
        }

        return $this->renderTemplate('booked/bookings/edit', array_merge(
            [
                'reservation' => $reservation,
                'canManage' => $canManage,
                'canEditSessionNotes' => $canEditSessionNotes,
                'emailEnabled' => true,
                'smsEnabled' => $settings->isSmsConfigured() && ($settings->smsConfirmationEnabled ?? false),
                'currency' => Booked::getInstance()->reports->getCurrency(),
                'order' => $order,
            ],
            $this->getFormOptions()
        ));
    }

    private function findScopedReservation(int $id): ?ReservationInterface
    {
        $query = ReservationFactory::find()->id($id);
        Booked::getInstance()->getPermission()->scopeReservationQuery($query);
        return $query->one();
    }

    private function getFormOptions(): array
    {
        $mapOptions = static fn(iterable $items, string $labelFn = 'title') => array_map(
            static fn($item) => ['value' => $item->id, 'label' => is_callable($labelFn) ? $labelFn($item) : $item->$labelFn],
            is_array($items) ? $items : iterator_to_array($items)
        );

        return [
            'statuses' => Reservation::getStatuses(),
            'serviceOptions' => array_merge(
                [['value' => '', 'label' => Craft::t('booked', 'form.selectService')]],
                array_map(
                    static fn($s) => ['value' => $s->id, 'label' => $s->title . ($s->duration ? " ({$s->duration} min)" : '')],
                    Service::find()->orderBy('title')->all()
                )
            ),
            'employeeOptions' => array_merge(
                [['value' => '', 'label' => Craft::t('booked', 'form.noEmployee')]],
                $mapOptions(Employee::find()->siteId('*')->orderBy('title')->all())
            ),
            'locationOptions' => array_merge(
                [['value' => '', 'label' => Craft::t('booked', 'form.noLocation')]],
                $mapOptions(Location::find()->siteId('*')->orderBy('title')->all())
            ),
            'eventDateOptions' => array_merge(
                [['value' => '', 'label' => Craft::t('booked', 'form.notEventBooking')]],
                array_map(
                    static fn($e) => ['value' => $e->id, 'label' => "{$e->title} ({$e->eventDate})"],
                    EventDate::find()->siteId('*')->unique()->where(['>=', 'eventDate', date('Y-m-d')])->orderBy('eventDate')->all()
                )
            ),
            'timezoneOptions' => array_merge(
                [['value' => '', 'label' => Craft::t('booked', 'form.selectTimezone')]],
                array_map(static fn($tz) => ['value' => $tz, 'label' => $tz], \DateTimeZone::listIdentifiers())
            ),
        ];
    }

    /** @return EventDate[] */
    private function getScopedEventDates(): array
    {
        $query = EventDate::find()->siteId('*')->unique()->orderBy(['eventDate' => SORT_DESC]);
        $staffIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();
        if ($staffIds !== null) {
            $employees = Employee::find()->siteId('*')->id($staffIds)->all();
            $locationIds = array_unique(array_filter(array_map(fn($e) => $e->locationId, $employees)));
            if (!empty($locationIds)) {
                $query->andWhere(['or', ['locationId' => $locationIds], ['locationId' => null]]);
            }
        }
        return $query->all();
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $id = $request->getBodyParam('id');

        $reservation = $id
            ? ($this->findScopedReservation((int)$id) ?? throw new NotFoundHttpException('Booking not found'))
            : ReservationFactory::create();

        $oldStatus = $id ? $reservation->status : null;

        $reservation->userName = strip_tags(trim($request->getRequiredBodyParam('userName')));
        $reservation->userEmail = strtolower(strip_tags(trim($request->getRequiredBodyParam('userEmail'))));
        $reservation->userPhone = ($phone = $request->getBodyParam('userPhone')) ? strip_tags(trim($phone)) : null;
        $reservation->bookingDate = FormFieldHelper::extractDateValue($request->getRequiredBodyParam('bookingDate'));
        $reservation->startTime = FormFieldHelper::extractTimeValue($request->getRequiredBodyParam('startTime'));
        $reservation->endTime = FormFieldHelper::extractTimeValue($request->getRequiredBodyParam('endTime'));
        $submittedStatus = $request->getRequiredBodyParam('status');
        $validStatuses = array_keys(ReservationRecord::getStatuses());
        if (!in_array($submittedStatus, $validStatuses, true)) {
            Craft::$app->getSession()->setError(Craft::t('booked', 'errors.invalidStatus'));
            return $this->renderTemplate('booked/bookings/edit', array_merge(
                ['reservation' => $reservation],
                $this->getFormOptions()
            ));
        }
        $reservation->status = $submittedStatus;
        $reservation->notes = ($notes = $request->getBodyParam('notes')) ? substr(strip_tags(trim($notes)), 0, 5000) : null;
        $reservation->quantity = (int)($request->getBodyParam('quantity') ?? 1);

        // Session notes — only allow setting if user is admin or assigned employee
        $user = Craft::$app->getUser()->getIdentity();
        $canEditSessionNotes = $user->admin;
        if (!$canEditSessionNotes && $reservation->employeeId) {
            $employees = Booked::getInstance()->getPermission()->getEmployeesForCurrentUser();
            $canEditSessionNotes = collect($employees)->contains('id', $reservation->employeeId);
        }
        if ($canEditSessionNotes) {
            $sessionNotes = $request->getBodyParam('sessionNotes');
            $reservation->sessionNotes = $sessionNotes ? substr(strip_tags(trim($sessionNotes)), 0, 10000) : null;
        }

        $reservation->serviceId = ($v = $request->getBodyParam('serviceId')) ? (int)$v : null;
        $reservation->employeeId = ($v = $request->getBodyParam('employeeId')) ? (int)$v : null;
        $reservation->locationId = ($v = $request->getBodyParam('locationId')) ? (int)$v : null;
        $reservation->eventDateId = ($v = $request->getBodyParam('eventDateId')) ? (int)$v : null;
        $reservation->userTimezone = $request->getBodyParam('userTimezone') ?: null;

        if (method_exists($reservation, 'setFieldValuesFromRequest')) {
            $reservation->setFieldValuesFromRequest('fields');
        }

        $canSkipCheck = Craft::$app->getUser()->getIsAdmin() && (bool)$request->getBodyParam('skipAvailabilityCheck');

        // Mutex lock to prevent TOCTOU race between conflict check and save
        $mutex = Craft::$app->getMutex();
        $lockKey = 'booked-cp-save-' . $reservation->bookingDate . '-' . ($reservation->employeeId ?? 'any');
        if (!$mutex->acquire($lockKey, 10)) {
            Craft::$app->getSession()->setError(Craft::t('booked', 'errors.slotAlreadyBooked'));
            return $this->renderTemplate('booked/bookings/edit', array_merge(
                ['reservation' => $reservation],
                $this->getFormOptions()
            ));
        }

        try {
            if (!$canSkipCheck && $reservation->status === 'confirmed') {
                $conflictResponse = $this->checkForBookingConflicts($reservation, $id);
                if ($conflictResponse !== null) {
                    return $conflictResponse;
                }
            }

            if (!$reservation->save()) {
                Craft::$app->getSession()->setError(Craft::t('booked', 'messages.bookingNotSaved'));
                return $this->renderTemplate('booked/bookings/edit', array_merge(
                    ['reservation' => $reservation],
                    $this->getFormOptions()
                ));
            }
        } finally {
            $mutex->release($lockKey);
        }

        if ($oldStatus !== null && $oldStatus !== $reservation->status) {
            Booked::getInstance()->getAudit()->logStatusChange($reservation->id, $oldStatus, $reservation->status);
        }

        Craft::$app->getSession()->setNotice(Craft::t('booked', 'messages.bookingSaved'));
        return $this->redirect('booked/bookings');
    }

    private function checkForBookingConflicts($reservation, ?int $id): ?\yii\web\Response
    {
        $normTime = static fn(?string $t): string => $t ? substr($t, 0, 5) : '';

        if ($id) {
            $original = $this->findScopedReservation($id);
            if ($original &&
                $original->getBookingDate() === $reservation->bookingDate &&
                $normTime($original->getStartTime()) === $normTime($reservation->startTime) &&
                $normTime($original->getEndTime()) === $normTime($reservation->endTime) &&
                $original->getEmployeeId() === $reservation->employeeId
            ) {
                return null;
            }
        }

        $conflictQuery = ReservationFactory::find()->bookingDate($reservation->bookingDate)->status('confirmed');
        if ($reservation->employeeId) {
            $conflictQuery->employeeId($reservation->employeeId);
        }
        if ($id) {
            $conflictQuery->andWhere(['!=', 'id', $id]);
        }

        foreach ($conflictQuery->all() as $conflict) {
            $cStart = $normTime($conflict->getStartTime());
            $cEnd = $normTime($conflict->getEndTime());
            $rStart = $normTime($reservation->startTime);
            $rEnd = $normTime($reservation->endTime);
            if ($rStart < $cEnd && $rEnd > $cStart) {
                Craft::$app->getSession()->setError(Craft::t('booked', 'errors.slotAlreadyBooked', [
                    'date' => $reservation->bookingDate,
                    'time' => $reservation->startTime,
                ]));
                return $this->renderTemplate('booked/bookings/edit', array_merge(
                    ['reservation' => $reservation],
                    $this->getFormOptions()
                ));
            }
        }

        return null;
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $reservation = $this->findScopedReservation((int) Craft::$app->request->getRequiredBodyParam('id'))
            ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.bookingNotFound'));

        $reservation->delete()
            ? Craft::$app->getSession()->setNotice(Craft::t('booked', 'messages.bookingDeleted'))
            : Craft::$app->getSession()->setError(Craft::t('booked', 'messages.bookingNotDeleted'));

        return $this->redirect('booked/bookings');
    }

    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();

        $ids = Craft::$app->request->getRequiredBodyParam('ids');
        if (!is_array($ids) || empty($ids)) {
            Craft::$app->getSession()->setError(Craft::t('booked', 'errors.noBookingsSelected'));
            return $this->redirect('booked/bookings');
        }

        $deleted = $failed = 0;
        foreach ($ids as $id) {
            $reservation = $this->findScopedReservation((int) $id);
            ($reservation && $reservation->delete()) ? $deleted++ : $failed++;
        }

        if ($deleted > 0) {
            Craft::$app->getSession()->setNotice(Craft::t('booked', 'messages.bookingsDeleted', ['count' => $deleted]));
        }
        if ($failed > 0) {
            Craft::$app->getSession()->setError(Craft::t('booked', 'messages.bookingsNotDeleted', ['count' => $failed]));
        }

        return $this->redirect('booked/bookings');
    }

    public function actionUpdateStatus(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $reservation = $this->findScopedReservation((int) $request->getRequiredBodyParam('id'))
            ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.bookingNotFound'));

        try {
            $submittedStatus = $request->getRequiredBodyParam('status');
            $validStatuses = array_keys(ReservationRecord::getStatuses());
            if (!in_array($submittedStatus, $validStatuses, true)) {
                if ($request->getAcceptsJson()) {
                    return $this->jsonError(Craft::t('booked', 'errors.invalidStatus'));
                }
                Craft::$app->getSession()->setError(Craft::t('booked', 'errors.invalidStatus'));
                return $this->redirectToPostedUrl();
            }

            $this->bookingService->updateReservation(
                $reservation->getId(),
                ['status' => $submittedStatus]
            );

            if ($request->getAcceptsJson()) {
                return $this->jsonSuccess(Craft::t('booked', 'messages.statusUpdated'));
            }
            Craft::$app->getSession()->setNotice(Craft::t('booked', 'messages.statusUpdated'));
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }

        return $this->redirectToPostedUrl();
    }

    public function actionResendConfirmation(): Response
    {
        $this->requirePostRequest();

        $reservation = $this->findScopedReservation((int) Craft::$app->request->getRequiredBodyParam('id'))
            ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.bookingNotFound'));

        // Reset the idempotency flag so the CAS guard in SendBookingEmailJob allows re-sending
        Craft::$app->db->createCommand()->update(
            '{{%booked_reservations}}',
            ['notificationSent' => false],
            ['id' => $reservation->getId()],
        )->execute();

        Booked::getInstance()->getBookingNotification()->queueBookingEmail($reservation->getId(), 'confirmation');
        Craft::$app->getSession()->setNotice(Craft::t('booked', 'messages.emailSent'));

        return $this->redirectToPostedUrl();
    }

    public function actionResendSms(): Response
    {
        $this->requirePostRequest();

        $reservation = $this->findScopedReservation((int)Craft::$app->request->getRequiredBodyParam('id'))
            ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.bookingNotFound'));

        if (!Booked::getInstance()->getSettings()->isSmsConfigured()) {
            Craft::$app->getSession()->setError(Craft::t('booked', 'messages.smsNotConfigured'));
            return $this->redirectToPostedUrl();
        }

        Booked::getInstance()->getTwilioSms()->sendConfirmation($reservation)
            ? Craft::$app->getSession()->setNotice(Craft::t('booked', 'messages.smsConfirmationSent'))
            : Craft::$app->getSession()->setError(Craft::t('booked', 'messages.smsConfirmationFailed'));

        return $this->redirectToPostedUrl();
    }

    public function actionExport(): Response
    {
        $request = Craft::$app->request;
        $keys = ['status', 'serviceId', 'employeeId', 'locationId', 'dateFrom', 'dateTo', 'userEmail'];
        $criteria = [];
        foreach ($keys as $k) {
            $criteria[$k] = $request->getParam($k);
        }

        $query = ReservationFactory::find();
        Booked::getInstance()->getPermission()->scopeReservationQuery($query);

        if (!empty($criteria['status'])) {
            $query->status($criteria['status']);
        }
        if (!empty($criteria['serviceId'])) {
            $query->serviceId((int) $criteria['serviceId']);
        }
        if (!empty($criteria['employeeId'])) {
            $query->employeeId((int) $criteria['employeeId']);
        }
        if (!empty($criteria['locationId'])) {
            $query->locationId((int) $criteria['locationId']);
        }
        if (!empty($criteria['dateFrom'])) {
            $query->andWhere(['>=', 'booked_reservations.bookingDate', $criteria['dateFrom']]);
        }
        if (!empty($criteria['dateTo'])) {
            $query->andWhere(['<=', 'booked_reservations.bookingDate', $criteria['dateTo']]);
        }
        if (!empty($criteria['userEmail'])) {
            $query->userEmail($criteria['userEmail']);
        }

        $query->orderBy(['bookingDate' => SORT_DESC, 'startTime' => SORT_DESC]);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['ID', 'Name', 'Email', 'Phone', 'Service', 'Employee', 'Location', 'Event', 'Date', 'Start Time', 'End Time', 'Quantity', 'Status', 'Notes', 'Created']);

        foreach ($query->each(100) as $r) {
            $service = $r->serviceId ? Service::find()->id($r->serviceId)->one() : null;
            $employee = $r->employeeId ? Employee::find()->siteId('*')->id($r->employeeId)->one() : null;
            $location = $r->locationId ? Location::find()->siteId('*')->id($r->locationId)->one() : null;
            $eventDate = $r->eventDateId ? EventDate::find()->id($r->eventDateId)->one() : null;

            fputcsv($handle, [
                (string)$r->id,
                CsvHelper::sanitizeValue($r->userName ?? ''),
                CsvHelper::sanitizeValue($r->userEmail ?? ''),
                CsvHelper::sanitizeValue($r->userPhone ?? ''),
                CsvHelper::sanitizeValue($service->title ?? ''),
                CsvHelper::sanitizeValue($employee->title ?? ''),
                CsvHelper::sanitizeValue($location->title ?? ''),
                CsvHelper::sanitizeValue($eventDate->title ?? ''),
                (string)($r->bookingDate ?? ''),
                (string)($r->startTime ?? ''),
                (string)($r->endTime ?? ''),
                (string)($r->quantity ?? 1),
                (string)$r->getStatusLabel(),
                CsvHelper::sanitizeValue($r->notes ?? ''),
                $r->dateCreated ? $r->dateCreated->format('Y-m-d H:i:s') : '',
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $this->response->sendContentAsFile($content, 'bookings-' . date('Y-m-d') . '.csv', [
            'mimeType' => 'text/csv',
        ]);
    }
}
