<?php

namespace anvildev\booked\mcp;

use anvildev\booked\Booked;
use anvildev\booked\mcp\support\Presenter;
use anvildev\booked\records\WaitlistRecord;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * MCP tools for the booking waitlist — both service waitlists (a customer
 * waiting for any/specific slot of a service) and event waitlists (waiting for
 * a seat on a full event date).
 */
class WaitlistTools
{
    use ToolResponseTrait;

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_list_waitlist',
        description: 'List active waitlist entries for a service, in priority order.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function listWaitlist(int $serviceId): array
    {
        return $this->guard(function() use ($serviceId): array {
            $entries = Booked::getInstance()->getWaitlist()->getActiveEntriesForService($serviceId);

            return [
                'serviceId' => $serviceId,
                'count' => count($entries),
                'entries' => array_map(static fn(WaitlistRecord $e) => Presenter::waitlistEntry($e, redactPii: true), $entries),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_waitlist_stats',
        description: 'Aggregate waitlist statistics (active/notified/converted/expired counts).',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function waitlistStats(): array
    {
        return $this->guard(static fn(): array => [
            'stats' => Booked::getInstance()->getWaitlist()->getStats(),
        ]);
    }

    /**
     * Add a customer to a service waitlist.
     *
     * @param int $serviceId Service to wait for.
     * @param string $userName Customer name.
     * @param string $userEmail Customer email.
     * @param string|null $userPhone Customer phone.
     * @param int|null $employeeId Preferred employee.
     * @param int|null $locationId Preferred location.
     * @param string|null $preferredDate Preferred date, Y-m-d.
     * @param string|null $preferredTimeStart Preferred earliest time, HH:MM.
     * @param string|null $preferredTimeEnd Preferred latest time, HH:MM.
     * @param string|null $notes Free-text notes.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_add_to_waitlist',
        description: 'Add a customer to a service waitlist, with optional preferred employee/location/date/time.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function addToWaitlist(
        int $serviceId,
        string $userName,
        string $userEmail,
        ?string $userPhone = null,
        ?int $employeeId = null,
        ?int $locationId = null,
        ?string $preferredDate = null,
        ?string $preferredTimeStart = null,
        ?string $preferredTimeEnd = null,
        ?string $notes = null,
    ): array {
        return $this->guard(function() use ($serviceId, $userName, $userEmail, $userPhone, $employeeId, $locationId, $preferredDate, $preferredTimeStart, $preferredTimeEnd, $notes): array {
            $entry = Booked::getInstance()->getWaitlist()->addToWaitlist([
                'serviceId' => $serviceId,
                'userName' => $userName,
                'userEmail' => $userEmail,
                'userPhone' => $userPhone,
                'employeeId' => $employeeId,
                'locationId' => $locationId,
                'preferredDate' => $preferredDate,
                'preferredTimeStart' => $preferredTimeStart,
                'preferredTimeEnd' => $preferredTimeEnd,
                'notes' => $notes,
            ]);

            if (!$entry instanceof WaitlistRecord) {
                return ['error' => 'Could not add to waitlist (already listed, or waitlist disabled for this service).'];
            }

            return ['success' => true, 'entry' => Presenter::waitlistEntry($entry)];
        });
    }

    /**
     * Add a customer to an event date's waitlist.
     *
     * @param int $eventDateId Event date to wait for.
     * @param string $userName Customer name.
     * @param string $userEmail Customer email.
     * @param string|null $userPhone Customer phone.
     * @param string|null $notes Free-text notes.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_add_to_event_waitlist',
        description: 'Add a customer to the waitlist for a full event date.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function addToEventWaitlist(
        int $eventDateId,
        string $userName,
        string $userEmail,
        ?string $userPhone = null,
        ?string $notes = null,
    ): array {
        return $this->guard(function() use ($eventDateId, $userName, $userEmail, $userPhone, $notes): array {
            $entry = Booked::getInstance()->getWaitlist()->addToEventWaitlist([
                'eventDateId' => $eventDateId,
                'userName' => $userName,
                'userEmail' => $userEmail,
                'userPhone' => $userPhone,
                'notes' => $notes,
            ]);

            if (!$entry instanceof WaitlistRecord) {
                return ['error' => 'Could not add to event waitlist (already listed, or waitlist disabled).'];
            }

            return ['success' => true, 'entry' => Presenter::waitlistEntry($entry)];
        });
    }

    /**
     * @param int $entryId Waitlist entry id.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_cancel_waitlist_entry',
        description: 'Cancel (remove) a waitlist entry by id.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function cancelWaitlistEntry(int $entryId): array
    {
        return $this->guard(static fn(): array => [
            'success' => Booked::getInstance()->getWaitlist()->cancelEntry($entryId),
            'entryId' => $entryId,
        ]);
    }

    /**
     * Manually notify a waitlist entry that a spot may be available.
     *
     * @param int $entryId Waitlist entry id.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_notify_waitlist_entry',
        description: 'Manually send the "a spot is available" notification to a waitlist entry.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function notifyWaitlistEntry(int $entryId): array
    {
        return $this->guard(static fn(): array => [
            'success' => Booked::getInstance()->getWaitlist()->manualNotify($entryId),
            'entryId' => $entryId,
        ]);
    }
}
