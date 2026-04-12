<?php

namespace anvildev\booked\contracts;

use anvildev\booked\elements\Employee;
use anvildev\booked\elements\EventDate;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use craft\elements\User;

/**
 * Defines the contract for both Element-based (Commerce) and
 * ActiveRecord-based (non-Commerce) Reservation implementations.
 *
 * @property int|null $id
 * @property string|null $uid
 * @property int|null $siteId
 * @property string $userName
 * @property string $userEmail
 * @property string|null $userPhone
 * @property int|null $userId
 * @property string|null $userTimezone
 * @property string $bookingDate
 * @property string|null $endDate
 * @property string $startTime
 * @property string $endTime
 * @property string|null $status
 * @property string|null $notes
 * @property string|null $sessionNotes
 * @property int $quantity
 * @property string $confirmationToken
 * @property string|null $virtualMeetingUrl
 * @property string|null $virtualMeetingProvider
 * @property string|null $virtualMeetingId
 * @property string|null $googleEventId
 * @property string|null $outlookEventId
 * @property bool $notificationSent
 * @property bool $emailReminder24hSent
 * @property bool $emailReminder1hSent
 * @property bool $smsReminder24hSent
 * @property bool $smsConfirmationSent
 * @property \DateTime|null $smsConfirmationSentAt
 * @property bool $smsCancellationSent
 * @property string|null $smsDeliveryStatus
 * @property int|null $employeeId
 * @property int|null $locationId
 * @property int|null $serviceId
 * @property int|null $eventDateId
 * @property \DateTime|null $dateCreated
 * @property \DateTime|null $dateUpdated
 */
interface ReservationInterface
{
    public function getId(): ?int;
    public function getUid(): ?string;
    public function getUserName(): string;
    public function getUserEmail(): string;
    public function getUserPhone(): ?string;
    public function getUserId(): ?int;
    public function getUserTimezone(): ?string;
    public function customerEmail(): string;
    public function customerName(): string;
    public function getBookingDate(): string;
    public function getStartTime(): string;
    public function getEndTime(): string;
    public function getEndDate(): ?string;
    public function isMultiDay(): bool;
    public function getDurationDays(): ?int;
    public function getStatus(): ?string;
    public function getNotes(): ?string;
    public function getSessionNotes(): ?string;
    public function getQuantity(): int;
    public function getConfirmationToken(): string;
    public function getVirtualMeetingUrl(): ?string;
    public function getVirtualMeetingProvider(): ?string;
    public function getVirtualMeetingId(): ?string;
    public function getGoogleEventId(): ?string;
    public function getOutlookEventId(): ?string;
    public function getNotificationSent(): bool;
    public function getEmailReminder24hSent(): bool;
    public function getEmailReminder1hSent(): bool;
    public function getSmsReminder24hSent(): bool;
    public function getSmsConfirmationSent(): bool;
    public function getSmsConfirmationSentAt(): ?\DateTime;
    public function getSmsCancellationSent(): bool;
    public function getSmsDeliveryStatus(): ?string;
    public function getSiteId(): ?int;
    public function getEmployeeId(): ?int;
    public function getLocationId(): ?int;
    public function getServiceId(): ?int;
    public function getEventDateId(): ?int;
    public function getDateCreated(): ?\DateTime;
    public function getDateUpdated(): ?\DateTime;

    public function getService(): ?Service;
    public function getEmployee(): ?Employee;
    public function getLocation(): ?Location;
    public function getEventDate(): ?EventDate;
    public function getUser(): ?User;

    /** @return array Array of extras with quantity and price info */
    public function getExtras(): array;

    public function cancel(): bool;
    public function markAsNoShow(): bool;
    public function canBeCancelled(): bool;
    public function getFormattedDateTime(): string;
    public function getDurationMinutes(): int;
    public function conflictsWith(ReservationInterface $other): bool;
    public function getBookingDateTime(): ?\DateTime;
    public function getStatusLabel(): string;
    public function isEventBased(): bool;
    public function getManagementUrl(): string;
    public function getCancelUrl(): string;
    public function getIcsUrl(): string;
    public function getCpEditUrl(): ?string;
    public function getExtrasPrice(): float;
    public function getExtrasSummary(): string;
    public function getTotalPrice(): float;
    public function recalculateTotals(): void;
    public function getTotalDuration(): int;
    public function hasExtras(): bool;
    public function save(bool $runValidation = true): bool;
    public function delete(): bool;

    // Signatures match Yii Model for Element compatibility (no return types)
    public function validate($attributeNames = null, $clearErrors = true);
    public function getErrors($attribute = null);
    public function hasErrors($attribute = null);
    public function addError($attribute, $error = '');
}
