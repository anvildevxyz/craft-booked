<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\records\WaitlistRecord;
use anvildev\booked\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

class WaitlistServiceEventTest extends TestCase
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

    public function testWaitlistRecordAcceptsEventDateId(): void
    {
        $record = $this->makeRecord(['eventDateId' => 42]);
        $this->assertEquals(42, $record->eventDateId);
    }

    public function testWaitlistRecordRulesHaveConditionalRequirements(): void
    {
        $record = $this->makeRecord([]);
        $rules = $record->rules();

        // Verify conditional required rules exist for serviceId and eventDateId
        $conditionalServiceRule = null;
        $conditionalEventRule = null;
        foreach ($rules as $rule) {
            if (($rule[0] ?? '') === 'serviceId' && ($rule[1] ?? '') === 'required') {
                $conditionalServiceRule = $rule;
            }
            if (($rule[0] ?? '') === 'eventDateId' && ($rule[1] ?? '') === 'required') {
                $conditionalEventRule = $rule;
            }
        }
        $this->assertNotNull($conditionalServiceRule, 'serviceId should have a conditional required rule');
        $this->assertNotNull($conditionalEventRule, 'eventDateId should have a conditional required rule');
        $this->assertArrayHasKey('when', $conditionalServiceRule, 'serviceId required rule should have a when callback');
        $this->assertArrayHasKey('when', $conditionalEventRule, 'eventDateId required rule should have a when callback');
    }

    public function testWaitlistRecordRequiresServiceOrEventValidation(): void
    {
        $this->requiresCraft();

        $record = $this->makeRecord([
            'userName' => 'Test',
            'userEmail' => 'test@example.com',
            'status' => WaitlistRecord::STATUS_ACTIVE,
            'priority' => 0,
        ]);
        // Neither serviceId nor eventDateId set — should fail validation
        $this->assertFalse($record->validate());
    }

    public function testWaitlistRecordValidWithEventDateId(): void
    {
        $record = $this->makeRecord([
            'eventDateId' => 42,
            'userName' => 'Test',
            'userEmail' => 'test@example.com',
            'status' => WaitlistRecord::STATUS_ACTIVE,
            'priority' => 0,
        ]);
        // eventDateId set without serviceId — should pass relevant field validation
        $valid = $record->validate(['userName', 'userEmail', 'status', 'priority']);
        $this->assertTrue($valid);
    }

    public function testWaitlistRecordValidWithServiceId(): void
    {
        $record = $this->makeRecord([
            'serviceId' => 10,
            'userName' => 'Test',
            'userEmail' => 'test@example.com',
            'status' => WaitlistRecord::STATUS_ACTIVE,
            'priority' => 0,
        ]);
        $valid = $record->validate(['userName', 'userEmail', 'status', 'priority']);
        $this->assertTrue($valid);
    }
}
