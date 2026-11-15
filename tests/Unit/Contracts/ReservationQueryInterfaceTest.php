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

    /**
     * The condition-rule classes (ReservationEmployeeConditionRule and
     * friends) pass getElementIds() — an array — straight into these.
     * A ?int-only signature type-errors the moment a native-index filter is
     * actually applied; this only surfaced by exercising the filter live,
     * not by reading the condition rule's source.
     *
     * @dataProvider arrayAcceptingMethodsProvider
     */
    public function testMethodAcceptsAnArrayOfIds(string $method): void
    {
        $ref = new \ReflectionMethod(ReservationQueryInterface::class, $method);
        $type = $ref->getParameters()[0]->getType();

        $this->assertNotNull($type, "{$method}() has no declared parameter type");
        $typeNames = $type instanceof \ReflectionUnionType
            ? array_map(fn(\ReflectionNamedType $t) => $t->getName(), $type->getTypes())
            : [$type->getName()];

        $this->assertContains('array', $typeNames, "{$method}() must accept an array of ids, not just a single int");
    }

    public static function arrayAcceptingMethodsProvider(): array
    {
        return [
            ['employeeId'],
            ['locationId'],
            ['serviceId'],
            ['eventDateId'],
        ];
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
