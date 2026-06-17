<?php

namespace anvildev\booked\mcp;

use anvildev\booked\Booked;
use anvildev\booked\elements\BlackoutDate;
use anvildev\booked\mcp\support\Presenter;
use Craft;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * MCP tools for blackout dates — ranges during which bookings are blocked,
 * optionally scoped to specific locations and/or employees.
 *
 * There is no hard delete: deactivate a blackout with
 * {@see self::setBlackoutDateActive()} (isActive=false) to lift it.
 */
class BlackoutDateTools
{
    use ToolResponseTrait;

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_list_blackout_dates',
        description: 'List Booked blackout dates (ranges when booking is blocked), with their location/employee scope.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function listBlackoutDates(int $limit = 100): array
    {
        return $this->guard(function() use ($limit): array {
            $blackouts = BlackoutDate::find()->siteId('*')->status(null)->limit($this->clampLimit($limit))->all();

            return [
                'count' => count($blackouts),
                'blackoutDates' => array_map(static fn(BlackoutDate $b) => Presenter::blackoutDate($b), $blackouts),
            ];
        });
    }

    /**
     * Create a blackout date range.
     *
     * @param string $title Label for the blackout (required).
     * @param string $startDate Range start, Y-m-d.
     * @param string $endDate Range end, Y-m-d.
     * @param int[]|null $locationIds Limit the blackout to these locations (empty/null = all).
     * @param int[]|null $employeeIds Limit the blackout to these employees (empty/null = all).
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_create_blackout_date',
        description: 'Create a Booked blackout date range, optionally scoped to specific locations and/or employees.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function createBlackoutDate(
        string $title,
        string $startDate,
        string $endDate,
        ?array $locationIds = null,
        ?array $employeeIds = null,
    ): array {
        return $this->guardWrite(function() use ($title, $startDate, $endDate, $locationIds, $employeeIds): array {
            $blackout = new BlackoutDate();
            $blackout->title = $title;
            $blackout->startDate = $startDate;
            $blackout->endDate = $endDate;
            $blackout->locationIds = $locationIds ?? [];
            $blackout->employeeIds = $employeeIds ?? [];
            $blackout->isActive = true;

            if (!Craft::$app->getElements()->saveElement($blackout)) {
                return ['error' => 'Failed to create blackout date.', 'validationErrors' => $blackout->getErrors()];
            }

            return ['success' => true, 'blackoutDate' => Presenter::blackoutDate($blackout)];
        });
    }

    /**
     * Activate or deactivate a blackout date (the soft alternative to deleting).
     *
     * @param int $id Blackout date id.
     * @param bool $isActive Whether the blackout should be in effect.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_set_blackout_date_active',
        description: 'Activate or deactivate a blackout date by id (isActive). Use this to lift a blackout '
            . 'instead of deleting it.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function setBlackoutDateActive(int $id, bool $isActive): array
    {
        return $this->guardWrite(function() use ($id, $isActive): array {
            $blackout = BlackoutDate::find()->siteId('*')->status(null)->id($id)->one();
            if (!$blackout instanceof BlackoutDate) {
                return ['error' => "Blackout date #{$id} not found."];
            }

            $blackout->isActive = $isActive;
            if (!Craft::$app->getElements()->saveElement($blackout)) {
                return ['error' => 'Failed to update blackout date.', 'validationErrors' => $blackout->getErrors()];
            }

            return ['success' => true, 'blackoutDate' => Presenter::blackoutDate($blackout)];
        });
    }

    /**
     * Check whether a given day is blacked out, optionally for a specific
     * employee/location.
     *
     * @param string $date Day to check, Y-m-d.
     * @param int|null $employeeId Restrict the check to this employee.
     * @param int|null $locationId Restrict the check to this location.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_check_date_blacked_out',
        description: 'Check whether a date (Y-m-d) is blacked out, optionally for a specific employee/location.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function checkDateBlackedOut(string $date, ?int $employeeId = null, ?int $locationId = null): array
    {
        return $this->guard(static fn(): array => [
            'date' => $date,
            'isBlackedOut' => Booked::getInstance()->getBlackoutDate()->isDateBlackedOut($date, $employeeId, $locationId),
        ]);
    }
}
