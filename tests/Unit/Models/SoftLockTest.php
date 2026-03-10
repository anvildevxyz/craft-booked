<?php

namespace anvildev\booked\tests\Unit\Models;

use anvildev\booked\models\SoftLock;
use anvildev\booked\tests\Support\TestCase;

/**
 * SoftLock Model Test
 *
 * Tests the SoftLock model functionality
 */
class SoftLockTest extends TestCase
{
    public function testValidation(): void
    {
        $model = new SoftLock();

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('token', $model->getErrors());
        $this->assertArrayHasKey('serviceId', $model->getErrors());
        $this->assertArrayHasKey('date', $model->getErrors());
        $this->assertArrayHasKey('startTime', $model->getErrors());
        $this->assertArrayHasKey('endTime', $model->getErrors());
        $this->assertArrayHasKey('expiresAt', $model->getErrors());
    }

    public function testValidDataPassesValidation(): void
    {
        $model = new SoftLock([
            'token' => 'test-token-12345',
            'serviceId' => 1,
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => '2025-06-15 14:05:00',
        ]);

        $this->assertTrue($model->validate());
        $this->assertEmpty($model->getErrors());
    }

    public function testTokenMustBeString(): void
    {
        $model = new SoftLock([
            'token' => 12345,
            'serviceId' => 1,
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => '2025-06-15 14:05:00',
        ]);

        $this->assertTrue($model->validate());
        $this->assertIsString($model->token);
    }

    public function testServiceIdAcceptsIntegerStrings(): void
    {
        // PHP's typed properties convert string numbers to integers
        $model = new SoftLock([
            'token' => 'test-token',
            'serviceId' => '123', // String representation should work
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => '2025-06-15 14:05:00',
        ]);

        $this->assertTrue($model->validate());
        $this->assertSame(123, $model->serviceId); // Converted to int
    }

    public function testEmployeeIdIsOptional(): void
    {
        $model = new SoftLock([
            'token' => 'test-token',
            'serviceId' => 1,
            'employeeId' => null,
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => '2025-06-15 14:05:00',
        ]);

        $this->assertTrue($model->validate());
    }

    public function testEmployeeIdAcceptsIntegerStrings(): void
    {
        // PHP's typed properties convert string numbers to integers
        $model = new SoftLock([
            'token' => 'test-token',
            'serviceId' => 1,
            'employeeId' => '456', // String representation should work
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => '2025-06-15 14:05:00',
        ]);

        $this->assertTrue($model->validate());
        $this->assertSame(456, $model->employeeId); // Converted to int
    }

    public function testLocationIdIsOptional(): void
    {
        $model = new SoftLock([
            'token' => 'test-token',
            'serviceId' => 1,
            'locationId' => null,
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => '2025-06-15 14:05:00',
        ]);

        $this->assertTrue($model->validate());
    }

    public function testLocationIdAcceptsIntegerStrings(): void
    {
        // PHP's typed properties convert string numbers to integers
        $model = new SoftLock([
            'token' => 'test-token',
            'serviceId' => 1,
            'locationId' => '789', // String representation should work
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => '2025-06-15 14:05:00',
        ]);

        $this->assertTrue($model->validate());
        $this->assertSame(789, $model->locationId); // Converted to int
    }

    public function testDateMustBeString(): void
    {
        $model = new SoftLock([
            'token' => 'test-token',
            'serviceId' => 1,
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => '2025-06-15 14:05:00',
        ]);

        $this->assertTrue($model->validate());
        $this->assertIsString($model->date);
    }

    public function testExpiresAtMustBeValidDateTime(): void
    {
        $model = new SoftLock([
            'token' => 'test-token',
            'serviceId' => 1,
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => 'invalid-datetime',
        ]);

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('expiresAt', $model->getErrors());
    }

    public function testExpiresAtMustBeInCorrectFormat(): void
    {
        $model = new SoftLock([
            'token' => 'test-token',
            'serviceId' => 1,
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => '2025-06-15', // Missing time
        ]);

        $this->assertFalse($model->validate());
        $this->assertArrayHasKey('expiresAt', $model->getErrors());
    }

    public function testAllFieldsCanBeSet(): void
    {
        $model = new SoftLock([
            'id' => 123,
            'token' => 'test-token-abc123',
            'serviceId' => 5,
            'employeeId' => 10,
            'locationId' => 2,
            'date' => '2025-12-25',
            'startTime' => '09:00',
            'endTime' => '10:00',
            'quantity' => 3,
            'expiresAt' => '2025-12-25 09:05:00',
        ]);

        $this->assertEquals(123, $model->id);
        $this->assertEquals('test-token-abc123', $model->token);
        $this->assertEquals(5, $model->serviceId);
        $this->assertEquals(10, $model->employeeId);
        $this->assertEquals(2, $model->locationId);
        $this->assertEquals('2025-12-25', $model->date);
        $this->assertEquals('09:00', $model->startTime);
        $this->assertEquals('10:00', $model->endTime);
        $this->assertEquals(3, $model->quantity);
        $this->assertEquals('2025-12-25 09:05:00', $model->expiresAt);
    }

    public function testDefaultValuesAreNull(): void
    {
        $model = new SoftLock();

        $this->assertNull($model->id);
        $this->assertNull($model->token);
        $this->assertNull($model->serviceId);
        $this->assertNull($model->employeeId);
        $this->assertNull($model->locationId);
        $this->assertNull($model->date);
        $this->assertNull($model->startTime);
        $this->assertNull($model->endTime);
        $this->assertEquals(1, $model->quantity);
        $this->assertNull($model->expiresAt);
    }

    public function testLongTokenIsAccepted(): void
    {
        $longToken = str_repeat('a', 100);

        $model = new SoftLock([
            'token' => $longToken,
            'serviceId' => 1,
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => '2025-06-15 14:05:00',
        ]);

        $this->assertTrue($model->validate());
        $this->assertEquals($longToken, $model->token);
    }

    public function testExpiresAtWithMicroseconds(): void
    {
        $model = new SoftLock([
            'token' => 'test-token',
            'serviceId' => 1,
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
            'expiresAt' => '2025-06-15 14:05:00',
        ]);

        $this->assertTrue($model->validate());
    }

    public function testMidnightExpiration(): void
    {
        $model = new SoftLock([
            'token' => 'test-token',
            'serviceId' => 1,
            'date' => '2025-06-15',
            'startTime' => '23:55',
            'endTime' => '23:59',
            'expiresAt' => '2025-06-16 00:00:00',
        ]);

        $this->assertTrue($model->validate());
    }

    public function testRulesArray(): void
    {
        $model = new SoftLock();
        $rules = $model->rules();

        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules);
    }
}
