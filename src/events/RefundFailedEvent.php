<?php

namespace anvildev\booked\events;

use anvildev\booked\contracts\ReservationInterface;
use yii\base\Event;

class RefundFailedEvent extends Event
{
    public ReservationInterface $reservation;
    public float $refundAmount;
    public string $error;
}
