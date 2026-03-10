<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\TwilioSmsService;
use anvildev\booked\tests\Support\TestCase;

/**
 * TwilioSmsService Test
 *
 * Tests the pure utility functions in TwilioSmsService
 */
class TwilioSmsServiceTest extends TestCase
{
    private TwilioSmsService $service;

    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TwilioSmsService();
    }

    // =========================================================================
    // normalizePhoneNumber() Tests - E.164 Format Input
    // =========================================================================

    public function testNormalizePhoneNumberAlreadyE164(): void
    {
        $result = $this->service->normalizePhoneNumber('+12025551234', 'US');

        $this->assertEquals('+12025551234', $result);
    }

    public function testNormalizePhoneNumberE164WithCountryCode44(): void
    {
        $result = $this->service->normalizePhoneNumber('+447911123456', 'GB');

        $this->assertEquals('+447911123456', $result);
    }

    public function testNormalizePhoneNumberE164WithCountryCode41(): void
    {
        $result = $this->service->normalizePhoneNumber('+41791234567', 'CH');

        $this->assertEquals('+41791234567', $result);
    }

    // =========================================================================
    // normalizePhoneNumber() Tests - US Numbers
    // =========================================================================

    public function testNormalizePhoneNumberUsLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('2025551234', 'US');

        $this->assertEquals('+12025551234', $result);
    }

    public function testNormalizePhoneNumberUsWithDashes(): void
    {
        $result = $this->service->normalizePhoneNumber('202-555-1234', 'US');

        $this->assertEquals('+12025551234', $result);
    }

    public function testNormalizePhoneNumberUsWithParentheses(): void
    {
        $result = $this->service->normalizePhoneNumber('(202) 555-1234', 'US');

        $this->assertEquals('+12025551234', $result);
    }

    public function testNormalizePhoneNumberUsWithSpaces(): void
    {
        $result = $this->service->normalizePhoneNumber('202 555 1234', 'US');

        $this->assertEquals('+12025551234', $result);
    }

    public function testNormalizePhoneNumberUsWithDots(): void
    {
        $result = $this->service->normalizePhoneNumber('202.555.1234', 'US');

        $this->assertEquals('+12025551234', $result);
    }

    public function testNormalizePhoneNumberUsWithCountryCodeNoPlus(): void
    {
        $result = $this->service->normalizePhoneNumber('12025551234', 'US');

        $this->assertEquals('+12025551234', $result);
    }

    // =========================================================================
    // normalizePhoneNumber() Tests - European Numbers
    // =========================================================================

    public function testNormalizePhoneNumberSwissLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('0791234567', 'CH');

        $this->assertEquals('+41791234567', $result);
    }

    public function testNormalizePhoneNumberSwissWithSpaces(): void
    {
        $result = $this->service->normalizePhoneNumber('079 123 45 67', 'CH');

        $this->assertEquals('+41791234567', $result);
    }

    public function testNormalizePhoneNumberGermanLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('01511234567', 'DE');

        $this->assertEquals('+491511234567', $result);
    }

    public function testNormalizePhoneNumberGermanWithSpaces(): void
    {
        $result = $this->service->normalizePhoneNumber('0151 123 4567', 'DE');

        $this->assertEquals('+491511234567', $result);
    }

    public function testNormalizePhoneNumberUkLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('07911123456', 'GB');

        $this->assertEquals('+447911123456', $result);
    }

    public function testNormalizePhoneNumberFrenchLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('0612345678', 'FR');

        $this->assertEquals('+33612345678', $result);
    }

    public function testNormalizePhoneNumberAustrianLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('0664123456', 'AT');

        $this->assertEquals('+43664123456', $result);
    }

    public function testNormalizePhoneNumberItalianLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('3401234567', 'IT');

        $this->assertEquals('+393401234567', $result);
    }

    public function testNormalizePhoneNumberSpanishLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('612345678', 'ES');

        $this->assertEquals('+34612345678', $result);
    }

    public function testNormalizePhoneNumberDutchLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('0612345678', 'NL');

        $this->assertEquals('+31612345678', $result);
    }

    public function testNormalizePhoneNumberBelgianLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('0471234567', 'BE');

        $this->assertEquals('+32471234567', $result);
    }

    // =========================================================================
    // normalizePhoneNumber() Tests - Other Countries
    // =========================================================================

    public function testNormalizePhoneNumberCanadianLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('4165551234', 'CA');

        $this->assertEquals('+14165551234', $result);
    }

    public function testNormalizePhoneNumberAustralianLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('0412345678', 'AU');

        $this->assertEquals('+61412345678', $result);
    }

    public function testNormalizePhoneNumberNewZealandLocal(): void
    {
        $result = $this->service->normalizePhoneNumber('021123456', 'NZ');

        $this->assertEquals('+6421123456', $result);
    }

    // =========================================================================
    // normalizePhoneNumber() Tests - Default Country Code
    // =========================================================================

    public function testNormalizePhoneNumberDefaultsToUs(): void
    {
        $result = $this->service->normalizePhoneNumber('2025551234');

        $this->assertEquals('+12025551234', $result);
    }

    public function testNormalizePhoneNumberUnknownCountryDefaultsTo1(): void
    {
        $result = $this->service->normalizePhoneNumber('5551234567', 'XX');

        $this->assertEquals('+15551234567', $result);
    }

    // =========================================================================
    // normalizePhoneNumber() Tests - Invalid Numbers
    // =========================================================================

    public function testNormalizePhoneNumberTooShort(): void
    {
        $result = $this->service->normalizePhoneNumber('12345', 'US');

        $this->assertNull($result);
    }

    public function testNormalizePhoneNumberTooLong(): void
    {
        $result = $this->service->normalizePhoneNumber('12345678901234567890', 'US');

        $this->assertNull($result);
    }

    public function testNormalizePhoneNumberEmptyString(): void
    {
        $result = $this->service->normalizePhoneNumber('', 'US');

        $this->assertNull($result);
    }

    public function testNormalizePhoneNumberOnlySpecialChars(): void
    {
        $result = $this->service->normalizePhoneNumber('()--', 'US');

        $this->assertNull($result);
    }

    // =========================================================================
    // normalizePhoneNumber() Tests - Edge Cases
    // =========================================================================

    public function testNormalizePhoneNumberWithLeadingZeros(): void
    {
        // European numbers often start with 0
        $result = $this->service->normalizePhoneNumber('00447911123456', 'GB');

        // Strips leading zeros and adds country code
        $this->assertEquals('+447911123456', $result);
    }

    public function testNormalizePhoneNumberWithMultipleLeadingZeros(): void
    {
        $result = $this->service->normalizePhoneNumber('000791234567', 'CH');

        $this->assertEquals('+41791234567', $result);
    }

    // =========================================================================
    // renderMessage() Tests - Basic Replacement
    // =========================================================================

    public function testRenderMessageBasicReplacement(): void
    {
        $template = 'Hello {{name}}!';
        $variables = ['name' => 'John'];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Hello John!', $result);
    }

    public function testRenderMessageMultipleVariables(): void
    {
        $template = '{{service}} on {{date}} at {{time}}';
        $variables = [
            'service' => 'Haircut',
            'date' => '2024-01-15',
            'time' => '10:00',
        ];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Haircut on 2024-01-15 at 10:00', $result);
    }

    public function testRenderMessageSameVariableMultipleTimes(): void
    {
        $template = '{{name}} said hello to {{name}}';
        $variables = ['name' => 'John'];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('John said hello to John', $result);
    }

    // =========================================================================
    // renderMessage() Tests - Unused Placeholders
    // =========================================================================

    public function testRenderMessageRemovesUnusedPlaceholders(): void
    {
        $template = 'Hello {{name}}! Your code is {{code}}.';
        $variables = ['name' => 'John'];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Hello John! Your code is .', $result);
    }

    public function testRenderMessageRemovesAllUnusedPlaceholders(): void
    {
        $template = '{{unused1}} Hello {{name}} {{unused2}}';
        $variables = ['name' => 'John'];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Hello John', $result);
    }

    // =========================================================================
    // renderMessage() Tests - Whitespace Cleanup
    // =========================================================================

    public function testRenderMessageCleansUpExtraWhitespace(): void
    {
        $template = 'Hello    {{name}}!   How   are   you?';
        $variables = ['name' => 'John'];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Hello John! How are you?', $result);
    }

    public function testRenderMessageTrimsLeadingAndTrailingWhitespace(): void
    {
        $template = '   Hello {{name}}!   ';
        $variables = ['name' => 'John'];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Hello John!', $result);
    }

    public function testRenderMessageCleansUpNewlines(): void
    {
        $template = "Hello {{name}}!\nHow are you?";
        $variables = ['name' => 'John'];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Hello John! How are you?', $result);
    }

    // =========================================================================
    // renderMessage() Tests - Empty Inputs
    // =========================================================================

    public function testRenderMessageEmptyTemplate(): void
    {
        $result = $this->service->renderMessage('', ['name' => 'John']);

        $this->assertEquals('', $result);
    }

    public function testRenderMessageEmptyVariables(): void
    {
        $result = $this->service->renderMessage('Hello World!', []);

        $this->assertEquals('Hello World!', $result);
    }

    public function testRenderMessageNoPlaceholders(): void
    {
        $result = $this->service->renderMessage('Hello World!', ['name' => 'John']);

        $this->assertEquals('Hello World!', $result);
    }

    // =========================================================================
    // renderMessage() Tests - Special Characters
    // =========================================================================

    public function testRenderMessageWithSpecialCharactersInValue(): void
    {
        $template = 'Customer: {{name}}';
        $variables = ['name' => 'John & Jane <Test>'];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Customer: John & Jane <Test>', $result);
    }

    public function testRenderMessageWithEmojisInValue(): void
    {
        $template = 'Status: {{status}}';
        $variables = ['status' => '✅ Confirmed'];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Status: ✅ Confirmed', $result);
    }

    public function testRenderMessageWithUnicodeInValue(): void
    {
        $template = 'Name: {{name}}';
        $variables = ['name' => 'Müller 日本語'];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Name: Müller 日本語', $result);
    }

    // =========================================================================
    // renderMessage() Tests - Real-world Templates
    // =========================================================================

    public function testRenderMessageConfirmationTemplate(): void
    {
        $template = 'Your booking is confirmed! {{service}} on {{date}} at {{time}}. {{location}}';
        $variables = [
            'service' => 'Haircut',
            'date' => 'Jan 15, 2024',
            'time' => '10:00',
            'location' => 'Main Street Salon',
        ];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Your booking is confirmed! Haircut on Jan 15, 2024 at 10:00. Main Street Salon', $result);
    }

    public function testRenderMessageReminderTemplate(): void
    {
        $template = 'Reminder: {{service}} tomorrow at {{time}}. {{location}}';
        $variables = [
            'service' => 'Massage Therapy',
            'time' => '14:30',
            'location' => 'Wellness Center',
        ];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Reminder: Massage Therapy tomorrow at 14:30. Wellness Center', $result);
    }

    public function testRenderMessageCancellationTemplate(): void
    {
        $template = 'Your booking for {{service}} on {{date}} has been cancelled.';
        $variables = [
            'service' => 'Personal Training',
            'date' => 'Jan 20, 2024',
        ];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Your booking for Personal Training on Jan 20, 2024 has been cancelled.', $result);
    }

    public function testRenderMessageWithMissingOptionalVariables(): void
    {
        $template = 'Booking: {{service}} on {{date}} at {{time}}. Location: {{location}}. Employee: {{employee}}';
        $variables = [
            'service' => 'Consultation',
            'date' => 'Jan 25',
            'time' => '09:00',
            // location and employee missing
        ];

        $result = $this->service->renderMessage($template, $variables);

        // Missing placeholders are removed, extra spaces cleaned
        $this->assertEquals('Booking: Consultation on Jan 25 at 09:00. Location: . Employee:', $result);
    }

    // =========================================================================
    // renderMessage() Tests - Type Coercion
    // =========================================================================

    public function testRenderMessageWithNumericValue(): void
    {
        $template = 'Quantity: {{quantity}}';
        $variables = ['quantity' => 5];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Quantity: 5', $result);
    }

    public function testRenderMessageWithFloatValue(): void
    {
        $template = 'Price: ${{price}}';
        $variables = ['price' => 99.99];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Price: $99.99', $result);
    }

    public function testRenderMessageWithNullValue(): void
    {
        $template = 'Notes: {{notes}}';
        $variables = ['notes' => null];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Notes:', $result);
    }

    public function testRenderMessageWithEmptyStringValue(): void
    {
        $template = 'Notes: {{notes}}';
        $variables = ['notes' => ''];

        $result = $this->service->renderMessage($template, $variables);

        $this->assertEquals('Notes:', $result);
    }
}
