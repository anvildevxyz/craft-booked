# MCP Tools

Booked integrates with the [craft-mcp](https://github.com/stimmtdigital/craft-mcp)
plugin to expose its booking data and operations to AI assistants (Claude
Desktop, Claude Code, Cursor, …) over the [Model Context Protocol](https://modelcontextprotocol.io).

The integration is **optional and zero-config**. Booked only *soft-depends* on
craft-mcp: when the plugin is installed, Booked registers its tools on craft-mcp's
`EVENT_REGISTER_TOOLS` event; when it is absent, Booked runs exactly as before.
Registration is guarded by a `class_exists()` check, so there is nothing to turn
on inside Booked itself.

## Setup

1. Install and enable craft-mcp:

   ```bash
   composer require stimmt/craft-mcp
   php craft plugin/install mcp
   ```

2. Enable the MCP server in `config/mcp.php`:

   ```php
   <?php
   return [
       'enabled' => true,
   ];
   ```

3. Generate a client config (Claude Code, Cursor, Claude Desktop, …):

   ```bash
   php craft mcp/install
   ```

Booked's tools are then advertised automatically under the `booked` source. See
the [craft-mcp docs](https://github.com/stimmtdigital/craft-mcp/blob/master/docs/extending.md)
for client-specific configuration.

> craft-mcp requires PHP 8.3+. Booked itself supports PHP 8.2+; the higher floor
> only applies when you opt into the MCP integration.

## Tools

All tools are registered under the `PLUGIN` category. Tools that mutate data are
flagged **dangerous** via `#[McpToolMeta(dangerous: true)]`, so MCP clients can
require confirmation before running them.

Booked has **no hard delete** for services, locations, employees or schedules —
retire them with the matching `update_*` tool and `enabled: false` (blackout
dates use `set_blackout_date_active`). The one exception is
`booked_delete_event_date`, which hard-deletes but refuses when reservations
exist.

### Catalog — `CatalogTools`

| Tool | Description | Dangerous |
| --- | --- | --- |
| `booked_list_services` | List bookable services (id, title, duration, price, slot length). | |
| `booked_get_service` | Get a single service by id. | |
| `booked_create_service` | Create a new bookable service. | ⚠️ |
| `booked_update_service` | Update a service; `enabled:false` retires it. | ⚠️ |
| `booked_list_employees` | List employees, filterable by service/location. | |
| `booked_get_employee` | Get a single employee by id. | |
| `booked_create_employee` | Create an employee (links services/location/user/working hours). | ⚠️ |
| `booked_update_employee` | Update an employee; `enabled:false` retires them. | ⚠️ |
| `booked_list_locations` | List locations with address and timezone. | |
| `booked_get_location` | Get a single location by id. | |
| `booked_create_location` | Create a location. | ⚠️ |
| `booked_update_location` | Update a location; `enabled:false` retires it. | ⚠️ |

### Availability — `AvailabilityTools`

| Tool | Description | Dangerous |
| --- | --- | --- |
| `booked_check_availability` | Available slots for a day (Y-m-d), filterable by service/employee/location. | |
| `booked_next_available_date` | Next day with an open slot, searching forward from today. | |
| `booked_availability_summary` | Open/closed summary across a date range. | |

### Reservations — `ReservationTools`

| Tool | Description | Dangerous |
| --- | --- | --- |
| `booked_list_reservations` | List reservations, filterable by status/service/employee/location/date range. | |
| `booked_get_reservation` | Get a single reservation by id. | |
| `booked_booking_stats` | Aggregate counts (total, confirmed, pending, today, this month). | |
| `booked_create_booking` | Create a time-slot reservation (full availability validation + slot locking). | ⚠️ |
| `booked_create_event_booking` | Reserve seats on an event date (capacity-checked). | ⚠️ |
| `booked_update_reservation` | Edit details, reschedule (date/time/employee/location), or set status (confirmed/cancelled). | ⚠️ |
| `booked_reduce_reservation_quantity` | Release spots/seats (partial refund under Commerce). | ⚠️ |
| `booked_increase_reservation_quantity` | Add spots/seats (re-checks capacity). | ⚠️ |
| `booked_cancel_reservation` | Cancel a reservation, freeing its slot/capacity. | ⚠️ |
| `booked_refund_reservation` | Issue a full Commerce refund (Commerce only). | ⚠️ |

### Event dates — `EventDateTools`

| Tool | Description | Dangerous |
| --- | --- | --- |
| `booked_list_event_dates` | List event dates, optionally bounded by a date range. | |
| `booked_get_event_date` | Get an event date by id, including remaining capacity. | |
| `booked_create_event_date` | Create a one-time event date. | ⚠️ |
| `booked_update_event_date` | Update an event date; `enabled:false` retires it. | ⚠️ |
| `booked_delete_event_date` | Delete an event date (fails if it has reservations). | ⚠️ |

### Schedules — `ScheduleTools`

| Tool | Description | Dangerous |
| --- | --- | --- |
| `booked_list_schedules` | List availability schedules (weekly working-hours patterns). | |
| `booked_get_schedule` | Get a schedule by id, including its working-hours map. | |
| `booked_create_schedule` | Create a schedule (working hours keyed "1"(Mon)–"7"(Sun)). | ⚠️ |
| `booked_update_schedule` | Update a schedule. | ⚠️ |
| `booked_get_employee_schedules` | List the schedules assigned to an employee. | |
| `booked_set_employee_schedules` | Replace an employee's assigned schedules (priority order). | ⚠️ |

### Blackout dates — `BlackoutDateTools`

| Tool | Description | Dangerous |
| --- | --- | --- |
| `booked_list_blackout_dates` | List blackout date ranges and their scope. | |
| `booked_create_blackout_date` | Create a blackout range (optionally scoped to locations/employees). | ⚠️ |
| `booked_set_blackout_date_active` | Activate/deactivate a blackout (the soft alternative to deleting). | ⚠️ |
| `booked_check_date_blacked_out` | Check whether a date is blacked out (optionally per employee/location). | |

### Service extras & links — `ServiceExtraTools`

| Tool | Description | Dangerous |
| --- | --- | --- |
| `booked_list_service_extras` | List all bookable add-ons. | |
| `booked_create_service_extra` | Create an add-on (price/duration/required). | ⚠️ |
| `booked_get_service_extras` | List the extras attached to a service. | |
| `booked_set_service_extras` | Replace the extras attached to a service. | ⚠️ |
| `booked_get_service_locations` | List the locations a service can be booked at. | |
| `booked_set_service_locations` | Replace a service's locations (empty = all). | ⚠️ |

### Reports — `ReportTools` (read-only)

| Tool | Description |
| --- | --- |
| `booked_revenue_report` | Revenue over a date range (optional previous-period comparison). |
| `booked_bookings_by_service` | Counts/revenue grouped by service. |
| `booked_bookings_by_employee` | Counts/revenue grouped by employee. |
| `booked_bookings_by_location` | Counts/revenue grouped by location. |
| `booked_cancellation_report` | Cancellation/no-show counts and rates. |
| `booked_peak_hours_report` | Busiest hours (optionally days of week). |
| `booked_utilization_report` | Capacity utilization (booked vs available). |
| `booked_dashboard_summary` | High-level dashboard summary. |

### Waitlist — `WaitlistTools`

| Tool | Description | Dangerous |
| --- | --- | --- |
| `booked_list_waitlist` | List active waitlist entries for a service. | |
| `booked_waitlist_stats` | Aggregate waitlist statistics. | |
| `booked_add_to_waitlist` | Add a customer to a service waitlist. | ⚠️ |
| `booked_add_to_event_waitlist` | Add a customer to an event-date waitlist. | ⚠️ |
| `booked_cancel_waitlist_entry` | Remove a waitlist entry. | ⚠️ |
| `booked_notify_waitlist_entry` | Manually send the "spot available" notification. | ⚠️ |

Write tools route through Booked's services (`BookingService`,
`EventDateService`, `ScheduleAssignmentService`, `ServiceExtraService`,
`WaitlistService`, …) and element layer, so all validation, slot-locking,
notification and webhook side effects behave identically to the Control Panel.
Every tool response is passed through a JSON-safe presenter, so reports that
embed Craft elements are collapsed to compact `{id, title}` stubs.

## How it works

The tool classes live in [`src/mcp/`](src/mcp):

- Each public tool method carries `#[McpTool(name, description)]` (from the
  `mcp/sdk` package) plus `#[McpToolMeta(category, dangerous)]` (from craft-mcp).
  These attributes are read **reflectively** by craft-mcp — Booked never
  instantiates them, which is why the package can stay an optional dependency.
- Tool bodies are wrapped in `ToolResponseTrait::guard()`, which converts any
  thrown exception into a structured `['error' => …]` payload instead of a
  transport fault.
- `src/mcp/support/Presenter.php` is the single place that decides which element
  fields are serialised over the protocol.

Registration is wired in `Booked::registerMcpTools()`:

```php
if (!class_exists(\stimmt\craft\Mcp\Mcp::class)) {
    return;
}

Event::on(
    \stimmt\craft\Mcp\Mcp::class,
    \stimmt\craft\Mcp\Mcp::EVENT_REGISTER_TOOLS,
    static function(\stimmt\craft\Mcp\events\RegisterToolsEvent $event): void {
        $event->addTool(\anvildev\booked\mcp\CatalogTools::class, 'booked');
        $event->addTool(\anvildev\booked\mcp\AvailabilityTools::class, 'booked');
        $event->addTool(\anvildev\booked\mcp\ReservationTools::class, 'booked');
        $event->addTool(\anvildev\booked\mcp\EventDateTools::class, 'booked');
    }
);
```
