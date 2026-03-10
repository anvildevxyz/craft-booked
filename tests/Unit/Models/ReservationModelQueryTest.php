<?php

namespace anvildev\booked\tests\Unit\Models;

use anvildev\booked\contracts\ReservationQueryInterface;
use anvildev\booked\models\db\ReservationModelQuery;
use anvildev\booked\tests\Support\TestCase;

/**
 * ReservationModelQuery Test
 *
 * Tests the query builder for ReservationModel that implements ReservationQueryInterface.
 *
 * Note: Most tests require database/Craft CMS initialization and are skipped in unit test mode.
 */
class ReservationModelQueryTest extends TestCase
{
    private function requiresDatabase(): void
    {
        if (!class_exists(\Craft::class, false)) {
            $this->markTestSkipped('Requires full Craft CMS initialization with database');
        }
    }

    // =========================================================================
    // Interface Implementation (Class structure only)
    // =========================================================================

    public function testClassImplementsReservationQueryInterface(): void
    {
        $this->assertTrue(
            is_a(ReservationModelQuery::class, ReservationQueryInterface::class, true),
            'ReservationModelQuery must implement ReservationQueryInterface'
        );
    }

    // =========================================================================
    // Method Existence
    // =========================================================================

    /**
     * @dataProvider filterMethodsProvider
     */
    public function testHasFilterMethod(string $methodName): void
    {
        $this->assertTrue(
            method_exists(ReservationModelQuery::class, $methodName),
            "ReservationModelQuery should have method: {$methodName}"
        );
    }

    public static function filterMethodsProvider(): array
    {
        return [
            ['id'],
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
        ];
    }

    /**
     * @dataProvider eagerLoadingMethodsProvider
     */
    public function testHasEagerLoadingMethod(string $methodName): void
    {
        $this->assertTrue(
            method_exists(ReservationModelQuery::class, $methodName),
            "ReservationModelQuery should have method: {$methodName}"
        );
    }

    public static function eagerLoadingMethodsProvider(): array
    {
        return [
            ['withEmployee'],
            ['withService'],
            ['withLocation'],
            ['withRelations'],
        ];
    }

    /**
     * @dataProvider queryMethodsProvider
     */
    public function testHasQueryMethod(string $methodName): void
    {
        $this->assertTrue(
            method_exists(ReservationModelQuery::class, $methodName),
            "ReservationModelQuery should have method: {$methodName}"
        );
    }

    public static function queryMethodsProvider(): array
    {
        return [
            ['orderBy'],
            ['limit'],
            ['offset'],
            ['where'],
            ['andWhere'],
            ['one'],
            ['all'],
            ['count'],
            ['exists'],
            ['ids'],
            ['getQuery'],
        ];
    }

    // =========================================================================
    // Database-Dependent Tests (Skipped in Unit Mode)
    // =========================================================================

    public function testIdReturnsStatic(): void
    {
        $this->requiresDatabase();
        $query = new ReservationModelQuery();
        $result = $query->id(1);
        $this->assertSame($query, $result);
    }

    public function testUserNameReturnsStatic(): void
    {
        $this->requiresDatabase();
        $query = new ReservationModelQuery();
        $result = $query->userName('John');
        $this->assertSame($query, $result);
    }

    public function testUserEmailReturnsStatic(): void
    {
        $this->requiresDatabase();
        $query = new ReservationModelQuery();
        $result = $query->userEmail('john@example.com');
        $this->assertSame($query, $result);
    }

    public function testStatusReturnsStatic(): void
    {
        $this->requiresDatabase();
        $query = new ReservationModelQuery();
        $result = $query->status('confirmed');
        $this->assertSame($query, $result);
    }

    public function testMethodsCanBeChained(): void
    {
        $this->requiresDatabase();
        $query = new ReservationModelQuery();

        $result = $query
            ->employeeId(10)
            ->serviceId(20)
            ->status('confirmed')
            ->bookingDate('2025-06-15')
            ->orderBy(['startTime' => SORT_ASC])
            ->limit(50)
            ->offset(0);

        $this->assertInstanceOf(ReservationModelQuery::class, $result);
    }

    public function testChainWithEagerLoading(): void
    {
        $this->requiresDatabase();
        $query = new ReservationModelQuery();

        $result = $query
            ->withEmployee()
            ->withService()
            ->withLocation()
            ->status('confirmed')
            ->limit(10);

        $this->assertInstanceOf(ReservationModelQuery::class, $result);
    }

    public function testStatusAcceptsArray(): void
    {
        $this->requiresDatabase();
        $query = new ReservationModelQuery();
        $result = $query->status(['confirmed', 'pending']);
        $this->assertSame($query, $result);
    }

    public function testBookingDateAcceptsArray(): void
    {
        $this->requiresDatabase();
        $query = new ReservationModelQuery();
        $result = $query->bookingDate(['and', '>= 2025-01-01', '< 2025-02-01']);
        $this->assertSame($query, $result);
    }

    public function testNullValuesAreIgnored(): void
    {
        $this->requiresDatabase();
        $query = new ReservationModelQuery();

        // All null values should be ignored and return self
        $this->assertSame($query, $query->id(null));
        $this->assertSame($query, $query->userName(null));
        $this->assertSame($query, $query->userEmail(null));
        $this->assertSame($query, $query->userId(null));
        $this->assertSame($query, $query->bookingDate(null));
        $this->assertSame($query, $query->employeeId(null));
        $this->assertSame($query, $query->status(null));
        $this->assertSame($query, $query->confirmationToken(null));
    }
}
