<?php

namespace anvildev\booked\tests\Unit\Records;

use anvildev\booked\records\ReservationRecord;
use anvildev\booked\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

class ReservationRecordTest extends TestCase
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

    private function makeRecord(array $props = []): MockInterface
    {
        $mock = Mockery::mock(ReservationRecord::class)->makePartial();
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($mock, $props);
        return $mock;
    }

    // =========================================================================
    // Static methods & constants
    // =========================================================================

    public function testTableNameReturnsCorrectTable(): void
    {
        $this->assertEquals('{{%booked_reservations}}', ReservationRecord::tableName());
    }

    public function testStatusConstants(): void
    {
        $this->assertEquals('pending', ReservationRecord::STATUS_PENDING);
        $this->assertEquals('confirmed', ReservationRecord::STATUS_CONFIRMED);
        $this->assertEquals('cancelled', ReservationRecord::STATUS_CANCELLED);
    }

    // =========================================================================
    // getStatuses()
    // =========================================================================

    public function testGetStatusesReturnsAllThreeStatuses(): void
    {
        $statuses = ReservationRecord::getStatuses();
        $this->assertCount(3, $statuses);
        $this->assertArrayHasKey('pending', $statuses);
        $this->assertArrayHasKey('confirmed', $statuses);
        $this->assertArrayHasKey('cancelled', $statuses);
    }

}
