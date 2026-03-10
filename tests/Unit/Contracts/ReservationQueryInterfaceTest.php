<?php

namespace anvildev\booked\tests\Unit\Contracts;

use anvildev\booked\contracts\ReservationQueryInterface;
use anvildev\booked\tests\Support\TestCase;

/**
 * ReservationQueryInterface Test
 *
 * Validates the interface defines all required methods.
 */
class ReservationQueryInterfaceTest extends TestCase
{
    public function testInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(ReservationQueryInterface::class));
    }

    /**
     * @dataProvider requiredMethodsProvider
     */
    public function testInterfaceDefinesMethod(string $method): void
    {
        $ref = new \ReflectionClass(ReservationQueryInterface::class);
        $this->assertTrue($ref->hasMethod($method), "Missing method: {$method}");
    }

    public static function requiredMethodsProvider(): array
    {
        return [
            ['id'],
            ['siteId'],
            ['userName'],
            ['userEmail'],
            ['userId'],
            ['bookingDate'],
            ['startTime'],
            ['endTime'],
            ['employeeId'],
            ['locationId'],
            ['serviceId'],
            ['eventDateId'],
            ['status'],
            ['reservationStatus'],
            ['confirmationToken'],
            ['forCurrentUser'],
            ['withEmployee'],
            ['withService'],
            ['withLocation'],
            ['withRelations'],
            ['orderBy'],
            ['limit'],
            ['offset'],
            ['one'],
            ['all'],
            ['count'],
            ['exists'],
            ['ids'],
            ['where'],
            ['andWhere'],
        ];
    }
}
