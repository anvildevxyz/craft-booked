<?php

namespace anvildev\booked\elements\exporters;

use anvildev\booked\helpers\CsvHelper;
use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;

class EmployeeScheduleCsvExporter extends ElementExporter
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public static function displayName(): string
    {
        return Craft::t('booked', 'export.employeeSchedules');
    }

    public static function isFormattable(): bool
    {
        return false;
    }

    public function export(ElementQueryInterface $query): mixed
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        $headers = ['Employee', 'Email', 'Location'];
        foreach (self::DAYS as $day) {
            $headers[] = ucfirst($day);
        }
        $headers[] = 'Total Hours/Week';
        fputcsv($handle, $headers);

        foreach (Db::each($query) as $employee) {
            /** @var \anvildev\booked\elements\Employee $employee */
            $hours = $employee->workingHours;
            if (is_string($hours)) {
                $hours = json_decode($hours, true) ?: [];
            }

            $totalMinutes = 0;
            $row = [
                CsvHelper::sanitizeValue($employee->title ?? ''),
                CsvHelper::sanitizeValue($employee->email ?? ''),
                CsvHelper::sanitizeValue($employee->getLocation()?->title ?? ''),
            ];

            foreach (self::DAYS as $day) {
                $daySchedule = $hours[$day] ?? null;
                if ($daySchedule && ($daySchedule['enabled'] ?? false)) {
                    $start = $daySchedule['start'] ?? '';
                    $end = $daySchedule['end'] ?? '';
                    $row[] = "{$start} - {$end}";
                    $totalMinutes += $this->calculateMinutes($start, $end);
                } else {
                    $row[] = 'Off';
                }
            }

            $row[] = number_format($totalMinutes / 60, 1);
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    public function getFilename(): string
    {
        return 'employee-schedules-' . date('Y-m-d') . '.csv';
    }

    private function calculateMinutes(string $start, string $end): int
    {
        $s = \DateTime::createFromFormat('H:i', $start);
        $e = \DateTime::createFromFormat('H:i', $end);
        if (!$s || !$e) {
            return 0;
        }
        $diff = $s->diff($e);
        return $diff->h * 60 + $diff->i;
    }
}
