<?php

namespace anvildev\booked\tests\Unit\Records;

use anvildev\booked\records\SettingsRecord;
use anvildev\booked\tests\Support\TestCase;

class SettingsRecordTest extends TestCase
{
    public function testEncryptedFieldsContainsAllSecrets(): void
    {
        $expected = [
            'googleCalendarClientSecret',
            'outlookCalendarClientSecret',
            'zoomClientSecret',
            'teamsClientSecret',
            'twilioAuthToken',
            'recaptchaSecretKey',
            'hcaptchaSecretKey',
            'turnstileSecretKey',
        ];

        $this->assertSame($expected, SettingsRecord::ENCRYPTED_FIELDS);
    }

    public function testEncryptedFieldsCount(): void
    {
        $this->assertCount(8, SettingsRecord::ENCRYPTED_FIELDS);
    }
}
