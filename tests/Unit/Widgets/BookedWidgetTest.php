<?php

namespace anvildev\booked\tests\Unit\Widgets;

use anvildev\booked\tests\Support\TestCase;
use anvildev\booked\widgets\BookedWidget;

class BookedWidgetTest extends TestCase
{
    public function testDisplayName(): void
    {
        $this->requiresCraft();
        $name = BookedWidget::displayName();
        $this->assertNotEmpty($name);
    }

    public function testIconPath(): void
    {
        $this->requiresCraft();
        $icon = BookedWidget::icon();
        // icon() should return a path or null
        $this->assertTrue($icon === null || is_string($icon));
    }

    public function testDefaultLookaheadDays(): void
    {
        $widget = new BookedWidget();
        $this->assertEquals(1, $widget->lookaheadDays);
    }

    public function testSettingsValidation(): void
    {
        $widget = new BookedWidget();
        $widget->lookaheadDays = 3;
        $rules = $widget->rules();
        $this->assertNotEmpty($rules);
    }

    public function testValidLookaheadValues(): void
    {
        $this->requiresCraft();
        $widget = new BookedWidget();

        $widget->lookaheadDays = 1;
        $this->assertTrue($widget->validate());

        $widget->lookaheadDays = 3;
        $this->assertTrue($widget->validate());

        $widget->lookaheadDays = 7;
        $this->assertTrue($widget->validate());
    }

    public function testInvalidLookaheadValue(): void
    {
        $this->requiresCraft();
        $widget = new BookedWidget();
        $widget->lookaheadDays = 5;
        $this->assertFalse($widget->validate());
    }
}
