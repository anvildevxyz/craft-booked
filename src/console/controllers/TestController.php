<?php

namespace anvildev\booked\console\controllers;

use anvildev\booked\Booked;
use anvildev\booked\elements\BlackoutDate;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\EventDate;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Schedule;
use anvildev\booked\elements\Service;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\models\ReservationModel;
use anvildev\booked\models\Settings;
use anvildev\booked\records\ReservationRecord;
use anvildev\booked\records\WaitlistRecord;
use Craft;
use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

class TestController extends Controller
{
    private const TEST_PREFIX = '[TEST]';
    private const BATCH_SIZE = 500;

    private const TIMEZONES = [
        'America/New_York', 'America/Chicago', 'America/Denver',
        'America/Los_Angeles', 'Europe/London',
    ];

    private const SCHEDULE_TEMPLATES = [
        ['start' => '09:00', 'end' => '17:00', 'breakStart' => '12:00', 'breakEnd' => '13:00'],
        ['start' => '07:00', 'end' => '15:00', 'breakStart' => '11:00', 'breakEnd' => '11:30'],
        ['start' => '12:00', 'end' => '20:00', 'breakStart' => '16:00', 'breakEnd' => '16:30'],
        ['start' => '08:00', 'end' => '18:00', 'breakStart' => '12:30', 'breakEnd' => '13:30'],
        ['start' => '06:00', 'end' => '12:00', 'breakStart' => null, 'breakEnd' => null],
    ];

