<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\tests\Support\TestCase;

class PermissionServiceTest extends TestCase
{
    public function testHasClearCacheMethod(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/PermissionService.php'
        );
        $this->assertStringContainsString(
            'function clearCache',
            $source,
            'PermissionService must have a clearCache() method for long-lived processes'
        );
    }

    public function testClearCacheResetsEmployeesCache(): void
    {
        $reflection = new \ReflectionClass(\anvildev\booked\services\PermissionService::class);
        $method = $reflection->getMethod('clearCache');
        $this->assertTrue($method->isPublic(), 'clearCache() must be public');

        $property = $reflection->getProperty('employeesCache');
        $property->setAccessible(true);

        $service = new \anvildev\booked\services\PermissionService();
        // Manually set cache via reflection
        $property->setValue($service, [1 => ['fake']]);
        $this->assertNotEmpty($property->getValue($service));

        $service->clearCache();
        $this->assertEmpty($property->getValue($service));
    }
}
