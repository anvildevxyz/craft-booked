<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\BlackoutDate;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\EventDate;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\Service;
use anvildev\booked\elements\ServiceExtra;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies that all elements define searchable attributes for CP search indexing.
 */
class SearchableAttributesTest extends TestCase
{
    /**
     * @dataProvider elementProvider
     */
    public function testDefineSearchableAttributes(string $elementClass, array $expected): void
    {
        $method = new ReflectionMethod($elementClass, 'defineSearchableAttributes');

        $this->assertSame($expected, $method->invoke(null));
    }

    public static function elementProvider(): array
    {
        return [
            'Service' => [Service::class, ['description']],
            'Employee' => [Employee::class, ['email']],
            'Location' => [Location::class, ['addressLine1', 'addressLine2', 'locality', 'administrativeArea', 'postalCode', 'countryCode']],
            'EventDate' => [EventDate::class, ['description']],
            'BlackoutDate' => [BlackoutDate::class, ['startDate', 'endDate']],
            'Reservation' => [Reservation::class, ['userName', 'userEmail', 'userPhone', 'notes']],
            'ServiceExtra' => [ServiceExtra::class, ['title', 'description']],
        ];
    }
}
