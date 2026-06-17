<?php

namespace anvildev\booked\mcp;

use anvildev\booked\Booked;
use anvildev\booked\elements\Schedule;
use anvildev\booked\mcp\support\Presenter;
use Craft;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * MCP tools for availability schedules and their assignment to employees.
 *
 * A schedule holds a weekly working-hours pattern (optionally bounded by a
 * date range) and can be shared across employees/services. Working hours are a
 * map keyed "1"(Mon)–"7"(Sun), each `{enabled, start, end, breakStart?,
 * breakEnd?}`; a per-day `capacity` may also be set.
 */
class ScheduleTools
{
    use ToolResponseTrait;

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_list_schedules',
        description: 'List Booked availability schedules (weekly working-hours patterns) with their date bounds.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function listSchedules(int $limit = 50): array
    {
        return $this->guard(function() use ($limit): array {
            $schedules = Schedule::find()->siteId('*')->status(null)->limit($this->clampLimit($limit))->all();

            return [
                'count' => count($schedules),
                'schedules' => array_map(static fn(Schedule $s) => Presenter::schedule($s), $schedules),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_get_schedule',
        description: 'Get a single Booked schedule by id, including its working-hours map.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function getSchedule(int $id): array
    {
        return $this->guard(function() use ($id): array {
            $schedule = Schedule::find()->siteId('*')->status(null)->id($id)->one();
            if (!$schedule instanceof Schedule) {
                return ['error' => "Schedule #{$id} not found."];
            }

            return ['schedule' => Presenter::schedule($schedule)];
        });
    }

    /**
     * Create an availability schedule.
     *
     * @param string $title Schedule name (required).
     * @param array<string, mixed>|null $workingHours Per-day map keyed "1"(Mon)–"7"(Sun), each {enabled, start, end, breakStart?, breakEnd?}.
     * @param string|null $startDate Date the schedule starts applying, Y-m-d (null = always).
     * @param string|null $endDate Date the schedule stops applying, Y-m-d (null = open-ended).
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_create_schedule',
        description: 'Create a Booked availability schedule (weekly working-hours pattern). Returns the created schedule.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function createSchedule(
        string $title,
        #[Schema(type: 'object')] ?array $workingHours = null,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        return $this->guardWrite(function() use ($title, $workingHours, $startDate, $endDate): array {
            $schedule = new Schedule();
            $schedule->title = $title;
            $schedule->workingHours = $workingHours ?? [];
            $schedule->startDate = $startDate;
            $schedule->endDate = $endDate;

            if (!Craft::$app->getElements()->saveElement($schedule)) {
                return ['error' => 'Failed to create schedule.', 'validationErrors' => $schedule->getErrors()];
            }

            return ['success' => true, 'schedule' => Presenter::schedule($schedule)];
        });
    }

    /**
     * Update a schedule. Only provided (non-null) fields change.
     *
     * @param array<string, mixed>|null $workingHours Replacement working-hours map.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_update_schedule',
        description: 'Update a Booked schedule by id. Only the fields you pass are changed.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function updateSchedule(
        int $id,
        ?string $title = null,
        #[Schema(type: 'object')] ?array $workingHours = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?bool $enabled = null,
    ): array {
        return $this->guardWrite(function() use ($id, $title, $workingHours, $startDate, $endDate, $enabled): array {
            $schedule = Schedule::find()->siteId('*')->status(null)->id($id)->one();
            if (!$schedule instanceof Schedule) {
                return ['error' => "Schedule #{$id} not found."];
            }

            foreach ([
                'title' => $title,
                'workingHours' => $workingHours,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'enabled' => $enabled,
            ] as $field => $value) {
                if ($value !== null) {
                    $schedule->$field = $value;
                }
            }

            if (!Craft::$app->getElements()->saveElement($schedule)) {
                return ['error' => 'Failed to update schedule.', 'validationErrors' => $schedule->getErrors()];
            }

            return ['success' => true, 'schedule' => Presenter::schedule($schedule)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_get_employee_schedules',
        description: 'List the schedules assigned to an employee, in priority order.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function getEmployeeSchedules(int $employeeId): array
    {
        return $this->guard(function() use ($employeeId): array {
            $schedules = Booked::getInstance()->getScheduleAssignment()->getSchedulesForEmployee($employeeId);

            return [
                'employeeId' => $employeeId,
                'count' => count($schedules),
                'schedules' => array_map(static fn(Schedule $s) => Presenter::schedule($s), $schedules),
            ];
        });
    }

    /**
     * Replace the full set of schedules assigned to an employee.
     *
     * @param int $employeeId Employee to assign schedules to.
     * @param int[] $scheduleIds Schedule ids, in priority order (first = highest).
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_set_employee_schedules',
        description: 'Set (replace) the schedules assigned to an employee. Order is priority order.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function setEmployeeSchedules(int $employeeId, array $scheduleIds): array
    {
        return $this->guardWrite(static fn(): array => [
            'success' => Booked::getInstance()->getScheduleAssignment()->setSchedulesForEmployee($employeeId, $scheduleIds),
            'employeeId' => $employeeId,
            'scheduleIds' => $scheduleIds,
        ]);
    }
}
