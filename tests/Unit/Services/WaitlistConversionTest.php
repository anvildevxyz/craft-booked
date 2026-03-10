<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\records\WaitlistRecord;
use anvildev\booked\services\WaitlistService;
use anvildev\booked\tests\Support\TestCase;

class WaitlistConversionTest extends TestCase
{
    public function testWaitlistRecordHasConversionTokenAttribute(): void
    {
        $this->requiresCraft();

        $record = new WaitlistRecord();
        $this->assertTrue($record->hasProperty('conversionToken'));
    }

    public function testWaitlistRecordHasConversionExpiresAtAttribute(): void
    {
        $this->requiresCraft();

        $record = new WaitlistRecord();
        $this->assertTrue($record->hasProperty('conversionExpiresAt'));
    }

    public function testCreateConversionTokenMethodExists(): void
    {
        $service = new WaitlistService();
        $this->assertTrue(method_exists($service, 'createConversionToken'));
    }

    public function testValidateConversionTokenMethodExists(): void
    {
        $service = new WaitlistService();
        $this->assertTrue(method_exists($service, 'validateConversionToken'));
    }

    public function testCompleteConversionMethodExists(): void
    {
        $service = new WaitlistService();
        $this->assertTrue(method_exists($service, 'completeConversion'));
    }

    public function testCreateConversionTokenRequiresNotifiedStatus(): void
    {
        $this->requiresCraft();

        $service = new WaitlistService();
        // Non-existent entry should return null
        $result = $service->createConversionToken(999999);
        $this->assertNull($result);
    }

    public function testValidateConversionTokenReturnsNullForInvalidToken(): void
    {
        $this->requiresCraft();

        $service = new WaitlistService();
        $result = $service->validateConversionToken('nonexistenttoken');
        $this->assertNull($result);
    }

    public function testConversionTokenPropertyDefaults(): void
    {
        $this->requiresCraft();

        $record = new WaitlistRecord();
        $this->assertNull($record->conversionToken);
        $this->assertNull($record->conversionExpiresAt);
    }

    public function testWaitlistConversionControllerExists(): void
    {
        $this->assertTrue(
            class_exists(\anvildev\booked\controllers\WaitlistConversionController::class)
        );
    }

    public function testWaitlistConversionControllerAllowsAnonymousConvert(): void
    {
        $controller = new \ReflectionClass(\anvildev\booked\controllers\WaitlistConversionController::class);
        $property = $controller->getProperty('allowAnonymous');
        $property->setAccessible(true);

        $instance = $controller->newInstanceWithoutConstructor();
        $value = $property->getValue($instance);

        $this->assertIsArray($value);
        $this->assertContains('convert', $value);
    }

    public function testSettingsHasWaitlistConversionMinutes(): void
    {
        $settings = new \anvildev\booked\models\Settings();
        $this->assertTrue(
            property_exists($settings, 'waitlistConversionMinutes'),
            'Settings must have waitlistConversionMinutes property for waitlist conversion time limit'
        );
        $this->assertSame(30, $settings->waitlistConversionMinutes);
    }

    public function testCreateConversionTokenSignature(): void
    {
        $ref = new \ReflectionMethod(WaitlistService::class, 'createConversionToken');
        $params = $ref->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('waitlistEntryId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
    }

    public function testValidateConversionTokenSignature(): void
    {
        $ref = new \ReflectionMethod(WaitlistService::class, 'validateConversionToken');
        $params = $ref->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('token', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
    }

    public function testCompleteConversionSignature(): void
    {
        $ref = new \ReflectionMethod(WaitlistService::class, 'completeConversion');
        $params = $ref->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('waitlistEntryId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
    }

    public function testCreateConversionTokenReturnType(): void
    {
        $ref = new \ReflectionMethod(WaitlistService::class, 'createConversionToken');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('string', $returnType->getName());
        $this->assertTrue($returnType->allowsNull());
    }

    public function testValidateConversionTokenReturnType(): void
    {
        $ref = new \ReflectionMethod(WaitlistService::class, 'validateConversionToken');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
    }

    public function testCompleteConversionReturnType(): void
    {
        $ref = new \ReflectionMethod(WaitlistService::class, 'completeConversion');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('void', $returnType->getName());
    }
}
