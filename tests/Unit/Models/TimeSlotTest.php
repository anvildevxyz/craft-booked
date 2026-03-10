<?php

namespace anvildev\booked\tests\Unit\Models;

use anvildev\booked\models\TimeSlot;
use anvildev\booked\tests\Support\TestCase;

/**
 * TimeSlot Model Test
 *
 * Tests the TimeSlot value object functionality
 */
class TimeSlotTest extends TestCase
{
    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorWithValidTimes(): void
    {
        $slot = new TimeSlot('09:00', '10:00');

        $this->assertEquals('09:00', $slot->getStartTime());
        $this->assertEquals('10:00', $slot->getEndTime());
    }

    public function testConstructorWithMidnightStart(): void
    {
        $slot = new TimeSlot('00:00', '01:00');

        $this->assertEquals('00:00', $slot->getStartTime());
        $this->assertEquals('01:00', $slot->getEndTime());
    }

    public function testConstructorWithEndOfDay(): void
    {
        $slot = new TimeSlot('23:00', '24:00');

        $this->assertEquals('23:00', $slot->getStartTime());
        $this->assertEquals('24:00', $slot->getEndTime());
    }

    // =========================================================================
    // fromDuration() Tests
    // =========================================================================

    public function testFromDuration30Minutes(): void
    {
        $slot = TimeSlot::fromDuration('09:00', 30);

        $this->assertEquals('09:00', $slot->getStartTime());
        $this->assertEquals('09:30', $slot->getEndTime());
        $this->assertEquals(30, $slot->getDuration());
    }

    public function testFromDuration60Minutes(): void
    {
        $slot = TimeSlot::fromDuration('14:00', 60);

        $this->assertEquals('14:00', $slot->getStartTime());
        $this->assertEquals('15:00', $slot->getEndTime());
        $this->assertEquals(60, $slot->getDuration());
    }

    public function testFromDuration90Minutes(): void
    {
        $slot = TimeSlot::fromDuration('09:30', 90);

        $this->assertEquals('09:30', $slot->getStartTime());
        $this->assertEquals('11:00', $slot->getEndTime());
        $this->assertEquals(90, $slot->getDuration());
    }

    public function testFromDurationCrossingHour(): void
    {
        $slot = TimeSlot::fromDuration('09:45', 30);

        $this->assertEquals('09:45', $slot->getStartTime());
        $this->assertEquals('10:15', $slot->getEndTime());
    }

    // =========================================================================
    // fromMinutes() Tests
    // =========================================================================

    public function testFromMinutes(): void
    {
        // 9:00 = 540 minutes, 10:00 = 600 minutes
        $slot = TimeSlot::fromMinutes(540, 600);

        $this->assertEquals('09:00', $slot->getStartTime());
        $this->assertEquals('10:00', $slot->getEndTime());
    }

    public function testFromMinutesMidnight(): void
    {
        $slot = TimeSlot::fromMinutes(0, 60);

        $this->assertEquals('00:00', $slot->getStartTime());
        $this->assertEquals('01:00', $slot->getEndTime());
    }

    public function testFromMinutesEndOfDay(): void
    {
        // 23:00 = 1380, 24:00 = 1440
        $slot = TimeSlot::fromMinutes(1380, 1440);

        $this->assertEquals('23:00', $slot->getStartTime());
        $this->assertEquals('24:00', $slot->getEndTime());
    }

    // =========================================================================
    // getStartMinutes() / getEndMinutes() Tests
    // =========================================================================

    public function testGetStartMinutes(): void
    {
        $slot = new TimeSlot('09:30', '10:00');

        // 9 hours * 60 + 30 minutes = 570
        $this->assertEquals(570, $slot->getStartMinutes());
    }

    public function testGetEndMinutes(): void
    {
        $slot = new TimeSlot('09:00', '10:30');

        // 10 hours * 60 + 30 minutes = 630
        $this->assertEquals(630, $slot->getEndMinutes());
    }

