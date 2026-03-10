<?php

namespace anvildev\booked\tests\Unit\Controllers;

use anvildev\booked\models\forms\BookingForm;
use anvildev\booked\tests\Support\TestCase;

class BookingFormValidationTest extends TestCase
{
    private function validServiceForm(array $overrides = []): BookingForm
    {
        $form = new BookingForm();
        $form->setAttributes(array_merge([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '10:00',
            'endTime' => '11:00',
            'serviceId' => 1,
            'userTimezone' => 'Europe/Zurich',
        ], $overrides), false);
        return $form;
    }

    private function validEventForm(array $overrides = []): BookingForm
    {
        $form = new BookingForm();
        $form->setAttributes(array_merge([
            'userName' => 'Jane Doe',
            'userEmail' => 'jane@example.com',
            'eventDateId' => 5,
            'userTimezone' => 'Europe/Zurich',
        ], $overrides), false);
        return $form;
    }

    public function testValidServiceBookingPasses(): void
    {
        $form = $this->validServiceForm();
        $this->assertTrue($form->validate(), implode('; ', $form->getErrorSummary(true)));
    }

    public function testValidEventBookingPasses(): void
    {
        $form = $this->validEventForm();
        $this->assertTrue($form->validate(), implode('; ', $form->getErrorSummary(true)));
    }

    public function testRequiredFieldsForServiceBooking(): void
    {
        $form = new BookingForm();
        $form->validate();
        $errors = $form->getErrors();

        $this->assertArrayHasKey('userName', $errors);
        $this->assertArrayHasKey('userEmail', $errors);
        $this->assertArrayHasKey('bookingDate', $errors);
        $this->assertArrayHasKey('startTime', $errors);
        $this->assertArrayHasKey('endTime', $errors);
        $this->assertArrayHasKey('serviceId', $errors);
    }

    public function testEventBookingDoesNotRequireServiceFields(): void
    {
        $form = $this->validEventForm();
        $form->validate();
        $errors = $form->getErrors();

        $this->assertArrayNotHasKey('bookingDate', $errors);
        $this->assertArrayNotHasKey('startTime', $errors);
        $this->assertArrayNotHasKey('endTime', $errors);
        $this->assertArrayNotHasKey('serviceId', $errors);
    }

    /**
     * @dataProvider invalidEmailProvider
     */
    public function testInvalidEmailFormat(string $email): void
    {
        $form = $this->validServiceForm(['userEmail' => $email]);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('userEmail'));
    }

    public static function invalidEmailProvider(): array
    {
        return [
            'no at sign' => ['notanemail'],
            'no domain' => ['user@'],
            'spaces' => ['user @example.com'],
        ];
    }

    public function testInvalidDateFormat(): void
    {
        $form = $this->validServiceForm(['bookingDate' => '15-06-2025']);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('bookingDate'));
    }

    /**
     * @dataProvider invalidTimeProvider
     */
    public function testInvalidTimeFormat(string $time): void
    {
        $form = $this->validServiceForm(['startTime' => $time]);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('startTime'));
    }

    public static function invalidTimeProvider(): array
    {
        return [
            'am/pm format' => ['10:00 AM'],
            'invalid hour' => ['25:00'],
            'no colon' => ['1000'],
        ];
    }

    public function testInvalidTimezone(): void
    {
        $form = $this->validServiceForm(['userTimezone' => 'Not/A/Timezone']);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('userTimezone'));
    }

    public function testValidTimezone(): void
    {
        $form = $this->validServiceForm(['userTimezone' => 'America/New_York']);
        $this->assertTrue($form->validate(), implode('; ', $form->getErrorSummary(true)));
    }

    public function testPhoneWithLettersFailsWhenSmsEnabled(): void
    {
        $form = $this->validServiceForm([
            'userPhone' => 'not-a-phone-abc',
            'smsEnabled' => true,
        ]);
        $form->smsEnabled = true;
        $form->validate();
        $this->assertNotEmpty($form->getErrors('userPhone'));
    }

    public function testPhoneWithLettersPassesWhenSmsDisabled(): void
    {
        $form = $this->validServiceForm(['userPhone' => 'not-a-phone-abc']);
        $form->smsEnabled = false;
        $this->assertTrue($form->validate(), implode('; ', $form->getErrorSummary(true)));
    }

    public function testValidExtras(): void
    {
        $form = new class() extends BookingForm {
            protected function getValidExtraIdsForService(int $serviceId): array
            {
                return [1, 3];
            }
        };
        $form->setAttributes([
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
            'bookingDate' => '2025-06-15',
            'startTime' => '10:00',
            'endTime' => '11:00',
            'serviceId' => 1,
            'userTimezone' => 'Europe/Zurich',
            'extras' => [1 => 2, 3 => 1],
        ], false);
        $this->assertTrue($form->validate(), implode('; ', $form->getErrorSummary(true)));
    }

    public function testInvalidExtraIdNonNumeric(): void
    {
        $form = $this->validServiceForm(['extras' => ['abc' => 1]]);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('extras'));
    }

    public function testInvalidExtraNegativeQuantity(): void
    {
        $form = $this->validServiceForm(['extras' => [1 => -5]]);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('extras'));
    }

    public function testQuantityMinimumIsOne(): void
    {
        $form = $this->validServiceForm(['quantity' => 0]);
        $form->validate();
        $this->assertNotEmpty($form->getErrors('quantity'));
    }

    public function testHoneypotSpamDetection(): void
    {
        $form = $this->validServiceForm(['honeypot' => 'spam content']);
        $this->assertTrue($form->isSpam());

        $cleanForm = $this->validServiceForm(['honeypot' => null]);
        $this->assertFalse($cleanForm->isSpam());

        $emptyForm = $this->validServiceForm(['honeypot' => '']);
        $this->assertFalse($emptyForm->isSpam());
    }

    public function testGetReservationDataStructure(): void
    {
        $form = $this->validServiceForm(['extras' => [1 => 2, '3' => '1']]);
        $data = $form->getReservationData();

        $expectedKeys = [
            'userName', 'userEmail', 'userPhone', 'userTimezone',
            'bookingDate', 'startTime', 'endTime', 'serviceId',
            'employeeId', 'locationId', 'eventDateId', 'notes',
            'quantity', 'extras',
        ];
        $this->assertArrayHasKeys($expectedKeys, $data);
        $this->assertSame('john@example.com', $data['userEmail']);
        $this->assertSame(1, $data['serviceId']);
    }

    public function testGetReservationDataCastsExtrasToInt(): void
    {
        $form = $this->validServiceForm(['extras' => ['5' => '3', '10' => '1']]);
        $data = $form->getReservationData();

        foreach ($data['extras'] as $id => $qty) {
            $this->assertIsInt($id, 'Extra ID should be cast to int');
            $this->assertIsInt($qty, 'Extra quantity should be cast to int');
        }
        $this->assertSame([5 => 3, 10 => 1], $data['extras']);
    }

    public function testInputSanitizationStripsHtml(): void
    {
        $form = $this->validServiceForm([
            'userName' => '<script>alert("xss")</script>John',
        ]);
        $form->validate();
        $this->assertStringNotContainsString('<script>', $form->userName);
    }

    public function testEmailIsLowercased(): void
    {
        $form = $this->validServiceForm(['userEmail' => 'John@EXAMPLE.COM']);
        $form->validate();
        $this->assertSame('john@example.com', $form->userEmail);
    }
}
