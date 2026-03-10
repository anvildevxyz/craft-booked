<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\events\RefundFailedEvent;
use anvildev\booked\services\RefundService;
use anvildev\booked\tests\Support\TestCase;

class RefundServiceTest extends TestCase
{
    public function testRefundServiceCanBeInstantiated(): void
    {
        $service = new RefundService();
        $this->assertInstanceOf(RefundService::class, $service);
    }

    public function testRefundFailedEventConstantDefined(): void
    {
        $this->assertEquals('refundFailed', RefundService::EVENT_REFUND_FAILED);
    }

    public function testRefundFailedEventHasRequiredProperties(): void
    {
        $event = new RefundFailedEvent();
        $this->assertObjectHasProperty('reservation', $event);
        $this->assertObjectHasProperty('refundAmount', $event);
        $this->assertObjectHasProperty('error', $event);
    }

}
