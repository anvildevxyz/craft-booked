<?php

namespace anvildev\booked\mcp\support;

use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\elements\BlackoutDate;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\EventDate;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Schedule;
use anvildev\booked\elements\Service;
use anvildev\booked\elements\ServiceExtra;
use anvildev\booked\helpers\PiiRedactor;
use anvildev\booked\records\WaitlistRecord;
use craft\base\ElementInterface;

/**
 * Serialises Booked elements and reservations into plain, MCP-friendly arrays.
 *
 * Every value returned here is JSON-safe (scalars, arrays, ISO-8601 date
 * strings) so the MCP SDK can hand it straight to an AI client. Presenters are
 * the single place that decides which fields are exposed over the protocol.
 */
final class Presenter
{
    /**
     * Recursively coerce a value into something json_encode can always handle.
     *
     * Booked's reporting/dashboard services return arrays that embed live Craft
     * element objects (built for Twig rendering). Those would either bloat the
     * MCP payload or break serialisation, so any element is collapsed to a
     * compact {id, title} stub. Dates become ISO-8601 strings, non-finite
     * floats become null, and other objects fall back to their JsonSerializable
     * / string / public-property form. Tool responses are passed through this
     * so a tool can never crash the transport with an unserialisable result.
     */
    public static function jsonSafe(mixed $value, int $depth = 0): mixed
    {
        // Hard depth cap — guards against cyclic / self-referential objects in
        // report/dashboard data recursing until the stack overflows.
        if ($depth > 16) {
            return null;
        }
        if ($value instanceof ElementInterface) {
            return ['id' => $value->id, 'title' => $value->title ?? (string)$value];
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_array($value)) {
            return array_map(static fn($v) => self::jsonSafe($v, $depth + 1), $value);
        }
        if ($value instanceof \JsonSerializable) {
            return self::jsonSafe($value->jsonSerialize(), $depth + 1);
        }
        if ($value instanceof \stdClass) {
            // Plain data object (e.g. decoded JSON) — safe to expand.
            return self::jsonSafe(get_object_vars($value), $depth + 1);
        }
        if (is_object($value)) {
            // Never dump arbitrary class internals via get_object_vars() — a report
            // row could carry a model/config/credential object. Collapse to a string
            // (if stringable) or an opaque class stub.
            return method_exists($value, '__toString')
                ? (string)$value
                : ['_class' => $value::class];
        }
        if (is_float($value) && !is_finite($value)) {
            return null;
        }
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function service(Service $service): array
    {
        return [
            'id' => $service->id,
            'title' => $service->title,
            'enabled' => $service->enabled,
            'description' => $service->description,
            'duration' => $service->duration,
            'durationType' => $service->durationType,
            'timeSlotLength' => $service->timeSlotLength,
            'pricingMode' => $service->pricingMode,
            'price' => $service->price,
            'bufferBefore' => $service->bufferBefore,
            'bufferAfter' => $service->bufferAfter,
            'minTimeBeforeBooking' => $service->minTimeBeforeBooking,
            'allowCancellation' => $service->allowCancellation,
            'cancellationPolicyHours' => $service->cancellationPolicyHours,
            'enableWaitlist' => $service->enableWaitlist,
            'siteId' => $service->siteId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function employee(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'title' => $employee->title,
            'enabled' => $employee->enabled,
            'email' => $employee->email,
            'userId' => $employee->userId,
            'locationId' => $employee->locationId,
            'serviceIds' => $employee->serviceIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function location(Location $location): array
    {
        return [
            'id' => $location->id,
            'title' => $location->title,
            'enabled' => $location->enabled,
            'timezone' => $location->timezone,
            'addressLine1' => $location->addressLine1,
            'addressLine2' => $location->addressLine2,
            'locality' => $location->locality,
            'administrativeArea' => $location->administrativeArea,
            'postalCode' => $location->postalCode,
            'countryCode' => $location->countryCode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function eventDate(EventDate $eventDate): array
    {
        return [
            'id' => $eventDate->id,
            'title' => $eventDate->title,
            'enabled' => $eventDate->enabled,
            'description' => $eventDate->description,
            'eventDate' => $eventDate->eventDate,
            'endDate' => $eventDate->endDate,
            'startTime' => $eventDate->startTime,
            'endTime' => $eventDate->endTime,
            'locationId' => $eventDate->locationId,
            'capacity' => $eventDate->capacity,
            'price' => $eventDate->price,
            'allowCancellation' => $eventDate->allowCancellation,
            'enableWaitlist' => $eventDate->enableWaitlist,
        ];
    }

    /**
     * @param bool $redactPii Mask customer email/phone — set for bulk list output, where one
     *                        call could otherwise exfiltrate the whole customer base.
     * @return array<string, mixed>
     */
    public static function reservation(ReservationInterface $reservation, bool $redactPii = false): array
    {
        $email = $reservation->getUserEmail();
        $phone = $reservation->getUserPhone();

        return [
            'id' => $reservation->getId(),
            'uid' => $reservation->getUid(),
            'status' => $reservation->getStatus(),
            'serviceId' => $reservation->getServiceId(),
            'employeeId' => $reservation->getEmployeeId(),
            'locationId' => $reservation->getLocationId(),
            'eventDateId' => $reservation->getEventDateId(),
            'bookingDate' => $reservation->getBookingDate(),
            'endDate' => $reservation->getEndDate(),
            'startTime' => $reservation->getStartTime(),
            'endTime' => $reservation->getEndTime(),
            'isMultiDay' => $reservation->isMultiDay(),
            'quantity' => $reservation->getQuantity(),
            'userName' => $reservation->getUserName(),
            'userEmail' => $redactPii ? PiiRedactor::redactEmail($email) : $email,
            'userPhone' => $redactPii ? PiiRedactor::redactPhone($phone) : $phone,
            'userTimezone' => $reservation->getUserTimezone(),
            'notes' => $reservation->getNotes(),
            'hasVirtualMeeting' => $reservation->getVirtualMeetingUrl() !== null,
        ];
        // NOTE: confirmationToken and virtualMeetingUrl are deliberately NOT exposed —
        // both are bearer capabilities (the token authenticates the public
        // cancel/reschedule endpoints; the URL is an unauthenticated meeting join link).
    }

    /**
     * @return array<string, mixed>
     */
    public static function schedule(Schedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'title' => $schedule->title,
            'enabled' => $schedule->enabled,
            'workingHours' => $schedule->workingHours,
            'startDate' => $schedule->startDate,
            'endDate' => $schedule->endDate,
            'sortOrder' => $schedule->sortOrder,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function blackoutDate(BlackoutDate $blackout): array
    {
        return [
            'id' => $blackout->id,
            'title' => $blackout->title,
            'startDate' => $blackout->startDate,
            'endDate' => $blackout->endDate,
            'locationIds' => $blackout->locationIds,
            'employeeIds' => $blackout->employeeIds,
            'isActive' => $blackout->isActive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serviceExtra(ServiceExtra $extra): array
    {
        return [
            'id' => $extra->id,
            'title' => $extra->title,
            'enabled' => $extra->enabled,
            'description' => $extra->description,
            'price' => $extra->price,
            'duration' => $extra->duration,
            'maxQuantity' => $extra->maxQuantity,
            'isRequired' => $extra->isRequired,
        ];
    }

    /**
     * @param bool $redactPii Mask customer email/phone for bulk list output.
     * @return array<string, mixed>
     */
    public static function waitlistEntry(WaitlistRecord $entry, bool $redactPii = false): array
    {
        return [
            'id' => $entry->id,
            'status' => $entry->status,
            'serviceId' => $entry->serviceId,
            'eventDateId' => $entry->eventDateId,
            'employeeId' => $entry->employeeId,
            'locationId' => $entry->locationId,
            'preferredDate' => $entry->preferredDate,
            'preferredTimeStart' => $entry->preferredTimeStart,
            'preferredTimeEnd' => $entry->preferredTimeEnd,
            'userName' => $entry->userName,
            'userEmail' => $redactPii ? PiiRedactor::redactEmail($entry->userEmail) : $entry->userEmail,
            'userPhone' => $redactPii ? PiiRedactor::redactPhone($entry->userPhone) : $entry->userPhone,
            'priority' => $entry->priority,
            'notifiedAt' => $entry->notifiedAt,
            'expiresAt' => $entry->expiresAt,
            'dateCreated' => $entry->dateCreated,
        ];
    }
}
