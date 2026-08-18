<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\SlotGeneratorService;
use anvildev\booked\tests\Support\TestCase;

/**
 * SlotGeneratorService Test
 *
 * Tests the pure utility functions in SlotGeneratorService.
 * Note: Methods that depend on Craft CMS (getSlotInterval, addEmployeeInfo) are skipped.
 */
class SlotGeneratorServiceTest extends TestCase
{
    private SlotGeneratorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SlotGeneratorService();
    }

    // =========================================================================
    // deduplicateByTime() Tests
    // =========================================================================

    public function testDeduplicateByTimeEmpty(): void
    {
        $result = $this->service->deduplicateByTime([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDeduplicateByTimeSingleSlot(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertNull($result[0]['employeeId']);
    }

    public function testDeduplicateByTimeDuplicatesRemoved(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'employeeName' => 'John'],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'employeeName' => 'Jane'],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 1, 'employeeName' => 'John'],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertCount(2, $result);
        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertEquals('10:00', $result[1]['time']);
    }

    public function testDeduplicateByTimeSetsEmployeeToNull(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 5, 'employeeName' => 'John Doe'],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertNull($result[0]['employeeId']);
        $this->assertNull($result[0]['employeeName']);
    }

    public function testDeduplicateByTimePreservesOtherFields(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'duration' => 60, 'available' => true],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertEquals(60, $result[0]['duration']);
        $this->assertTrue($result[0]['available']);
    }

    public function testDeduplicateByTimeKeepsFirstOccurrence(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'locationId' => 100],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'locationId' => 200],
        ];

        $result = $this->service->deduplicateByTime($slots);

        // Should keep first occurrence's location
        $this->assertEquals(100, $result[0]['locationId']);
    }

    /**
     * A booking belongs to one employee, so the merged slot must advertise the
     * best single employee — never the total, which is a party nobody can book.
     */
    public function testDeduplicateByTimeReportsTheBestEmployeesSeats(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'maxCapacity' => 3, 'bookedQuantity' => 1, 'availableCapacity' => 2],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'maxCapacity' => 3, 'bookedQuantity' => 0, 'availableCapacity' => 3],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertCount(1, $result);
        $this->assertEquals(3, $result[0]['availableCapacity']);
        $this->assertEquals(3, $result[0]['maxCapacity']);
        $this->assertEquals(0, $result[0]['bookedQuantity']);
        // The per-employee split survives for filters that ask "any seat left?".
        $this->assertEquals([1 => 2, 2 => 3], $result[0]['seatsByEmployee']);
    }

    public function testDeduplicateByTimeKeepsTheBestWhenItComesFirst(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'maxCapacity' => 5, 'bookedQuantity' => 0, 'availableCapacity' => 5],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'maxCapacity' => 2, 'bookedQuantity' => 0, 'availableCapacity' => 2],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertEquals(5, $result[0]['availableCapacity']);
        $this->assertEquals(5, $result[0]['maxCapacity']);
    }

    public function testDeduplicateByTimeTreatsNullCapacityAsUnlimited(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'maxCapacity' => 3, 'availableCapacity' => 2],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'maxCapacity' => null, 'availableCapacity' => null],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertNull($result[0]['maxCapacity']);
        $this->assertNull($result[0]['availableCapacity']);
        $this->assertNull($result[0]['seatsByEmployee'][2]);
    }

    public function testDeduplicateByTimeIgnoresRepeatedEmployee(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'maxCapacity' => 2, 'availableCapacity' => 2],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'maxCapacity' => 2, 'availableCapacity' => 2],
        ];

        $result = $this->service->deduplicateByTime($slots);

        $this->assertEquals([1], $result[0]['availableEmployeeIds']);
        $this->assertEquals(2, $result[0]['maxCapacity']);
    }

    // =========================================================================
    // sortByTime() Tests
    // =========================================================================

    public function testSortByTimeEmpty(): void
    {
        $result = $this->service->sortByTime([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testSortByTimeAlreadySorted(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00'],
            ['time' => '10:00', 'endTime' => '11:00'],
            ['time' => '11:00', 'endTime' => '12:00'],
        ];

        $result = $this->service->sortByTime($slots);

        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertEquals('10:00', $result[1]['time']);
        $this->assertEquals('11:00', $result[2]['time']);
    }

    public function testSortByTimeReversed(): void
    {
        $slots = [
            ['time' => '11:00', 'endTime' => '12:00'],
            ['time' => '10:00', 'endTime' => '11:00'],
            ['time' => '09:00', 'endTime' => '10:00'],
        ];

        $result = $this->service->sortByTime($slots);

        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertEquals('10:00', $result[1]['time']);
        $this->assertEquals('11:00', $result[2]['time']);
    }

    public function testSortByTimeRandom(): void
    {
        $slots = [
            ['time' => '14:00', 'endTime' => '15:00'],
            ['time' => '09:00', 'endTime' => '10:00'],
            ['time' => '11:00', 'endTime' => '12:00'],
            ['time' => '08:00', 'endTime' => '09:00'],
        ];

        $result = $this->service->sortByTime($slots);

        $this->assertEquals('08:00', $result[0]['time']);
        $this->assertEquals('09:00', $result[1]['time']);
        $this->assertEquals('11:00', $result[2]['time']);
        $this->assertEquals('14:00', $result[3]['time']);
    }

    public function testSortByTimePreservesAllFields(): void
    {
        $slots = [
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 2, 'extra' => 'B'],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'extra' => 'A'],
        ];

        $result = $this->service->sortByTime($slots);

        $this->assertEquals('A', $result[0]['extra']);
        $this->assertEquals(1, $result[0]['employeeId']);
        $this->assertEquals('B', $result[1]['extra']);
        $this->assertEquals(2, $result[1]['employeeId']);
    }

    public function testSortByTimeSameTime(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2],
        ];

        $result = $this->service->sortByTime($slots);

        $this->assertCount(2, $result);
        $this->assertEquals('09:00', $result[0]['time']);
        $this->assertEquals('09:00', $result[1]['time']);
    }

    // =========================================================================
    // filterByEmployeeQuantity() Tests
    // =========================================================================

    public function testFilterByEmployeeQuantityEmpty(): void
    {
        $result = $this->service->filterByEmployeeQuantity([], 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFilterByEmployeeQuantityOne(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 1],
        ];

        $result = $this->service->filterByEmployeeQuantity($slots, 1);

        $this->assertCount(2, $result);
    }

    /**
     * Two employees with one seat each cannot seat a party of two: the booking
     * service hands the whole party to a single employee, so a slot passing on
     * head count was offered and then refused. Nothing is bookable here.
     */
    public function testFilterByEmployeeQuantityTwoSingleSeatEmployeesCannotSeatAPair(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 1],
        ];

        $this->assertCount(0, $this->service->filterByEmployeeQuantity($slots, 2));
        // Every one of them still serves a single booking.
        $this->assertCount(3, $this->service->filterByEmployeeQuantity($slots, 1));
    }

    public function testFilterByEmployeeQuantityNoneMatch(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 2],
        ];

        $result = $this->service->filterByEmployeeQuantity($slots, 2);

        $this->assertCount(0, $result);
    }

    /** Three single-seat employees still cannot seat one party of three. */
    public function testFilterByEmployeeQuantityThreeSingleSeatEmployeesCannotSeatATrio(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 3],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 1],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 2],
        ];

        $this->assertCount(0, $this->service->filterByEmployeeQuantity($slots, 3));

        // Give one of them three seats and 09:00 becomes bookable again.
        $slots[0]['availableCapacity'] = 3;
        $result = $this->service->filterByEmployeeQuantity($slots, 3);
        $this->assertCount(3, $result);
        foreach ($result as $slot) {
            $this->assertEquals('09:00', $slot['time']);
        }
    }

    public function testFilterByEmployeeQuantityCountsSeatsNotEmployees(): void
    {
        // One employee holding three seats can take a party of three.
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'availableCapacity' => 3],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 1, 'availableCapacity' => 2],
        ];

        $result = $this->service->filterByEmployeeQuantity($slots, 3);

        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]['time']);
    }

    /**
     * Seats must NOT add up across staff: the booking goes to one employee, so
     * two employees with two seats each cannot seat a party of four. Offering
     * that slot only to refuse the booking is worse than withholding it.
     */
    public function testFilterByEmployeeQuantityDoesNotAddSeatsAcrossEmployees(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'availableCapacity' => 2],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'availableCapacity' => 2],
        ];

        $this->assertCount(2, $this->service->filterByEmployeeQuantity($slots, 2));
        $this->assertCount(0, $this->service->filterByEmployeeQuantity($slots, 3));
        $this->assertCount(0, $this->service->filterByEmployeeQuantity($slots, 4));
    }

    public function testFilterByEmployeeQuantityUsesTheRoomiestEmployee(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'availableCapacity' => 1],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'availableCapacity' => 4],
        ];

        $this->assertCount(2, $this->service->filterByEmployeeQuantity($slots, 4));
        $this->assertCount(0, $this->service->filterByEmployeeQuantity($slots, 5));
    }

    public function testFilterByEmployeeQuantityKeepsUnlimitedSlots(): void
    {
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'availableCapacity' => null],
        ];

        $this->assertCount(1, $this->service->filterByEmployeeQuantity($slots, 9));
    }

    public function testFilterByEmployeeQuantityPreservesAllFields(): void
    {
        // One employee with two seats, so the party of two is actually bookable.
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'duration' => 60, 'availableCapacity' => 2],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'duration' => 60, 'availableCapacity' => 2],
        ];

        $result = $this->service->filterByEmployeeQuantity($slots, 2);

        $this->assertEquals(60, $result[0]['duration']);
        $this->assertEquals(60, $result[1]['duration']);
    }

    public function testFilterByEmployeeQuantityMultipleTimeSlots(): void
    {
        // Two-seat employees at 09:00 and 10:00; the 11:00 employee holds one.
        $slots = [
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 1, 'availableCapacity' => 2],
            ['time' => '09:00', 'endTime' => '10:00', 'employeeId' => 2, 'availableCapacity' => 2],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 1, 'availableCapacity' => 2],
            ['time' => '10:00', 'endTime' => '11:00', 'employeeId' => 2, 'availableCapacity' => 2],
            ['time' => '11:00', 'endTime' => '12:00', 'employeeId' => 1, 'availableCapacity' => 1],
        ];

        $result = $this->service->filterByEmployeeQuantity($slots, 2);

        $this->assertCount(4, $result);

        $times = array_column($result, 'time');
        $this->assertCount(2, array_filter($times, fn($t) => $t === '09:00'));
        $this->assertCount(2, array_filter($times, fn($t) => $t === '10:00'));
    }
}
