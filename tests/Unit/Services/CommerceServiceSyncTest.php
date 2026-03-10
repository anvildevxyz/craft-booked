<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\CommerceService;
use anvildev\booked\tests\Support\TestCase;

class CommerceServiceSyncTest extends TestCase
{
    public function testSyncLineItemQuantityMethodExists(): void
    {
        $service = new CommerceService();
        $this->assertTrue(method_exists($service, 'syncLineItemQuantity'));
    }
}
