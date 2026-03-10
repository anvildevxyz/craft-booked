<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\tests\Support\TestCase;

class FrontendFetchTest extends TestCase
{
    public function testBookingWizardParsesJsonResponses(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/web/js/booking-wizard.js');
        $fetchCount = substr_count($source, 'await fetch(');
        $jsonCount = substr_count($source, 'response.json()');
        // The release-lock fetch is fire-and-forget (no JSON parsing needed),
        // so we expect at least (fetchCount - 1) response.json() calls
        $this->assertGreaterThanOrEqual($fetchCount - 1, $jsonCount,
            "booking-wizard.js has {$fetchCount} fetch() calls but only {$jsonCount} response.json() calls"
        );
    }

    public function testBookingAvailabilityParsesJsonResponses(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/web/js/booking-availability.js');
        $fetchCount = substr_count($source, 'await fetch(');
        $jsonCount = substr_count($source, 'response.json()');
        $this->assertGreaterThanOrEqual($fetchCount, $jsonCount,
            "booking-availability.js has {$fetchCount} fetch() calls but only {$jsonCount} response.json() calls"
        );
    }
}
