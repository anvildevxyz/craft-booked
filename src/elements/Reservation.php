<?php

namespace anvildev\booked\elements;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\elements\db\ReservationQuery;
use anvildev\booked\helpers\DateHelper;
use anvildev\booked\helpers\ElementQueryHelper;
use anvildev\booked\helpers\ValidationHelper;
use anvildev\booked\records\ReservationRecord;
use anvildev\booked\traits\HasCancellationPolicy;
use anvildev\booked\traits\HasFormattedDateTime;
use anvildev\booked\traits\ValidatesTimeRange;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\User;
use craft\helpers\Html;
use craft\helpers\UrlHelper;

if (interface_exists(\craft\commerce\base\PurchasableInterface::class)) {
    class_alias(\craft\commerce\base\PurchasableInterface::class, 'anvildev\booked\elements\_ReservationPurchasable');
} else {
    class_alias(\anvildev\booked\contracts\PurchasableShim::class, 'anvildev\booked\elements\_ReservationPurchasable');
}

class Reservation extends Element implements _ReservationPurchasable, ReservationInterface
{
    use HasCancellationPolicy;
    use HasFormattedDateTime;
    use ValidatesTimeRange;

    public string $userName = '';
    public string $userEmail = '';
    public ?string $userPhone = null;
    public ?int $userId = null;
    public ?string $userTimezone = null;
    public string $bookingDate = '';
    public string $startTime = '';
    public string $endTime = '';
    public string $status = ReservationRecord::STATUS_CONFIRMED;
    public ?string $notes = null;
    public ?string $virtualMeetingUrl = null;
    public ?string $virtualMeetingProvider = null;
    public ?string $virtualMeetingId = null;
    public ?string $googleEventId = null;
    public ?string $outlookEventId = null;
    public bool $notificationSent = false;
    public bool $emailReminder24hSent = false;
    public bool $emailReminder1hSent = false;
    public bool $smsReminder24hSent = false;
    public bool $smsConfirmationSent = false;
    public ?\DateTime $smsConfirmationSentAt = null;
    public bool $smsCancellationSent = false;
    public ?string $smsDeliveryStatus = null;
    public string $confirmationToken = '';
    public ?int $employeeId = null;
    public ?int $locationId = null;
    public ?int $serviceId = null;
    public ?int $eventDateId = null;
    public int $quantity = 1;
    public float $totalPrice = 0.0;

    /**
     * The site the booking originated from (may differ from the element's siteId
     * for non-localized elements saved via Commerce on the primary site).
     */
    public ?int $bookingSiteId = null;

    private ?EventDate $_eventDate = null;
    private ?Service $_service = null;
    private ?Employee $_employee = null;
    private ?Location $_location = null;

    // ReservationInterface property getters
    public function getId(): ?int
    {
        return $this->id;
    }
    public function getUid(): ?string
    {
        return $this->uid;
    }
    public function getSiteId(): ?int
    {
        return $this->bookingSiteId ?? $this->siteId;
    }
    public function getUserName(): string
    {
        return $this->userName;
    }
    public function getUserEmail(): string
    {
        return $this->userEmail;
    }
    public function getUserPhone(): ?string
    {
        return $this->userPhone;
    }
    public function getUserId(): ?int
    {
        return $this->userId;
    }
    public function getUserTimezone(): ?string
    {
        return $this->userTimezone;
    }
    public function customerEmail(): string
    {
        return $this->userEmail;
    }
    public function customerName(): string
    {
        return $this->userName;
    }
    public function getBookingDate(): string
    {
        return $this->bookingDate;
    }
    public function getStartTime(): string
    {
        return $this->startTime;
    }
    public function getEndTime(): string
    {
        return $this->endTime;
    }
    public function getNotes(): ?string
    {
        return $this->notes;
    }
    public function getQuantity(): int
    {
        return $this->quantity;
    }
    public function getConfirmationToken(): string
    {
        return $this->confirmationToken;
    }
    public function getVirtualMeetingUrl(): ?string
    {
        return $this->virtualMeetingUrl;
    }
    public function getVirtualMeetingProvider(): ?string
    {
        return $this->virtualMeetingProvider;
    }
    public function getVirtualMeetingId(): ?string
    {
        return $this->virtualMeetingId;
    }
    public function getGoogleEventId(): ?string
    {
        return $this->googleEventId;
    }
    public function getOutlookEventId(): ?string
    {
        return $this->outlookEventId;
    }
    public function getNotificationSent(): bool
    {
        return $this->notificationSent;
    }
    public function getEmailReminder24hSent(): bool
    {
        return $this->emailReminder24hSent;
    }
    public function getEmailReminder1hSent(): bool
    {
        return $this->emailReminder1hSent;
    }
    public function getSmsReminder24hSent(): bool
    {
        return $this->smsReminder24hSent;
    }
    public function getSmsConfirmationSent(): bool
    {
        return $this->smsConfirmationSent;
    }
    public function getSmsConfirmationSentAt(): ?\DateTime
    {
        return $this->smsConfirmationSentAt;
    }
    public function getSmsCancellationSent(): bool
    {
        return $this->smsCancellationSent;
    }
    public function getSmsDeliveryStatus(): ?string
    {
        return $this->smsDeliveryStatus;
    }
    public function getEmployeeId(): ?int
    {
        return $this->employeeId;
    }
    public function getLocationId(): ?int
    {
        return $this->locationId;
    }
    public function getServiceId(): ?int
    {
        return $this->serviceId;
    }
    public function getEventDateId(): ?int
    {
        return $this->eventDateId;
    }
    public function getDateCreated(): ?\DateTime
    {
        return $this->dateCreated;
    }
    public function getDateUpdated(): ?\DateTime
    {
        return $this->dateUpdated;
    }

