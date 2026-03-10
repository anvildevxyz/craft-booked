<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\EventDate;
use anvildev\booked\tests\Support\TestCase;

class EventDateSoftDeleteTest extends TestCase
{
    private function makeEventDate(array $props = []): EventDate
    {
        $ref = new \ReflectionClass(EventDate::class);
        $event = $ref->newInstanceWithoutConstructor();

        $event->eventDate = $props['eventDate'] ?? '2025-06-15';
        $event->endDate = $props['endDate'] ?? null;
        $event->startTime = $props['startTime'] ?? '09:00';
        $event->endTime = $props['endTime'] ?? '17:00';
        $event->enabled = $props['enabled'] ?? true;
        $event->capacity = $props['capacity'] ?? null;
        $event->locationId = $props['locationId'] ?? null;
        $event->title = $props['title'] ?? null;
        $event->description = $props['description'] ?? null;
        $event->deletedAt = $props['deletedAt'] ?? null;

        return $event;
    }

    public function testEventDateHasDeletedAtProperty(): void
    {
        $eventDate = $this->makeEventDate();
        $this->assertNull($eventDate->deletedAt);
    }

    public function testSoftDeleteSetsDeletedAt(): void
    {
        $eventDate = $this->makeEventDate();
        $eventDate->softDelete();
        $this->assertNotNull($eventDate->deletedAt);
        $this->assertInstanceOf(\DateTime::class, new \DateTime($eventDate->deletedAt));
    }

    public function testIsSoftDeletedReturnsFalseByDefault(): void
    {
        $eventDate = $this->makeEventDate();
        $this->assertFalse($eventDate->isSoftDeleted());
    }

    public function testIsSoftDeletedReturnsTrueAfterSoftDelete(): void
    {
        $eventDate = $this->makeEventDate();
        $eventDate->softDelete();
        $this->assertTrue($eventDate->isSoftDeleted());
    }

    public function testSoftDeleteSetsValidDateTimeString(): void
    {
        $eventDate = $this->makeEventDate();
        $before = new \DateTime();
        $eventDate->softDelete();
        $after = new \DateTime();

        $deletedAt = new \DateTime($eventDate->deletedAt);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $deletedAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $deletedAt->getTimestamp());
    }

    public function testIsSoftDeletedReturnsTrueWithManualDeletedAt(): void
    {
        $eventDate = $this->makeEventDate(['deletedAt' => '2025-01-01 00:00:00']);
        $this->assertTrue($eventDate->isSoftDeleted());
    }
}
