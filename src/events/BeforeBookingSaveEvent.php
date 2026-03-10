<?php

namespace anvildev\booked\events;

class BeforeBookingSaveEvent extends BookingEvent
{
    public array $bookingData = [];
    public ?string $source = null;
    public ?string $errorMessage = null;
}
