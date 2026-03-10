<?php

namespace anvildev\booked\exceptions;

use Craft;

class BookingRateLimitException extends BookingException
{
    public function getName(): string
    {
        return Craft::t('booked', 'exceptions.bookingRateLimit');
    }
}
