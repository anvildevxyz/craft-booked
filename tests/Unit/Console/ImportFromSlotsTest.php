<?php

namespace anvildev\booked\tests\Unit\Console;

use anvildev\booked\tests\Support\TestCase;

/**
 * Guards the Slots → Booked import contract.
 *
 * The importer only works while Slots' schema stays a strict subset of Booked's:
 * it reads Slots columns and writes same-named Booked columns. Booked evolves
 * independently, so these tests fail the build the moment Booked drops or renames
 * a column the importer depends on — which is the failure mode that would
 * otherwise surface as a broken migration on a customer's site.
 *
 * They read Booked's own Install migration rather than a live database, so they
 * run in the unit suite with no Craft bootstrap.
 */
class ImportFromSlotsTest extends TestCase
{
    /**
     * Every column the importer copies, by Booked table. This list is the
     * contract; Slots' Install migration must keep declaring these too.
     *
     * @var array<string, string[]>
     */
    private const REQUIRED_COLUMNS = [
        'services' => [
            'description', 'duration', 'bufferBefore', 'bufferAfter', 'price', 'pricingMode',
            'minTimeBeforeBooking', 'timeSlotLength', 'availabilitySchedule', 'customerLimitEnabled',
            'customerLimitCount', 'customerLimitPeriod', 'customerLimitPeriodType', 'taxCategoryId',
            'allowCancellation', 'cancellationPolicyHours', 'allowRefund', 'refundTiers',
        ],
        'employees' => ['userId', 'locationId', 'email', 'workingHours', 'serviceIds'],
        'locations' => [
            'timezone', 'addressLine1', 'addressLine2', 'locality', 'administrativeArea',
            'postalCode', 'countryCode',
        ],
        'schedules' => ['workingHours', 'startDate', 'endDate'],
        'blackout_dates' => ['title', 'startDate', 'endDate', 'isActive'],
        'service_locations' => ['serviceId', 'locationId'],
        'employee_schedule_assignments' => ['employeeId', 'scheduleId', 'sortOrder'],
        'service_schedule_assignments' => ['serviceId', 'scheduleId', 'sortOrder'],
        'blackout_dates_employees' => ['blackoutDateId', 'employeeId'],
        'blackout_dates_locations' => ['blackoutDateId', 'locationId'],
        'reservations' => [
            'userName', 'userEmail', 'userPhone', 'userId', 'userTimezone', 'bookingDate', 'startTime',
            'endTime', 'status', 'activeSlotKey', 'employeeId', 'locationId', 'serviceId', 'siteId',
            'quantity', 'notes', 'sessionNotes', 'notificationSent', 'emailReminder24hSent',
            'emailReminder1hSent', 'confirmationToken',
        ],
        'payments' => [
            'reservationId', 'gateway', 'externalId', 'status', 'amount', 'currency',
            'refundedAmount', 'payload',
        ],
    ];

    private static function installSource(): string
    {
        $path = dirname(__DIR__, 3) . '/src/migrations/Install.php';
        self::assertFileExists($path);

        return file_get_contents($path);
    }

    private static function importerSource(): string
    {
        $path = dirname(__DIR__, 3) . '/src/console/controllers/ImportController.php';
        self::assertFileExists($path);

        return file_get_contents($path);
    }

    public function testImporterExists(): void
    {
        $this->assertStringContainsString(
            'public function actionFromSlots()',
            self::importerSource(),
            'Booked must expose booked/import/from-slots',
        );
    }

    /**
     * @dataProvider tableProvider
     */
    public function testBookedStillDeclaresEveryColumnTheImporterCopies(string $table, array $columns): void
    {
        $source = self::installSource();

        $start = strpos($source, "createTable('{{%booked_{$table}}}'");
        $this->assertNotFalse($start, "Booked's Install must still create booked_{$table}");

        $end = strpos($source, ']);', $start);
        $block = substr($source, $start, $end - $start);

        foreach ($columns as $column) {
            $this->assertStringContainsString(
                "'{$column}' =>",
                $block,
                "booked_{$table} must keep the '{$column}' column — the Slots importer copies it",
            );
        }
    }

    public static function tableProvider(): array
    {
        $cases = [];
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            $cases[$table] = [$table, $columns];
        }

        return $cases;
    }

    public function testImporterReadsSlotsTablePrefixAndIsOverridable(): void
    {
        $source = self::importerSource();

        $this->assertStringContainsString("public string \$prefix = 'slots_'", $source);
        $this->assertStringContainsString("'prefix'", $source, 'The prefix must be a console option');
    }

    public function testImporterRefusesToRunOverExistingDataByDefault(): void
    {
        $source = self::importerSource();

        $this->assertStringContainsString('public bool $append = false', $source);
        $this->assertStringContainsString('existingBookedData()', $source);
    }

    public function testImporterIsTransactional(): void
    {
        $source = self::importerSource();

        $this->assertStringContainsString('beginTransaction()', $source);
        $this->assertStringContainsString('rollBack()', $source);
    }

    public function testImporterSupportsDryRun(): void
    {
        $this->assertStringContainsString('public bool $dryRun = false', self::importerSource());
    }
}
