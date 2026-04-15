# Changelog

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