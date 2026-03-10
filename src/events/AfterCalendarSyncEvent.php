<?php

namespace anvildev\booked\events;

use anvildev\booked\contracts\ReservationInterface;
use yii\base\Event;

class AfterCalendarSyncEvent extends Event
{
    public ReservationInterface $reservation;
    public string $provider;
    public string $action;
    public bool $success = true;
    public ?string $errorMessage = null;
    public ?string $externalEventId = null;
    public array $response = [];
    public float $duration = 0.0;
}
