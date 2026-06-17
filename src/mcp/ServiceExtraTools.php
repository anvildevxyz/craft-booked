<?php

namespace anvildev\booked\mcp;

use anvildev\booked\Booked;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\ServiceExtra;
use anvildev\booked\mcp\support\Presenter;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * MCP tools for service add-ons (extras) and service↔location links.
 *
 * Extras are global add-ons (e.g. "gift wrap", "extra time") that are then
 * attached to specific services; locations restrict where a service can be
 * booked. Both go through Booked's relation services so join tables and caches
 * stay consistent.
 */
class ServiceExtraTools
{
    use ToolResponseTrait;

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_list_service_extras',
        description: 'List all Booked service extras (bookable add-ons) with price, duration and required flag.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function listServiceExtras(): array
    {
        return $this->guard(static function(): array {
            $extras = Booked::getInstance()->getServiceExtra()->getAllExtras(false);

            return [
                'count' => count($extras),
                'extras' => array_map(static fn(ServiceExtra $e) => Presenter::serviceExtra($e), $extras),
            ];
        });
    }

    /**
     * Create a service extra (add-on).
     *
     * @param string $title Name of the extra (required).
     * @param float $price Price of the extra.
     * @param int $duration Additional duration in minutes this extra adds to a booking.
     * @param int $maxQuantity Maximum quantity bookable per reservation.
     * @param bool $isRequired Whether the extra is mandatory for services it is attached to.
     * @param string|null $description Optional description.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_create_service_extra',
        description: 'Create a Booked service extra (add-on). Attach it to services with booked_set_service_extras.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function createServiceExtra(
        string $title,
        float $price = 0.0,
        int $duration = 0,
        int $maxQuantity = 1,
        bool $isRequired = false,
        ?string $description = null,
    ): array {
        return $this->guard(function() use ($title, $price, $duration, $maxQuantity, $isRequired, $description): array {
            $extra = new ServiceExtra();
            $extra->title = $title;
            $extra->price = $price;
            $extra->duration = $duration;
            $extra->maxQuantity = $maxQuantity;
            $extra->isRequired = $isRequired;
            $extra->description = $description;

            if (!Booked::getInstance()->getServiceExtra()->saveExtra($extra)) {
                return ['error' => 'Failed to create service extra.', 'validationErrors' => $extra->getErrors()];
            }

            return ['success' => true, 'extra' => Presenter::serviceExtra($extra)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_get_service_extras',
        description: 'List the extras attached to a specific service.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function getServiceExtras(int $serviceId, bool $enabledOnly = false): array
    {
        return $this->guard(function() use ($serviceId, $enabledOnly): array {
            $extras = Booked::getInstance()->getServiceExtra()->getExtrasForService($serviceId, $enabledOnly);

            return [
                'serviceId' => $serviceId,
                'count' => count($extras),
                'extras' => array_map(static fn(ServiceExtra $e) => Presenter::serviceExtra($e), $extras),
            ];
        });
    }

    /**
     * Replace the set of extras attached to a service.
     *
     * @param int $serviceId Service to attach extras to.
     * @param int[] $extraIds Extra ids to attach (replaces the current set).
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_set_service_extras',
        description: 'Set (replace) the extras attached to a service.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function setServiceExtras(int $serviceId, array $extraIds): array
    {
        return $this->guard(static fn(): array => [
            'success' => Booked::getInstance()->getServiceExtra()->setExtrasForService($serviceId, $extraIds),
            'serviceId' => $serviceId,
            'extraIds' => $extraIds,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_get_service_locations',
        description: 'List the locations a service can be booked at.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function getServiceLocations(int $serviceId): array
    {
        return $this->guard(function() use ($serviceId): array {
            $locations = Booked::getInstance()->getServiceLocation()->getLocationsForService($serviceId);

            return [
                'serviceId' => $serviceId,
                'count' => count($locations),
                'locations' => array_map(static fn(Location $l) => Presenter::location($l), $locations),
            ];
        });
    }

    /**
     * Replace the set of locations a service can be booked at.
     *
     * @param int $serviceId Service to set locations for.
     * @param int[] $locationIds Location ids (replaces the current set; empty = all locations).
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_set_service_locations',
        description: 'Set (replace) the locations a service can be booked at. Empty list means all locations.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function setServiceLocations(int $serviceId, array $locationIds): array
    {
        return $this->guard(static fn(): array => [
            'success' => Booked::getInstance()->getServiceLocation()->setLocationsForService($serviceId, $locationIds),
            'serviceId' => $serviceId,
            'locationIds' => $locationIds,
        ]);
    }
}
