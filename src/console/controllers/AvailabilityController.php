<?php

namespace anvildev\booked\console\controllers;

use anvildev\booked\Booked;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\records\ReservationRecord;
use anvildev\booked\services\ScheduleResolverService;
use anvildev\booked\services\SlotGeneratorService;
use anvildev\booked\services\TimeWindowService;
use craft\console\Controller;
use DateTime;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Availability debugging commands
 *
 * Visualizes the subtractive availability model step by step to help
 * diagnose "why can't I book at 2pm?" questions.
 */
class AvailabilityController extends Controller
{
    public ?int $service = null;
    public ?string $date = null;
    public ?int $employee = null;
    public ?int $location = null;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'service', 'date', 'employee', 'location'];
    }

    public function actionCheck(): int
    {
        if ($this->service === null) {
            $this->stderr("--service is required. Usage: php craft booked/availability/check --service=5 --date=2026-03-01\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $date = $this->date ?? (new DateTime())->format('Y-m-d');
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObj) {
            $this->stderr("Invalid date format: {$date}. Use Y-m-d.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $dayOfWeek = (int)$dateObj->format('N');
        $dayName = $dateObj->format('l');

        $serviceEl = Service::find()->siteId('*')->id($this->service)->one();
        if (!$serviceEl) {
            $this->stderr("Service #{$this->service} not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $employeeEl = null;
        if ($this->employee !== null) {
            $employeeEl = Employee::find()->siteId('*')->id($this->employee)->one();
            if (!$employeeEl) {
                $this->stderr("Employee #{$this->employee} not found.\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $locationEl = null;
        if ($this->location !== null) {
            $locationEl = Location::find()->siteId('*')->id($this->location)->one();
            if (!$locationEl) {
                $this->stderr("Location #{$this->location} not found.\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $this->stdout("\nAvailability Check\n", Console::BOLD);
        $this->stdout("═══════════════════════════════════\n\n");
        $this->stdout("Service:  ", Console::BOLD);
        $this->stdout("{$serviceEl->title} (#{$serviceEl->id})\n");
        $this->stdout("Date:     ", Console::BOLD);
        $this->stdout("{$date} ({$dayName})\n");
        $this->stdout("Duration: ", Console::BOLD);
        $this->stdout(($serviceEl->duration ?? 60) . " min\n");

        if ($serviceEl->bufferBefore || $serviceEl->bufferAfter) {
            $parts = array_filter([
                $serviceEl->bufferBefore ? "{$serviceEl->bufferBefore} min before" : null,
                $serviceEl->bufferAfter ? "{$serviceEl->bufferAfter} min after" : null,
            ]);
            $this->stdout("Buffers:  ", Console::BOLD);
            $this->stdout(implode(', ', $parts) . "\n");
        }

        if ($employeeEl) {
            $user = $employeeEl->getUser();
            $this->stdout("Employee: ", Console::BOLD);
            $this->stdout(($user ? $user->getName() : ($employeeEl->title ?? '')) . " (#{$employeeEl->id})\n");
        }

        if ($locationEl) {
            $this->stdout("Location: ", Console::BOLD);
            $this->stdout("{$locationEl->title} (#{$locationEl->id})\n");
        }

        $this->stdout("\n");

        $scheduleResolver = new ScheduleResolverService();
        $timeWindowService = new TimeWindowService();
        $slotGenerator = new SlotGeneratorService();

        $duration = $serviceEl->duration ?? 60;
        $slotInterval = $slotGenerator->getSlotInterval($serviceEl, $duration);

        $this->heading('1. Working Hours');

        $schedules = $scheduleResolver->getWorkingHours($dayOfWeek, $this->employee, $this->location, $this->service, $date);
        $useServiceSchedule = empty($schedules) && $serviceEl->hasAvailabilitySchedule();

        if ($useServiceSchedule) {
            $availability = $scheduleResolver->getServiceAvailability($serviceEl, $date, $dayOfWeek);
            if ($availability !== null) {
                $timeWindows = $scheduleResolver->buildWindowsFromServiceAvailability($availability);
                $this->stdout("  Source: Service-level schedule\n", Console::FG_CYAN);
                $this->printWindows($timeWindows);
                $this->runServiceSchedulePath($serviceEl, $date, $dayOfWeek, $timeWindows, $duration, $slotInterval, $scheduleResolver, $timeWindowService, $slotGenerator);
            } else {
                $this->stdout("  Service schedule exists but has no hours for {$dayName}\n", Console::FG_YELLOW);
                $this->noSlots();
            }
        } elseif (!empty($schedules)) {
            $byEmployee = [];
            foreach ($schedules as $s) {
                $byEmployee[$s->employeeId][] = $s;
            }

            $this->stdout("  Source: Employee schedules (" . count($byEmployee) . " employee" . (count($byEmployee) !== 1 ? 's' : '') . ")\n", Console::FG_CYAN);

            foreach ($byEmployee as $empId => $empSchedules) {
                $emp = Employee::find()->siteId('*')->id($empId)->one();
                $empUser = $emp?->getUser();
                $empName = $empUser ? $empUser->getName() : ($emp->title ?? "Employee #{$empId}");

                $this->stdout("\n  {$empName} (#{$empId}):\n", Console::BOLD);

                $empWindows = $timeWindowService->mergeWindows(
                    array_map(fn($s) => ['start' => $s->startTime, 'end' => $s->endTime, 'locationId' => $s->locationId], $empSchedules)
                );
                $this->printWindows($empWindows, '    ');

                // Blackouts
                $this->heading('2. Blackouts', '  ');
                if (isset($scheduleResolver->getBlackedOutEmployeeIds($date, [$empId], $this->location)[$empId])) {
                    $this->stdout("    ✗ Employee is blacked out on {$date}\n", Console::FG_RED);
                    continue;
                }
                $this->stdout("    ✓ No blackouts\n", Console::FG_GREEN);

                // Existing Bookings
                $this->heading('3. Existing Bookings', '  ');
                $bookings = ReservationFactory::find()
                    ->siteId('*')
                    ->bookingDate($date)
                    ->employeeId($empId)
                    ->status(['not', ReservationRecord::STATUS_CANCELLED])
                    ->all();

                if (empty($bookings)) {
                    $this->stdout("    ✓ No bookings\n", Console::FG_GREEN);
                } else {
                    $serviceIds = array_unique(array_filter(array_map(fn($b) => $b->serviceId, $bookings)));
                    $servicesById = !empty($serviceIds) ? Service::find()->siteId('*')->id($serviceIds)->indexBy('id')->all() : [];

                    foreach ($bookings as $booking) {
                        $bService = $booking->serviceId ? ($servicesById[$booking->serviceId] ?? null) : null;
                        $bBefore = $bService->bufferBefore ?? 0;
                        $bAfter = $bService->bufferAfter ?? 0;
                        $blockedStart = $timeWindowService->addMinutes($booking->startTime, -$bBefore);
                        $blockedEnd = $timeWindowService->addMinutes($booking->endTime, $bAfter);

                        $line = "    {$booking->startTime}–{$booking->endTime}";
                        if ($bBefore > 0 || $bAfter > 0) {
                            $line .= "  (blocks {$blockedStart}–{$blockedEnd} with buffers)";
                        }
                        if ($bService?->title) {
                            $line .= "  [{$bService->title}]";
                        }
                        $this->stdout($line . "\n", Console::FG_RED);
                    }

                    foreach ($bookings as $booking) {
                        $bService = $booking->serviceId ? ($servicesById[$booking->serviceId] ?? null) : null;
                        $blockedStart = $timeWindowService->addMinutes($booking->startTime, -($bService->bufferBefore ?? 0));
                        $blockedEnd = $timeWindowService->addMinutes($booking->endTime, $bService->bufferAfter ?? 0);
                        $empWindows = $timeWindowService->subtractWindow($empWindows, $blockedStart, $blockedEnd);
                    }
                }

                // Soft Locks
                $this->heading('4. Soft Locks', '  ');
                $empLocks = array_filter(
                    Booked::getInstance()->getSoftLock()->getActiveSoftLocksForDate($date, $this->service),
                    fn($l) => $l->employeeId === $empId || $l->employeeId === null,
                );
                if (empty($empLocks)) {
                    $this->stdout("    ✓ No active soft locks\n", Console::FG_GREEN);
                } else {
                    foreach ($empLocks as $lock) {
                        $this->stdout("    {$lock->startTime}–{$lock->endTime}  (expires {$lock->expiresAt})\n", Console::FG_YELLOW);
                    }
                }

                // Available Windows
                $this->heading('5. Available Windows', '  ');
                empty($empWindows)
                    ? $this->stdout("    No available time remaining\n", Console::FG_YELLOW)
                    : $this->printWindows($empWindows, '    ');

                // Generated Slots
                $this->heading('6. Generated Slots', '  ');
                $empSlots = $slotGenerator->generateSlots(
                    $empWindows, $duration, $slotInterval,
                    ['serviceId' => $this->service, 'locationId' => $this->location ?? $emp->locationId],
                );
                empty($empSlots)
                    ? $this->stdout("    No slots generated\n", Console::FG_YELLOW)
                    : $this->printSlots($empSlots, '    ');
            }
        } else {
            $this->stdout("  No working hours found for {$dayName}\n", Console::FG_YELLOW);
            $this->noSlots();
            return ExitCode::OK;
        }

        // Final result
        $this->stdout("\n");
        $this->heading('Final Result (via AvailabilityService)');
        $finalSlots = Booked::getInstance()->getAvailability()->getAvailableSlots($date, $this->employee, $this->location, $this->service);

        if (empty($finalSlots)) {
            $this->stdout("  No available slots\n", Console::FG_YELLOW);
        } else {
            $this->printSlots($finalSlots, '  ');
            $this->stdout("\n  Total: " . count($finalSlots) . " slot" . (count($finalSlots) !== 1 ? 's' : '') . "\n", Console::FG_GREEN);
        }

        $this->stdout("\n═══════════════════════════════════\n");
        return ExitCode::OK;
    }

    private function runServiceSchedulePath(
        Service $serviceEl, string $date, int $dayOfWeek, array $timeWindows,
        int $duration, int $slotInterval,
        ScheduleResolverService $scheduleResolver, TimeWindowService $timeWindowService, SlotGeneratorService $slotGenerator,
    ): void {
        // Blackouts
        $this->heading('2. Blackouts');
        if ($scheduleResolver->isDateBlackedOut($date, $this->employee, $this->location)) {
            $this->stdout("  ✗ Date is blacked out\n", Console::FG_RED);
            $this->noSlots();
            return;
        }
        $this->stdout("  ✓ No blackouts\n", Console::FG_GREEN);

        // Bookings
        $this->heading('3. Existing Bookings');
        $bookings = ReservationFactory::find()
            ->siteId('*')->bookingDate($date)->serviceId($this->service)
            ->status(['not', ReservationRecord::STATUS_CANCELLED])->all();

        if ($this->employee !== null) {
            $bookings = array_values(array_filter($bookings, fn($b) => $b->employeeId === $this->employee || $b->employeeId === null));
        }

        if (empty($bookings)) {
            $this->stdout("  ✓ No bookings\n", Console::FG_GREEN);
        } else {
            $bBefore = $serviceEl->bufferBefore ?? 0;
            $bAfter = $serviceEl->bufferAfter ?? 0;

            foreach ($bookings as $booking) {
                $blockedStart = $timeWindowService->addMinutes($booking->startTime, -$bBefore);
                $blockedEnd = $timeWindowService->addMinutes($booking->endTime, $bAfter);

                $line = "  {$booking->startTime}–{$booking->endTime}";
                if ($bBefore > 0 || $bAfter > 0) {
                    $line .= "  (blocks {$blockedStart}–{$blockedEnd} with buffers)";
                }
                $this->stdout($line . "\n", Console::FG_RED);

                $timeWindows = $timeWindowService->subtractWindow($timeWindows, $blockedStart, $blockedEnd);
            }
        }

        // Soft Locks
        $this->heading('4. Soft Locks');
        $locks = Booked::getInstance()->getSoftLock()->getActiveSoftLocksForDate($date, $this->service);
        if (empty($locks)) {
            $this->stdout("  ✓ No active soft locks\n", Console::FG_GREEN);
        } else {
            foreach ($locks as $lock) {
                $this->stdout("  {$lock->startTime}–{$lock->endTime}  (expires {$lock->expiresAt})\n", Console::FG_YELLOW);
            }
        }

        // Available Windows
        $this->heading('5. Available Windows');
        empty($timeWindows)
            ? $this->stdout("  No available time remaining\n", Console::FG_YELLOW)
            : $this->printWindows($timeWindows);

        // Generated Slots
        $this->heading('6. Generated Slots');
        $slots = $slotGenerator->generateSlots(
            $timeWindows, $duration, $slotInterval,
            ['serviceId' => $this->service, 'locationId' => $this->location],
        );
        empty($slots)
            ? $this->stdout("  No slots generated\n", Console::FG_YELLOW)
            : $this->printSlots($slots);
    }

    private function heading(string $label, string $indent = ''): void
    {
        $this->stdout("\n{$indent}{$label}\n", Console::BOLD);
    }

    private function noSlots(): void
    {
        $this->stdout("\n");
        $this->heading('Final Result');
        $this->stdout("  No available slots\n", Console::FG_YELLOW);
        $this->stdout("\n═══════════════════════════════════\n");
    }

    private function printWindows(array $windows, string $indent = '  '): void
    {
        if (empty($windows)) {
            $this->stdout("{$indent}(none)\n", Console::FG_YELLOW);
            return;
        }

        foreach ($windows as $w) {
            $this->stdout("{$indent}" . ($w['start'] ?? '??') . '–' . ($w['end'] ?? '??') . "\n", Console::FG_GREEN);
        }
    }

    private function printSlots(array $slots, string $indent = '  '): void
    {
        foreach (array_chunk(array_map(fn($s) => $s['time'] ?? '??', $slots), 8) as $chunk) {
            $this->stdout($indent . implode('  ', $chunk) . "\n", Console::FG_CYAN);
        }
    }
}
