<?php

namespace anvildev\booked\console\controllers;

use anvildev\booked\elements\BlackoutDate;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Schedule;
use anvildev\booked\elements\Service;
use Craft;
use craft\base\ElementInterface;
use craft\console\Controller;
use craft\db\Query;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Import booking data from the Slots plugin.
 *
 * Slots (anvildev/craft-slots) is Booked's smaller sibling. A site that outgrows
 * it can move its data here: both plugins install into the same Craft project
 * with different table prefixes, so this reads Slots' tables directly.
 *
 * The import is one-directional by design — there is no Booked → Slots path.
 *
 * Slots' schema is a strict subset of Booked's, so every column read here has a
 * same-named counterpart. Columns Booked adds (event dates, multi-day stays,
 * virtual meetings, SMS, calendar sync) are left at their defaults.
 */
class ImportController extends Controller
{
    /** Report what would be imported without writing anything. */
    public bool $dryRun = false;

    /** Table prefix Slots installed under. Only change this if you renamed it. */
    public string $prefix = 'slots_';

    /** Import even when Booked already holds data. Off by default — see actionFromSlots(). */
    public bool $append = false;

    /**
     * Element types, in dependency order. Employees reference a location, so
     * locations must exist first; the join tables come after everything.
     *
     * @var array<string, array{table: string, class: class-string<ElementInterface>, attributes: string[]}>
     */
    private const ELEMENT_MAP = [
        'locations' => [
            'table' => 'locations',
            'class' => Location::class,
            'attributes' => ['timezone', 'addressLine1', 'addressLine2', 'locality', 'administrativeArea', 'postalCode', 'countryCode'],
        ],
        'schedules' => [
            'table' => 'schedules',
            'class' => Schedule::class,
            'attributes' => ['workingHours', 'startDate', 'endDate'],
        ],
        'services' => [
            'table' => 'services',
            'class' => Service::class,
            'attributes' => [
                'description', 'duration', 'bufferBefore', 'bufferAfter', 'price', 'pricingMode',
                'minTimeBeforeBooking', 'timeSlotLength', 'availabilitySchedule', 'customerLimitEnabled',
                'customerLimitCount', 'customerLimitPeriod', 'customerLimitPeriodType', 'taxCategoryId',
                'allowCancellation', 'cancellationPolicyHours', 'allowRefund', 'refundTiers',
            ],
        ],
        'employees' => [
            'table' => 'employees',
            'class' => Employee::class,
            'attributes' => ['userId', 'email', 'workingHours', 'serviceIds'],
        ],
        'blackoutDates' => [
            'table' => 'blackout_dates',
            'class' => BlackoutDate::class,
            'attributes' => ['startDate', 'endDate', 'isActive'],
        ],
    ];

    /** Join tables: [slots table, booked table, columns, which columns hold element ids]. */
    private const JOIN_MAP = [
        ['service_locations', ['serviceId', 'locationId'], ['serviceId' => 'services', 'locationId' => 'locations']],
        ['employee_schedule_assignments', ['employeeId', 'scheduleId', 'sortOrder'], ['employeeId' => 'employees', 'scheduleId' => 'schedules']],
        ['service_schedule_assignments', ['serviceId', 'scheduleId', 'sortOrder'], ['serviceId' => 'services', 'scheduleId' => 'schedules']],
        ['blackout_dates_employees', ['blackoutDateId', 'employeeId'], ['blackoutDateId' => 'blackoutDates', 'employeeId' => 'employees']],
        ['blackout_dates_locations', ['blackoutDateId', 'locationId'], ['blackoutDateId' => 'blackoutDates', 'locationId' => 'locations']],
    ];

    private const RESERVATION_COLUMNS = [
        'userName', 'userEmail', 'userPhone', 'userId', 'userTimezone', 'bookingDate', 'startTime',
        'endTime', 'status', 'activeSlotKey', 'siteId', 'quantity', 'notes', 'sessionNotes',
        'notificationSent', 'emailReminder24hSent', 'emailReminder1hSent', 'confirmationToken',
        'dateCreated', 'dateUpdated',
    ];

    private const PAYMENT_COLUMNS = [
        'gateway', 'externalId', 'status', 'amount', 'currency', 'refundedAmount', 'payload',
        'dateCreated', 'dateUpdated',
    ];

