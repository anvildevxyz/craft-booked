<?php

namespace anvildev\booked\tests\Unit\Records;

use anvildev\booked\records\WebhookLogRecord;
use anvildev\booked\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

class WebhookLogRecordTest extends TestCase
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
        $mock = Mockery::mock(WebhookLogRecord::class)->makePartial();
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
        $this->assertEquals('{{%booked_webhook_logs}}', WebhookLogRecord::tableName());
    }

    // =========================================================================
    // getFormattedDuration()
    // =========================================================================

    public function testGetFormattedDurationReturnsDashWhenNull(): void
    {
        $record = $this->makeRecord(['duration' => null]);
        $this->assertEquals('-', $record->getFormattedDuration());
    }

    public function testGetFormattedDurationReturnsMilliseconds(): void
    {
        $record = $this->makeRecord(['duration' => 500]);
        $this->assertEquals('500ms', $record->getFormattedDuration());
    }

    public function testGetFormattedDurationReturnsZeroMilliseconds(): void
    {
        $record = $this->makeRecord(['duration' => 0]);
        $this->assertEquals('0ms', $record->getFormattedDuration());
    }

    public function testGetFormattedDurationReturnsSeconds(): void
    {
        $record = $this->makeRecord(['duration' => 1500]);
        $this->assertEquals('1.5s', $record->getFormattedDuration());
    }

    public function testGetFormattedDurationReturnsWholeSeconds(): void
    {
        $record = $this->makeRecord(['duration' => 2000]);
        $this->assertEquals('2s', $record->getFormattedDuration());
    }

    // =========================================================================
    // getStatusLabel()
    // =========================================================================

    public function testGetStatusLabelReturnsSuccessWhenTrue(): void
    {
        $record = $this->makeRecord(['success' => true, 'responseCode' => null]);
        $this->assertEquals('Success', $record->getStatusLabel());
    }

    public function testGetStatusLabelReturnsFailedWithCodeWhenFalse(): void
    {
        $record = $this->makeRecord(['success' => false, 'responseCode' => 404]);
        $this->assertEquals('Failed (404)', $record->getStatusLabel());
    }

    public function testGetStatusLabelReturnsFailedWithoutCodeWhenNull(): void
    {
        $record = $this->makeRecord(['success' => false, 'responseCode' => null]);
        $this->assertEquals('Failed', $record->getStatusLabel());
    }

}
