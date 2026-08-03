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
        // softDelete() stores UTC without a zone marker, per Craft's DB
        // convention. Parsing that naive string with new DateTime() would read it
        // in the server's local zone, putting it hours behind $before — which is
        // why this failed by exactly Zurich's offset and would have passed only
        // on a UTC machine.
        $utc = new \DateTimeZone('UTC');
        $before = new \DateTime('now', $utc);
        $service->softDelete();
        $after = new \DateTime('now', $utc);

        $deletedAt = new \DateTime($service->deletedAt, $utc);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $deletedAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $deletedAt->getTimestamp());
    }

    public function testIsSoftDeletedReturnsTrueWithManualDeletedAt(): void
    {
        $service = $this->makeService(['deletedAt' => '2025-01-01 00:00:00']);
        $this->assertTrue($service->isSoftDeleted());
    }
}
