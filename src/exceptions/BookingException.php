<?php

namespace anvildev\booked\exceptions;

use Craft;
use yii\base\Exception;

class BookingException extends Exception
{
    public function getName(): string
    {
        return Craft::t('booked', 'exceptions.bookingException');
    }
}
