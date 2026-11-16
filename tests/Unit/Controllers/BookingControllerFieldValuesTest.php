<?php

namespace anvildev\booked\tests\Unit\Controllers;

use anvildev\booked\tests\Support\TestCase;

/**
 * Answers to a booked service's custom questions (#146) have to reach
 * BookingForm the same way every other wizard/API field already does —
 * read from the request body, same shape the already-working `extras`
 * param uses right above it.
 */
class BookingControllerFieldValuesTest extends TestCase
{
    public function testActionCreateBookingReadsFieldValuesFromTheRequest(): void
    {
        $source = $this->sourceOfMethod(
            \anvildev\booked\controllers\BookingController::class,
            'actionCreateBooking',
        );

        $this->assertStringContainsString("getBodyParam('fields')", $source);
        $this->assertStringContainsString('$form->fieldValues = ', $source);
    }
}