    public function testGetMinutesMidnight(): void
    {
        $slot = new TimeSlot('00:00', '01:00');

        $this->assertEquals(0, $slot->getStartMinutes());
        $this->assertEquals(60, $slot->getEndMinutes());
    }

    // =========================================================================
    // getDuration() Tests
    // =========================================================================

    public function testGetDuration30Minutes(): void
    {
        $slot = new TimeSlot('09:00', '09:30');

        $this->assertEquals(30, $slot->getDuration());
    }

    public function testGetDuration60Minutes(): void
    {
        $slot = new TimeSlot('09:00', '10:00');

        $this->assertEquals(60, $slot->getDuration());
    }

    public function testGetDuration2Hours(): void
    {
        $slot = new TimeSlot('09:00', '11:00');

        $this->assertEquals(120, $slot->getDuration());
    }

    public function testGetDurationFullDay(): void
    {
        $slot = new TimeSlot('00:00', '24:00');

        $this->assertEquals(1440, $slot->getDuration());
    }

    // =========================================================================
    // overlaps() Tests
    // =========================================================================

    public function testOverlapsCompleteOverlap(): void
    {
        $slot1 = new TimeSlot('09:00', '11:00');
        $slot2 = new TimeSlot('10:00', '10:30');

        $this->assertTrue($slot1->overlaps($slot2));
        $this->assertTrue($slot2->overlaps($slot1));
    }

    public function testOverlapsPartialOverlapStart(): void
    {
        $slot1 = new TimeSlot('09:00', '10:30');
        $slot2 = new TimeSlot('10:00', '11:00');

        $this->assertTrue($slot1->overlaps($slot2));
        $this->assertTrue($slot2->overlaps($slot1));
    }

    public function testOverlapsPartialOverlapEnd(): void
    {
        $slot1 = new TimeSlot('10:00', '11:00');
        $slot2 = new TimeSlot('09:00', '10:30');

        $this->assertTrue($slot1->overlaps($slot2));
        $this->assertTrue($slot2->overlaps($slot1));
    }

    public function testOverlapsNoOverlapAdjacent(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('10:00', '11:00');

        // Adjacent slots don't overlap
        $this->assertFalse($slot1->overlaps($slot2));
        $this->assertFalse($slot2->overlaps($slot1));
    }

    public function testOverlapsNoOverlapSeparate(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('11:00', '12:00');

        $this->assertFalse($slot1->overlaps($slot2));
        $this->assertFalse($slot2->overlaps($slot1));
    }

    public function testOverlapsSameSlot(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('09:00', '10:00');

        $this->assertTrue($slot1->overlaps($slot2));
    }

    // =========================================================================
    // contains() Tests
    // =========================================================================

    public function testContainsCompletelyInside(): void
    {
        $outer = new TimeSlot('09:00', '12:00');
        $inner = new TimeSlot('10:00', '11:00');

        $this->assertTrue($outer->contains($inner));
        $this->assertFalse($inner->contains($outer));
    }

    public function testContainsSameStart(): void
    {
        $outer = new TimeSlot('09:00', '12:00');
        $inner = new TimeSlot('09:00', '10:00');

        $this->assertTrue($outer->contains($inner));
    }

    public function testContainsSameEnd(): void
    {
        $outer = new TimeSlot('09:00', '12:00');
        $inner = new TimeSlot('11:00', '12:00');

        $this->assertTrue($outer->contains($inner));
    }

    public function testContainsSameSlot(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('09:00', '10:00');

        $this->assertTrue($slot1->contains($slot2));
    }

    public function testContainsPartiallyOutside(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('09:30', '10:30');

        $this->assertFalse($slot1->contains($slot2));
    }

    // =========================================================================
    // isAdjacentTo() Tests
    // =========================================================================

    public function testIsAdjacentToRightSide(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('10:00', '11:00');

        $this->assertTrue($slot1->isAdjacentTo($slot2));
        $this->assertTrue($slot2->isAdjacentTo($slot1));
    }

    public function testIsAdjacentToLeftSide(): void
    {
        $slot1 = new TimeSlot('10:00', '11:00');
        $slot2 = new TimeSlot('09:00', '10:00');

        $this->assertTrue($slot1->isAdjacentTo($slot2));
    }

