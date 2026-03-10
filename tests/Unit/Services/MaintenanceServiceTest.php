<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\MaintenanceService;
use anvildev\booked\tests\Support\TestCase;
use craft\base\Component;

/**
 * MaintenanceService Test
 *
 * Tests the maintenance service that handles cleanup tasks for the booking system.
 */
class MaintenanceServiceTest extends TestCase
{
    private MaintenanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MaintenanceService();
    }

    // =========================================================================
    // Class Structure
    // =========================================================================

    public function testExtendsComponent(): void
    {
        $this->assertInstanceOf(Component::class, $this->service);
    }

    public function testHasRunAllMethod(): void
    {
        $this->assertTrue(method_exists($this->service, 'runAll'));
    }

    public function testHasCleanupExpiredSoftLocksMethod(): void
    {
        $this->assertTrue(method_exists($this->service, 'cleanupExpiredSoftLocks'));
    }

    public function testHasCleanupExpiredWaitlistMethod(): void
    {
        $this->assertTrue(method_exists($this->service, 'cleanupExpiredWaitlist'));
    }

    public function testHasCleanupOldWebhookLogsMethod(): void
    {
        $this->assertTrue(method_exists($this->service, 'cleanupOldWebhookLogs'));
    }

    public function testHasGetStatsMethod(): void
    {
        $this->assertTrue(method_exists($this->service, 'getStats'));
    }

    // =========================================================================
    // Method Signatures
    // =========================================================================

    public function testRunAllReturnsArray(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'runAll');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testCleanupMethodsReturnInt(): void
    {
        $methods = [
            'cleanupExpiredSoftLocks',
            'cleanupExpiredWaitlist',
            'cleanupOldWebhookLogs',

        ];

        foreach ($methods as $method) {
            $reflection = new \ReflectionMethod($this->service, $method);
            $returnType = $reflection->getReturnType();

            $this->assertNotNull($returnType, "Method {$method} should have return type");
            $this->assertEquals('int', $returnType->getName(), "Method {$method} should return int");
        }
    }

    public function testGetStatsReturnsArray(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'getStats');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    // =========================================================================
    // Default Parameters
    // =========================================================================

    public function testCleanupExpiredWaitlistHasNoParams(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'cleanupExpiredWaitlist');
        $params = $reflection->getParameters();

        $this->assertCount(0, $params);
    }

    public function testCleanupOldWebhookLogsHasDefaultDays(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'cleanupOldWebhookLogs');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertTrue($params[0]->isDefaultValueAvailable());
        $this->assertEquals(30, $params[0]->getDefaultValue());
    }

    // =========================================================================
    // Parameter Types
    // =========================================================================

    public function testCleanupExpiredWaitlistAcceptsNoArgs(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'cleanupExpiredWaitlist');
        $this->assertCount(0, $reflection->getParameters());
    }

    public function testCleanupOldWebhookLogsAcceptsInt(): void
    {
        $reflection = new \ReflectionMethod($this->service, 'cleanupOldWebhookLogs');
        $params = $reflection->getParameters();

        $type = $params[0]->getType();
        $this->assertEquals('int', $type->getName());
    }
}
