<?php

namespace anvildev\booked\tests\Unit\Records;

use anvildev\booked\records\CalendarInviteRecord;
use anvildev\booked\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

class CalendarInviteRecordTest extends TestCase
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
        $mock = Mockery::mock(CalendarInviteRecord::class)->makePartial();
        $ref = new \ReflectionProperty(\yii\db\BaseActiveRecord::class, '_attributes');
        $ref->setAccessible(true);
        $ref->setValue($mock, $props);
        return $mock;
    }

    // =========================================================================
    // Static methods
    // =========================================================================

    public function testTableNameReturnsCorrectTable(): void
    {
        $this->assertEquals('{{%booked_calendar_invites}}', CalendarInviteRecord::tableName());
    }

    // =========================================================================
    // isExpired()
    // =========================================================================

    public function testIsExpiredReturnsTrueWhenExpired(): void
    {
        $record = $this->makeRecord([
            'expiresAt' => (new \DateTime('-1 hour'))->format('Y-m-d H:i:s'),
        ]);
        $this->assertTrue($record->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenNotExpired(): void
    {
        $record = $this->makeRecord([
            'expiresAt' => (new \DateTime('+1 hour'))->format('Y-m-d H:i:s'),
        ]);
        $this->assertFalse($record->isExpired());
    }

    // =========================================================================
    // isUsed()
    // =========================================================================

    public function testIsUsedReturnsTrueWhenUsedAtSet(): void
    {
        $record = $this->makeRecord([
            'usedAt' => '2025-06-15 10:00:00',
        ]);
        $this->assertTrue($record->isUsed());
    }

    public function testIsUsedReturnsFalseWhenUsedAtNull(): void
    {
        $record = $this->makeRecord([
            'usedAt' => null,
        ]);
        $this->assertFalse($record->isUsed());
    }
}
