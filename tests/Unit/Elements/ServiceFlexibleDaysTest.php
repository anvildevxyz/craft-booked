<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\Service;
use anvildev\booked\tests\Support\TestCase;

class ServiceFlexibleDaysTest extends TestCase
{
    private function makeService(array $props = []): Service
    {
        $ref = new \ReflectionClass(Service::class);
        $service = $ref->newInstanceWithoutConstructor();
        $service->durationType = $props['durationType'] ?? 'minutes';
        $service->pricingMode = $props['pricingMode'] ?? 'flat';
        $service->duration = $props['duration'] ?? 60;
        $service->minDays = $props['minDays'] ?? null;
        $service->maxDays = $props['maxDays'] ?? null;
        return $service;
    }

    public function testIsFlexibleDayServiceReturnsTrue(): void
    {
        $service = $this->makeService(['durationType' => 'flexible_days']);
        $this->assertTrue($service->isFlexibleDayService());
    }

    public function testIsFlexibleDayServiceReturnsFalseForDays(): void
    {
        $service = $this->makeService(['durationType' => 'days']);
        $this->assertFalse($service->isFlexibleDayService());
    }

    public function testIsFlexibleDayServiceReturnsFalseForMinutes(): void
    {
        $service = $this->makeService(['durationType' => 'minutes']);
        $this->assertFalse($service->isFlexibleDayService());
    }

    public function testIsDayServiceReturnsTrueForFlexibleDays(): void
    {
        $service = $this->makeService(['durationType' => 'flexible_days']);
        $this->assertTrue($service->isDayService());
    }

    public function testIsDayServiceReturnsTrueForDays(): void
    {
        $service = $this->makeService(['durationType' => 'days']);
        $this->assertTrue($service->isDayService());
    }

    public function testIsDayServiceReturnsFalseForMinutes(): void
    {
        $service = $this->makeService(['durationType' => 'minutes']);
        $this->assertFalse($service->isDayService());
    }

    public function testMinMaxDaysCanBeSet(): void
    {
        $service = $this->makeService([
            'durationType' => 'flexible_days',
            'minDays' => 2,
            'maxDays' => 10,
        ]);
        $this->assertEquals(2, $service->minDays);
        $this->assertEquals(10, $service->maxDays);
    }
}
