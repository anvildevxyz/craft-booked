<?php

namespace anvildev\booked\tests\Unit\Records;

use anvildev\booked\records\CalendarSyncStatusRecord;
use anvildev\booked\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

class CalendarSyncStatusRecordTest extends TestCase
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
        $mock = Mockery::mock(CalendarSyncStatusRecord::class)->makePartial();
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
        $this->assertEquals('{{%booked_calendar_sync_status}}', CalendarSyncStatusRecord::tableName());
    }

    public function testStatusConstants(): void
    {
        $this->assertEquals('disconnected', CalendarSyncStatusRecord::STATUS_DISCONNECTED);
        $this->assertEquals('connected', CalendarSyncStatusRecord::STATUS_CONNECTED);
        $this->assertEquals('syncing', CalendarSyncStatusRecord::STATUS_SYNCING);
        $this->assertEquals('error', CalendarSyncStatusRecord::STATUS_ERROR);
    }

}
