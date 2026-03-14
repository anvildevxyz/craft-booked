<?php

namespace anvildev\booked\tests\Unit\Elements\Exporters;

use anvildev\booked\elements\exporters\EmployeeScheduleCsvExporter;
use anvildev\booked\tests\Support\TestCase;

class EmployeeScheduleCsvExporterTest extends TestCase
{
    public function testDisplayNameIsNotEmpty(): void
    {
        $this->requiresCraft();
        $this->assertNotEmpty(EmployeeScheduleCsvExporter::displayName());
    }

    public function testIsNotFormattable(): void
    {
        $this->assertFalse(EmployeeScheduleCsvExporter::isFormattable());
    }

    public function testFilenameIncludesDate(): void
    {
        $exporter = new EmployeeScheduleCsvExporter();
        $filename = $exporter->getFilename();
        $this->assertStringContainsString('employee-schedules-', $filename);
        $this->assertStringEndsWith('.csv', $filename);
    }
}
