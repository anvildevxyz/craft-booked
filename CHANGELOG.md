# Changelog

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