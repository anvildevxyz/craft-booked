<?php

namespace anvildev\booked\events;

use anvildev\booked\contracts\ReservationInterface;
use craft\events\CancelableEvent;

class BeforeCalendarSyncEvent extends CancelableEvent
{
    public ReservationInterface $reservation;
    public string $provider;
    public string $action;
    public array $eventData = [];
    public ?int $employeeId = null;
}
