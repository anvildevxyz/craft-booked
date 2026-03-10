<?php

namespace anvildev\booked\events;

use anvildev\booked\contracts\ReservationInterface;
use craft\events\CancelableEvent;

abstract class BookingEvent extends CancelableEvent
{
    public ReservationInterface $reservation;
    public bool $isNew = true;
}
