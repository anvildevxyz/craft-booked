<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\Service;
use anvildev\booked\tests\Support\TestCase;

class ServiceDurationTypeTest extends TestCase
{
    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    private function makeService(array $props = []): Service
    {
        $ref = new \ReflectionClass(Service::class);
        $service = $ref->newInstanceWithoutConstructor();

        $service->enabled = $props['enabled'] ?? true;
        $service->duration = $props['duration'] ?? 60;
        $service->durationType = $props['durationType'] ?? 'minutes';
        $service->pricingMode = $props['pricingMode'] ?? 'flat';
        $service->price = $props['price'] ?? null;
        $service->bufferBefore = $props['bufferBefore'] ?? null;
        $service->bufferAfter = $props['bufferAfter'] ?? null;
        $service->timeSlotLength = $props['timeSlotLength'] ?? null;

        return $service;
    }

    public function testDurationTypeDefaultsToMinutes(): void
    {
        $service = $this->makeService();
        $this->assertEquals('minutes', $service->durationType);
    }

    public function testDurationTypeCanBeDays(): void
    {
        $service = $this->makeService(['durationType' => 'days']);
        $this->assertEquals('days', $service->durationType);
    }

    public function testDurationTypeCanBeFlexibleDays(): void
    {
        $service = $this->makeService(['durationType' => 'flexible_days']);
        $this->assertEquals('flexible_days', $service->durationType);
    }

    public function testPricingModeDefaultsToFlat(): void
    {
        $service = $this->makeService();
        $this->assertEquals('flat', $service->pricingMode);
    }

    public function testPricingModeCanBePerUnit(): void
    {
        $service = $this->makeService(['pricingMode' => 'per_unit']);
        $this->assertEquals('per_unit', $service->pricingMode);
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

    public function testIsPerUnitPricingReturnsTrueForPerUnit(): void
    {
        $service = $this->makeService(['pricingMode' => 'per_unit']);
        $this->assertTrue($service->isPerUnitPricing());
    }

    public function testIsPerUnitPricingReturnsFalseForFlat(): void
    {
        $service = $this->makeService(['pricingMode' => 'flat']);
        $this->assertFalse($service->isPerUnitPricing());
    }

    public function testGetDurationLabelMinutes(): void
    {
        $service = $this->makeService(['duration' => 60, 'durationType' => 'minutes']);
        $label = $service->getDurationLabel();
        $this->assertStringContainsString('60', $label);
    }

    public function testGetDurationLabelDays(): void
    {
        $service = $this->makeService(['duration' => 3, 'durationType' => 'days']);
        $label = $service->getDurationLabel();
        $this->assertStringContainsString('3', $label);
    }
}
