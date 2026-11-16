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

    /**
     * Unlike core actions such as Delete, Craft gives a custom ElementAction
     * no canSave()/canDelete() check of its own — reachable via the native
     * bookings index's bulk actions, where a view-only staff member (or,
     * before a separate fix, any CP user at all) could otherwise mark
     * another employee's booking as no-show with nothing to stop them.
     */
    public function testPerformActionChecksCanSave(): void
    {
        $source = $this->sourceOfMethod(MarkAsNoShow::class, 'performAction');
        $this->assertStringContainsString(
            'canSave(',
            $source,
            'performAction() must check canSave() before mutating a reservation',
        );
    }
}
