<?php

namespace anvildev\booked\tests\Unit\Fields;

use anvildev\booked\elements\Service;
use anvildev\booked\fields\BookedServices;
use anvildev\booked\tests\Support\TestCase;

class BookedServicesTest extends TestCase
{
    private BookedServices $field;

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
        class_exists(BookedServices::class);
        error_reporting($previousLevel);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $ref = new \ReflectionClass(BookedServices::class);
        $this->field = $ref->newInstanceWithoutConstructor();
    }

    public function testDisplayName(): void
    {
        $this->assertIsString(BookedServices::displayName());
        $this->assertNotEmpty(BookedServices::displayName());
    }

    public function testElementType(): void
    {
        $this->assertSame(Service::class, BookedServices::elementType());
    }

    public function testDefaultSelectionLabel(): void
    {
        $this->assertIsString(BookedServices::defaultSelectionLabel());
        $this->assertNotEmpty(BookedServices::defaultSelectionLabel());
    }
}
