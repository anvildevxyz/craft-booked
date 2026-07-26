<?php

namespace anvildev\booked\events;

use yii\base\Event;

/**
 * Fired so plugins can register payment gateway adapters for direct payment
 * mode. Handlers push instances implementing
 * {@see \anvildev\booked\contracts\PaymentGatewayInterface} onto {@see $gateways}.
 */
class RegisterPaymentGatewaysEvent extends Event
{
    /** @var \anvildev\booked\contracts\PaymentGatewayInterface[] */
    public array $gateways = [];
}
