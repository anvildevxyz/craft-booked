<?php

namespace anvildev\booked\exceptions;

use Craft;

class BookingConflictException extends BookingException
{
    public function getName(): string
    {
        return Craft::t('booked', 'exceptions.bookingConflict');
    }
}
