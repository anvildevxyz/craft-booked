<?php

namespace anvildev\booked\exceptions;

use Craft;

class BookingNotFoundException extends BookingException
{
    public function getName(): string
    {
        return Craft::t('booked', 'exceptions.bookingNotFound');
    }
}
