<?php

namespace anvildev\booked\tests\Unit\Elements\Actions;

use anvildev\booked\elements\actions\MarkAsNoShow;
use anvildev\booked\tests\Support\TestCase;

class MarkAsNoShowTest extends TestCase
{
    public function testDisplayName(): void
    {
        $this->requiresCraft();
        $action = new MarkAsNoShow();
        $this->assertNotEmpty($action::displayName());
    }

    public function testConfirmationMessage(): void
    {
        $this->requiresCraft();
        $action = new MarkAsNoShow();
        $this->assertNotEmpty($action->getConfirmationMessage());
    }
}