    public function actionSeed(int $reservations = 10000): int
    {
        $this->stdout("Seeding stress test data...\n\n");
        $startTime = microtime(true);

        try {
            $siteId = Craft::$app->getSites()->getPrimarySite()->id;

            $locationIds = $this->seedLocations($siteId);
            $scheduleIds = $this->seedSchedules($siteId);
            $serviceIds = $this->seedServices($siteId);
            $employeeIds = $this->seedEmployees($siteId, $locationIds, $serviceIds, $scheduleIds);
            $reservationCount = $this->seedReservations($reservations, $employeeIds, $serviceIds, $locationIds, $siteId);
            $blackoutCount = $this->seedBlackoutDates($siteId, $employeeIds, $locationIds);
            $eventDateIds = $this->seedEventDates($siteId, $locationIds);
            $eventReservationCount = $this->seedEventReservations($siteId, $eventDateIds, $locationIds);

            $elapsed = round(microtime(true) - $startTime, 1);

            $this->stdout("\n─────────────────────────────────\n");
            $this->stdout("✓ Seeding complete in {$elapsed}s\n", Console::FG_GREEN);
            $this->stdout("─────────────────────────────────\n");

            foreach ([
                'Locations' => count($locationIds), 'Schedules' => count($scheduleIds),
                'Services' => count($serviceIds), 'Employees' => count($employeeIds),
                'Reservations' => $reservationCount, 'Blackouts' => $blackoutCount,
                'Event Dates' => count($eventDateIds), 'Event Bookings' => $eventReservationCount,
            ] as $label => $count) {
                $this->stdout(str_pad("{$label}:", 18) . "{$count}\n");
            }

            $this->stdout("─────────────────────────────────\n\n");
            $this->stdout("k6 configuration values:\n", Console::FG_YELLOW);
            $this->stdout("SERVICE_IDS:  [" . implode(', ', $serviceIds) . "]\n");
            $this->stdout("EMPLOYEE_IDS: [" . implode(', ', $employeeIds) . "]\n");
            $this->stdout("LOCATION_IDS: [" . implode(', ', $locationIds) . "]\n");
            $this->stdout("EVENT_DATE_IDS: [" . implode(', ', $eventDateIds) . "]\n");

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("✗ Error: {$e->getMessage()}\n", Console::FG_RED);
            Craft::error("Stress test seed failed: " . $e->getMessage(), __METHOD__);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    public function actionClear(): int
    {
        $this->stdout("Clearing stress test data...\n\n");

        if (!$this->confirm("This will delete ALL elements with [TEST] prefix. Continue?")) {
            $this->stdout("Cancelled.\n");
            return ExitCode::OK;
        }

        try {
            $counts = [];

            $testEmployeeIds = $this->getTestElementIds(Employee::class);
            $testServiceIds = $this->getTestElementIds(Service::class);

            if (!empty($testEmployeeIds) || !empty($testServiceIds)) {
                $condition = ['or'];
                if (!empty($testEmployeeIds)) {
                    $condition[] = ['in', 'employeeId', $testEmployeeIds];
                }
                if (!empty($testServiceIds)) {
                    $condition[] = ['in', 'serviceId', $testServiceIds];
                }

                $reservationIds = ReservationRecord::find()->where($condition)->select('id')->column();

                foreach (array_chunk($reservationIds, self::BATCH_SIZE) as $chunk) {
                    Craft::$app->getDb()->createCommand()
                        ->delete('{{%booked_reservations}}', ['in', 'id', $chunk])
                        ->execute();
                }
                $counts['Reservations'] = count($reservationIds);
            } else {
                $counts['Reservations'] = 0;
            }

            foreach ([
                'Event Dates' => EventDate::class,
                'Blackouts' => BlackoutDate::class,
                'Employees' => Employee::class,
                'Services' => Service::class,
                'Schedules' => Schedule::class,
                'Locations' => Location::class,
            ] as $label => $elementClass) {
                $ids = $this->getTestElementIds($elementClass);
                $counts[$label] = count(array_filter($ids, fn($id) => Craft::$app->getElements()->deleteElementById($id)));
            }

            $this->stdout("─────────────────────────────────\n");
            foreach ($counts as $label => $count) {
                $this->stdout("{$label}: {$count}\n", $count > 0 ? Console::FG_GREEN : null);
            }
            $this->stdout("─────────────────────────────────\n");
            $this->stdout("✓ Cleanup complete\n", Console::FG_GREEN);

            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("✗ Error: {$e->getMessage()}\n", Console::FG_RED);
            Craft::error("Stress test clear failed: " . $e->getMessage(), __METHOD__);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    public function actionVerifyNoDoubles(): int
    {
        $this->stdout("Checking for double-bookings...\n\n");

        try {
            $conflicts = Craft::$app->getDb()->createCommand(<<<SQL
SELECT
    r1.[[id]] AS id1, r2.[[id]] AS id2, r1.[[employeeId]], r1.[[bookingDate]],
    r1.[[startTime]] AS start1, r1.[[endTime]] AS end1,
    r2.[[startTime]] AS start2, r2.[[endTime]] AS end2
FROM {{%booked_reservations}} r1
INNER JOIN {{%booked_reservations}} r2
    ON r1.[[employeeId]] = r2.[[employeeId]]
    AND r1.[[bookingDate]] = r2.[[bookingDate]]
    AND r1.[[id]] < r2.[[id]]
    AND r1.[[startTime]] < r2.[[endTime]]
    AND r2.[[startTime]] < r1.[[endTime]]
WHERE r1.[[status]] = :status AND r2.[[status]] = :status AND r1.[[employeeId]] IS NOT NULL
LIMIT 100
SQL, [':status' => ReservationRecord::STATUS_CONFIRMED])->queryAll();

            if (empty($conflicts)) {
                $this->stdout("✓ No double-bookings found\n", Console::FG_GREEN);
                return ExitCode::OK;
            }

            $this->stderr("✗ Found " . count($conflicts) . " conflicts:\n\n", Console::FG_RED);
            $this->stdout(
                str_pad("ID1", 8) . str_pad("ID2", 8) . str_pad("Employee", 10) .
                str_pad("Date", 12) . str_pad("Slot 1", 15) . "Slot 2\n"
            );
            $this->stdout(str_repeat("-", 65) . "\n");

            foreach ($conflicts as $c) {
                $this->stdout(
                    str_pad((string)$c['id1'], 8) . str_pad((string)$c['id2'], 8) .
                    str_pad((string)$c['employeeId'], 10) . str_pad($c['bookingDate'], 12) .
                    str_pad("{$c['start1']}-{$c['end1']}", 15) . "{$c['start2']}-{$c['end2']}\n",
                    Console::FG_RED,
                );
            }

            return ExitCode::UNSPECIFIED_ERROR;
        } catch (\Throwable $e) {
            $this->stderr("✗ Error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /** @return int[] */
    private function seedLocations(int $siteId): array
    {
        $this->stdout("Creating locations...\n");
        $ids = [];

        foreach (self::TIMEZONES as $i => $tz) {
            $location = new Location();
            $location->title = self::TEST_PREFIX . " Location " . ($i + 1);
            $location->timezone = $tz;
            $location->siteId = $siteId;

            if (!Craft::$app->getElements()->saveElement($location)) {
                throw new \RuntimeException("Failed to save location: " . implode(', ', $location->getFirstErrors()));
            }
            $ids[] = $location->id;
        }

        $this->stdout("  ✓ " . count($ids) . " locations\n", Console::FG_GREEN);
        return $ids;
    }

    /** @return int[] */
    private function seedSchedules(int $siteId): array
    {
        $this->stdout("Creating schedules...\n");
        $ids = [];

        for ($i = 0; $i < 10; $i++) {
            $template = self::SCHEDULE_TEMPLATES[$i % count(self::SCHEDULE_TEMPLATES)];
            $weekdaysOnly = $i < 7;

            $workingHours = [];
            for ($day = 1; $day <= 7; $day++) {
                $workingHours[$day] = [
                    'enabled' => $weekdaysOnly ? $day < 6 : true,
                    'start' => $template['start'],
                    'end' => $template['end'],
                    'breakStart' => $template['breakStart'] ?? '',
                    'breakEnd' => $template['breakEnd'] ?? '',
                    'capacity' => 1,
                ];
            }

            $schedule = new Schedule();
            $schedule->title = self::TEST_PREFIX . " Schedule " . ($i + 1);
            $schedule->workingHours = $workingHours;
            $schedule->siteId = $siteId;

            if (!Craft::$app->getElements()->saveElement($schedule)) {
                throw new \RuntimeException("Failed to save schedule: " . implode(', ', $schedule->getFirstErrors()));
            }
            $ids[] = $schedule->id;
        }

        $this->stdout("  ✓ " . count($ids) . " schedules\n", Console::FG_GREEN);
        return $ids;
    }

    /** @return int[] */
    private function seedServices(int $siteId): array
    {
        $this->stdout("Creating services...\n");
        $ids = [];

        $durations = [30, 45, 60, 60, 90, 90, 120, 30, 45, 60, 60, 90, 120, 30, 60];
        $prices = [25, 40, 50, 75, 100, 80, 150, 35, 45, 60, 90, 120, 200, 30, 55];

        for ($i = 0; $i < 15; $i++) {
            $service = new Service();
            $service->title = self::TEST_PREFIX . " Service " . ($i + 1);
            $service->duration = $durations[$i];
            $service->price = (float)$prices[$i];
            $service->bufferBefore = $i % 3 === 0 ? 5 : 0;
            $service->bufferAfter = $i % 4 === 0 ? 10 : 0;
            $service->siteId = $siteId;

            if (!Craft::$app->getElements()->saveElement($service)) {
                throw new \RuntimeException("Failed to save service: " . implode(', ', $service->getFirstErrors()));
            }
            $ids[] = $service->id;
        }

        $this->stdout("  ✓ " . count($ids) . " services\n", Console::FG_GREEN);
        return $ids;
    }

    /** @return int[] */
    private function seedEmployees(int $siteId, array $locationIds, array $serviceIds, array $scheduleIds): array
    {
        $this->stdout("Creating employees...\n");
        $ids = [];
        $assignmentService = Booked::getInstance()->getScheduleAssignment();

        for ($i = 0; $i < 25; $i++) {
            $employee = new Employee();
            $employee->title = self::TEST_PREFIX . " Employee " . ($i + 1);
            $employee->email = "test-employee-{$i}@stress-test.local";
            $employee->locationId = $locationIds[$i % count($locationIds)];
            $employee->siteId = $siteId;

            $shuffledServices = $serviceIds;
            shuffle($shuffledServices);
            $employee->serviceIds = array_slice($shuffledServices, 0, rand(3, 5));

            if (!Craft::$app->getElements()->saveElement($employee)) {
                throw new \RuntimeException("Failed to save employee: " . implode(', ', $employee->getFirstErrors()));
            }

            $shuffledSchedules = $scheduleIds;
            shuffle($shuffledSchedules);
            for ($s = 0, $num = rand(1, 2); $s < $num; $s++) {
                $assignmentService->assignScheduleToEmployee($shuffledSchedules[$s], $employee->id, $s);
            }

            $ids[] = $employee->id;
        }

        $this->stdout("  ✓ " . count($ids) . " employees\n", Console::FG_GREEN);
        return $ids;
    }

    private function seedReservations(int $count, array $employeeIds, array $serviceIds, array $locationIds, int $siteId): int
    {
        $this->stdout("Creating {$count} reservations (batch insert)...\n");

        $db = Craft::$app->getDb();
        $columns = [
            'userName', 'userEmail', 'bookingDate', 'startTime', 'endTime',
            'status', 'confirmationToken', 'employeeId', 'serviceId',
            'locationId', 'siteId', 'quantity', 'notificationSent',
            'dateCreated', 'dateUpdated', 'uid',
        ];

        // 60% confirmed, ~25% cancelled, ~15% pending
        $statuses = array_merge(
            array_fill(0, 6, ReservationRecord::STATUS_CONFIRMED),
            array_fill(0, 3, ReservationRecord::STATUS_CANCELLED),
            [ReservationRecord::STATUS_PENDING],
        );

        $durations = [30, 45, 60, 60, 90, 90, 120, 30, 45, 60, 60, 90, 120, 30, 60];
        $serviceDurationMap = array_combine($serviceIds, array_map(fn($i) => $durations[$i] ?? 60, array_keys($serviceIds)));

        $slotStarts = [
            '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
            '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00',
        ];

        $now = new \DateTime();
        $nowStr = $now->format('Y-m-d H:i:s');
        $inserted = 0;
        $batch = [];
        $remaining = $count;

        $startDate = (clone $now)->modify('-90 days');
        $endDate = (clone $now)->modify('+90 days');
        $current = clone $startDate;

        while ($remaining > 0) {
            if ($current > $endDate) {
                $current = clone $startDate;
            }

            $dayOfWeek = (int)$current->format('N');
            $dateStr = $current->format('Y-m-d');

            $dayCount = min($remaining, match (true) {
                $dayOfWeek <= 2 => rand(50, 100),
                $dayOfWeek <= 5 => rand(10, 30),
                default => rand(5, 15),
            });

            for ($r = 0; $r < $dayCount; $r++) {
                $serviceId = $serviceIds[array_rand($serviceIds)];
                $startSlot = $slotStarts[array_rand($slotStarts)];

                $batch[] = [
                    self::TEST_PREFIX . " Customer {$inserted}",
                    "stress-test-{$inserted}@test.local",
                    $dateStr,
                    $startSlot,
                    date('H:i', strtotime($startSlot) + ($serviceDurationMap[$serviceId] * 60)),
                    $statuses[array_rand($statuses)],
                    bin2hex(random_bytes(32)),
                    $employeeIds[array_rand($employeeIds)],
                    $serviceId,
                    $locationIds[array_rand($locationIds)],
                    $siteId,
                    1,
                    true,
                    $nowStr,
                    $nowStr,
                    \craft\helpers\StringHelper::UUID(),
                ];

                $inserted++;
                $remaining--;

                if (count($batch) >= self::BATCH_SIZE) {
                    $db->createCommand()->batchInsert('{{%booked_reservations}}', $columns, $batch)->execute();
                    $batch = [];
                    $this->stdout("  ... {$inserted}/{$count}\r");
                }
            }

            $current->modify('+1 day');
        }

        if (!empty($batch)) {
            $db->createCommand()->batchInsert('{{%booked_reservations}}', $columns, $batch)->execute();
        }

        $this->stdout("  ✓ {$inserted} reservations\n", Console::FG_GREEN);
        return $inserted;
    }

    private function seedBlackoutDates(int $siteId, array $employeeIds, array $locationIds): int
    {
        $this->stdout("Creating blackout dates...\n");
        $count = 0;
        $now = new \DateTime();

        for ($i = 0; $i < 50; $i++) {
            $start = (clone $now)->modify(rand(-60, 60) . ' days');
            $end = (clone $start)->modify('+' . rand(1, 3) . ' days');

            $blackout = new BlackoutDate();
            $blackout->title = self::TEST_PREFIX . " Blackout " . ($i + 1);
            $blackout->startDate = $start->format('Y-m-d');
            $blackout->endDate = $end->format('Y-m-d');
            $blackout->siteId = $siteId;

            match ($i % 3) {
                0 => $blackout->employeeIds = [$employeeIds[array_rand($employeeIds)]],
                1 => $blackout->locationIds = [$locationIds[array_rand($locationIds)]],
                2 => (function() use ($blackout, $employeeIds, $locationIds) {
                    $blackout->employeeIds = [$employeeIds[array_rand($employeeIds)]];
                    $blackout->locationIds = [$locationIds[array_rand($locationIds)]];
                }
                )(),
            };

            if (!Craft::$app->getElements()->saveElement($blackout)) {
                $this->stderr("  Warning: Failed to save blackout {$i}: " . implode(', ', $blackout->getFirstErrors()) . "\n", Console::FG_YELLOW);
                continue;
            }
            $count++;
        }

        $this->stdout("  ✓ {$count} blackout dates\n", Console::FG_GREEN);
        return $count;
    }

    /** @return int[] */
    private function seedEventDates(int $siteId, array $locationIds): array
    {
        $this->stdout("Creating event dates...\n");
        $ids = [];
        $now = new \DateTime();

        $events = [
            ['title' => 'Yoga Workshop', 'duration' => 90, 'capacity' => 20, 'price' => 45.00],
            ['title' => 'Product Launch Webinar', 'duration' => 60, 'capacity' => 100, 'price' => null],
            ['title' => 'Team Building Session', 'duration' => 180, 'capacity' => 30, 'price' => 120.00],
            ['title' => 'First Aid Course', 'duration' => 240, 'capacity' => 15, 'price' => 89.50],
            ['title' => 'Photography Masterclass', 'duration' => 120, 'capacity' => 12, 'price' => 199.99],
            ['title' => 'Wine Tasting Evening', 'duration' => 150, 'capacity' => 25, 'price' => 75.00],
            ['title' => 'Cooking Class', 'duration' => 120, 'capacity' => 10, 'price' => 65.00],
            ['title' => 'Open Day Tour', 'duration' => 60, 'capacity' => null, 'price' => 0.0],
            ['title' => 'Tech Talk', 'duration' => 45, 'capacity' => 50, 'price' => null],
            ['title' => 'Meditation Session', 'duration' => 60, 'capacity' => 8, 'price' => 25.00],
        ];

        $startTimes = ['09:00', '10:00', '13:00', '14:00', '17:00', '18:30', '19:00'];

        foreach ($events as $i => $config) {
            $startTime = $startTimes[array_rand($startTimes)];

            $eventDate = new EventDate();
            $eventDate->title = self::TEST_PREFIX . ' ' . $config['title'];
            $eventDate->description = "Test event: {$config['title']}";
            $eventDate->eventDate = (clone $now)->modify('+' . rand(1, 60) . ' days')->format('Y-m-d');
            $eventDate->startTime = $startTime;
            $eventDate->endTime = date('H:i', strtotime($startTime) + ($config['duration'] * 60));
            $eventDate->capacity = $config['capacity'];
            $eventDate->price = $config['price'];
            $eventDate->siteId = $siteId;

            if ($i % 10 < 7) {
                $eventDate->locationId = $locationIds[array_rand($locationIds)];
            }

            if (!Craft::$app->getElements()->saveElement($eventDate)) {
                $this->stderr("  Warning: Failed to save event date {$i}: " . implode(', ', $eventDate->getFirstErrors()) . "\n", Console::FG_YELLOW);
                continue;
            }
            $ids[] = $eventDate->id;
        }

        $this->stdout("  ✓ " . count($ids) . " event dates\n", Console::FG_GREEN);
        return $ids;
    }

    private function seedEventReservations(int $siteId, array $eventDateIds, array $locationIds): int
    {
        $this->stdout("Creating event reservations...\n");

        $db = Craft::$app->getDb();
        $columns = [
            'userName', 'userEmail', 'bookingDate', 'startTime', 'endTime',
            'status', 'confirmationToken', 'employeeId', 'serviceId',
            'locationId', 'siteId', 'quantity', 'notificationSent',
            'eventDateId', 'dateCreated', 'dateUpdated', 'uid',
        ];

        $firstNames = ['Anna', 'Ben', 'Clara', 'David', 'Elena', 'Felix', 'Giulia', 'Hans', 'Iris', 'Jan',
            'Klara', 'Luca', 'Mia', 'Nils', 'Olivia', 'Peter', 'Rosa', 'Stefan', 'Tina', 'Uwe', ];
        $lastNames = ['Müller', 'Schmidt', 'Weber', 'Fischer', 'Wagner', 'Becker', 'Meier', 'Hoffmann',
            'Schulz', 'Koch', 'Richter', 'Wolf', 'Klein', 'Neumann', 'Schwarz', ];

        // ~67% confirmed, ~17% cancelled, ~17% pending
        $statuses = array_merge(
            array_fill(0, 4, ReservationRecord::STATUS_CONFIRMED),
            [ReservationRecord::STATUS_CANCELLED, ReservationRecord::STATUS_PENDING],
        );

        $nowStr = (new \DateTime())->format('Y-m-d H:i:s');
        $batch = [];
        $inserted = 0;

        $eventDates = EventDate::find()->siteId('*')->id($eventDateIds)->all();

        foreach ($eventDates as $eventDate) {
            for ($i = 0, $n = rand(3, 8); $i < $n; $i++) {
                $firstName = $firstNames[array_rand($firstNames)];
                $lastName = $lastNames[array_rand($lastNames)];

                $batch[] = [
                    self::TEST_PREFIX . " {$firstName} {$lastName}",
                    strtolower($firstName) . '.' . strtolower($lastName) . rand(1, 99) . '@test.local',
                    $eventDate->eventDate,
                    $eventDate->startTime,
                    $eventDate->endTime,
                    $statuses[array_rand($statuses)],
                    bin2hex(random_bytes(32)),
                    null,
                    null,
                    $eventDate->locationId ?? $locationIds[array_rand($locationIds)],
                    $siteId,
                    rand(1, 3),
                    true,
                    $eventDate->id,
                    $nowStr,
                    $nowStr,
                    \craft\helpers\StringHelper::UUID(),
                ];
                $inserted++;
            }
        }

        if (!empty($batch)) {
            $db->createCommand()->batchInsert('{{%booked_reservations}}', $columns, $batch)->execute();
        }

        $this->stdout("  ✓ {$inserted} event reservations\n", Console::FG_GREEN);
        return $inserted;
    }

    public function actionBenchmark(int $iterations = 5): int
    {
        $this->stdout("Benchmarking availability queries...\n\n");

        $serviceIds = $this->getTestElementIds(Service::class);
        $employeeIds = $this->getTestElementIds(Employee::class);

        if (empty($serviceIds)) {
            $this->stderr("No test data found. Run `php craft booked/test/seed` first.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $availability = Booked::getInstance()->getAvailability();
        $db = Craft::$app->getDb();
        $db->enableLogging = true;

        $results = [];

        for ($i = 0; $i < $iterations; $i++) {
            $serviceId = $serviceIds[array_rand($serviceIds)];
            $employeeId = (mt_rand(0, 1) === 1 && !empty($employeeIds)) ? $employeeIds[array_rand($employeeIds)] : null;
            $date = (new \DateTime())->modify('+' . mt_rand(1, 30) . ' days')->format('Y-m-d');

            $logger = Craft::getLogger();
            $logger->flush(true);

            $start = microtime(true);
            $slots = $availability->getAvailableSlots($date, $employeeId, null, $serviceId);
            $elapsed = (microtime(true) - $start) * 1000;

            $queryCount = $softLockQueries = $blackoutQueries = 0;
            foreach ($logger->messages as $message) {
                if (($message[2] ?? '') !== 'yii\\db\\Command::query' || ($message[1] ?? 0) !== \yii\log\Logger::LEVEL_PROFILE_BEGIN) {
                    continue;
                }
                $queryCount++;
                $sql = (string)($message[0] ?? '');
                if (str_contains($sql, 'soft_lock')) {
                    $softLockQueries++;
                }
                if (str_contains($sql, 'blackout')) {
                    $blackoutQueries++;
                }
            }

            $results[] = ['queries' => $queryCount, 'timeMs' => round($elapsed, 1)];

            $this->stdout(sprintf(
                "  [%d/%d] %s svc=%d %s => %d slots, %d queries (softlock=%d, blackout=%d), %.1fms\n",
                $i + 1, $iterations, $date, $serviceId,
                $employeeId ? "emp={$employeeId}" : 'any-employee',
                count($slots), $queryCount, $softLockQueries, $blackoutQueries, $elapsed,
            ));
        }

        $queryCol = array_column($results, 'queries');
        $timeCol = array_column($results, 'timeMs');

        $this->stdout("\n─────────────────────────────────\n");
        $this->stdout("Summary ({$iterations} iterations):\n", Console::FG_GREEN);
        $this->stdout(sprintf("  Avg queries: %.1f\n  Max queries: %d\n  Avg time:    %.1fms\n  Max time:    %.1fms\n",
            array_sum($queryCol) / count($queryCol), max($queryCol),
            array_sum($timeCol) / count($timeCol), max($timeCol),
        ));
        $this->stdout("─────────────────────────────────\n");

        return ExitCode::OK;
    }

    public function actionSecurity(): int
    {
        $passed = 0;
        $failed = 0;

        $test = function(string $name, callable $fn) use (&$passed, &$failed): void {
            try {
                $result = $fn();
                if ($result === true) {
                    $passed++;
                    $this->stdout("  ✓ {$name}\n", Console::FG_GREEN);
                } else {
                    $failed++;
                    $this->stdout("  ✗ {$name}" . (is_string($result) ? " — {$result}" : '') . "\n", Console::FG_RED);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->stdout("  ✗ {$name} — " . $e->getMessage() . "\n", Console::FG_RED);
            }
        };

        $this->stdout("\nSecurity & Validation Tests\n", Console::BOLD);
        $this->stdout("═══════════════════════════════════\n\n");

        $settings = Settings::loadSettings();

        // 1. IDOR Prevention
        $this->stdout("1. IDOR Prevention\n", Console::BOLD);
        $booking = ReservationFactory::find()->status('confirmed')->one();

        if (!$booking) {
            $this->stdout("  ⚠ No confirmed bookings — skipping IDOR tests\n\n", Console::FG_YELLOW);
        } else {
            $realToken = $booking->confirmationToken;
            $bookingId = $booking->getId();

            $test("Cancel with invalid token rejected", fn() => ReservationFactory::findByToken('invalid-token-guess-12345') === null ?: "Found reservation with fake token!");
            $test("Cancel with empty token rejected", fn() => ReservationFactory::findByToken('') === null ?: "Found reservation with empty token!");
            $test("Valid token finds correct booking", fn() => (($r = ReservationFactory::findByToken($realToken)) && $r->getId() === $bookingId) ? true : "Could not find booking with valid token");
            $test("Guessed token returns null", fn() => ReservationFactory::findByToken('aaaa' . str_repeat('b', 60)) === null ?: "Found reservation with guessed token!");
        }
        $this->stdout("\n");

        // 2. Token Strength
        $this->stdout("2. Confirmation Token Strength\n", Console::BOLD);
        $tokens = array_map(fn($b) => $b->confirmationToken, ReservationFactory::find()->limit(20)->all());

        $test("Tokens are 64+ characters", function() use ($tokens) {
            foreach ($tokens as $t) {
                if (strlen($t) < 64) {
                    return "Token too short: " . strlen($t) . " chars";
                }
            }
            return true;
        });
        $test("Tokens are unique", fn() => count($tokens) === count(array_unique($tokens)) ?: "Duplicate tokens found!");
        $test("Tokens are hex (cryptographically random)", function() use ($tokens) {
            foreach ($tokens as $t) {
                if (!preg_match('/^[a-f0-9]+$/i', $t)) {
                    return "Non-hex token found";
                }
            }
            return true;
        });
        $test("Tokens are not sequential", function() use ($tokens) {
            if (count($tokens) < 2) {
                return true;
            }
            sort($tokens);
            for ($i = 1; $i < count($tokens); $i++) {
                $common = 0;
                $len = min(strlen($tokens[$i - 1]), strlen($tokens[$i]));
                for ($j = 0; $j < $len && $tokens[$i - 1][$j] === $tokens[$i][$j]; $j++) {
                    $common++;
                }
                if ($common > 10) {
                    return "Tokens share {$common}-char prefix";
                }
            }
            return true;
        });
        $this->stdout("\n");

        // 3. Input Sanitization
        $this->stdout("3. Input Sanitization\n", Console::BOLD);
        $test("XSS payload stored as plain text", function() {
            $xss = '<script>alert("XSS")</script>';
            $m = new ReservationModel();
            $m->userName = $xss;
            return $m['userName'] === $xss ? true : "XSS payload modified";
        });
        $test("SQL injection payload stored as literal text", function() {
            $sql = "test@test.com'; DROP TABLE bookings;--";
            $m = new ReservationModel();
            $m->userEmail = $sql;
            return $m['userEmail'] === $sql ? true : "SQL payload modified";
        });
        $test("Unicode characters preserved", function() {
            $m = new ReservationModel();
            $m->userName = 'José García-López 李明';
            return $m['userName'] === 'José García-López 李明' ? true : "Unicode corrupted";
        });
        $test("Special symbols preserved", function() {
            $m = new ReservationModel();
            $m->userName = 'Meeting @ 3pm & discuss €pricing';
            $name = $m['userName'];
            return is_string($name) && str_contains($name, '€') && str_contains($name, '&') ? true : "Symbols corrupted";
        });
        $this->stdout("\n");

        // 4. CSRF
        $this->stdout("4. CSRF Protection\n", Console::BOLD);
        $test("CSRF validation is enabled", fn() => Craft::$app->getConfig()->getGeneral()->enableCsrfProtection ?: "CSRF disabled!");
        $test("POST without CSRF token returns 400", function() {
            $ch = curl_init('http://localhost/actions/booked/booking-management/cancel-booking');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'id=1&token=fake', CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code === 400 ?: "Expected 400, got {$code}";
        });
        $this->stdout("\n");

        // 5. Booking Validation
        $this->stdout("5. Booking Validation\n", Console::BOLD);
        $service = Service::find()->siteId('*')->one();

        if (!$service) {
            $this->stdout("  ⚠ No services found — skipping\n\n", Console::FG_YELLOW);
        } else {
            $futureDate = (new \DateTime('+3 days'))->format('Y-m-d');

            $makeModel = function(array $overrides = []) use ($service, $futureDate): ReservationModel {
                $m = new ReservationModel();
                $m->userName = $overrides['userName'] ?? 'Test User';
                $m->userEmail = $overrides['userEmail'] ?? 'test@example.com';
                $m->serviceId = $service->id;
                $m->bookingDate = $overrides['bookingDate'] ?? $futureDate;
                $m->startTime = $overrides['startTime'] ?? '10:00';
                $m->endTime = $overrides['endTime'] ?? '11:00';
                foreach ($overrides as $k => $v) {
                    if (!in_array($k, ['userName', 'userEmail', 'bookingDate', 'startTime', 'endTime'])) {
                        $m->$k = $v;
                    }
                }
                return $m;
            };

            $test("Missing customer name rejected", fn() => !$makeModel(['userName' => ''])->validate() ?: "Passed with empty name!");
            $test("Missing customer email rejected", fn() => !$makeModel(['userEmail' => ''])->validate() ?: "Passed with empty email!");
            $test("Invalid email 'notanemail' rejected", fn() => !$makeModel(['userEmail' => 'notanemail'])->validate() ?: "Passed with 'notanemail'!");
            $test("Invalid email 'test@' rejected", fn() => !$makeModel(['userEmail' => 'test@'])->validate() ?: "Passed with 'test@'!");
            $test("Valid email 'valid@example.com' accepted", fn() => $makeModel([
                'userEmail' => 'valid@example.com', 'confirmationToken' => bin2hex(random_bytes(32)),
                'status' => 'confirmed', 'quantity' => 1,
            ])->validate() ?: "Rejected valid booking");
            $test("Missing booking date rejected", fn() => !$makeModel(['bookingDate' => ''])->validate() ?: "Passed with empty date!");
            $test("Missing time slot rejected", fn() => !$makeModel(['startTime' => '', 'endTime' => ''])->validate() ?: "Passed with empty time!");
        }
        $this->stdout("\n");

        // 6. Advance Booking
        $this->stdout("6. Advance Booking & Date Limits\n", Console::BOLD);
        if ($service) {
            $avail = Booked::getInstance()->availability;

            $test("Past date returns no slots", fn() => empty($avail->getAvailableSlots((new \DateTime('-1 day'))->format('Y-m-d'), null, null, $service->id)) ?: "Got slots for yesterday!");
            $test("Beyond max advance booking returns no slots", function() use ($avail, $service, $settings) {
                $tooFar = (new \DateTime('+' . (($settings->maxAdvanceBookingDays ?? 90) + 1) . ' days'))->format('Y-m-d');
                return empty($avail->getAvailableSlots($tooFar, null, null, $service->id)) ?: "Got slots beyond limit!";
            });
            $test("Date within window returns array (no error)", function() use ($avail, $service) {
                $date = new \DateTime('+3 days');
                while (in_array($date->format('N'), ['6', '7'])) {
                    $date->modify('+1 day');
                }
                return is_array($avail->getAvailableSlots($date->format('Y-m-d'), null, null, $service->id)) ?: "Didn't return array";
            });
        }
        $this->stdout("\n");

        // 7. Cancel Policy
        $this->stdout("7. Cancel Policy Enforcement\n", Console::BOLD);
        $test("Future booking (30d) is cancellable", function() {
            $m = new ReservationModel();
            $m->status = 'confirmed';
            $m->bookingDate = (new \DateTime('+30 days'))->format('Y-m-d');
            $m->startTime = '10:00:00';
            return $m->canBeCancelled() ?: "30-day-future booking should be cancellable";
        });
        $test("Imminent booking NOT cancellable (within policy)", function() use ($settings) {
            if (($settings->cancellationPolicyHours ?? 24) <= 0) {
                return true;
            }
            $m = new ReservationModel();
            $m->status = 'confirmed';
            $m->bookingDate = (new \DateTime())->format('Y-m-d');
            $m->startTime = (new \DateTime('+30 minutes'))->format('H:i:s');
            return !$m->canBeCancelled() ?: "Should NOT be cancellable";
        });
        $test("Already cancelled booking cannot be cancelled", function() {
            $m = new ReservationModel();
            $m->status = 'cancelled';
            $m->bookingDate = (new \DateTime('+30 days'))->format('Y-m-d');
            $m->startTime = '10:00:00';
            return !$m->canBeCancelled() ?: "Cancelled booking should not be cancellable";
        });
        if ($booking) {
            $test("Real booking respects cancel policy", function() use ($booking, $settings) {
                $result = $booking->canBeCancelled();
                $hoursUntil = ((new \DateTime($booking->bookingDate . ' ' . $booking->startTime))->getTimestamp() - time()) / 3600;
                $cancelHours = $settings->cancellationPolicyHours ?? 24;
                return ($hoursUntil < $cancelHours) ? (!$result ?: "Within window but cancellable!") : ($result ?: "Outside window but not cancellable!");
            });
        }
        $this->stdout("\n");

        // 8. Rate Limiting
        $this->stdout("8. Rate Limiting\n", Console::BOLD);
        $validation = Booked::getInstance()->bookingValidation;
        $test("BookingValidationService available", fn() => $validation !== null ?: "Service not available");
        if ($validation) {
            foreach (['checkEmailRateLimit', 'checkIpRateLimit'] as $method) {
                if (method_exists($validation, $method)) {
                    $test(str_replace('check', '', str_replace('RateLimit', '', $method)) . " rate limit method callable", fn() => is_callable([$validation, $method]) ?: "Not callable");
                }
            }
        }
        $this->stdout("\n");

        // 9. Email Templates
        $this->stdout("9. Email Template Rendering\n", Console::BOLD);
        $emailRender = Booked::getInstance()->emailRender;

        if ($booking && $emailRender) {
            foreach ([
                'Confirmation' => 'renderConfirmationEmail',
                'StatusChange' => 'renderStatusChangeEmail',
                'Cancellation' => 'renderCancellationEmail',
                'Reminder' => 'renderReminderEmail',
                'OwnerNotification' => 'renderOwnerNotificationEmail',
            ] as $label => $method) {
                $test("{$label} email renders", function() use ($emailRender, $booking, $settings, $method, $label) {
                    if (!method_exists($emailRender, $method)) {
                        return "Method {$method} not found";
                    }
                    $html = match ($label) {
                        'Reminder' => $emailRender->$method($booking, $settings, 24),
                        'StatusChange' => $emailRender->$method($booking, 'pending', $settings),
                        default => $emailRender->$method($booking, $settings),
                    };
                    return (is_string($html) && strlen($html) > 100) ?: "Output too short or invalid";
                });
            }

            $test("WaitlistNotification email renders", function() use ($emailRender, $settings) {
                $entry = new WaitlistRecord();
                $entry->userName = 'Test User';
                $entry->userEmail = 'test@example.com';
                $entry->preferredDate = (new \DateTime('+3 days'))->format('Y-m-d');
                $entry->preferredTimeStart = '10:00';
                $entry->preferredTimeEnd = '11:00';
                $entry->status = 'active';
                if ($svc = Service::find()->siteId('*')->one()) {
                    $entry->serviceId = $svc->id;
                }
                $html = $emailRender->renderWaitlistNotificationEmail($entry, $settings);
                return (is_string($html) && strlen($html) > 100) ?: "Output too short";
            });
        }
        $this->stdout("\n");

        $this->stdout("═══════════════════════════════════\n");
        $this->stdout("Result: {$passed} passed", Console::FG_GREEN);
        if ($failed > 0) {
            $this->stdout(", {$failed} failed", Console::FG_RED);
        }
        $this->stdout(" (" . ($passed + $failed) . " total)\n");

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /** @param class-string<\craft\base\Element> $elementClass */
    private function getTestElementIds(string $elementClass): array
    {
        return $elementClass::find()->siteId('*')->title(self::TEST_PREFIX . '*')->ids();
    }
}
