<?php

namespace anvildev\booked\models;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\contracts\ReservationQueryInterface;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\EventDate;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use anvildev\booked\helpers\DateHelper;
use anvildev\booked\helpers\ElementQueryHelper;
use anvildev\booked\models\db\ReservationModelQuery;
use anvildev\booked\records\ReservationRecord;
use anvildev\booked\traits\HasCancellationPolicy;
use anvildev\booked\traits\HasFormattedDateTime;
use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\UrlHelper;

class ReservationModel extends Model implements ReservationInterface
{
    use HasCancellationPolicy;
    use HasFormattedDateTime;

    public ?int $id = null;
    public ?string $uid = null;
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
    public ?int $siteId = null;
    public int $quantity = 1;
    public float $totalPrice = 0.0;
    public ?\DateTime $dateCreated = null;
    public ?\DateTime $dateUpdated = null;

    private ?Service $_service = null;
    private ?Employee $_employee = null;
    private ?Location $_location = null;
    private ?EventDate $_eventDate = null;
    private ?User $_user = null;

    public static function find(): ReservationQueryInterface
    {
        return new ReservationModelQuery();
    }

    public static function findByToken(string $token): ?ReservationInterface
    {
        return $token === '' ? null : self::find()->confirmationToken($token)->one();
    }

    public static function getStatuses(): array
    {
        return ReservationRecord::getStatuses();
    }

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
        return $this->siteId;
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
    public function getStatus(): ?string
    {
        return $this->status;
    }
    public function getNotes(): ?string
    {
        return $this->notes;
    }
    public function getQuantity(): int
    {
        return $this->quantity ?? 1;
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

    public function getService(): ?Service
    {
        if ($this->_service === null && $this->serviceId) {
            $this->_service = ElementQueryHelper::forAllSites(
                Service::find()->id($this->serviceId)
            )->one();
        }
        return $this->_service;
    }

    public function getEmployee(): ?Employee
    {
        if ($this->_employee === null && $this->employeeId) {
            $this->_employee = Employee::find()->id($this->employeeId)->siteId('*')->one();
        }
        return $this->_employee;
    }

    public function getLocation(): ?Location
    {
        if ($this->_location === null && $this->locationId) {
            $this->_location = Location::find()->id($this->locationId)->siteId('*')->one();
        }
        return $this->_location;
    }

    public function setService(?Service $service): void
    {
        $this->_service = $service;
    }
    public function setEmployee(?Employee $employee): void
    {
        $this->_employee = $employee;
    }
    public function setLocation(?Location $location): void
    {
        $this->_location = $location;
    }

    public function getEventDate(): ?EventDate
    {
        if ($this->_eventDate === null && $this->eventDateId) {
            $this->_eventDate = Booked::getInstance()->eventDate->getEventDateById($this->eventDateId);
        }
        return $this->_eventDate;
    }

    public function getUser(): ?User
    {
        if ($this->_user === null && $this->userId) {
            $this->_user = User::find()->id($this->userId)->one();
        }
        return $this->_user;
    }

    public function getExtras(): array
    {
        return $this->id ? Booked::getInstance()->serviceExtra->getExtrasForReservation($this->id) : [];
    }

    public function cancel(): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->status = ReservationRecord::STATUS_CANCELLED;
        return $this->save(false);
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
            return false;
        }

