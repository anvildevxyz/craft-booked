<?php

namespace anvildev\booked\events;

class AfterBookingSaveEvent extends BookingEvent
{
    public bool $success = true;
    public array $errors = [];

    /**
     * Set on an update (never a create) whose $data touched the slot itself —
     * bookingDate/startTime/endTime/employeeId/locationId — as opposed to a
     * notes-only or status-only edit. Listeners that react to a reschedule
     * specifically (customer notification, calendar sync) gate on this rather
     * than firing on every save.
     */
    public bool $isReschedule = false;
    public ?string $previousBookingDate = null;
    public ?string $previousStartTime = null;
}