    /** @var array<string, array<int, int>> old Slots element id => new Booked element id */
    private array $idMap = [];

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun', 'prefix', 'append']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['d' => 'dryRun']);
    }

    /**
     * Copy services, staff, locations, schedules, blackout dates, bookings and
     * payments out of Slots and into Booked.
     *
     * By default this refuses to run when Booked already holds booking data, so
     * it cannot silently interleave two data sets. Pass --append to override.
     */
    public function actionFromSlots(): int
    {
        $this->stdout("\nImport from Slots\n", Console::BOLD);
        $this->stdout("═══════════════════════════════════\n\n");

        if (!$this->checkSourceTables()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $counts = $this->sourceCounts();
        $this->stdout("Found in Slots:\n");
        foreach ($counts as $label => $n) {
            $this->stdout(sprintf("  %-18s %d\n", $label, $n));
        }
        $this->stdout("\n");

        if (array_sum($counts) === 0) {
            $this->stdout("Nothing to import.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if (!$this->append && ($existing = $this->existingBookedData()) !== []) {
            $this->stderr("Booked already holds data: " . implode(', ', $existing) . ".\n", Console::FG_RED);
            $this->stderr("Importing on top of it would interleave two data sets.\n");
            $this->stderr("Re-run with --append if that is genuinely what you want.\n\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $this->stdout("Dry run — nothing was written.\n", Console::FG_YELLOW);
            $this->stdout("Per-site title overrides are not carried over; each element is\n");
            $this->stdout("imported with its primary-site title.\n\n");
            return ExitCode::OK;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $imported = [];
            foreach (self::ELEMENT_MAP as $key => $spec) {
                $imported[$key] = $this->importElements($key, $spec);
            }
            $imported['joins'] = $this->importJoins();
            $imported['reservations'] = $this->importReservations();
            $imported['payments'] = $this->importPayments();

            $transaction->commit();

            $this->stdout("\nImported:\n", Console::FG_GREEN);
            foreach ($imported as $label => $n) {
                $this->stdout(sprintf("  %-18s %d\n", $label, $n));
            }
            $this->stdout("\nPer-site title overrides were not carried over — each element uses\n");
            $this->stdout("its primary-site title. Review localized content before going live.\n\n");

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            $this->stderr("\nImport failed and was rolled back: {$e->getMessage()}\n", Console::FG_RED);
            Craft::error("Slots import failed: {$e->getMessage()}", __METHOD__);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    private function sourceTable(string $name): string
    {
        return '{{%' . $this->prefix . $name . '}}';
    }

    private function checkSourceTables(): bool
    {
        $db = Craft::$app->getDb();
        $missing = [];

        foreach (['services', 'employees', 'locations', 'schedules', 'blackout_dates', 'reservations'] as $t) {
            if (!$db->tableExists($this->sourceTable($t))) {
                $missing[] = $this->prefix . $t;
            }
        }

        if ($missing !== []) {
            $this->stderr("No Slots tables found (missing: " . implode(', ', $missing) . ").\n", Console::FG_RED);
            $this->stderr("Is Slots installed in this project? Use --prefix if it uses a custom table prefix.\n\n");
            return false;
        }

        return true;
    }

    /** @return array<string, int> */
    private function sourceCounts(): array
    {
        $counts = [];
        foreach (self::ELEMENT_MAP as $key => $spec) {
            $counts[$key] = (new Query())->from($this->sourceTable($spec['table']))->count();
        }
        $counts['reservations'] = (new Query())->from($this->sourceTable('reservations'))->count();
        $counts['payments'] = Craft::$app->getDb()->tableExists($this->sourceTable('payments'))
            ? (new Query())->from($this->sourceTable('payments'))->count()
            : 0;

        return array_map('intval', $counts);
    }

    /** @return string[] human-readable list of what Booked already holds */
    private function existingBookedData(): array
    {
        $found = [];
        foreach ([
            'services' => '{{%booked_services}}',
            'employees' => '{{%booked_employees}}',
            'locations' => '{{%booked_locations}}',
            'bookings' => '{{%booked_reservations}}',
        ] as $label => $table) {
            $n = (int)(new Query())->from($table)->count();
            if ($n > 0) {
                $found[] = "{$n} {$label}";
            }
        }

        return $found;
    }

    /**
     * @param array{table: string, class: class-string<ElementInterface>, attributes: string[]} $spec
     */
    private function importElements(string $key, array $spec): int
    {
        $sourceType = 'anvildev\\slots\\elements\\' . (new \ReflectionClass($spec['class']))->getShortName();
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        $rows = (new Query())
            ->select(['s.*', 'es.title', 'e.enabled'])
            ->from(['s' => $this->sourceTable($spec['table'])])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[s.id]]')
            ->innerJoin(['es' => '{{%elements_sites}}'], '[[es.elementId]] = [[e.id]] AND [[es.siteId]] = :site', [':site' => $primarySiteId])
            ->where(['e.type' => $sourceType])
            ->andWhere(['e.dateDeleted' => null])
            ->all();

        $this->idMap[$key] = [];
        $n = 0;

        foreach ($rows as $row) {
            /** @var ElementInterface $element */
            $element = new $spec['class']();
            $element->title = $row['title'] ?? null;
            $element->enabled = (bool)($row['enabled'] ?? true);

            foreach ($spec['attributes'] as $attr) {
                if (array_key_exists($attr, $row)) {
                    $element->$attr = $row[$attr];
                }
            }

            // Employees point at a location that was imported a moment ago.
            if ($key === 'employees' && !empty($row['locationId'])) {
                $element->locationId = $this->idMap['locations'][(int)$row['locationId']] ?? null;
            }

            if (!Craft::$app->getElements()->saveElement($element)) {
                throw new \RuntimeException(sprintf(
                    'Could not save %s imported from Slots #%s: %s',
                    $spec['class'],
                    (string)$row['id'],
                    implode(', ', $element->getErrorSummary(true)),
                ));
            }

            $this->idMap[$key][(int)$row['id']] = (int)$element->id;
            $n++;
        }

        $this->stdout(sprintf("  %-18s %d\n", $key, $n));

        return $n;
    }

    private function importJoins(): int
    {
        $db = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $total = 0;

        foreach (self::JOIN_MAP as [$table, $columns, $elementColumns]) {
            if (!$db->tableExists($this->sourceTable($table))) {
                continue;
            }

            $rows = (new Query())->from($this->sourceTable($table))->all();
            $insert = [];

            foreach ($rows as $row) {
                $values = [];
                $skip = false;

                foreach ($columns as $col) {
                    if (isset($elementColumns[$col])) {
                        $mapped = $this->idMap[$elementColumns[$col]][(int)$row[$col]] ?? null;
                        if ($mapped === null) {
                            // The referenced element was soft-deleted in Slots.
                            $skip = true;
                            break;
                        }
                        $values[] = $mapped;
                    } else {
                        $values[] = $row[$col] ?? null;
                    }
                }

                if (!$skip) {
                    $insert[] = array_merge($values, [$now, $now, \craft\helpers\StringHelper::UUID()]);
                }
            }

            if ($insert !== []) {
                $db->createCommand()->batchInsert(
                    '{{%booked_' . $table . '}}',
                    array_merge($columns, ['dateCreated', 'dateUpdated', 'uid']),
                    $insert,
                )->execute();
                $total += count($insert);
            }
        }

        $this->stdout(sprintf("  %-18s %d\n", 'joins', $total));

        return $total;
    }

    private function importReservations(): int
    {
        $db = Craft::$app->getDb();
        $rows = (new Query())->from($this->sourceTable('reservations'))->all();
        $this->idMap['reservations'] = [];
        $n = 0;

        foreach ($rows as $row) {
            $values = [];
            foreach (self::RESERVATION_COLUMNS as $col) {
                $values[$col] = $row[$col] ?? null;
            }

            $values['serviceId'] = $this->mapOrNull('services', $row['serviceId'] ?? null);
            $values['employeeId'] = $this->mapOrNull('employees', $row['employeeId'] ?? null);
            $values['locationId'] = $this->mapOrNull('locations', $row['locationId'] ?? null);
            $values['uid'] = \craft\helpers\StringHelper::UUID();

            $db->createCommand()->insert('{{%booked_reservations}}', $values)->execute();
            $this->idMap['reservations'][(int)$row['id']] = (int)$db->getLastInsertID();
            $n++;
        }

        $this->stdout(sprintf("  %-18s %d\n", 'reservations', $n));

        return $n;
    }

    private function importPayments(): int
    {
        $db = Craft::$app->getDb();

        if (!$db->tableExists($this->sourceTable('payments'))) {
            return 0;
        }

        $rows = (new Query())->from($this->sourceTable('payments'))->all();
        $n = 0;

        foreach ($rows as $row) {
            $reservationId = $this->idMap['reservations'][(int)$row['reservationId']] ?? null;
            if ($reservationId === null) {
                continue;
            }

            $values = ['reservationId' => $reservationId];
            foreach (self::PAYMENT_COLUMNS as $col) {
                $values[$col] = $row[$col] ?? null;
            }
            $values['uid'] = \craft\helpers\StringHelper::UUID();

            $db->createCommand()->insert('{{%booked_payments}}', $values)->execute();
            $n++;
        }

        $this->stdout(sprintf("  %-18s %d\n", 'payments', $n));

        return $n;
    }

    private function mapOrNull(string $key, mixed $oldId): ?int
    {
        if ($oldId === null || $oldId === '') {
            return null;
        }

        return $this->idMap[$key][(int)$oldId] ?? null;
    }
}
