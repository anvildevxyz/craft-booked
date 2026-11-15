<?php

namespace anvildev\booked\elements\db;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationQueryInterface;
use Craft;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use craft\web\Application as WebApplication;

/**
 * @method \anvildev\booked\elements\Reservation[]|array all($db = null)
 * @method \anvildev\booked\elements\Reservation|array|null one($db = null)
 * @method \anvildev\booked\elements\Reservation|array|null nth(int $n, ?Connection $db = null)
 */
class ReservationQuery extends ElementQuery implements ReservationQueryInterface
{
    public function init(): void
    {
        parent::init();
        // Return all reservation statuses (confirmed, pending, cancelled) by default,
        // rather than relying on Craft's default 'enabled' element status.
        $this->status = null;
    }

    public ?string $userName = null;
    public ?string $userEmail = null;
    public ?string $userPhone = null;
    public ?int $userId = null;
    public array|string|null $bookingDate = null;
    public array|string|null $endDate = null;
    public ?string $startTime = null;
    public ?string $endTime = null;
    public array|string|null $reservationStatus = null;
    public ?int $employeeId = null;
    public ?int $locationId = null;
    public ?int $serviceId = null;
    public ?int $eventDateId = null;
    public ?string $confirmationToken = null;

    private bool $_forCurrentUser = false;

    public function employeeId(?int $value): static
    {
        $this->employeeId = $value;
        return $this;
    }

    public function locationId(?int $value): static
    {
        $this->locationId = $value;
        return $this;
    }

    public function serviceId(?int $value): static
    {
        $this->serviceId = $value;
        return $this;
    }

    public function eventDateId(?int $value): static
    {
        $this->eventDateId = $value;
        return $this;
    }

    public function userName(?string $value): static
    {
        $this->userName = $value;
        return $this;
    }

    public function userEmail(?string $value): static
    {
        $this->userEmail = $value;
        return $this;
    }

    public function userPhone(?string $value): static
    {
        $this->userPhone = $value;
        return $this;
    }

    public function userId(?int $value): static
    {
        $this->userId = $value;
        return $this;
    }

    /**
     * Filter by the currently logged-in user (by userId or email fallback).
     * If no user is logged in, returns no results.
     */
    public function forCurrentUser(): static
    {
        $this->_forCurrentUser = true;
        return $this;
    }

    public function bookingDate(array|string|null $value): static
    {
        $this->bookingDate = $value;
        return $this;
    }

    public function endDate(array|string|null $value): static
    {
        $this->endDate = $value;
        return $this;
    }

    public function startTime(?string $value): static
    {
        $this->startTime = $value;
        return $this;
    }

    public function endTime(?string $value): static
    {
        $this->endTime = $value;
        return $this;
    }

    public function reservationStatus(array|string|null $value): static
    {
        $this->reservationStatus = $value;
        return $this;
    }

    public function confirmationToken(?string $value): static
    {
        $this->confirmationToken = $value;
        return $this;
    }

    public function withEmployee(): static
    {
        $this->with(['employee']);
        return $this;
    }

    public function withService(): static
    {
        $this->with(['service']);
        return $this;
    }

    public function withLocation(): static
    {
        $this->with(['location']);
        return $this;
    }

    public function withRelations(): static
    {
        $this->with(['employee', 'service', 'location']);
        return $this;
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            'confirmed', 'pending', 'cancelled', 'no_show' => ['booked_reservations.status' => $status],
            default => parent::statusCondition($status),
        };
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $t = 'booked_reservations';
        $this->joinElementTable($t);

        // Prevent ID collisions with other element types when AR mode IDs overlap
        $this->subQuery->andWhere(['elements.type' => $this->elementType]);

        $this->query->addSelect([
            "$t.userName", "$t.userEmail", "$t.userPhone", "$t.userId",
            "$t.userTimezone", "$t.bookingDate", "$t.endDate", "$t.startTime", "$t.endTime",
            "$t.status", "$t.notes", "$t.sessionNotes", "$t.notificationSent", "$t.confirmationToken",
            "$t.employeeId", "$t.locationId", "$t.serviceId", "$t.quantity",
            "$t.virtualMeetingUrl", "$t.virtualMeetingProvider", "$t.virtualMeetingId",
            "$t.googleEventId", "$t.outlookEventId",
            "$t.emailReminder24hSent", "$t.emailReminder1hSent",
            "$t.smsReminder24hSent", "$t.eventDateId",
            "$t.smsConfirmationSent", "$t.smsCancellationSent",
            "$t.smsDeliveryStatus", "$t.smsConfirmationSentAt",
            "$t.siteId AS bookingSiteId",
        ]);

