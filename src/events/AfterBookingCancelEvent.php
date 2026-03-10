<?php

namespace anvildev\booked\events;

class AfterBookingCancelEvent extends BookingEvent
{
    public bool $wasPaid = false;
    public bool $shouldRefund = false;
    public ?string $reason = null;
    public bool $success = true;
}
