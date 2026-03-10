<?php

namespace anvildev\booked\tests\Unit\Records;

use anvildev\booked\records\WaitlistRecord;
use anvildev\booked\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

class WaitlistRecordTest extends TestCase
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
        $mock = Mockery::mock(WaitlistRecord::class)->makePartial();
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
        $this->assertEquals('{{%booked_waitlist}}', WaitlistRecord::tableName());
    }

    public function testStatusConstants(): void
    {
        $this->assertEquals('active', WaitlistRecord::STATUS_ACTIVE);
        $this->assertEquals('notified', WaitlistRecord::STATUS_NOTIFIED);
        $this->assertEquals('converted', WaitlistRecord::STATUS_CONVERTED);
        $this->assertEquals('expired', WaitlistRecord::STATUS_EXPIRED);
        $this->assertEquals('cancelled', WaitlistRecord::STATUS_CANCELLED);
    }

    // =========================================================================
    // getStatuses()
    // =========================================================================

    public function testGetStatusesReturnsAllFiveStatuses(): void
    {
        $statuses = WaitlistRecord::getStatuses();
        $this->assertCount(5, $statuses);
        $this->assertArrayHasKey('active', $statuses);
        $this->assertArrayHasKey('notified', $statuses);
        $this->assertArrayHasKey('converted', $statuses);
        $this->assertArrayHasKey('expired', $statuses);
        $this->assertArrayHasKey('cancelled', $statuses);
    }

    // =========================================================================
    // getStatusLabel()
    // =========================================================================

    public function testGetStatusLabelReturnsLabelForKnownStatus(): void
    {
        $record = $this->makeRecord(['status' => 'active']);
        $this->assertEquals('Active', $record->getStatusLabel());
    }

    public function testGetStatusLabelReturnsStatusStringForUnknownStatus(): void
    {
        $record = $this->makeRecord(['status' => 'custom']);
        $this->assertEquals('custom', $record->getStatusLabel());
    }

    // =========================================================================
    // isActive()
    // =========================================================================

    public function testIsActiveReturnsTrueWhenActive(): void
    {
        $record = $this->makeRecord(['status' => 'active']);
        $this->assertTrue($record->isActive());
    }

    public function testIsActiveReturnsFalseWhenNotActive(): void
    {
        $record = $this->makeRecord(['status' => 'notified']);
        $this->assertFalse($record->isActive());
    }

    // =========================================================================
    // isNotified()
    // =========================================================================

    public function testIsNotifiedReturnsTrueWhenNotified(): void
    {
        $record = $this->makeRecord(['status' => 'notified']);
        $this->assertTrue($record->isNotified());
    }

    public function testIsNotifiedReturnsFalseWhenActive(): void
    {
        $record = $this->makeRecord(['status' => 'active']);
        $this->assertFalse($record->isNotified());
    }

    // =========================================================================
    // canBeNotified()
    // =========================================================================

    public function testCanBeNotifiedReturnsTrueWhenActive(): void
    {
        $record = $this->makeRecord(['status' => 'active']);
        $this->assertTrue($record->canBeNotified());
    }

    public function testCanBeNotifiedReturnsTrueWhenNotified(): void
    {
        $record = $this->makeRecord(['status' => 'notified']);
        $this->assertTrue($record->canBeNotified());
    }

    public function testCanBeNotifiedReturnsFalseWhenExpired(): void
    {
        $record = $this->makeRecord(['status' => 'expired']);
        $this->assertFalse($record->canBeNotified());
    }
}
