<?php

namespace anvildev\booked\events;

class AfterBookingSaveEvent extends BookingEvent
{
    public bool $success = true;
    public array $errors = [];
}