        // Simple param filters (string keys map property name => column name)
        foreach (['userName', 'userEmail', 'userPhone', 'userId', 'endDate', 'startTime', 'endTime', 'reservationStatus' => 'status', 'employeeId', 'locationId', 'serviceId', 'eventDateId'] as $key => $col) {
            $prop = is_int($key) ? $col : $key;
            if ($this->$prop !== null) {
                $this->subQuery->andWhere(Db::parseParam("$t.$col", $this->$prop));
            }
        }

        if ($this->confirmationToken !== null) {
            $this->subQuery->andWhere(Db::parseParam("$t.confirmationToken", $this->confirmationToken));
        }

        // Current user: match by userId OR email (legacy bookings)
        if ($this->_forCurrentUser) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            $this->subQuery->andWhere($currentUser
                ? ['or', ["$t.userId" => $currentUser->id], ["$t.userEmail" => $currentUser->email]]
                : '1 = 0'
            );
        }

        // Booking date supports operator pairs, 'and' compound conditions, and simple values
        if ($this->bookingDate) {
            $this->_applyBookingDateFilter($t);
        }

        $this->_applyStaffScope();

        return true;
    }

    /**
     * Limits a view-only staff member to the employees they manage, and only
     * while Craft is building an element index.
     *
     * The bookings index query is created inside Craft's own controller, so a
     * filter applied by our controllers never runs for it. Seat counts,
     * availability and the public booking form also use this query. Scoping
     * those would hide other employees' bookings and let a staff member book
     * a seat that is already taken, so the limit stays on element-index requests.
     *
     * PermissionService::scopeReservationQuery() already implements the staff
     * narrowing for callers like CustomerService::bookingsFor() — reused here
     * rather than re-deriving the same employeeId condition a second way. But
     * unlike those callers, nothing upstream of this one already required
     * booked-viewBookings: Craft's own element-indexes controller only checks
     * for CP access, not any permission specific to this plugin, so every
     * logged-in CP user reaches this query. getStaffEmployeeIds() returns
     * null ("full access") for exactly that user too — it was written
     * assuming a caller had already gated on booked-viewBookings — so this
     * method has to check that itself before delegating, or any CP user with
     * no Booked permission at all would see every reservation.
     */
    private function _applyStaffScope(): void
    {
        // Console and queue runs have no user session to scope against.
        // Checked against the application type, not a request-reported flag:
        // the latter falls back to a PHP_SAPI comparison when unset, which
        // misclassifies a web app booted from a CLI context.
        if (!Craft::$app instanceof WebApplication) {
            return;
        }

        $segments = Craft::$app->getRequest()->getActionSegments();
        if (($segments[0] ?? null) !== 'element-indexes') {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null || !$user->can('booked-viewBookings')) {
            $this->subQuery->andWhere('0=1');
            return;
        }

        Booked::getInstance()?->getPermission()->scopeReservationQuery($this);
    }

    private function _applyBookingDateFilter(string $t): void
    {
        if (!is_array($this->bookingDate)) {
            $this->subQuery->andWhere(Db::parseParam("$t.bookingDate", $this->bookingDate));
            return;
        }

        $bd = $this->bookingDate;

        // Operator format: ['>', '2024-01-01']
        if (count($bd) === 2 && is_string($bd[0]) && in_array($bd[0], ['>', '<', '>=', '<=', '!=', '<>'])) {
            $this->subQuery->andWhere([$bd[0], "$t.bookingDate", $bd[1]]);
            return;
        }

        // Compound: ['and', '>= 2026-02-01', '<= 2026-02-28']
        if (($bd[0] ?? null) === 'and') {
            $conditions = ['and'];
            foreach (array_slice($bd, 1) as $condition) {
                $conditions[] = Db::parseParam("$t.bookingDate", $condition);
            }
            $this->subQuery->andWhere($conditions);
            return;
        }

        $this->subQuery->andWhere(Db::parseParam("$t.bookingDate", $bd));
    }
}