    // Static metadata
    public static function displayName(): string
    {
        return Craft::t('booked', 'element.reservation');
    }
    public static function lowerDisplayName(): string
    {
        return Craft::t('booked', 'element.reservationLower');
    }
    public static function pluralDisplayName(): string
    {
        return Craft::t('booked', 'element.reservations');
    }
    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('booked', 'element.reservationsLower');
    }
    public static function refHandle(): ?string
    {
        return 'reservation';
    }
    public static function gqlTypeNameByContext(mixed $context): string
    {
        return 'BookedReservation';
    }
    public function getGqlTypeName(): string
    {
        return static::gqlTypeNameByContext(null);
    }
    public static function createCondition(): \craft\elements\conditions\ElementConditionInterface
    {
        return \Craft::createObject(conditions\ReservationCondition::class, [static::class]);
    }

    public static function hasTitles(): bool
    {
        return false;
    }
    public static function hasStatuses(): bool
    {
        return true;
    }
    public static function statuses(): array
    {
        return ['confirmed' => 'green', 'pending' => 'orange', 'cancelled' => null, 'no_show' => 'red'];
    }

    public static function eagerLoadingMap(array $sourceElements, string $handle): array|null|false
    {
        $propMap = [
            'service' => ['prop' => 'serviceId', 'type' => Service::class],
            'employee' => ['prop' => 'employeeId', 'type' => Employee::class],
            'location' => ['prop' => 'locationId', 'type' => Location::class],
            'eventDate' => ['prop' => 'eventDateId', 'type' => EventDate::class],
        ];

        if (isset($propMap[$handle])) {
            $prop = $propMap[$handle]['prop'];
            $map = [];
            foreach ($sourceElements as $el) {
                if ($el->$prop) {
                    $map[] = ['source' => $el->id, 'target' => $el->$prop];
                }
            }

            return ['elementType' => $propMap[$handle]['type'], 'map' => $map];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    public function setEagerLoadedElements(string $handle, array $elements, \craft\elements\db\EagerLoadPlan $plan): void
    {
        switch ($handle) {
            case 'service':
                /** @var Service|null $service */
                $service = $elements[0] ?? null;
                $this->_service = $service;
                break;
            case 'employee':
                /** @var Employee|null $employee */
                $employee = $elements[0] ?? null;
                $this->_employee = $employee;
                break;
            case 'location':
                /** @var Location|null $location */
                $location = $elements[0] ?? null;
                $this->_location = $location;
                break;
            case 'eventDate':
                /** @var EventDate|null $eventDate */
                $eventDate = $elements[0] ?? null;
                $this->_eventDate = $eventDate;
                break;
            default:
                parent::setEagerLoadedElements($handle, $elements, $plan);
        }
    }

    /** @return ReservationQuery */
    public static function find(): ReservationQuery
    {
        return new ReservationQuery(static::class);
    }

    protected function getRecord(): ?ReservationRecord
    {
        return ReservationRecord::findOne($this->id);
    }
    protected function getSettings(): \anvildev\booked\models\Settings
    {
        return \anvildev\booked\models\Settings::loadSettings();
    }

    protected static function defineActions(?string $source = null): array
    {
        return [
            actions\MarkAsNoShow::class,
            Delete::class,
        ];
    }

    protected static function defineExporters(string $source): array
    {
        $exporters = parent::defineExporters($source);
        $exporters[] = exporters\ReservationCsvExporter::class;

        return $exporters;
    }


    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('booked', 'element.allReservations'),
                'defaultSort' => ['bookingDate', 'desc'],
                'type' => 'native',
            ],
            [
                'heading' => Craft::t('booked', 'labels.status'),
            ],
            [
                'key' => 'confirmed',
                'label' => Craft::t('booked', 'status.confirmed'),
                'criteria' => ['reservationStatus' => ReservationRecord::STATUS_CONFIRMED],
                'defaultSort' => ['bookingDate', 'desc'],
                'type' => 'native',
            ],
            [
                'key' => 'pending',
                'label' => Craft::t('booked', 'status.pending'),
                'criteria' => ['reservationStatus' => ReservationRecord::STATUS_PENDING],
                'defaultSort' => ['bookingDate', 'desc'],
                'type' => 'native',
            ],
            [
                'key' => 'cancelled',
                'label' => Craft::t('booked', 'status.cancelled'),
                'criteria' => ['reservationStatus' => ReservationRecord::STATUS_CANCELLED],
                'defaultSort' => ['bookingDate', 'desc'],
                'type' => 'native',
            ],
            [
                'key' => 'no_show',
                'label' => Craft::t('booked', 'status.noShow'),
                'criteria' => ['reservationStatus' => ReservationRecord::STATUS_NO_SHOW],
                'defaultSort' => ['bookingDate', 'desc'],
                'type' => 'native',
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'id' => ['label' => Craft::t('booked', 'reservation.id')],
            'userName' => ['label' => Craft::t('booked', 'reservation.name')],
            'userEmail' => ['label' => Craft::t('booked', 'reservation.email')],
            'serviceName' => ['label' => Craft::t('booked', 'reservation.service')],
            'bookingDate' => ['label' => Craft::t('booked', 'reservation.dateTime')],
            'quantity' => ['label' => Craft::t('booked', 'reservation.seats')],
            'duration' => ['label' => Craft::t('booked', 'reservation.duration')],
            'eventDateName' => ['label' => Craft::t('booked', 'reservation.eventDate')],
            'dateCreated' => ['label' => Craft::t('booked', 'reservation.created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['id', 'userName', 'userEmail', 'serviceName', 'eventDateName', 'bookingDate', 'quantity', 'duration', 'dateCreated'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['userName', 'userEmail', 'userPhone', 'notes'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('booked', 'reservation.name'),
                'orderBy' => 'booked_reservations.userName',
                'attribute' => 'userName',
            ],
            [
                'label' => Craft::t('booked', 'reservation.email'),
                'orderBy' => 'booked_reservations.userEmail',
                'attribute' => 'userEmail',
            ],
            [
                'label' => Craft::t('booked', 'reservation.sortBookingDate'),
                'orderBy' => 'booked_reservations.bookingDate',
                'attribute' => 'bookingDate',
            ],
            [
                'label' => Craft::t('booked', 'reservation.created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
            ],
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        $url = $this->getCpEditUrl();
        return match ($attribute) {
            'id' => $url ? Html::a('#' . $this->id, $url) : '#' . $this->id,
            'userName' => Html::encode($this->userName),
            'userEmail' => Html::encode($this->userEmail),
            'serviceName' => ($svc = $this->getService()) ? Html::encode($svc->title) : Html::tag('span', '-', ['class' => 'light']),
            'eventDateName' => ($ed = $this->getEventDate())
                ? Html::encode($ed->title) . Html::tag('br') . Html::tag('span', $ed->getFormattedDate(), ['class' => 'light', 'style' => 'font-size: 11px;'])
                : Html::tag('span', "\u{2014}", ['class' => 'light']),
            'bookingDate' => Html::tag('div',
                Html::tag('strong', Craft::$app->formatter->asDate($this->bookingDate, 'short')) .
                Html::tag('br') .
                Html::tag('span', $this->startTime . ' - ' . $this->endTime, ['class' => 'light', 'style' => 'font-size: 11px;'])
            ),
            'quantity' => ($qty = $this->quantity ?? 1) > 1
                ? Html::tag('span', $qty . 'x', ['class' => 'badge', 'style' => 'background-color: #0d78f2; color: white;'])
                : Html::tag('span', (string)$qty, ['class' => 'light']),
            'duration' => Html::tag('span', $this->getDurationMinutes() . ' Min.', ['class' => 'light']),
            default => parent::attributeHtml($attribute),
        };
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['userName', 'userEmail', 'bookingDate', 'startTime', 'endTime'], 'required'],
            [['userEmail'], 'email'],
            [['userName', 'userEmail', 'userPhone'], 'string', 'max' => 255],
            [['userTimezone'], 'string', 'max' => 50],
            [['bookingDate'], ValidationHelper::DATE_VALIDATOR, 'format' => ValidationHelper::DATE_FORMAT],
            [['startTime', 'endTime'], 'match', 'pattern' => ValidationHelper::TIME_FORMAT_PATTERN],
            [['status'], 'in', 'range' => [
                ReservationRecord::STATUS_PENDING,
                ReservationRecord::STATUS_CONFIRMED,
                ReservationRecord::STATUS_CANCELLED,
                ReservationRecord::STATUS_NO_SHOW,
            ]],
            [['notes', 'virtualMeetingUrl', 'virtualMeetingProvider', 'virtualMeetingId'], 'string'],
            [['notificationSent', 'emailReminder24hSent', 'emailReminder1hSent', 'smsReminder24hSent', 'smsConfirmationSent', 'smsCancellationSent'], 'boolean'],
            [['smsDeliveryStatus'], 'string', 'max' => 20],
            [['confirmationToken'], 'string', 'max' => 64],
            [['quantity'], 'integer', 'min' => 1],
            [['quantity'], 'required'],
            [['quantity'], 'default', 'value' => 1],
            [['userId'], 'integer'],
            // Custom validation: Employee-Location consistency
            ['locationId', 'validateEmployeeAndLocationExist'],
        ]);
    }

    protected function validateBookingDate(): void
    {
        if (!$this->id && $this->bookingDate && $this->startTime) {
            $bookingDateTime = DateHelper::parseDateTime($this->bookingDate, $this->startTime);
            $now = new \DateTime();

            if (!$bookingDateTime) {
                return; // Invalid date/time format, let other validators handle it
            }

            // Check if booking is in the past
            if ($bookingDateTime->getTimestamp() < $now->getTimestamp()) {
                $this->addError('bookingDate', Craft::t('booked', 'validation.pastBookingNotAllowed'));
                return;
            }

            // Check minimum advance booking time
            $settings = $this->getSettings();
            $minimumAdvanceHours = $settings->minimumAdvanceBookingHours ?? 2;

            // If set to 0, allow immediate bookings
            if ($minimumAdvanceHours > 0) {
                $minimumBookingTime = clone $now;
                $minimumBookingTime->modify("+{$minimumAdvanceHours} hours");

                if ($bookingDateTime->getTimestamp() < $minimumBookingTime->getTimestamp()) {
                    $hoursText = $minimumAdvanceHours === 1
                        ? Craft::t('booked', 'labels.hour')
                        : Craft::t('booked', 'labels.hours');
                    $this->addError('bookingDate', Craft::t('booked', 'validation.minimumAdvanceBooking', [
                        'hours' => $minimumAdvanceHours,
                        'hoursText' => $hoursText,
                    ]));
                }
            }
        }
    }

    protected function validateQuantity(): void
    {
        if ($this->quantity < 1) {
            $this->addError('quantity', Craft::t('booked', 'validation.quantityMinimum'));
        }
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function init(): void
    {
        parent::init();
        if (empty($this->userTimezone)) {
            $this->userTimezone = Craft::$app->getTimeZone();
        }
    }

    public function __toString(): string
    {
        if ($this->userName && $this->bookingDate) {
            return "$this->userName — $this->bookingDate";
        }

        return $this->userName ?: parent::__toString();
    }

    public function canDuplicate(User $user): bool
    {
        return false;
    }

    protected function cpEditUrl(): ?string
    {
        return 'booked/bookings/' . $this->id;
    }

    public function canView(User $user): bool
    {
        if ($user->admin) {
            return true;
        }
        if (!$user->can('booked-viewBookings')) {
            return false;
        }
        // Staff scoping: can only view bookings for their linked employees
        if (!$user->can('booked-manageBookings')) {
            $employees = Booked::getInstance()->getPermission()->getEmployeesForUser($user->id);
            if ($employees && !in_array($this->employeeId, array_map(fn($e) => $e->id, $employees), true)) {
                return false;
            }
        }
        return true;
    }

    public function canSave(User $user): bool
    {
        return $user->admin || $user->can('booked-manageBookings');
    }

    public function canDelete(User $user): bool
    {
        return $user->admin || $user->can('booked-manageBookings');
    }

    /** Validate employee and location exist when both IDs are set */
    public function validateEmployeeAndLocationExist($attribute, $params): void
    {
        if (!$this->employeeId || !$this->locationId) {
            return;
        }
        if (!Employee::find()->id($this->employeeId)->siteId('*')->exists()) {
            $this->addError('employeeId', Craft::t('booked', 'reservation.employeeNotExist'));
        }
        if (!Location::find()->id($this->locationId)->siteId('*')->exists()) {
            $this->addError('locationId', Craft::t('booked', 'reservation.locationNotExist'));
        }
    }

    public function extraFields(): array
    {
        return [
            'extras' => 'getExtras',
            'extrasPrice' => 'getExtrasPrice',
            'extrasSummary' => 'getExtrasSummary',
            'totalPrice' => 'getTotalPrice',
            'totalDuration' => 'getTotalDuration',
            'hasExtras' => 'hasExtras',
        ];
    }

    public function beforeValidate(): bool
    {
        if (!parent::beforeValidate()) {
            return false;
        }
        if ($this->eventDateId) {
            $eventDate = $this->getEventDate();
            if (!$eventDate) {
                $this->addError('eventDateId', Craft::t('booked', 'validation.eventDateNotFound'));
                return false;
            }
            if (!$eventDate->isAvailable()) {
                $this->addError('eventDateId', Craft::t('booked', 'validation.eventNotAvailable'));
                return false;
            }
        } else {
            $this->validateTimeRange();
            $this->validateBookingDate();
        }
        $this->validateQuantity();
        return true;
    }

    public function afterSave(bool $isNew): void
    {
        $wasCancelled = false;
        $quantityReduced = false;

        if (!$isNew) {
            $record = $this->getRecord();
            if (!$record) {
                throw new \Exception('Invalid reservation ID: ' . $this->id);
            }

            // Detect status transition to cancelled (for waitlist notification)
            if ($record->status !== ReservationRecord::STATUS_CANCELLED
                && $this->status === ReservationRecord::STATUS_CANCELLED) {
                $wasCancelled = true;
            }

            // Detect quantity reduction (frees up capacity → notify waitlist)
            if ((int)$record->quantity > (int)$this->quantity) {
                $quantityReduced = true;
            }
        } else {
            // Check if a row already exists (e.g. from batch-inserted seed data
            // that has no matching elements entry). If so, update it instead of inserting.
            $record = ReservationRecord::findOne($this->id) ?? new ReservationRecord();
            $record->id = (int)$this->id;

            // Generate confirmation token for new reservations
            if (empty($this->confirmationToken)) {
                $this->confirmationToken = ReservationRecord::generateConfirmationToken();
            }
        }

        $record->userName = $this->userName;
        $record->userEmail = $this->userEmail;
        $record->userPhone = $this->userPhone;
        $record->userId = $this->userId;
        $record->userTimezone = $this->userTimezone ?? Craft::$app->getTimeZone();

        // Store times directly in the configured timezone (no conversion)
        $record->bookingDate = $this->bookingDate;
        $record->startTime = $this->startTime;
        $record->endTime = $this->endTime;

        $record->status = $this->status;
        $record->notes = $this->notes;
        $record->virtualMeetingUrl = $this->virtualMeetingUrl;
        $record->virtualMeetingProvider = $this->virtualMeetingProvider;
        $record->virtualMeetingId = $this->virtualMeetingId;
        $record->googleEventId = $this->googleEventId ?? null;
        $record->outlookEventId = $this->outlookEventId ?? null;
        $record->notificationSent = $this->notificationSent;
        $record->emailReminder24hSent = $this->emailReminder24hSent;
        $record->emailReminder1hSent = $this->emailReminder1hSent;
        $record->smsReminder24hSent = $this->smsReminder24hSent;
        $record->smsConfirmationSent = $this->smsConfirmationSent;
        $record->smsConfirmationSentAt = $this->smsConfirmationSentAt;
        $record->smsCancellationSent = $this->smsCancellationSent;
        $record->smsDeliveryStatus = $this->smsDeliveryStatus;
        $record->confirmationToken = $this->confirmationToken;
        $record->employeeId = $this->employeeId;
        $record->locationId = $this->locationId;
        $record->serviceId = $this->serviceId;
        $record->eventDateId = $this->eventDateId;
        $record->quantity = $this->quantity;
        $record->siteId = $this->bookingSiteId ?? $this->siteId;

        try {
            $record->save(false);
        } catch (\yii\db\IntegrityException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'activeSlotKey') || str_contains($message, 'Duplicate entry')) {
                throw new \anvildev\booked\exceptions\BookingConflictException(
                    Craft::t('booked', 'booking.slotAlreadyBooked')
                );
            }

            Craft::error('Reservation save failed with IntegrityException: ' . $message, __METHOD__);
            throw $e;
        }

        // Notify waitlist when capacity is freed (cancellation or quantity reduction via CP)
        if ($wasCancelled || $quantityReduced) {
            $waitlist = Booked::getInstance()->waitlist;
            if ($this->eventDateId) {
                try {
                    $waitlist->checkAndNotifyEventWaitlist($this->eventDateId);
                } catch (\Throwable $e) {
                    Craft::error("Failed to notify event waitlist after CP change: " . $e->getMessage(), __METHOD__);
                }
            }
            if ($this->serviceId) {
                try {
                    $waitlist->checkAndNotifyWaitlist(
                        $this->serviceId,
                        $this->bookingDate,
                        $this->startTime,
                        $this->endTime,
                        $this->employeeId,
                        $this->locationId
                    );
                } catch (\Throwable $e) {
                    Craft::error("Failed to notify service waitlist after CP change: " . $e->getMessage(), __METHOD__);
                }
            }
        }

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        $this->getRecord()?->delete();
        parent::afterDelete();
    }

    public static function getStatuses(): array
    {
        return ReservationRecord::getStatuses();
    }
    public function getStatusLabel(): string
    {
        return self::getStatuses()[$this->status] ?? 'Unknown';
    }

    public function cancel(): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->status = ReservationRecord::STATUS_CANCELLED;
        return Craft::$app->elements->saveElement($this);
    }

    public function markAsNoShow(): bool
    {
        if ($this->status === ReservationRecord::STATUS_CANCELLED) {
            return false;
        }
        if ($this->status === ReservationRecord::STATUS_NO_SHOW) {
            return false;
        }

        $this->status = ReservationRecord::STATUS_NO_SHOW;
        return Craft::$app->elements->saveElement($this);
    }

    public function getDurationMinutes(): int
    {
        $start = DateHelper::parseTime($this->startTime);
        $end = DateHelper::parseTime($this->endTime);

        if (!$start || !$end) {
            return 0;
        }

        $diff = $start->diff($end);
        return (int) ($diff->h * 60 + $diff->i);
    }

    public function conflictsWith(ReservationInterface $other): bool
    {
        if ($this->getBookingDate() !== $other->getBookingDate()) {
            return false;
        }

        $thisStart = DateHelper::parseTime($this->getStartTime());
        $thisEnd = DateHelper::parseTime($this->getEndTime());
        $otherStart = DateHelper::parseTime($other->getStartTime());
        $otherEnd = DateHelper::parseTime($other->getEndTime());

        if (!$thisStart || !$thisEnd || !$otherStart || !$otherEnd) {
            return false; // Invalid times, no conflict
        }

        return !($thisEnd->getTimestamp() <= $otherStart->getTimestamp() || $thisStart->getTimestamp() >= $otherEnd->getTimestamp());
    }

    public function getManagementUrl(): string
    {
        return UrlHelper::siteUrl('booking/manage/' . $this->confirmationToken, null, null, $this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id);
    }
    public function getCancelUrl(): string
    {
        return UrlHelper::siteUrl('booking/cancel/' . $this->confirmationToken, null, null, $this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id);
    }
    public function getIcsUrl(): string
    {
        return UrlHelper::siteUrl('booking/ics/' . $this->confirmationToken, null, null, $this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id);
    }

    public function getBookingDateTime(): ?\DateTime
    {
        if (empty($this->bookingDate) || empty($this->startTime)) {
            return null;
        }

        try {
            $dateTimeString = $this->bookingDate . ' ' . $this->startTime;
            $tz = new \DateTimeZone($this->userTimezone ?: Craft::$app->getTimeZone());
            $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $dateTimeString, $tz);
            if (!$dateTime) {
                $dateTime = \DateTime::createFromFormat('Y-m-d H:i', $dateTimeString, $tz);
            }

            return $dateTime;
        } catch (\Exception $e) {
            \Craft::error('Failed to create DateTime: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    public function getEmployee(): ?Employee
    {
        if ($this->_employee === null && $this->employeeId !== null) {
            $this->_employee = Employee::find()->id($this->employeeId)->siteId('*')->one();
        }
        return $this->_employee;
    }

    public function getUser(): ?User
    {
        return $this->userId !== null ? User::find()->id($this->userId)->one() : null;
    }

    public function getService(): ?Service
    {
        if ($this->_service === null && $this->serviceId !== null) {
            /** @var Service|null $service */
            $service = ElementQueryHelper::forAllSites(Service::find()->id($this->serviceId))->one();
            $this->_service = $service;
        }
        return $this->_service;
    }

    public function getEventDate(): ?EventDate
    {
        if ($this->_eventDate === null && $this->eventDateId) {
            $this->_eventDate = Booked::getInstance()->eventDate->getEventDateById($this->eventDateId);
        }
        return $this->_eventDate;
    }

    public function isEventBased(): bool
    {
        return $this->eventDateId !== null;
    }

    public function getLocation(): ?Location
    {
        if ($this->_location === null && $this->locationId !== null) {
            $this->_location = Location::find()->id($this->locationId)->siteId('*')->one();
        }
        return $this->_location;
    }

    public function getExtras(): array
    {
        return $this->id ? Booked::getInstance()->serviceExtra->getExtrasForReservation($this->id) : [];
    }

    public function getExtrasPrice(): float
    {
        return $this->id ? Booked::getInstance()->serviceExtra->getTotalExtrasPrice($this->id) : 0.0;
    }

    public function getExtrasSummary(): string
    {
        return $this->id ? Booked::getInstance()->serviceExtra->getExtrasSummary($this->id) : '';
    }

    public function getTotalPrice(): float
    {
        $service = $this->getService();
        $servicePrice = ($service && isset($service->price)) ? (float)$service->price * $this->quantity : 0.0;
        $eventDate = $this->getEventDate();
        $eventPrice = ($eventDate && $eventDate->price) ? (float)$eventDate->price * $this->quantity : 0.0;
        return $servicePrice + $eventPrice + $this->getExtrasPrice();
    }

    public function recalculateTotals(): void
    {
        $this->totalPrice = $this->getTotalPrice();
    }

    public function getTotalDuration(): int
    {
        return $this->getDurationMinutes() + array_sum(array_map(
            fn($item) => ($item['extra'] && $item['extra']->duration > 0) ? $item['extra']->duration * $item['quantity'] : 0,
            $this->getExtras()
        ));
    }

    public function hasExtras(): bool
    {
        return count($this->getExtras()) > 0;
    }

    public static function findByToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return self::find()
            ->confirmationToken($token)
            ->one();
    }

    // ReservationInterface persistence
    public function save(bool $runValidation = true): bool
    {
        return Craft::$app->elements->saveElement($this, $runValidation);
    }
    public function delete(): bool
    {
        return Craft::$app->elements->deleteElement($this);
    }

    // PurchasableInterface (only called when Commerce is installed)

    public function getStore(): \craft\commerce\models\Store
    {
        return \craft\commerce\Plugin::getInstance()->getStores()->getStoreBySiteId($this->siteId);
    }

    public function getStoreId(): int
    {
        return $this->getStore()->id;
    }

    public function getPrice(): ?float
    {
        $service = $this->getService();
        $unitPrice = ($service && isset($service->price)) ? (float) $service->price : 0.0;

        $eventDate = $this->getEventDate();
        if ($eventDate && $eventDate->price) {
            $unitPrice += (float) $eventDate->price;
        }

        $extrasTotal = $this->getExtrasPrice();
        if ($extrasTotal > 0) {
            $unitPrice += $extrasTotal / max(1, $this->quantity);
        }

        return $unitPrice;
    }

    public function getPromotionalPrice(): ?float
    {
        return null;
    }

    public function getSalePrice(): ?float
    {
        return $this->getPrice();
    }

    public function getSales(): array
    {
        return \craft\commerce\Plugin::getInstance()->getSales()->getSalesForPurchasable($this);
    }

    public function getSku(): string
    {
        return 'BOOKING-' . ($this->id ?? 'NEW');
    }

    public function getDescription(): string
    {
        $eventDate = $this->getEventDate();
        $service = $this->getService();
        $date = \DateTime::createFromFormat('Y-m-d', $this->bookingDate);
        $formattedDate = ($date ? $date->format('d.m.Y') : $this->bookingDate) . ' at ' . substr($this->startTime, 0, 5);

        if ($eventDate) {
            $desc = $eventDate->title . ' - ' . $formattedDate;
        } else {
            $desc = ($service ? $service->title : 'Service') . ' - ' . $formattedDate;
        }

        if ($this->id) {
            try {
                $extras = Booked::getInstance()->serviceExtra->getExtrasForReservation($this->id);
                if ($extras) {
                    $desc .= ' + ' . implode(', ', array_map(fn($e) => $e['extra']->title, $extras));
                }
            } catch (\Throwable) {
            }
        }
        return $desc;
    }

    public function getTaxCategory(): \craft\commerce\models\TaxCategory
    {
        $taxCategories = \craft\commerce\Plugin::getInstance()->getTaxCategories();
        $service = $this->getService();
        if ($service?->taxCategoryId && ($cat = $taxCategories->getTaxCategoryById($service->taxCategoryId))) {
            return $cat;
        }
        $settings = \anvildev\booked\models\Settings::loadSettings();
        if ($settings->commerceTaxCategoryId && ($cat = $taxCategories->getTaxCategoryById($settings->commerceTaxCategoryId))) {
            return $cat;
        }
        return $taxCategories->getDefaultTaxCategory();
    }

    public function getShippingCategory(): \craft\commerce\models\ShippingCategory
    {
        return \craft\commerce\Plugin::getInstance()->getShippingCategories()->getDefaultShippingCategory($this->getStoreId());
    }

    public function getIsAvailable(): bool
    {
        return $this->status !== ReservationRecord::STATUS_CANCELLED;
    }

    public function populateLineItem(\craft\commerce\models\LineItem $lineItem): void
    {
        $lineItem->qty = $this->quantity ?? 1;
        $lineItem->price = $this->getPrice();
        $lineItem->sku = $this->getSku();
        $lineItem->description = $this->getDescription();
    }

    public function getSnapshot(): array
    {
        $snapshot = [
            'bookingDate' => $this->bookingDate, 'startTime' => $this->startTime, 'endTime' => $this->endTime,
            'userName' => $this->userName, 'userEmail' => $this->userEmail,
        ];
        if ($this->eventDateId) {
            $eventDate = $this->getEventDate();
            $snapshot['eventDateId'] = $this->eventDateId;
            $snapshot['eventDateTitle'] = $eventDate?->title;
        }
        return $snapshot;
    }

    public function getLineItemRules(\craft\commerce\models\LineItem $lineItem): array
    {
        return [];
    }

    public function afterOrderComplete(\craft\commerce\elements\Order $order, \craft\commerce\models\LineItem $lineItem): void
    {
    }

    public function hasFreeShipping(): bool
    {
        return true;
    }

    public function getIsShippable(): bool
    {
        return false;
    }

    public function getIsTaxable(): bool
    {
        return true;
    }

    public function getIsPromotable(): bool
    {
        return false;
    }

    public function getPromotionRelationSource(): mixed
    {
        return null;
    }

    public static function hasInventory(): bool
    {
        return false;
    }
}
