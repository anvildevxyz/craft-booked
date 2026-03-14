<?php

namespace anvildev\booked\tests\Unit\Elements\Exporters;

use anvildev\booked\elements\exporters\ReservationCsvExporter;
use anvildev\booked\tests\Support\TestCase;

class ReservationCsvExporterTest extends TestCase
{
    public function testDisplayNameIsNotEmpty(): void
    {
        $this->requiresCraft();
        $this->assertNotEmpty(ReservationCsvExporter::displayName());
    }

    public function testIsNotFormattable(): void
    {
        $this->assertFalse(ReservationCsvExporter::isFormattable());
    }

    public function testFilenameIncludesDate(): void
    {
        $exporter = new ReservationCsvExporter();
        $filename = $exporter->getFilename();
        $this->assertStringContainsString('bookings-', $filename);
        $this->assertStringContainsString(date('Y-m-d'), $filename);
        $this->assertStringEndsWith('.csv', $filename);
    }
}
