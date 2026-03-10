<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\Service;
use anvildev\booked\tests\Support\TestCase;

class ServiceSoftDeleteTest extends TestCase
{
    private function makeService(array $props = []): Service
    {
        $ref = new \ReflectionClass(Service::class);
        $service = $ref->newInstanceWithoutConstructor();

        $service->duration = $props['duration'] ?? 60;
        $service->price = $props['price'] ?? null;
        $service->description = $props['description'] ?? null;
        $service->deletedAt = $props['deletedAt'] ?? null;

        return $service;
    }

    public function testServiceHasDeletedAtProperty(): void
    {
        $service = $this->makeService();
        $this->assertNull($service->deletedAt);
    }

    public function testSoftDeleteSetsDeletedAt(): void
    {
        $service = $this->makeService();
        $service->softDelete();
        $this->assertNotNull($service->deletedAt);
        $this->assertInstanceOf(\DateTime::class, new \DateTime($service->deletedAt));
    }

    public function testIsSoftDeletedReturnsFalseByDefault(): void
    {
        $service = $this->makeService();
        $this->assertFalse($service->isSoftDeleted());
    }

    public function testIsSoftDeletedReturnsTrueAfterSoftDelete(): void
    {
        $service = $this->makeService();
        $service->softDelete();
        $this->assertTrue($service->isSoftDeleted());
    }

    public function testSoftDeleteSetsValidDateTimeString(): void
    {
        $service = $this->makeService();
        $before = new \DateTime();
        $service->softDelete();
        $after = new \DateTime();

        $deletedAt = new \DateTime($service->deletedAt);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $deletedAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $deletedAt->getTimestamp());
    }

    public function testIsSoftDeletedReturnsTrueWithManualDeletedAt(): void
    {
        $service = $this->makeService(['deletedAt' => '2025-01-01 00:00:00']);
        $this->assertTrue($service->isSoftDeleted());
    }
}