    public function testIsAdjacentToWithGap(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('10:30', '11:00');

        $this->assertFalse($slot1->isAdjacentTo($slot2));
    }

    public function testIsAdjacentToOverlapping(): void
    {
        $slot1 = new TimeSlot('09:00', '10:30');
        $slot2 = new TimeSlot('10:00', '11:00');

        $this->assertFalse($slot1->isAdjacentTo($slot2));
    }

    // =========================================================================
    // containsTime() Tests
    // =========================================================================

    public function testContainsTimeInMiddle(): void
    {
        $slot = new TimeSlot('09:00', '11:00');

        $this->assertTrue($slot->containsTime('10:00'));
    }

    public function testContainsTimeAtStart(): void
    {
        $slot = new TimeSlot('09:00', '11:00');

        $this->assertTrue($slot->containsTime('09:00'));
    }

    public function testContainsTimeAtEndExclusive(): void
    {
        $slot = new TimeSlot('09:00', '11:00');

        // End time is exclusive
        $this->assertFalse($slot->containsTime('11:00'));
    }

    public function testContainsTimeBefore(): void
    {
        $slot = new TimeSlot('09:00', '11:00');

        $this->assertFalse($slot->containsTime('08:00'));
    }

    public function testContainsTimeAfter(): void
    {
        $slot = new TimeSlot('09:00', '11:00');

        $this->assertFalse($slot->containsTime('12:00'));
    }

    // =========================================================================
    // merge() Tests
    // =========================================================================

    public function testMergeAdjacentSlots(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('10:00', '11:00');

        $merged = $slot1->merge($slot2);

        $this->assertEquals('09:00', $merged->getStartTime());
        $this->assertEquals('11:00', $merged->getEndTime());
    }

    public function testMergeOverlappingSlots(): void
    {
        $slot1 = new TimeSlot('09:00', '10:30');
        $slot2 = new TimeSlot('10:00', '11:00');

        $merged = $slot1->merge($slot2);

        $this->assertEquals('09:00', $merged->getStartTime());
        $this->assertEquals('11:00', $merged->getEndTime());
    }

    public function testMergeContainedSlot(): void
    {
        $slot1 = new TimeSlot('09:00', '12:00');
        $slot2 = new TimeSlot('10:00', '11:00');

        $merged = $slot1->merge($slot2);

        $this->assertEquals('09:00', $merged->getStartTime());
        $this->assertEquals('12:00', $merged->getEndTime());
    }

    public function testMergeNonOverlappingThrowsException(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('11:00', '12:00');

        $this->expectException(\InvalidArgumentException::class);
        $slot1->merge($slot2);
    }

    // =========================================================================
    // subtract() Tests
    // =========================================================================

