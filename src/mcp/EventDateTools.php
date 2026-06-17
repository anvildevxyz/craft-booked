<?php

namespace anvildev\booked\mcp;

use anvildev\booked\Booked;
use anvildev\booked\elements\EventDate;
use anvildev\booked\mcp\support\Presenter;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * MCP tools for Booked's one-time events (event dates).
 *
 * Creation runs through {@see \anvildev\booked\services\EventDateService} so
 * the element is validated and saved the same way the Control Panel does it.
 */
class EventDateTools
{
    use ToolResponseTrait;

    /**
     * List event dates, optionally bounded by a date range.
     *
     * @param string|null $fromDate Only events on/after this date (Y-m-d).
     * @param string|null $toDate Only events on/before this date (Y-m-d).
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_list_event_dates',
        description: 'List Booked event dates (one-time bookable events) with their capacity and pricing, '
            . 'optionally bounded by a date range.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function listEventDates(?string $fromDate = null, ?string $toDate = null): array
    {
        return $this->guard(function() use ($fromDate, $toDate): array {
            $events = Booked::getInstance()->getEventDate()->getEventDates($fromDate, $toDate, '*');

            return [
                'count' => count($events),
                'eventDates' => array_map(static fn(EventDate $e) => Presenter::eventDate($e), $events),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_get_event_date',
        description: 'Get a single Booked event date by id, including remaining capacity.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function getEventDate(int $id): array
    {
        return $this->guard(function() use ($id): array {
            $service = Booked::getInstance()->getEventDate();
            $event = $service->getEventDateById($id);
            if (!$event instanceof EventDate) {
                return ['error' => "Event date #{$id} not found."];
            }

            return [
                'eventDate' => Presenter::eventDate($event),
                'remainingCapacity' => $service->getRemainingCapacity($id),
                'bookedCount' => $service->getBookedCount($id),
            ];
        });
    }

    /**
     * Create a one-time event date.
     *
     * @param string $title Event name.
     * @param string $eventDate Day of the event, Y-m-d.
     * @param string $startTime Start time, HH:MM (24h).
     * @param string $endTime End time, HH:MM (24h).
     * @param int|null $capacity Maximum number of seats (null = unlimited).
     * @param int|null $locationId Location the event is held at.
     * @param float|null $price Price per seat.
     * @param string|null $description Free-text description.
     * @param bool $enabled Whether the event is immediately bookable.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_create_event_date',
        description: 'Create a one-time Booked event date. Returns the created event.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function createEventDate(
        string $title,
        string $eventDate,
        string $startTime,
        string $endTime,
        ?int $capacity = null,
        ?int $locationId = null,
        ?float $price = null,
        ?string $description = null,
        bool $enabled = true,
    ): array {
        return $this->guard(function() use (
            $title,
            $eventDate,
            $startTime,
            $endTime,
            $capacity,
            $locationId,
            $price,
            $description,
            $enabled,
        ): array {
            $event = Booked::getInstance()->getEventDate()->createEventDate([
                'title' => $title,
                'eventDate' => $eventDate,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'capacity' => $capacity,
                'locationId' => $locationId,
                'price' => $price,
                'description' => $description,
                'enabled' => $enabled,
            ]);

            return [
                'success' => true,
                'eventDate' => Presenter::eventDate($event),
            ];
        });
    }

    /**
     * Update an event date. Only provided (non-null) fields change; pass
     * enabled=false to hide it from booking.
     *
     * @param int $id Event date to update.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_update_event_date',
        description: 'Update a Booked event date by id. Only the fields you pass are changed. '
            . 'Set enabled=false to retire it.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function updateEventDate(
        int $id,
        ?string $title = null,
        ?string $eventDate = null,
        ?string $startTime = null,
        ?string $endTime = null,
        ?int $capacity = null,
        ?int $locationId = null,
        ?float $price = null,
        ?string $description = null,
        ?bool $enabled = null,
    ): array {
        return $this->guard(function() use ($id, $title, $eventDate, $startTime, $endTime, $capacity, $locationId, $price, $description, $enabled): array {
            $data = array_filter([
                'title' => $title,
                'eventDate' => $eventDate,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'capacity' => $capacity,
                'locationId' => $locationId,
                'price' => $price,
                'description' => $description,
                'enabled' => $enabled,
            ], static fn($v) => $v !== null);

            if ($data === []) {
                return ['error' => 'Provide at least one field to update.'];
            }

            $event = Booked::getInstance()->getEventDate()->updateEventDate($id, $data);

            return ['success' => true, 'eventDate' => Presenter::eventDate($event)];
        });
    }

    /**
     * Delete an event date. Refused (with an error) if any reservations exist
     * for it — retire it with update enabled=false instead.
     *
     * @param int $id Event date to delete.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_delete_event_date',
        description: 'Delete a Booked event date by id. Fails if it has any reservations; in that case set '
            . 'enabled=false via booked_update_event_date instead.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function deleteEventDate(int $id): array
    {
        return $this->guard(static fn(): array => [
            'success' => Booked::getInstance()->getEventDate()->deleteEventDate($id),
            'id' => $id,
        ]);
    }
}
