<?php

namespace anvildev\booked\mcp;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\mcp\support\Presenter;

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * MCP tools for reading and managing reservations.
 *
 * Reads go through the reservation query layer; writes go through
 * {@see \anvildev\booked\services\BookingService}, so all of Booked's
 * validation, slot-locking and notification side effects are preserved.
 * The create/cancel tools are flagged dangerous because they commit (or undo)
 * real bookings.
 */
class ReservationTools
{
    use ToolResponseTrait;

    /**
     * List reservations with optional filters.
     *
     * @param string|null $status One of: pending, confirmed, cancelled, no_show.
     * @param int|null $serviceId Filter by service.
     * @param int|null $employeeId Filter by employee.
     * @param int|null $locationId Filter by location.
     * @param string|null $fromDate Only reservations on/after this booking date (Y-m-d).
     * @param string|null $toDate Only reservations on/before this booking date (Y-m-d).
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_list_reservations',
        description: 'List reservations, optionally filtered by status, service, employee, location and '
            . 'booking-date range. Returns customer and slot details for each.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function listReservations(
        ?string $status = null,
        ?int $serviceId = null,
        ?int $employeeId = null,
        ?int $locationId = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        return $this->guard(function() use ($status, $serviceId, $employeeId, $locationId, $fromDate, $toDate, $limit, $offset): array {
            $query = ReservationFactory::find()
                ->siteId('*')
                ->status(null)
                ->limit($limit)
                ->offset($offset);

            if ($status !== null) {
                $query->reservationStatus($status);
            }
            if ($serviceId !== null) {
                $query->serviceId($serviceId);
            }
            if ($employeeId !== null) {
                $query->employeeId($employeeId);
            }
            if ($locationId !== null) {
                $query->locationId($locationId);
            }
            if ($fromDate !== null && $toDate !== null) {
                $query->bookingDate(['and', ">= {$fromDate}", "<= {$toDate}"]);
            } elseif ($fromDate !== null) {
                // Array form, not ">= {$fromDate}": ReservationModelQuery treats
                // the string as a literal equality match and finds nothing.
                $query->bookingDate(['>=', $fromDate]);
            } elseif ($toDate !== null) {
                $query->bookingDate(['<=', $toDate]);
            }

            $reservations = $query->all();

            return [
                'count' => count($reservations),
                'reservations' => array_map(
                    static fn(ReservationInterface $r) => Presenter::reservation($r, redactPii: true),
                    $reservations,
                ),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_get_reservation',
        description: 'Get a single reservation by id, including customer, slot and status details.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function getReservation(int $id): array
    {
        return $this->guard(function() use ($id): array {
            $reservation = Booked::getInstance()->getBooking()->getReservationById($id);
            if (!$reservation instanceof ReservationInterface) {
                return ['error' => "Reservation #{$id} not found."];
            }

            // Redact here too — ids are enumerable, so an un-redacted get would
            // bypass the bulk-list redaction one record at a time.
            return ['reservation' => Presenter::reservation($reservation, redactPii: true)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_booking_stats',
        description: 'Get aggregate booking counts (total, confirmed, pending, today, this month).',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function bookingStats(): array
    {
        return $this->guard(static fn(): array => [
            'stats' => Booked::getInstance()->getBooking()->getBookingStats(),
        ]);
    }

    /**
     * Create a reservation for a time-slot service.
     *
     * @param int $serviceId Service being booked.
     * @param string $bookingDate Day of the booking, Y-m-d.
     * @param string $startTime Slot start time, HH:MM (24h).
     * @param string $userName Customer name.
     * @param string $userEmail Customer email.
     * @param string|null $userPhone Customer phone (optional).
     * @param int|null $employeeId Specific employee, if the service requires one.
     * @param int|null $locationId Specific location, if applicable.
     * @param int $quantity Number of spots/seats to book.
     * @param string|null $userTimezone IANA timezone of the customer (e.g. Europe/Zurich).
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_create_booking',
        description: 'Create a reservation for a time-slot service. Runs full availability validation and '
            . 'slot locking; returns the created reservation or a validation error.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function createBooking(
        int $serviceId,
        string $bookingDate,
        string $startTime,
        string $userName,
        string $userEmail,
        ?string $userPhone = null,
        ?int $employeeId = null,
        ?int $locationId = null,
        int $quantity = 1,
        ?string $userTimezone = null,
    ): array {
        return $this->guard(function() use (
            $serviceId,
            $bookingDate,
            $startTime,
            $userName,
            $userEmail,
            $userPhone,
            $employeeId,
            $locationId,
            $quantity,
            $userTimezone,
        ): array {
            if ($quantity < 1) {
                return ['error' => 'quantity must be at least 1.'];
            }
            if ($this->rateLimitReached()) {
                return $this->rateLimitError('creating more bookings');
            }

            $reservation = Booked::getInstance()->getBooking()->createReservation([
                'serviceId' => $serviceId,
                'bookingDate' => $bookingDate,
                'startTime' => $startTime,
                'userName' => $userName,
                'userEmail' => $userEmail,
                'userPhone' => $userPhone,
                'employeeId' => $employeeId,
                'locationId' => $locationId,
                'quantity' => $quantity,
                'userTimezone' => $userTimezone,
                'source' => 'mcp',
            ]);

            // Record only after success, so failed attempts don't consume budget.
            $this->recordRateLimitedCall();

            return [
                'success' => true,
                'reservation' => Presenter::reservation($reservation),
            ];
        });
    }

    /**
     * Book a spot on a one-time event date.
     *
     * @param int $eventDateId Event date to book.
     * @param string $userName Customer name.
     * @param string $userEmail Customer email.
     * @param string|null $userPhone Customer phone (optional).
     * @param int $quantity Number of seats to reserve.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_create_event_booking',
        description: 'Reserve one or more seats on a Booked event date. Validates remaining capacity; '
            . 'returns the created reservation or an error.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function createEventBooking(
        int $eventDateId,
        string $userName,
        string $userEmail,
        ?string $userPhone = null,
        int $quantity = 1,
    ): array {
        return $this->guard(function() use ($eventDateId, $userName, $userEmail, $userPhone, $quantity): array {
            if ($quantity < 1) {
                return ['error' => 'quantity must be at least 1.'];
            }
            if ($this->rateLimitReached()) {
                return $this->rateLimitError('creating more bookings');
            }

            $reservation = Booked::getInstance()->getBooking()->createReservation([
                'eventDateId' => $eventDateId,
                'userName' => $userName,
                'userEmail' => $userEmail,
                'userPhone' => $userPhone,
                'quantity' => $quantity,
                'source' => 'mcp',
            ]);

            $this->recordRateLimitedCall();

            return [
                'success' => true,
                'reservation' => Presenter::reservation($reservation),
            ];
        });
    }

    /**
     * @param int $id Reservation to cancel.
     * @param string $reason Optional reason recorded against the cancellation.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_cancel_reservation',
        description: 'Cancel an existing reservation by id, freeing its slot/capacity. Returns whether the '
            . 'cancellation succeeded.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function cancelReservation(int $id, string $reason = ''): array
    {
        return $this->guard(function() use ($id, $reason): array {
            if ($this->rateLimitReached()) {
                return $this->rateLimitError('cancelling more bookings');
            }

            $ok = Booked::getInstance()->getBooking()->cancelReservation($id, $reason);

            // Only a real cancellation notifies; a no-op doesn't, so don't charge it.
            if ($ok) {
                $this->recordRateLimitedCall();
            }

            return [
                'success' => $ok,
                'id' => $id,
            ];
        });
    }

    /**
     * Update an existing reservation: edit customer details, reschedule
     * (date/time/employee/location), or change status. Only provided (non-null)
     * fields change. Rescheduling re-runs availability validation and slot
     * locking, exactly like the Control Panel.
     *
     * @param int $id Reservation to update.
     * @param string|null $bookingDate New booking date, Y-m-d (reschedule).
     * @param string|null $startTime New start time, HH:MM (reschedule).
     * @param int|null $employeeId Reassign to this employee (reschedule).
     * @param int|null $locationId Reassign to this location (reschedule).
     * @param string|null $status New status: confirmed. (pending is reserved for Commerce; to cancel, use booked_cancel_reservation — this tool rejects status=cancelled because flipping the field here skips the refund/capacity-release flow.)
     * @param string|null $userName Customer name.
     * @param string|null $userEmail Customer email.
     * @param string|null $userPhone Customer phone.
     * @param string|null $notes Internal notes.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_update_reservation',
        description: 'Update a reservation: edit customer details, reschedule (date/time/employee/location), '
            . 'or set status to confirmed. Reschedules are availability-validated and slot-locked. '
            . 'To cancel, use booked_cancel_reservation (status=cancelled is rejected here, as it would skip refund/capacity release).',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function updateReservation(
        int $id,
        ?string $bookingDate = null,
        ?string $startTime = null,
        ?int $employeeId = null,
        ?int $locationId = null,
        ?string $status = null,
        ?string $userName = null,
        ?string $userEmail = null,
        ?string $userPhone = null,
        ?string $notes = null,
    ): array {
        return $this->guard(function() use ($id, $bookingDate, $startTime, $employeeId, $locationId, $status, $userName, $userEmail, $userPhone, $notes): array {
            // Flipping status to cancelled here skips the refund, capacity
            // release and waitlist notify that executeCancellation() runs.
            if ($status === 'cancelled') {
                return ['error' => 'Use booked_cancel_reservation to cancel; it runs the refund and capacity-release flow that updating the status directly would skip.'];
            }

            $data = array_filter([
                'bookingDate' => $bookingDate,
                'startTime' => $startTime,
                'employeeId' => $employeeId,
                'locationId' => $locationId,
                'status' => $status,
                'userName' => $userName,
                'userEmail' => $userEmail,
                'userPhone' => $userPhone,
                'notes' => $notes,
            ], static fn($v) => $v !== null);

            if ($data === []) {
                return ['error' => 'Provide at least one field to update.'];
            }

            // A status change or reschedule notifies; a bare detail edit doesn't.
            $willNotify = $status !== null
                || $bookingDate !== null
                || $startTime !== null
                || $employeeId !== null
                || $locationId !== null;
            if ($willNotify && $this->rateLimitReached()) {
                return $this->rateLimitError('updating more reservations');
            }

            $reservation = Booked::getInstance()->getBooking()->updateReservation($id, $data);

            if ($willNotify) {
                $this->recordRateLimitedCall();
            }

            return ['success' => true, 'reservation' => Presenter::reservation($reservation)];
        });
    }

    /**
     * @param int $id Reservation id.
     * @param int $reduceBy How many spots/seats to release.
     * @param string $reason Optional reason recorded against the change.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_reduce_reservation_quantity',
        description: 'Reduce the quantity (spots/seats) of a reservation, releasing capacity. '
            . 'Triggers a partial refund when Commerce is enabled.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function reduceReservationQuantity(int $id, int $reduceBy, string $reason = ''): array
    {
        return $this->guard(function() use ($id, $reduceBy, $reason): array {
            // Notifies the waitlist and emails the customer (and refunds under Commerce).
            if ($this->rateLimitReached()) {
                return $this->rateLimitError('changing more reservation quantities');
            }

            $ok = Booked::getInstance()->getBooking()->reduceQuantity($id, $reduceBy, $reason);
            if ($ok) {
                $this->recordRateLimitedCall();
            }

            return ['success' => $ok, 'id' => $id];
        });
    }

    /**
     * @param int $id Reservation id.
     * @param int $increaseBy How many additional spots/seats to add.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_increase_reservation_quantity',
        description: 'Increase the quantity (spots/seats) of a reservation, re-checking capacity for the slot.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function increaseReservationQuantity(int $id, int $increaseBy): array
    {
        return $this->guard(function() use ($id, $increaseBy): array {
            if ($this->rateLimitReached()) {
                return $this->rateLimitError('changing more reservation quantities');
            }

            $ok = Booked::getInstance()->getBooking()->increaseQuantity($id, $increaseBy);
            if ($ok) {
                $this->recordRateLimitedCall();
            }

            return ['success' => $ok, 'id' => $id];
        });
    }

    /**
     * Issue a full refund for a reservation through Craft Commerce. Requires the
     * Commerce integration to be enabled.
     *
     * @param int $id Reservation to refund.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_refund_reservation',
        description: 'Issue a full Commerce refund for a reservation. Only available when Booked\'s Commerce '
            . 'integration is enabled.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function refundReservation(int $id): array
    {
        return $this->guard(function() use ($id): array {
            $booked = Booked::getInstance();
            if (!$booked->isCommerceEnabled()) {
                return ['error' => 'Commerce integration is not enabled; refunds are unavailable.'];
            }

            $reservation = $booked->getBooking()->getReservationById($id);
            if (!$reservation instanceof ReservationInterface) {
                return ['error' => "Reservation #{$id} not found."];
            }

            // Own budget — a refund spree shouldn't lock out cancellations.
            if ($this->rateLimitReached('refund')) {
                return $this->rateLimitError('issuing more refunds');
            }

            $ok = $booked->getRefund()->processFullRefund($reservation);
            if ($ok) {
                $this->recordRateLimitedCall('refund');
            }

            return [
                'success' => $ok,
                'id' => $id,
            ];
        });
    }
}