    public function testSubtractNoOverlap(): void
    {
        $slot = new TimeSlot('09:00', '10:00');
        $other = new TimeSlot('11:00', '12:00');

        $result = $slot->subtract($other);

        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]->getStartTime());
        $this->assertEquals('10:00', $result[0]->getEndTime());
    }

    public function testSubtractCompletelyCovers(): void
    {
        $slot = new TimeSlot('10:00', '11:00');
        $other = new TimeSlot('09:00', '12:00');

        $result = $slot->subtract($other);

        $this->assertCount(0, $result);
    }

    public function testSubtractFromStart(): void
    {
        $slot = new TimeSlot('09:00', '12:00');
        $other = new TimeSlot('09:00', '10:00');

        $result = $slot->subtract($other);

        $this->assertCount(1, $result);
        $this->assertEquals('10:00', $result[0]->getStartTime());
        $this->assertEquals('12:00', $result[0]->getEndTime());
    }

    public function testSubtractFromEnd(): void
    {
        $slot = new TimeSlot('09:00', '12:00');
        $other = new TimeSlot('11:00', '12:00');

        $result = $slot->subtract($other);

        $this->assertCount(1, $result);
        $this->assertEquals('09:00', $result[0]->getStartTime());
        $this->assertEquals('11:00', $result[0]->getEndTime());
    }

    public function testSubtractFromMiddle(): void
    {
        $slot = new TimeSlot('09:00', '12:00');
        $other = new TimeSlot('10:00', '11:00');

        $result = $slot->subtract($other);

        $this->assertCount(2, $result);
        $this->assertEquals('09:00', $result[0]->getStartTime());
        $this->assertEquals('10:00', $result[0]->getEndTime());
        $this->assertEquals('11:00', $result[1]->getStartTime());
        $this->assertEquals('12:00', $result[1]->getEndTime());
    }

    public function testSubtractSameSlot(): void
    {
        $slot = new TimeSlot('09:00', '10:00');
        $other = new TimeSlot('09:00', '10:00');

        $result = $slot->subtract($other);

        $this->assertCount(0, $result);
    }

    // =========================================================================
    // shift() Tests
    // =========================================================================

    public function testShiftForward30Minutes(): void
    {
        $slot = new TimeSlot('09:00', '10:00');
        $shifted = $slot->shift(30);

        $this->assertEquals('09:30', $shifted->getStartTime());
        $this->assertEquals('10:30', $shifted->getEndTime());
    }

    public function testShiftBackward30Minutes(): void
    {
        $slot = new TimeSlot('10:00', '11:00');
        $shifted = $slot->shift(-30);

        $this->assertEquals('09:30', $shifted->getStartTime());
        $this->assertEquals('10:30', $shifted->getEndTime());
    }

    public function testShiftDoesNotModifyOriginal(): void
    {
        $slot = new TimeSlot('09:00', '10:00');
        $shifted = $slot->shift(30);

        // Original should be unchanged
        $this->assertEquals('09:00', $slot->getStartTime());
        $this->assertEquals('10:00', $slot->getEndTime());

        // Shifted should be different
        $this->assertNotEquals($slot->getStartTime(), $shifted->getStartTime());
    }

    // =========================================================================
    // isValid() Tests
    // =========================================================================

    public function testIsValidNormalSlot(): void
    {
        $slot = new TimeSlot('09:00', '10:00');

        $this->assertTrue($slot->isValid());
    }

    public function testIsValidFullDay(): void
    {
        $slot = new TimeSlot('00:00', '24:00');

        $this->assertTrue($slot->isValid());
    }

    public function testIsValidStartBeforeEnd(): void
    {
        $slot = new TimeSlot('10:00', '09:00');

        $this->assertFalse($slot->isValid());
    }

    public function testIsValidSameStartAndEnd(): void
    {
        $slot = new TimeSlot('09:00', '09:00');

        $this->assertFalse($slot->isValid());
    }

    // =========================================================================
    // toArray() Tests
    // =========================================================================

    public function testToArray(): void
    {
        $slot = new TimeSlot('09:00', '10:30');

        $result = $slot->toArray();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('start', $result);
        $this->assertArrayHasKey('end', $result);
        $this->assertArrayHasKey('duration', $result);
        $this->assertEquals('09:00', $result['start']);
        $this->assertEquals('10:30', $result['end']);
        $this->assertEquals(90, $result['duration']);
    }

    // =========================================================================
    // __toString() Tests
    // =========================================================================

    public function testToString(): void
    {
        $slot = new TimeSlot('09:00', '10:30');

        $this->assertEquals('09:00-10:30', (string)$slot);
    }

    // =========================================================================
    // equals() Tests
    // =========================================================================

    public function testEqualsIdentical(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('09:00', '10:00');

        $this->assertTrue($slot1->equals($slot2));
    }

    public function testEqualsDifferentStart(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('09:30', '10:00');

        $this->assertFalse($slot1->equals($slot2));
    }

    public function testEqualsDifferentEnd(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('09:00', '10:30');

        $this->assertFalse($slot1->equals($slot2));
    }

    public function testEqualsBothDifferent(): void
    {
        $slot1 = new TimeSlot('09:00', '10:00');
        $slot2 = new TimeSlot('11:00', '12:00');

        $this->assertFalse($slot1->equals($slot2));
    }
}
