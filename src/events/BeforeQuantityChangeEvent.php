<?php

namespace anvildev\booked\events;

class BeforeQuantityChangeEvent extends BookingEvent
{
    public int $previousQuantity;
    public int $reduceBy = 0;
    public int $increaseBy = 0;
    public int $newQuantity;
    public ?string $reason = null;
}
