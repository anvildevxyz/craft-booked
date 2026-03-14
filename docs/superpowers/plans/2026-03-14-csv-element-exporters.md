# CSV Element Exporters Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enhance the existing reservation CSV exporter with richer data (service, employee, location, duration, price) and add new CSV exporters for Employee schedules and Service catalog.

**Architecture:** Build on the existing `ReservationCsvExporter` pattern — each exporter extends `craft\base\ElementExporter`, uses `CsvHelper::sanitizeValue()` for injection prevention, and writes UTF-8 BOM CSV to `php://temp`. Register new exporters via `defineExporters()` on their respective element classes. Employee and Service elements currently have no custom exporters.

**Tech Stack:** PHP 8.2, Craft CMS 5, PHPUnit 9.5

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Modify | `src/elements/exporters/ReservationCsvExporter.php` | Add service, employee, location, duration, price columns |
| Create | `src/elements/exporters/EmployeeScheduleCsvExporter.php` | Export employee working hours for payroll |
| Create | `src/elements/exporters/ServiceCatalogCsvExporter.php` | Export service catalog with pricing and duration |
| Modify | `src/elements/Employee.php` | Register `defineExporters()` |
| Modify | `src/elements/Service.php` | Register `defineExporters()` |
| Modify | `src/translations/en/booked.php` | Add exporter display names |
| Modify | `src/translations/{de,es,fr,it,ja,nl,pt}/booked.php` | Add exporter display names |
| Create | `tests/Unit/Elements/Exporters/ReservationCsvExporterTest.php` | Test enhanced reservation export |
| Create | `tests/Unit/Elements/Exporters/EmployeeScheduleCsvExporterTest.php` | Test employee schedule export |
| Create | `tests/Unit/Elements/Exporters/ServiceCatalogCsvExporterTest.php` | Test service catalog export |

---

## Task 1: Enhance ReservationCsvExporter

**Files:**
- Modify: `src/elements/exporters/ReservationCsvExporter.php`
- Create: `tests/Unit/Elements/Exporters/ReservationCsvExporterTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Elements/Exporters/ReservationCsvExporterTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it passes** (these test existing behavior)

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Elements/Exporters/ReservationCsvExporterTest.php -v`

- [ ] **Step 3: Enhance the exporter with additional columns**

Replace the `export()` method in `src/elements/exporters/ReservationCsvExporter.php`:

```php
public function export(ElementQueryInterface $query): mixed
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, [
        'ID', 'Name', 'Email', 'Phone',
        'Service', 'Employee', 'Location',
        'Date', 'Start Time', 'End Time', 'Duration (min)',
        'Status', 'Quantity', 'Price',
        'Notes', 'Created',
    ]);

    /** @var \anvildev\booked\elements\db\ReservationQuery $query */
    foreach (Db::each($query->withRelations()) as $r) {
        /** @var \anvildev\booked\elements\Reservation $r */
        fputcsv($handle, [
            (string) $r->id,
            CsvHelper::sanitizeValue($r->userName ?? ''),
            CsvHelper::sanitizeValue($r->userEmail ?? ''),
            CsvHelper::sanitizeValue($r->userPhone ?? ''),
            CsvHelper::sanitizeValue($r->getService()?->title ?? ''),
            CsvHelper::sanitizeValue($r->getEmployee()?->title ?? ''),
            CsvHelper::sanitizeValue($r->getLocation()?->title ?? ''),
            (string) ($r->bookingDate ?? ''),
            (string) ($r->startTime ?? ''),
            (string) ($r->endTime ?? ''),
            (string) $r->getDurationMinutes(),
            (string) $r->getStatusLabel(),
            (string) $r->quantity,
            number_format($r->totalPrice, 2, '.', ''),
            CsvHelper::sanitizeValue($r->notes ?? ''),
            $r->dateCreated ? $r->dateCreated->format('Y-m-d H:i:s') : '',
        ]);
    }

    rewind($handle);
    $content = stream_get_contents($handle);
    fclose($handle);

    return $content;
}
```

Key changes:
- Added `$query->withRelations()` to eager-load service, employee, location
- Added Service, Employee, Location, Duration, Quantity, Price columns
- Price formatted to 2 decimal places

- [ ] **Step 4: Run tests and code quality**

Run: `cd plugins/booked && composer test && composer ecs:check`

- [ ] **Step 5: Commit**

```bash
git add src/elements/exporters/ReservationCsvExporter.php tests/Unit/Elements/Exporters/ReservationCsvExporterTest.php
git commit -m "feat(csv): enhance reservation exporter with service, employee, location, duration, price columns"
```

---

## Task 2: Create EmployeeScheduleCsvExporter

**Files:**
- Create: `src/elements/exporters/EmployeeScheduleCsvExporter.php`
- Modify: `src/elements/Employee.php` — add `defineExporters()`
- Create: `tests/Unit/Elements/Exporters/EmployeeScheduleCsvExporterTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Elements/Exporters/EmployeeScheduleCsvExporterTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Elements/Exporters/EmployeeScheduleCsvExporterTest.php -v`
Expected: FAIL — class not found

- [ ] **Step 3: Create the exporter**

Create `src/elements/exporters/EmployeeScheduleCsvExporter.php`:

```php
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
```

- [ ] **Step 4: Register on Employee element**

In `src/elements/Employee.php`, add after `defineActions()`:

```php
protected static function defineExporters(string $source): array
{
    $exporters = parent::defineExporters($source);
    $exporters[] = exporters\EmployeeScheduleCsvExporter::class;
    return $exporters;
}
```

Since `Employee.php` is in the `anvildev\booked\elements` namespace, the relative reference `exporters\EmployeeScheduleCsvExporter::class` works without any additional `use` import — matching how `Reservation.php` does it at line 340.

