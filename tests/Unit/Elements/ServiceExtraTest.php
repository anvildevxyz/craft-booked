<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\ServiceExtra;
use anvildev\booked\tests\Support\TestCase;

/**
 * ServiceExtra Element Test
 *
 * Tests pure business logic methods on the ServiceExtra element.
 * Uses ReflectionClass to bypass Element::init() which requires Craft::$app.
 */
class ServiceExtraTest extends TestCase
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

    /**
     * Create a ServiceExtra instance without calling init()
     */
    private function makeExtra(array $props = []): ServiceExtra
    {
        $ref = new \ReflectionClass(ServiceExtra::class);
        $extra = $ref->newInstanceWithoutConstructor();

        $extra->price = $props['price'] ?? 0.0;
        $extra->duration = $props['duration'] ?? 0;
        $extra->maxQuantity = $props['maxQuantity'] ?? 1;
        $extra->isRequired = $props['isRequired'] ?? false;
        $extra->enabled = $props['enabled'] ?? true;

        return $extra;
    }

    // =========================================================================
    // isValidQuantity()
    // =========================================================================

    public function testIsValidQuantityReturnsFalseForZero(): void
    {
        $extra = $this->makeExtra(['maxQuantity' => 5]);
        $this->assertFalse($extra->isValidQuantity(0));
    }

    public function testIsValidQuantityReturnsFalseForNegative(): void
    {
        $extra = $this->makeExtra(['maxQuantity' => 5]);
        $this->assertFalse($extra->isValidQuantity(-1));
    }

    public function testIsValidQuantityReturnsTrueForOne(): void
    {
        $extra = $this->makeExtra(['maxQuantity' => 5]);
        $this->assertTrue($extra->isValidQuantity(1));
    }

    public function testIsValidQuantityReturnsTrueAtMax(): void
    {
        $extra = $this->makeExtra(['maxQuantity' => 3]);
        $this->assertTrue($extra->isValidQuantity(3));
    }

    public function testIsValidQuantityReturnsFalseAboveMax(): void
    {
        $extra = $this->makeExtra(['maxQuantity' => 3]);
        $this->assertFalse($extra->isValidQuantity(4));
    }

    public function testIsValidQuantityUnlimitedAllowsAnyPositive(): void
    {
        $extra = $this->makeExtra(['maxQuantity' => 0]); // 0 = unlimited
        $this->assertTrue($extra->isValidQuantity(100));
    }

    public function testIsValidQuantityUnlimitedStillRejectsZero(): void
    {
        $extra = $this->makeExtra(['maxQuantity' => 0]);
        $this->assertFalse($extra->isValidQuantity(0));
    }

    // =========================================================================
    // getTotalPrice()
    // =========================================================================

    public function testGetTotalPriceWithSingleQuantity(): void
    {
        $extra = $this->makeExtra(['price' => 10.50, 'maxQuantity' => 5]);
        $this->assertEquals(10.50, $extra->getTotalPrice(1));
    }

    public function testGetTotalPriceMultipliesByQuantity(): void
    {
        $extra = $this->makeExtra(['price' => 10.00, 'maxQuantity' => 5]);
        $this->assertEquals(30.00, $extra->getTotalPrice(3));
    }

    public function testGetTotalPriceCapsAtMaxQuantity(): void
    {
        $extra = $this->makeExtra(['price' => 10.00, 'maxQuantity' => 3]);
        // Requesting 5 but max is 3, so should cap at 3 * 10 = 30
        $this->assertEquals(30.00, $extra->getTotalPrice(5));
    }

    public function testGetTotalPriceUnlimitedUsesFullQuantity(): void
    {
        $extra = $this->makeExtra(['price' => 5.00, 'maxQuantity' => 0]);
        $this->assertEquals(500.00, $extra->getTotalPrice(100));
    }

    public function testGetTotalPriceDefaultsToOne(): void
    {
        $extra = $this->makeExtra(['price' => 25.00, 'maxQuantity' => 5]);
        $this->assertEquals(25.00, $extra->getTotalPrice());
    }

    public function testGetTotalPriceZeroPriceReturnsZero(): void
    {
        $extra = $this->makeExtra(['price' => 0.0, 'maxQuantity' => 5]);
        $this->assertEquals(0.0, $extra->getTotalPrice(3));
    }

    // =========================================================================
    // getTotalDuration()
    // =========================================================================

    public function testGetTotalDurationWithSingleQuantity(): void
    {
        $extra = $this->makeExtra(['duration' => 15, 'maxQuantity' => 5]);
        $this->assertEquals(15, $extra->getTotalDuration(1));
    }

    public function testGetTotalDurationMultipliesByQuantity(): void
    {
        $extra = $this->makeExtra(['duration' => 10, 'maxQuantity' => 5]);
        $this->assertEquals(30, $extra->getTotalDuration(3));
    }

    public function testGetTotalDurationCapsAtMaxQuantity(): void
    {
        $extra = $this->makeExtra(['duration' => 10, 'maxQuantity' => 2]);
        $this->assertEquals(20, $extra->getTotalDuration(5));
    }

    public function testGetTotalDurationUnlimitedUsesFullQuantity(): void
    {
        $extra = $this->makeExtra(['duration' => 5, 'maxQuantity' => 0]);
        $this->assertEquals(50, $extra->getTotalDuration(10));
    }

    public function testGetTotalDurationZeroDurationReturnsZero(): void
    {
        $extra = $this->makeExtra(['duration' => 0, 'maxQuantity' => 5]);
        $this->assertEquals(0, $extra->getTotalDuration(3));
    }

    // =========================================================================
    // getStatus()
    // =========================================================================

    public function testGetStatusReturnsEnabledWhenEnabled(): void
    {
        $extra = $this->makeExtra(['enabled' => true]);
        $this->assertEquals('enabled', $extra->getStatus());
    }

    public function testGetStatusReturnsDisabledWhenDisabled(): void
    {
        $extra = $this->makeExtra(['enabled' => false]);
        $this->assertEquals('disabled', $extra->getStatus());
    }

    // =========================================================================
    // Static methods
    // =========================================================================

    public function testHasTitlesReturnsTrue(): void
    {
        $this->assertTrue(ServiceExtra::hasTitles());
    }

    public function testHasStatusesReturnsTrue(): void
    {
        $this->assertTrue(ServiceExtra::hasStatuses());
    }

    public function testHasUrisReturnsFalse(): void
    {
        $this->assertFalse(ServiceExtra::hasUris());
    }

    public function testIsLocalizedReturnsTrue(): void
    {
        $this->assertTrue(ServiceExtra::isLocalized());
    }

    public function testRefHandleReturnsServiceExtra(): void
    {
        $this->assertEquals('serviceExtra', ServiceExtra::refHandle());
    }

    public function testStatusesContainsEnabledAndDisabled(): void
    {
        $statuses = ServiceExtra::statuses();
        $this->assertArrayHasKey('enabled', $statuses);
        $this->assertArrayHasKey('disabled', $statuses);
    }
}
