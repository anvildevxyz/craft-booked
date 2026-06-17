# Changelog

## 1.2.1 - 2026-06-17

### Added
- MCP authorization settings (Settings → Security): **Allow MCP write operations** (`mcpWriteEnabled`) and **Allow MCP refunds** (`mcpAllowRefunds`), both **off by default**. A migration adds the columns to existing installs.

### Security
- MCP write tools are now **default-deny**: every create/update/cancel/delete/refund tool is gated behind `mcpWriteEnabled` (and refunds additionally behind `mcpAllowRefunds`), rather than relying on the craft-mcp server config alone (this supersedes the 1.2.0 "authorization is delegated to the craft-mcp server" note). In a web context an authenticated user must also hold `booked-manageBookings`.
- `booked_refund_reservation` is now idempotent — it nets out prior refunds and never refunds more than the outstanding (paid minus already-refunded) amount.
- Customer PII (email/phone) is now redacted by default in all MCP responses — including create/update/booking responses, not just bulk lists — so a forgotten flag fails safe.
- MCP list tools clamp their page size to a hard ceiling (200) to prevent unbounded result sets (memory exhaustion / bulk data export).

### Fixed
- `booked_delete_event_date` on an event that still has reservations now returns the actionable "retire it with `enabled=false` instead" message (via a typed exception) rather than a generic internal-error response.

## 1.2.0 - 2026-06-17

### Added
- MCP integration: Booked now registers ~50 tools with the optional [craft-mcp](https://github.com/stimmtdigital/craft-mcp) plugin, exposing near-complete headless admin to AI assistants — services, employees, locations, schedules, blackout dates, service extras, availability, reservations, event dates, waitlist and reporting. Covers reads, create/update (soft-disable via `enabled` rather than hard delete), reschedule, quantity changes, refunds and analytics. The dependency is soft (`class_exists`-guarded) — Booked runs unchanged when craft-mcp is absent. See [MCP.md](MCP.md).
- MCP safety model: customer email/phone are redacted on every reservation/waitlist read (not just bulk lists); booking capability tokens and virtual-meeting URLs are never exposed; cancellations always run the refund/capacity-release flow (status cannot be force-set to `cancelled` via update); retired (disabled) services/events remain listable and re-enablable; inputs are validated (quantity ≥ 1, employee `serviceIds` must exist); and notification/refund side effects are rate-limited with a fixed-window, mutex-guarded limiter (separate budgets, charged only on success) since Booked's IP-based limiter does not apply over MCP. Authorization is delegated to the craft-mcp server (IP allowlist / dangerous-tool gating). All 50 tools verified end-to-end against the live MCP server.

## 1.1.1 - 2026-04-15

### Added
- New `slot/get-range-capacity` controller action and `BookedAvailability.getRangeCapacity()` JS helper for querying the remaining capacity of a multi-day date range (tightest day wins).
- `ScheduleResolverService::getCapacityForDay()` resolves day-based capacity from `Schedule.workingHours[day].capacity`, honoring service → employee → aggregated-employee precedence.
- Day-service wizard step now shows a quantity picker (with +/− controls and live remaining-capacity hint) when a multi-day range has capacity greater than one.
- Inline booking error banner on the date/time step so backend error messages surface to the user instead of being swallowed.

### Changed
- Multi-day availability now enforces capacity per day using the resolved schedule capacity instead of `Service.capacity`, correctly accounting for overlapping reservations across each day of the range.
- `SlotController` rate limits raised to 120 req/min across `get-slots`, `get-dates`, `get-valid-end-dates`, `get-event-dates`, and `get-availability-calendar` to reduce false 429s for legitimate wizard traffic.
- `BookedAvailability.getDates()` and `createBooking()` now parse error response bodies so the wizard can display the server message and status code.
- Wizard caches fetched available-date results per month/service/employee/location/quantity/extras key and de-dupes in-flight requests.
- Flexible-day price calculation guards against missing `selectedService.price`.

### Fixed
- GraphQL mutation registration for `QuantityMutations`, `ReservationMutations`, and `WaitlistMutations` (minor correction).
- Day-service “Next” button enable/disable logic now accounts for day-range capacity picker state.

## 1.0.2 - 2026-04-02

### Fixed
- Bundled Alpine.js with the plugin so the built-in booking wizard works out of the box without requiring the site theme to include Alpine separately ([#3](https://github.com/anvildevxyz/craft-booked/issues/3))
- Alpine.js is loaded at `POS_END` to ensure proper initialization order with wizard components
- Added detection to skip loading Alpine.js if the site already includes it

## 1.0.0 - Unreleased

### Fixed

- SMS confirmations, reminders, and cancellations for multi-day reservations now use the `sms.*.multiday` translation strings when no custom SMS template is set (previously the same time-based default was used, so `{{time}}` was empty).

### Added

- **Multi-day and flexible-day services** — Services can use `durationType` `days` (fixed consecutive-day stays) or `flexible_days` (guest-selected length between min/max). Documented in [AVAILABILITY.md](AVAILABILITY.md#multi-day-and-flexible-day-services), [TUTORIAL.md](TUTORIAL.md#day-based-services-rentals-retreats-multi-night-stays), and the REST/GraphQL sections of [DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md) / [GRAPHQL.md](GRAPHQL.md). Reservations store an inclusive `endDate` with null times; email/SMS templates receive `isMultiDay`, `formattedEndDate`, and day-aware `duration` variables.