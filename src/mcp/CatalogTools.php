<?php

namespace anvildev\booked\mcp;

use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use anvildev\booked\mcp\support\Presenter;
use Craft;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * MCP tools for Booked's catalog: services, employees and locations.
 *
 * Services are localized, so queries use `->unique()` to collapse per-site
 * duplicates; employees and locations are not localized, so we only widen the
 * site scope. All reads run across every site (`->siteId('*')`) and include
 * disabled elements (`->status(null)`) — an admin AI assistant should see the
 * full catalog, not just what a front-end visitor would.
 */
class CatalogTools
{
    use ToolResponseTrait;

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_list_services',
        description: 'List bookable services in Booked (id, title, duration, price, slot length). '
            . 'Returns both enabled and disabled services across all sites.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function listServices(int $limit = 50, int $offset = 0): array
    {
        return $this->guard(function() use ($limit, $offset): array {
            $services = Service::find()
                ->siteId('*')
                ->status(null)
                ->unique()
                ->limit($limit)
                ->offset($offset)
                ->all();

            return [
                'count' => count($services),
                'services' => array_map(static fn(Service $s) => Presenter::service($s), $services),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_get_service',
        description: 'Get the full definition of a single Booked service by id.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function getService(int $id): array
    {
        return $this->guard(function() use ($id): array {
            $service = Service::find()->siteId('*')->status(null)->unique()->id($id)->one();
            if (!$service instanceof Service) {
                return ['error' => "Service #{$id} not found."];
            }

            return ['service' => Presenter::service($service)];
        });
    }

    /**
     * Create a new bookable service.
     *
     * @param string $title Display name of the service (required).
     * @param int $duration Length of the service in $durationType units.
     * @param string $durationType One of: minutes, hours, days.
     * @param float|null $price Flat price, when pricingMode is "flat".
     * @param int|null $timeSlotLength Slot granularity in minutes (defaults to the service duration).
     * @param bool $enabled Whether the service is immediately bookable.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_create_service',
        description: 'Create a new bookable service in Booked. Returns the created service.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function createService(
        string $title,
        int $duration = 60,
        string $durationType = 'minutes',
        ?float $price = null,
        ?int $timeSlotLength = null,
        bool $enabled = true,
    ): array {
        return $this->guard(function() use ($title, $duration, $durationType, $price, $timeSlotLength, $enabled): array {
            $service = new Service();
            $service->title = $title;
            $service->duration = $duration;
            $service->durationType = $durationType;
            $service->price = $price;
            $service->timeSlotLength = $timeSlotLength;
            $service->enabled = $enabled;

            if (!Craft::$app->getElements()->saveElement($service)) {
                return [
                    'error' => 'Failed to create service.',
                    'validationErrors' => $service->getErrors(),
                ];
            }

            return [
                'success' => true,
                'service' => Presenter::service($service),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_list_employees',
        description: 'List Booked employees (staff who deliver services), including which services and location each is tied to.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function listEmployees(?int $serviceId = null, ?int $locationId = null, int $limit = 50): array
    {
        return $this->guard(function() use ($serviceId, $locationId, $limit): array {
            $query = Employee::find()->siteId('*')->status(null)->limit($limit);
            if ($serviceId !== null) {
                $query->serviceId($serviceId);
            }
            if ($locationId !== null) {
                $query->locationId($locationId);
            }
            $employees = $query->all();

            return [
                'count' => count($employees),
                'employees' => array_map(static fn(Employee $e) => Presenter::employee($e), $employees),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_list_locations',
        description: 'List Booked locations (where services are delivered), with address and timezone.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function listLocations(int $limit = 50): array
    {
        return $this->guard(function() use ($limit): array {
            $locations = Location::find()->siteId('*')->status(null)->limit($limit)->all();

            return [
                'count' => count($locations),
                'locations' => array_map(static fn(Location $l) => Presenter::location($l), $locations),
            ];
        });
    }

    /**
     * Update an existing service. Only provided (non-null) fields are changed;
     * pass enabled=false to soft-disable a service instead of deleting it.
     *
     * @param string $durationType One of: minutes, hours, days.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_update_service',
        description: 'Update a Booked service by id. Only the fields you pass are changed. '
            . 'Set enabled=false to retire a service (Booked has no hard delete for services).',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function updateService(
        int $id,
        ?string $title = null,
        ?int $duration = null,
        ?string $durationType = null,
        ?float $price = null,
        ?int $timeSlotLength = null,
        ?bool $enabled = null,
    ): array {
        return $this->guard(function() use ($id, $title, $duration, $durationType, $price, $timeSlotLength, $enabled): array {
            $service = Service::find()->siteId('*')->status(null)->unique()->id($id)->one();
            if (!$service instanceof Service) {
                return ['error' => "Service #{$id} not found."];
            }

            if ($title !== null) {
                $service->title = $title;
            }
            if ($duration !== null) {
                $service->duration = $duration;
            }
            if ($durationType !== null) {
                $service->durationType = $durationType;
            }
            if ($price !== null) {
                $service->price = $price;
            }
            if ($timeSlotLength !== null) {
                $service->timeSlotLength = $timeSlotLength;
            }
            if ($enabled !== null) {
                $service->enabled = $enabled;
            }

            if (!Craft::$app->getElements()->saveElement($service)) {
                return ['error' => 'Failed to update service.', 'validationErrors' => $service->getErrors()];
            }

            return ['success' => true, 'service' => Presenter::service($service)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_get_location',
        description: 'Get a single Booked location by id.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function getLocation(int $id): array
    {
        return $this->guard(function() use ($id): array {
            $location = Location::find()->siteId('*')->status(null)->id($id)->one();
            if (!$location instanceof Location) {
                return ['error' => "Location #{$id} not found."];
            }

            return ['location' => Presenter::location($location)];
        });
    }

    /**
     * Create a new location.
     *
     * @param string $title Display name of the location (required).
     * @param string|null $timezone IANA timezone (e.g. Europe/Zurich); defaults to the system timezone.
     * @param string|null $addressLine1 Street address.
     * @param string|null $locality City / town.
     * @param string|null $administrativeArea State / region.
     * @param string|null $postalCode Postal / ZIP code.
     * @param string|null $countryCode Two-letter ISO country code.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_create_location',
        description: 'Create a new Booked location. Returns the created location.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function createLocation(
        string $title,
        ?string $timezone = null,
        ?string $addressLine1 = null,
        ?string $locality = null,
        ?string $administrativeArea = null,
        ?string $postalCode = null,
        ?string $countryCode = null,
    ): array {
        return $this->guard(function() use ($title, $timezone, $addressLine1, $locality, $administrativeArea, $postalCode, $countryCode): array {
            $location = new Location();
            $location->title = $title;
            $location->timezone = $timezone;
            $location->addressLine1 = $addressLine1;
            $location->locality = $locality;
            $location->administrativeArea = $administrativeArea;
            $location->postalCode = $postalCode;
            $location->countryCode = $countryCode;

            if (!Craft::$app->getElements()->saveElement($location)) {
                return ['error' => 'Failed to create location.', 'validationErrors' => $location->getErrors()];
            }

            return ['success' => true, 'location' => Presenter::location($location)];
        });
    }

    /**
     * Update a location. Only provided (non-null) fields change; pass
     * enabled=false to retire it.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_update_location',
        description: 'Update a Booked location by id. Only the fields you pass are changed. '
            . 'Set enabled=false to retire a location.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function updateLocation(
        int $id,
        ?string $title = null,
        ?string $timezone = null,
        ?string $addressLine1 = null,
        ?string $locality = null,
        ?string $administrativeArea = null,
        ?string $postalCode = null,
        ?string $countryCode = null,
        ?bool $enabled = null,
    ): array {
        return $this->guard(function() use ($id, $title, $timezone, $addressLine1, $locality, $administrativeArea, $postalCode, $countryCode, $enabled): array {
            $location = Location::find()->siteId('*')->status(null)->id($id)->one();
            if (!$location instanceof Location) {
                return ['error' => "Location #{$id} not found."];
            }

            foreach ([
                'title' => $title,
                'timezone' => $timezone,
                'addressLine1' => $addressLine1,
                'locality' => $locality,
                'administrativeArea' => $administrativeArea,
                'postalCode' => $postalCode,
                'countryCode' => $countryCode,
                'enabled' => $enabled,
            ] as $field => $value) {
                if ($value !== null) {
                    $location->$field = $value;
                }
            }

            if (!Craft::$app->getElements()->saveElement($location)) {
                return ['error' => 'Failed to update location.', 'validationErrors' => $location->getErrors()];
            }

            return ['success' => true, 'location' => Presenter::location($location)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_get_employee',
        description: 'Get a single Booked employee by id, including their service and location links.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN)]
    public function getEmployee(int $id): array
    {
        return $this->guard(function() use ($id): array {
            $employee = Employee::find()->siteId('*')->status(null)->id($id)->one();
            if (!$employee instanceof Employee) {
                return ['error' => "Employee #{$id} not found."];
            }

            return ['employee' => Presenter::employee($employee)];
        });
    }

    /**
     * Create a new employee.
     *
     * @param string $title Employee display name (required).
     * @param string|null $email Contact email.
     * @param int|null $locationId Location this employee works at.
     * @param int[]|null $serviceIds Ids of services this employee delivers.
     * @param int|null $userId Linked Craft user id, for self-service.
     * @param array<string, mixed>|null $workingHours Per-day hours keyed "1"(Mon)–"7"(Sun), each {enabled, start, end, breakStart?, breakEnd?}.
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_create_employee',
        description: 'Create a new Booked employee (staff member who delivers services). '
            . 'Optionally link services, a location, a Craft user, and inline working hours.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function createEmployee(
        string $title,
        ?string $email = null,
        ?int $locationId = null,
        ?array $serviceIds = null,
        ?int $userId = null,
        #[Schema(type: 'object')] ?array $workingHours = null,
    ): array {
        return $this->guard(function() use ($title, $email, $locationId, $serviceIds, $userId, $workingHours): array {
            $employee = new Employee();
            $employee->title = $title;
            $employee->email = $email;
            $employee->locationId = $locationId;
            $employee->serviceIds = $serviceIds ?? [];
            $employee->userId = $userId;
            if ($workingHours !== null) {
                $employee->workingHours = $workingHours;
            }

            if (!Craft::$app->getElements()->saveElement($employee)) {
                return ['error' => 'Failed to create employee.', 'validationErrors' => $employee->getErrors()];
            }

            return ['success' => true, 'employee' => Presenter::employee($employee)];
        });
    }

    /**
     * Update an employee. Only provided (non-null) fields change; pass
     * enabled=false to retire them.
     *
     * @param int[]|null $serviceIds Replacement set of service ids this employee delivers.
     * @param array<string, mixed>|null $workingHours Per-day hours keyed "1"(Mon)–"7"(Sun).
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'booked_update_employee',
        description: 'Update a Booked employee by id. Only the fields you pass are changed. '
            . 'Set enabled=false to retire them; serviceIds replaces the full set.',
    )]
    #[McpToolMeta(category: ToolCategory::PLUGIN, dangerous: true)]
    public function updateEmployee(
        int $id,
        ?string $title = null,
        ?string $email = null,
        ?int $locationId = null,
        ?array $serviceIds = null,
        ?int $userId = null,
        #[Schema(type: 'object')] ?array $workingHours = null,
        ?bool $enabled = null,
    ): array {
        return $this->guard(function() use ($id, $title, $email, $locationId, $serviceIds, $userId, $workingHours, $enabled): array {
            $employee = Employee::find()->siteId('*')->status(null)->id($id)->one();
            if (!$employee instanceof Employee) {
                return ['error' => "Employee #{$id} not found."];
            }

            foreach ([
                'title' => $title,
                'email' => $email,
                'locationId' => $locationId,
                'serviceIds' => $serviceIds,
                'userId' => $userId,
                'workingHours' => $workingHours,
                'enabled' => $enabled,
            ] as $field => $value) {
                if ($value !== null) {
                    $employee->$field = $value;
                }
            }

            if (!Craft::$app->getElements()->saveElement($employee)) {
                return ['error' => 'Failed to update employee.', 'validationErrors' => $employee->getErrors()];
            }

            return ['success' => true, 'employee' => Presenter::employee($employee)];
        });
    }
}
