<?php

namespace anvildev\booked\tests\Unit\Controllers;

use anvildev\booked\controllers\BookingDataController;
use anvildev\booked\tests\Support\TestCase;
use craft\fields\Assets;
use craft\fields\Checkboxes;
use craft\fields\Dropdown;
use craft\fields\Lightswitch;
use craft\fields\PlainText;
use craft\fields\Email;
use craft\fields\RadioButtons;
use ReflectionMethod;

/**
 * Maps the wizard's known question field types (#146) to the type string
 * the front-end step renderer switches on — one per Craft field class the
 * public booking wizard actually knows how to draw. Behavioural proof of
 * the endpoint itself (a real service, a real field layout, a real HTTP
 * request) is this session's live-verified work; this just locks down the
 * type-mapping table so a future field type doesn't silently fall through.
 */
class BookingDataControllerQuestionsTest extends TestCase
{
    private function wizardFieldType(\craft\base\FieldInterface $field): ?string
    {
        $controller = (new \ReflectionClass(BookingDataController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BookingDataController::class, 'wizardFieldType');
        $method->setAccessible(true);

        return $method->invoke($controller, $field);
    }

    public function testPlainTextMapsToText(): void
    {
        $field = new PlainText();
        $field->multiline = false;
        $this->assertSame('text', $this->wizardFieldType($field));
    }

    public function testMultilinePlainTextMapsToTextarea(): void
    {
        $field = new PlainText();
        $field->multiline = true;
        $this->assertSame('textarea', $this->wizardFieldType($field));
    }

    public function testDropdownMapsToDropdown(): void
    {
        $this->assertSame('dropdown', $this->wizardFieldType(new Dropdown()));
    }

    public function testRadioButtonsMapsToRadio(): void
    {
        $this->assertSame('radio', $this->wizardFieldType(new RadioButtons()));
    }

    public function testCheckboxesMapsToCheckboxes(): void
    {
        $this->assertSame('checkboxes', $this->wizardFieldType(new Checkboxes()));
    }

    public function testLightswitchMapsToLightswitch(): void
    {
        $this->assertSame('lightswitch', $this->wizardFieldType(new Lightswitch()));
    }

    public function testAssetsMapsToAssets(): void
    {
        $this->assertSame('assets', $this->wizardFieldType(new Assets()));
    }

    /**
     * An unsupported field type (anything this wizard step has no renderer
     * for) is skipped, not sent as something the front end can't draw.
     */
    public function testAnUnsupportedFieldTypeMapsToNull(): void
    {
        $this->assertNull($this->wizardFieldType(new Email()));
    }
}
