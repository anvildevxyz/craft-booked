<?php

namespace anvildev\booked\tests\Unit\Fields;

use anvildev\booked\elements\EventDate;
use anvildev\booked\fields\BookedEventDates;
use anvildev\booked\tests\Support\TestCase;

class BookedEventDatesTest extends TestCase
{
    private BookedEventDates $field;

    /**
     * @beforeClass
     */
    public static function loadFieldClass(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }

        // Suppress PHP 8.4 deprecation from Craft's BaseRelationField during class loading
        $previousLevel = error_reporting(E_ALL & ~E_DEPRECATED);
        class_exists(BookedEventDates::class);
        error_reporting($previousLevel);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $ref = new \ReflectionClass(BookedEventDates::class);
        $this->field = $ref->newInstanceWithoutConstructor();
    }

    public function testDisplayName(): void
    {
        $this->assertIsString(BookedEventDates::displayName());
        $this->assertNotEmpty(BookedEventDates::displayName());
    }

    public function testElementType(): void
    {
        $this->assertSame(EventDate::class, BookedEventDates::elementType());
    }

    public function testDefaultSelectionLabel(): void
    {
        $this->assertIsString(BookedEventDates::defaultSelectionLabel());
        $this->assertNotEmpty(BookedEventDates::defaultSelectionLabel());
    }
}
