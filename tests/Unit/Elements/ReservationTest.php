<?php

namespace anvildev\booked\tests\Unit\Elements;

use anvildev\booked\elements\Reservation;
use anvildev\booked\tests\Support\TestCase;

class ReservationTest extends TestCase
{
    public function testStatusesIncludesNoShow(): void
    {
        $this->requiresCraft();
        $statuses = Reservation::statuses();
        $this->assertArrayHasKey('no_show', $statuses);
        $this->assertCount(4, $statuses);
    }

    public function testStatusesHasCorrectColors(): void
    {
        $this->requiresCraft();
        $statuses = Reservation::statuses();
        $this->assertIsArray($statuses['confirmed']);
        $this->assertEquals(\craft\enums\Color::Green, $statuses['confirmed']['color']);
        $this->assertIsArray($statuses['pending']);
        $this->assertEquals(\craft\enums\Color::Orange, $statuses['pending']['color']);
        $this->assertIsArray($statuses['cancelled']);
        $this->assertArrayNotHasKey('color', $statuses['cancelled']);
        $this->assertIsArray($statuses['no_show']);
        $this->assertEquals(\craft\enums\Color::Red, $statuses['no_show']['color']);
    }

    public function testMarkAsNoShowMethodExists(): void
    {
        $this->assertTrue(method_exists(Reservation::class, 'markAsNoShow'));
    }

    public function testDefineSourcesIncludesNoShow(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/elements/Reservation.php');
        $this->assertStringContainsString("'key' => 'no_show'", $source);
        $this->assertStringContainsString('STATUS_NO_SHOW', $source);
    }

    // =========================================================================
    // Native element index (#89) — sources, sort options and search replace
    // what the hand-rolled bookings table used to do itself.
    // =========================================================================

    /**
     * @dataProvider sortOptionAttributeProvider
     */
    public function testDefineSortOptionsIncludesAttribute(string $attribute): void
    {
        $source = $this->sourceOfMethod(Reservation::class, 'defineSortOptions');
        $this->assertStringContainsString(
            "'attribute' => '{$attribute}'",
            $source,
            "The bookings index sort menu should offer '{$attribute}'",
        );
    }

    public static function sortOptionAttributeProvider(): array
    {
        return [
            'userName' => ['userName'],
            'userEmail' => ['userEmail'],
            'bookingDate' => ['bookingDate'],
            'dateCreated' => ['dateCreated'],
        ];
    }

    public function testBookingDateIsSearchable(): void
    {
        $source = $this->sourceOfMethod(Reservation::class, 'defineSearchableAttributes');
        $this->assertStringContainsString("'bookingDate'", $source);
    }

    public function testSearchKeywordsForBookingDateCoverBothDateFormats(): void
    {
        $source = $this->sourceOfMethod(Reservation::class, 'getSearchKeywords');
        $this->assertStringContainsString(
            "'bookingDate'",
            $source,
            'getSearchKeywords() should special-case bookingDate',
        );
        $this->assertStringContainsString(
            "'d.m.Y'",
            $source,
            'getSearchKeywords() should index the European date format alongside the stored ISO one',
        );
    }

    public function testIdIsSearchable(): void
    {
        $source = $this->sourceOfMethod(Reservation::class, 'defineSearchableAttributes');
        $this->assertStringContainsString("'id'", $source);
    }

    /**
     * The hand-rolled table this replaced (#89) matched a bare numeric
     * search against the reservation's own id (e.g. one quoted in a support
     * email); the native index's keyword search needs id indexed to do the
     * same.
     */
    public function testSearchKeywordsIncludeTheRawId(): void
    {
        $source = $this->sourceOfMethod(Reservation::class, 'getSearchKeywords');
        $this->assertStringContainsString(
            "\$attribute === 'id'",
            $source,
            'getSearchKeywords() should special-case id',
        );
    }

    // =========================================================================
    // getFieldLayout() (#146) — a reservation's questions are whichever ones
    // the booked service defines; it has no layout of its own.
    // =========================================================================

    /**
     * No serviceId means getService() returns null before ever reaching
     * Craft::$app, so this is safe to exercise without full Craft init.
     */
    public function testGetFieldLayoutIsNullWithNoService(): void
    {
        $reservation = (new \ReflectionClass(Reservation::class))->newInstanceWithoutConstructor();
        $reservation->serviceId = null;

        $this->assertNull($reservation->getFieldLayout());
    }

    public function testGetFieldLayoutDelegatesToTheService(): void
    {
        $source = $this->sourceOfMethod(Reservation::class, 'getFieldLayout');
        $this->assertStringContainsString('getService()', $source);
        $this->assertStringContainsString('getReservationFieldLayout()', $source);
    }
}
