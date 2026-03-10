<?php

namespace anvildev\booked\events;

use anvildev\booked\records\WebhookRecord;
use yii\base\Event;

class WebhookEvent extends Event
{
    public WebhookRecord $webhook;
    public array $payload = [];
    public string $event = '';
    public ?int $reservationId = null;
    public bool $success = false;
    public ?int $responseCode = null;
    public ?string $errorMessage = null;
}
