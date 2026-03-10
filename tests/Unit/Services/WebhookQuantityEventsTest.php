<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\WebhookService;
use anvildev\booked\tests\Support\TestCase;

class WebhookQuantityEventsTest extends TestCase
{
    public function testQuantityReducedEventConstant(): void
    {
        $this->assertEquals('booking.quantity.reduced', WebhookService::EVENT_BOOKING_QUANTITY_REDUCED);
    }

    public function testQuantityIncreasedEventConstant(): void
    {
        $this->assertEquals('booking.quantity.increased', WebhookService::EVENT_BOOKING_QUANTITY_INCREASED);
    }

    public function testQuantityEventConstantsAreUnique(): void
    {
        $this->assertNotEquals(
            WebhookService::EVENT_BOOKING_QUANTITY_REDUCED,
            WebhookService::EVENT_BOOKING_QUANTITY_INCREASED
        );
    }

    public function testQuantityEventsFollowNamingConvention(): void
    {
        $this->assertStringStartsWith('booking.quantity.', WebhookService::EVENT_BOOKING_QUANTITY_REDUCED);
        $this->assertStringStartsWith('booking.quantity.', WebhookService::EVENT_BOOKING_QUANTITY_INCREASED);
    }

    public function testQuantityEventsDoNotConflictWithExistingConstants(): void
    {
        $allConstants = [
            WebhookService::EVENT_BOOKING_CREATED,
            WebhookService::EVENT_BOOKING_CANCELLED,
            WebhookService::EVENT_BOOKING_UPDATED,
            WebhookService::EVENT_BOOKING_QUANTITY_REDUCED,
            WebhookService::EVENT_BOOKING_QUANTITY_INCREASED,
        ];

        $this->assertCount(5, array_unique($allConstants), 'All event constants must be unique');
    }

    public function testGetEventTypesIncludesQuantityEvents(): void
    {
        $this->requiresCraft();

        $service = new WebhookService();
        $types = $service->getEventTypes();

        $this->assertArrayHasKey('booking.quantity.reduced', $types);
        $this->assertArrayHasKey('booking.quantity.increased', $types);
        $this->assertCount(5, $types);
    }
}