- [ ] **Step 5: Run tests**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Elements/Exporters/EmployeeScheduleCsvExporterTest.php -v`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/elements/exporters/EmployeeScheduleCsvExporter.php src/elements/Employee.php tests/Unit/Elements/Exporters/EmployeeScheduleCsvExporterTest.php
git commit -m "feat(csv): add employee schedule CSV exporter with weekly hours"
```

---

## Task 3: Create ServiceCatalogCsvExporter

**Files:**
- Create: `src/elements/exporters/ServiceCatalogCsvExporter.php`
- Modify: `src/elements/Service.php` — add `defineExporters()`
- Create: `tests/Unit/Elements/Exporters/ServiceCatalogCsvExporterTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/Elements/Exporters/ServiceCatalogCsvExporterTest.php`:

```php
<?php

namespace anvildev\booked\tests\Unit\Elements\Exporters;

use anvildev\booked\elements\exporters\ServiceCatalogCsvExporter;
use anvildev\booked\tests\Support\TestCase;

class ServiceCatalogCsvExporterTest extends TestCase
{
    public function testDisplayNameIsNotEmpty(): void
    {
        $this->requiresCraft();
        $this->assertNotEmpty(ServiceCatalogCsvExporter::displayName());
    }

    public function testIsNotFormattable(): void
    {
        $this->assertFalse(ServiceCatalogCsvExporter::isFormattable());
    }

    public function testFilenameIncludesDate(): void
    {
        $exporter = new ServiceCatalogCsvExporter();
        $filename = $exporter->getFilename();
        $this->assertStringContainsString('service-catalog-', $filename);
        $this->assertStringEndsWith('.csv', $filename);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Elements/Exporters/ServiceCatalogCsvExporterTest.php -v`

- [ ] **Step 3: Create the exporter**

Create `src/elements/exporters/ServiceCatalogCsvExporter.php`:

```php
<?php

namespace anvildev\booked\elements\exporters;

use anvildev\booked\helpers\CsvHelper;
use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;

class ServiceCatalogCsvExporter extends ElementExporter
{
    public static function displayName(): string
    {
        return Craft::t('booked', 'export.serviceCatalog');
    }

    public static function isFormattable(): bool
    {
        return false;
    }

    public function export(ElementQueryInterface $query): mixed
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'ID', 'Title', 'Description',
            'Duration (min)', 'Buffer Before (min)', 'Buffer After (min)',
            'Time Slot (min)', 'Price',
            'Min Advance (min)', 'Waitlist',
            'Cancellation Allowed', 'Cancellation Hours',
            'Status', 'Created',
        ]);

        foreach (Db::each($query) as $service) {
            /** @var \anvildev\booked\elements\Service $service */
            fputcsv($handle, [
                (string) $service->id,
                CsvHelper::sanitizeValue($service->title ?? ''),
                CsvHelper::sanitizeValue($service->description ?? ''),
                (string) ($service->duration ?? ''),
                (string) ($service->bufferBefore ?? 0),
                (string) ($service->bufferAfter ?? 0),
                (string) ($service->timeSlotLength ?? ''),
                $service->price !== null ? number_format($service->price, 2, '.', '') : '',
                (string) ($service->minTimeBeforeBooking ?? ''),
                $service->enableWaitlist ? 'Yes' : 'No',
                $service->allowCancellation ? 'Yes' : 'No',
                (string) ($service->cancellationPolicyHours ?? ''),
                $service->enabled ? 'Enabled' : 'Disabled',
                $service->dateCreated ? $service->dateCreated->format('Y-m-d H:i:s') : '',
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    public function getFilename(): string
    {
        return 'service-catalog-' . date('Y-m-d') . '.csv';
    }
}
```

- [ ] **Step 4: Register on Service element**

In `src/elements/Service.php`, add `defineExporters()`:

```php
protected static function defineExporters(string $source): array
{
    $exporters = parent::defineExporters($source);
    $exporters[] = exporters\ServiceCatalogCsvExporter::class;
    return $exporters;
}
```

Since `Service.php` is in the `anvildev\booked\elements` namespace, the relative reference works without any additional import.

- [ ] **Step 5: Run tests**

Run: `cd plugins/booked && ./vendor/bin/phpunit tests/Unit/Elements/Exporters/ -v`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/elements/exporters/ServiceCatalogCsvExporter.php src/elements/Service.php tests/Unit/Elements/Exporters/ServiceCatalogCsvExporterTest.php
git commit -m "feat(csv): add service catalog CSV exporter with pricing and duration"
```

---

## Task 4: Add Translations

**Files:**
- Modify: `src/translations/en/booked.php`
- Modify: `src/translations/{de,es,fr,it,ja,nl,pt}/booked.php`

- [ ] **Step 1: Add English translation keys**

```php
'export.employeeSchedules' => 'Employee Schedules CSV',
'export.serviceCatalog' => 'Service Catalog CSV',
```

- [ ] **Step 2: Add same keys to all other language files** (English placeholders)

- [ ] **Step 3: Commit**

```bash
git add src/translations/
git commit -m "feat(csv): add translation keys for new exporters"
```

---

## Task 5: Code Quality and Final Verification

- [ ] **Step 1: Run ECS**

Run: `cd plugins/booked && composer ecs:check`
Fix: `composer ecs`

- [ ] **Step 2: Run PHPStan**

Run: `cd plugins/booked && composer phpstan`
Ensure no new errors.

- [ ] **Step 3: Run full test suite**

Run: `cd plugins/booked && composer test`
Expected: All tests pass.

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "chore: fix code style for CSV exporter feature"
```