        return !($thisEnd->getTimestamp() <= $otherStart->getTimestamp()
            || $thisStart->getTimestamp() >= $otherEnd->getTimestamp());
    }

    public function getBookingDateTime(): ?\DateTime
    {
        if (empty($this->bookingDate) || empty($this->startTime)) {
            return null;
        }

        try {
            $dateTimeString = $this->bookingDate . ' ' . $this->startTime;
            $tz = new \DateTimeZone($this->userTimezone ?: Craft::$app->getTimeZone());
            return \DateTime::createFromFormat('Y-m-d H:i:s', $dateTimeString, $tz)
                ?: \DateTime::createFromFormat('Y-m-d H:i', $dateTimeString, $tz)
                ?: null;
        } catch (\Exception $e) {
            Craft::error('Failed to create DateTime: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    public function getStatusLabel(): string
    {
        return self::getStatuses()[$this->status] ?? 'Unknown';
    }

    public function isEventBased(): bool
    {
        return $this->eventDateId !== null;
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

    public function getCpEditUrl(): ?string
    {
        return $this->id ? UrlHelper::cpUrl('booked/bookings/' . $this->id) : null;
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
        $servicePrice = ($service && isset($service->price)) ? (float) $service->price * $this->quantity : 0.0;
        $eventDate = $this->getEventDate();
        $eventPrice = ($eventDate && $eventDate->price) ? (float) $eventDate->price * $this->quantity : 0.0;
        return $servicePrice + $eventPrice + $this->getExtrasPrice();
    }

    public function recalculateTotals(): void
    {
        $this->totalPrice = $this->getTotalPrice();
    }

    public function getTotalDuration(): int
    {
        $extrasDuration = 0;
        foreach ($this->getExtras() as $item) {
            if ($item['extra'] && $item['extra']->duration > 0) {
                $extrasDuration += $item['extra']->duration * $item['quantity'];
            }
        }
        return $this->getDurationMinutes() + $extrasDuration;
    }

    public function hasExtras(): bool
    {
        return count($this->getExtras()) > 0;
    }

    public function save(bool $runValidation = true): bool
    {
        if ($runValidation && !$this->validate()) {
            return false;
        }

        $isNew = $this->id === null;

        if ($isNew) {
            $record = new ReservationRecord();
            if (empty($this->confirmationToken)) {
                $this->confirmationToken = ReservationRecord::generateConfirmationToken();
            }
            if (empty($this->uid)) {
                $this->uid = \craft\helpers\StringHelper::UUID();
            }
        } else {
            $record = ReservationRecord::findOne($this->id);
            if (!$record) {
                $this->addError('id', 'Reservation not found.');
                return false;
            }
        }

        if ($isNew && $this->siteId === null) {
            $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        $record->userName = $this->userName;
        $record->userEmail = $this->userEmail;
        $record->userPhone = $this->userPhone;
        $record->userId = $this->userId;
        $record->userTimezone = $this->userTimezone ?? Craft::$app->getTimeZone();
        $record->bookingDate = $this->bookingDate;
        $record->startTime = $this->startTime;
        $record->endTime = $this->endTime;
        $record->status = $this->status;
        $record->notes = $this->notes;
        $record->virtualMeetingUrl = $this->virtualMeetingUrl;
        $record->virtualMeetingProvider = $this->virtualMeetingProvider;
        $record->virtualMeetingId = $this->virtualMeetingId;
        $record->googleEventId = $this->googleEventId;
        $record->outlookEventId = $this->outlookEventId;
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
        $record->siteId = $this->siteId;
        $record->quantity = $this->quantity;

        if (!$record->save(false)) {
            $this->addErrors($record->getErrors());
            return false;
        }

        if ($isNew) {
            $this->id = $record->id;
        }
        $this->dateCreated = self::parseDateTime($record->dateCreated);
        $this->dateUpdated = self::parseDateTime($record->dateUpdated);

        return true;
    }

    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        $record = ReservationRecord::findOne($this->id);
        return $record && $record->delete() !== false;
    }

    public function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['userName', 'userEmail', 'bookingDate', 'startTime', 'endTime'], 'required'],
            [['userEmail'], 'email'],
            [['userName', 'userEmail', 'userPhone'], 'string', 'max' => 255],
            [['userTimezone'], 'string', 'max' => 50],
            [['bookingDate'], 'date', 'format' => 'php:Y-m-d'],
            [['startTime', 'endTime'], 'match', 'pattern' => '/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            [['status'], 'in', 'range' => [
                ReservationRecord::STATUS_PENDING,
                ReservationRecord::STATUS_CONFIRMED,
                ReservationRecord::STATUS_CANCELLED,
            ]],
            [['notes', 'virtualMeetingUrl', 'virtualMeetingProvider', 'virtualMeetingId'], 'string'],
            [['notificationSent', 'emailReminder24hSent', 'emailReminder1hSent', 'smsReminder24hSent', 'smsConfirmationSent', 'smsCancellationSent'], 'boolean'],
            [['smsDeliveryStatus'], 'string', 'max' => 20],
            [['confirmationToken'], 'string', 'max' => 64],
            [['quantity'], 'integer', 'min' => 1],
            [['quantity'], 'default', 'value' => 1],
            [['userId', 'employeeId', 'locationId', 'serviceId', 'eventDateId', 'siteId'], 'integer'],
        ]);
    }

    public static function fromRecord(ReservationRecord $record): self
    {
        $model = new self();
        $model->id = $record->id;
        $model->uid = $record->uid ?? null;
        $model->userName = $record->userName;
        $model->userEmail = $record->userEmail;
        $model->userPhone = $record->userPhone;
        $model->userId = $record->userId;
        $model->userTimezone = $record->userTimezone;
        $model->bookingDate = $record->bookingDate;
        $model->startTime = $record->startTime;
        $model->endTime = $record->endTime;
        $model->status = $record->status;
        $model->notes = $record->notes;
        $model->virtualMeetingUrl = $record->virtualMeetingUrl;
        $model->virtualMeetingProvider = $record->virtualMeetingProvider;
        $model->virtualMeetingId = $record->virtualMeetingId;
        $model->googleEventId = $record->googleEventId;
        $model->outlookEventId = $record->outlookEventId;
        $model->notificationSent = (bool) $record->notificationSent;
        $model->emailReminder24hSent = (bool) $record->emailReminder24hSent;
        $model->emailReminder1hSent = (bool) $record->emailReminder1hSent;
        $model->smsReminder24hSent = (bool) $record->smsReminder24hSent;
        $model->smsConfirmationSent = (bool) $record->smsConfirmationSent;
        $model->smsConfirmationSentAt = self::parseDateTime($record->smsConfirmationSentAt);
        $model->smsCancellationSent = (bool) $record->smsCancellationSent;
        $model->smsDeliveryStatus = $record->smsDeliveryStatus;
        $model->confirmationToken = $record->confirmationToken;
        $model->employeeId = $record->employeeId;
        $model->locationId = $record->locationId;
        $model->serviceId = $record->serviceId;
        $model->eventDateId = $record->eventDateId;
        $model->siteId = $record->siteId ? (int) $record->siteId : null;
        $model->quantity = (int) $record->quantity;
        $model->dateCreated = self::parseDateTime($record->dateCreated);
        $model->dateUpdated = self::parseDateTime($record->dateUpdated);

        return $model;
    }

    private static function parseDateTime($value): ?\DateTime
    {
        if ($value instanceof \DateTime) {
            return $value;
        }
        if (is_string($value)) {
            try {
                return new \DateTime($value);
            } catch (\Exception) {
                return null;
            }
        }
        return null;
    }
}
