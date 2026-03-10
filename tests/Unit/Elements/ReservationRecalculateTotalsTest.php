<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\models\ReservationModel;
use anvildev\booked\tests\Support\TestCase;

class ReservationRecalculateTotalsTest extends TestCase
{
    public function testRecalculateTotalsMethodExistsOnModel(): void
    {
        $reservation = new ReservationModel();
        $this->assertTrue(method_exists($reservation, 'recalculateTotals'));
    }

    public function testRecalculateTotalsMethodExistsOnElement(): void
    {
        $this->assertTrue(method_exists(\anvildev\booked\elements\Reservation::class, 'recalculateTotals'));
    }

    public function testRecalculateTotalsUpdatesModelTotalPrice(): void
    {
        $reservation = new ReservationModel();
        $reservation->quantity = 3;
        $reservation->recalculateTotals();
        // Without a service or event attached, price should be 0
        $this->assertEquals(0.0, $reservation->totalPrice);
    }

    public function testTotalPricePropertyExistsOnModel(): void
    {
        $model = new ReservationModel();
        $this->assertObjectHasProperty('totalPrice', $model);
        $this->assertEquals(0.0, $model->totalPrice);
    }

    public function testTotalPricePropertyExistsOnElement(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/elements/Reservation.php');
        $this->assertStringContainsString('public float $totalPrice = 0.0;', $source);
    }

    public function testRecalculateTotalsStoresComputedValue(): void
    {
        // Verify recalculateTotals stores the result of getTotalPrice on the model
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/models/ReservationModel.php');
        preg_match('/function recalculateTotals\(\).*?\{(.*?)\}/s', $source, $match);
        $this->assertNotEmpty($match[1]);
        $this->assertStringContainsString('getTotalPrice()', $match[1]);
        $this->assertStringContainsString('$this->totalPrice', $match[1]);
    }

    public function testRecalculateTotalsExistsOnElementSource(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/elements/Reservation.php');
        preg_match('/function recalculateTotals\(\).*?\{(.*?)\}/s', $source, $match);
        $this->assertNotEmpty($match[1]);
        $this->assertStringContainsString('getTotalPrice()', $match[1]);
        $this->assertStringContainsString('$this->totalPrice', $match[1]);
    }

    public function testReduceQuantityCallsRecalculateTotals(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/services/BookingService.php');
        // Find the reduceQuantity method body
        preg_match('/function reduceQuantity\(.*?\{(.*?)\n    \}/s', $source, $match);
        $this->assertNotEmpty($match[1], 'reduceQuantity method should exist in BookingService');
        $this->assertStringContainsString('recalculateTotals()', $match[1]);
    }

    public function testRecalculateTotalsIsCalledAfterQuantitySet(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/services/BookingService.php');
        // Verify recalculateTotals comes after quantity assignment and before save
        $quantityPos = strpos($source, '$reservation->quantity = $newQuantity;');
        $recalcPos = strpos($source, '$reservation->recalculateTotals();');
        $savePos = strpos($source, '$reservation->save()', $quantityPos);

        $this->assertNotFalse($quantityPos, 'quantity assignment should exist');
        $this->assertNotFalse($recalcPos, 'recalculateTotals call should exist');
        $this->assertNotFalse($savePos, 'save call should exist after quantity set');
        $this->assertGreaterThan($quantityPos, $recalcPos, 'recalculateTotals should come after quantity assignment');
        $this->assertLessThan($savePos, $recalcPos, 'recalculateTotals should come before save');
    }
}
