<?php

namespace anvildev\booked\tests\Unit\Records;

use anvildev\booked\records\WebhookRecord;
use anvildev\booked\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

class WebhookRecordTest extends TestCase
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
     * Create a record mock with attributes set via reflection
     * to bypass ActiveRecord's __set which requires DB schema.
     */
    private function makeRecord(array $props = []): MockInterface
    {
        $mock = Mockery::mock(WebhookRecord::class)->makePartial();
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
        $this->assertEquals('{{%booked_webhooks}}', WebhookRecord::tableName());
    }

    public function testGenerateSecretReturns64CharHex(): void
    {
        $secret = WebhookRecord::generateSecret();
        $this->assertEquals(64, strlen($secret));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function testGenerateSecretIsUnique(): void
    {
        $a = WebhookRecord::generateSecret();
        $b = WebhookRecord::generateSecret();
        $this->assertNotEquals($a, $b);
    }

    // =========================================================================
    // getEventsArray()
    // =========================================================================

    public function testGetEventsArrayReturnsArrayWhenAlreadyArray(): void
    {
        $events = ['booking.created', 'booking.cancelled'];
        $record = $this->makeRecord(['events' => $events]);
        $this->assertEquals($events, $record->getEventsArray());
    }

    public function testGetEventsArrayDecodesJsonString(): void
    {
        $record = $this->makeRecord(['events' => '["booking.created","booking.cancelled"]']);
        $this->assertEquals(['booking.created', 'booking.cancelled'], $record->getEventsArray());
    }

    public function testGetEventsArrayReturnsEmptyForNull(): void
    {
        $record = $this->makeRecord(['events' => null]);
        $this->assertEquals([], $record->getEventsArray());
    }

    public function testGetEventsArrayReturnsEmptyForInvalidJson(): void
    {
        $record = $this->makeRecord(['events' => 'not-json']);
        $this->assertEquals([], $record->getEventsArray());
    }

    // =========================================================================
    // getHeadersArray()
    // =========================================================================

    public function testGetHeadersArrayReturnsArrayWhenAlreadyArray(): void
    {
        $headers = ['X-Custom' => 'value'];
        $record = $this->makeRecord(['headers' => $headers]);
        $this->assertEquals($headers, $record->getHeadersArray());
    }

    public function testGetHeadersArrayDecodesJsonString(): void
    {
        $record = $this->makeRecord(['headers' => '{"X-Custom":"value"}']);
        $this->assertEquals(['X-Custom' => 'value'], $record->getHeadersArray());
    }

    public function testGetHeadersArrayReturnsEmptyForNull(): void
    {
        $record = $this->makeRecord(['headers' => null]);
        $this->assertEquals([], $record->getHeadersArray());
    }

    // =========================================================================
    // handlesEvent()
    // =========================================================================

    public function testHandlesEventReturnsTrueWhenEventPresent(): void
    {
        $record = $this->makeRecord(['events' => ['booking.created', 'booking.cancelled']]);
        $this->assertTrue($record->handlesEvent('booking.created'));
    }

    public function testHandlesEventReturnsFalseWhenEventAbsent(): void
    {
        $record = $this->makeRecord(['events' => ['booking.created']]);
        $this->assertFalse($record->handlesEvent('booking.cancelled'));
    }

    public function testHandlesEventReturnsFalseWhenEventsEmpty(): void
    {
        $record = $this->makeRecord(['events' => []]);
        $this->assertFalse($record->handlesEvent('booking.created'));
    }
}
