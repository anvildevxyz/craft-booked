<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\models\forms\BookingForm;
use anvildev\booked\tests\Support\TestCase;

class MultiDayBookingFlowTest extends TestCase
{
    public function testBookingFormAcceptsEndDate(): void
    {
        $form = new BookingForm();
        $form->endDate = '2026-06-12';
        $this->assertEquals('2026-06-12', $form->endDate);
    }

    public function testBookingFormValidatesWithoutTimeForMultiDay(): void
    {
        $form = new BookingForm();
        $form->userName = 'Glen Test';
        $form->userEmail = 'glen@test.com';
        $form->bookingDate = '2026-06-10';
        $form->endDate = '2026-06-12';
        $form->serviceId = 1;
        $form->quantity = 2;
        $form->startTime = null;
        $form->endTime = null;

        $form->validate();
        $this->assertFalse($form->hasErrors('startTime'));
        $this->assertFalse($form->hasErrors('endTime'));
    }

    public function testBookingFormStillRequiresTimeWithoutEndDate(): void
    {
        $form = new BookingForm();
        $form->userName = 'Glen Test';
        $form->userEmail = 'glen@test.com';
        $form->bookingDate = '2026-06-10';
        $form->endDate = null;
        $form->serviceId = 1;
        $form->quantity = 1;
        $form->startTime = null;
        $form->endTime = null;

        $form->validate();
        $this->assertTrue($form->hasErrors('startTime'));
        $this->assertTrue($form->hasErrors('endTime'));
    }

    public function testBookingFormGetReservationDataIncludesEndDate(): void
    {
        $form = new BookingForm();
        $form->userName = 'Glen';
        $form->userEmail = 'glen@test.com';
        $form->bookingDate = '2026-06-10';
        $form->endDate = '2026-06-12';
        $form->serviceId = 1;
        $form->quantity = 2;

        $data = $form->getReservationData();
        $this->assertArrayHasKey('endDate', $data);
        $this->assertEquals('2026-06-12', $data['endDate']);
    }
}
