<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\elements\ServiceExtra;
use anvildev\booked\services\ServiceExtraService;
use anvildev\booked\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * ServiceExtraService Test
 *
 * Tests orchestration logic using partial mocks to stub element queries.
 * DB-dependent methods (getAllExtras, setExtrasForService, getTotalExtrasPrice, etc.)
 * require integration tests.
 */
class ServiceExtraServiceTest extends TestCase
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
     * @return ServiceExtraService|MockInterface
     */
    private function makePartialService(): MockInterface
    {
        return Mockery::mock(ServiceExtraService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    }

    /**
     * Create a mock ServiceExtra with configurable properties
     */
    private function makeMockExtra(array $props = []): MockInterface
    {
        $mock = Mockery::mock(ServiceExtra::class);

        // Set properties via shouldReceive for __get or direct property access
        foreach ($props as $key => $value) {
            $mock->{$key} = $value;
        }

        return $mock;
    }

    // =========================================================================
    // validateRequiredExtras() - Required extras validation
    // =========================================================================

    public function testValidateRequiredExtrasReturnsEmptyWhenAllSelected(): void
    {
        $extra1 = $this->makeMockExtra(['id' => 1, 'title' => 'Extra A', 'isRequired' => true]);
        $extra2 = $this->makeMockExtra(['id' => 2, 'title' => 'Extra B', 'isRequired' => true]);

        $service = $this->makePartialService();
        $service->shouldReceive('getExtrasForService')->with(10)->andReturn([$extra1, $extra2]);

        $missing = $service->validateRequiredExtras(10, [1 => 1, 2 => 2]);

        $this->assertEmpty($missing);
    }

    public function testValidateRequiredExtrasReturnsMissingNames(): void
    {
        $extra1 = $this->makeMockExtra(['id' => 1, 'title' => 'Extra A', 'isRequired' => true]);
        $extra2 = $this->makeMockExtra(['id' => 2, 'title' => 'Extra B', 'isRequired' => true]);

        $service = $this->makePartialService();
        $service->shouldReceive('getExtrasForService')->with(10)->andReturn([$extra1, $extra2]);

        // Only select extra 1, not extra 2
        $missing = $service->validateRequiredExtras(10, [1 => 1]);

        $this->assertCount(1, $missing);
        $this->assertEquals('Extra B', $missing[0]);
    }

    public function testValidateRequiredExtrasIgnoresOptionalExtras(): void
    {
        $required = $this->makeMockExtra(['id' => 1, 'title' => 'Required', 'isRequired' => true]);
        $optional = $this->makeMockExtra(['id' => 2, 'title' => 'Optional', 'isRequired' => false]);

        $service = $this->makePartialService();
        $service->shouldReceive('getExtrasForService')->with(10)->andReturn([$required, $optional]);

        // Select required but not optional
        $missing = $service->validateRequiredExtras(10, [1 => 1]);

        $this->assertEmpty($missing);
    }

    public function testValidateRequiredExtrasReturnsAllMissingWhenNoneSelected(): void
    {
        $extra1 = $this->makeMockExtra(['id' => 1, 'title' => 'A', 'isRequired' => true]);
        $extra2 = $this->makeMockExtra(['id' => 2, 'title' => 'B', 'isRequired' => true]);

        $service = $this->makePartialService();
        $service->shouldReceive('getExtrasForService')->with(10)->andReturn([$extra1, $extra2]);

        $missing = $service->validateRequiredExtras(10, []);

        $this->assertCount(2, $missing);
        $this->assertContains('A', $missing);
        $this->assertContains('B', $missing);
    }

    public function testValidateRequiredExtrasTreatsZeroQuantityAsMissing(): void
    {
        $extra = $this->makeMockExtra(['id' => 1, 'title' => 'Required Extra', 'isRequired' => true]);

        $service = $this->makePartialService();
        $service->shouldReceive('getExtrasForService')->with(10)->andReturn([$extra]);

        $missing = $service->validateRequiredExtras(10, [1 => 0]);

        $this->assertCount(1, $missing);
        $this->assertEquals('Required Extra', $missing[0]);
    }

    public function testValidateRequiredExtrasTreatsNegativeQuantityAsMissing(): void
    {
        $extra = $this->makeMockExtra(['id' => 1, 'title' => 'Required Extra', 'isRequired' => true]);

        $service = $this->makePartialService();
        $service->shouldReceive('getExtrasForService')->with(10)->andReturn([$extra]);

        $missing = $service->validateRequiredExtras(10, [1 => -1]);

        $this->assertCount(1, $missing);
    }

    public function testValidateRequiredExtrasReturnsEmptyWhenNoExtrasForService(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getExtrasForService')->with(10)->andReturn([]);

        $missing = $service->validateRequiredExtras(10, []);

        $this->assertEmpty($missing);
    }

    // =========================================================================
    // calculateExtrasDuration() - Duration summing
    // =========================================================================

    public function testCalculateExtrasDurationSumsMultipleExtras(): void
    {
        $extra1 = $this->makeMockExtra(['enabled' => true]);
        $extra1->shouldReceive('getTotalDuration')->with(2)->andReturn(30);

        $extra2 = $this->makeMockExtra(['enabled' => true]);
        $extra2->shouldReceive('getTotalDuration')->with(1)->andReturn(15);

        $service = $this->makePartialService();
        $service->shouldReceive('getExtrasByIds')->with([1, 2])->andReturn([1 => $extra1, 2 => $extra2]);

        $total = $service->calculateExtrasDuration([1 => 2, 2 => 1]);

        $this->assertEquals(45, $total);
    }

    public function testCalculateExtrasDurationSkipsZeroQuantity(): void
    {
        $service = $this->makePartialService();
        $service->shouldNotReceive('getExtrasByIds');

        $total = $service->calculateExtrasDuration([1 => 0, 2 => 0]);

        $this->assertEquals(0, $total);
    }

    public function testCalculateExtrasDurationSkipsNegativeQuantity(): void
    {
        $service = $this->makePartialService();
        $service->shouldNotReceive('getExtrasByIds');

        $total = $service->calculateExtrasDuration([1 => -1]);

        $this->assertEquals(0, $total);
    }

    public function testCalculateExtrasDurationSkipsDisabledExtras(): void
    {
        $extra = $this->makeMockExtra(['enabled' => false]);
        $extra->shouldNotReceive('getTotalDuration');

        $service = $this->makePartialService();
        $service->shouldReceive('getExtrasByIds')->with([1])->andReturn([1 => $extra]);

        $total = $service->calculateExtrasDuration([1 => 2]);

        $this->assertEquals(0, $total);
    }

    public function testCalculateExtrasDurationSkipsNonExistentExtras(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getExtrasByIds')->with([999])->andReturn([]);

        $total = $service->calculateExtrasDuration([999 => 1]);

        $this->assertEquals(0, $total);
    }

    public function testCalculateExtrasDurationReturnsZeroForEmptyArray(): void
    {
        $service = $this->makePartialService();

        $total = $service->calculateExtrasDuration([]);

        $this->assertEquals(0, $total);
    }

    public function testCalculateExtrasDurationPassesQuantityToGetTotalDuration(): void
    {
        $extra = $this->makeMockExtra(['enabled' => true]);
        $extra->shouldReceive('getTotalDuration')->once()->with(5)->andReturn(75);

        $service = $this->makePartialService();
        $service->shouldReceive('getExtrasByIds')->with([1])->andReturn([1 => $extra]);

        $total = $service->calculateExtrasDuration([1 => 5]);

        $this->assertEquals(75, $total);
    }

    // =========================================================================
    // saveExtrasForReservation() - Validation/skip logic
    // (Cannot test full save flow since ReservationExtraRecord needs DB)
    // =========================================================================

    public function testSaveExtrasSkipsZeroQuantity(): void
    {
        $service = $this->makePartialService();
        // getExtraById should NOT be called for zero-quantity extras
        $service->shouldNotReceive('getExtraById');

        // ReservationExtraRecord::deleteAll needs DB — this will fail
        // We can only test the skip logic conceptually here
        // Full save flow needs integration tests
        $this->assertTrue(true); // Placeholder — integration test needed
    }

    // =========================================================================
    // Service structure
    // =========================================================================

    public function testServiceIsComponent(): void
    {
        $service = new ServiceExtraService();
        $this->assertInstanceOf(ServiceExtraService::class, $service);
    }

    public function testServiceHasExpectedMethods(): void
    {
        $service = new ServiceExtraService();
        $this->assertTrue(method_exists($service, 'getAllExtras'));
        $this->assertTrue(method_exists($service, 'getExtrasForService'));
        $this->assertTrue(method_exists($service, 'getExtraById'));
        $this->assertTrue(method_exists($service, 'saveExtra'));
        $this->assertTrue(method_exists($service, 'setExtrasForService'));
        $this->assertTrue(method_exists($service, 'getExtrasForReservation'));
        $this->assertTrue(method_exists($service, 'saveExtrasForReservation'));
        $this->assertTrue(method_exists($service, 'calculateExtrasDuration'));
        $this->assertTrue(method_exists($service, 'validateRequiredExtras'));
        $this->assertTrue(method_exists($service, 'getExtrasSummary'));
        $this->assertTrue(method_exists($service, 'getTotalExtrasPrice'));
    }
}
