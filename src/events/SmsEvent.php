<?php

namespace anvildev\booked\events;

use yii\base\Event;

class SmsEvent extends Event
{
    public string $to = '';
    public string $message = '';
    public string $messageType = 'general';
    public ?int $reservationId = null;
    public bool $success = false;
    public ?string $errorMessage = null;
}
