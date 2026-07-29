# Changelog

## 1.4.5 - 2026-07-29

### Fixed
- **Event holds now count against remaining seats, not total capacity.** Existing confirmed bookings were invisible to the hold ledger, so a nearly-full event could still hand out holds for seats that were already taken (e.g. a 2-seats-left event granting a 5-seat hold). Holds are now capped at the remaining capacity. The booking-time capacity re-check already prevented any actual oversell; this tightens the holds themselves.
- **The "quantity exceeds capacity" message now shows the requested quantity.** The `{quantity}` placeholder was never filled in, so the message read "The requested quantity () exceeds the remaining capacity (2)."

## 1.4.4 - 2026-07-29

### Fixed
- **Group-event holds no longer block the whole event.** An event soft lock reserved the entire event instead of the requested seats, so while one customer was in checkout a second customer was turned away ("This time slot is temporarily reserved") even when dozens of seats remained. Event locks now reserve only the requested seats against the event capacity, matching how appointment slot locks behave.

## 1.4.3 - 2026-07-29

### Fixed
- **The booking calendar no longer errors when whole-day / flexible-day bookings exist.** Timeless (all-day) reservations were being fed into timed-slot overlap math, throwing on their null start/end times and taking down the availability calendar for unrelated timed services. Timeless bookings are now skipped from timed-slot calculations.
- **Group-capacity slots no longer disappear while someone else is mid-checkout.** A soft lock on a slot with capacity greater than one now holds only the seats it reserves (by quantity) instead of hiding the whole slot; single-capacity and per-employee slots are unchanged (#74).
- **Booking wizard review labels** read the singular nouns "Service", "Employee", "Location" and "Event" again, instead of the "Choose a …" step prompts (#75).
- **A free service (no price set) no longer shows a "0.00" total row** in the wizard review — the row is hidden entirely (#76).
- **The wizard success screen shows the booking id again.** The reservation is now placed on the context before the confirmed-state render, so the booking id and appointment are no longer blank (#77).
- **Choosing a location now re-scopes the staff list.** Picking a location re-fetches employees filtered to it, so the employee step no longer lists staff from other locations (which sent the customer to an all-disabled calendar).
- **A preselected service now skips the service step.** A `serviceId` deep link (or config) skips the service step even when several services exist, instead of showing it with nothing selected; `locationId`, `date` and `time` deep links are honored again.
- **Sold-out events now offer a waitlist.** The vanilla event wizard gained the waitlist form the appointment wizard already had — a fully-booked event opens a "join the waitlist" form (scoped to that event) instead of being a dead end.
- **Event slots are now held during checkout.** Soft locks for events were never created — the lock request carried a `serviceId` of `0`, which the lock guard treated as missing and rejected — so two customers could race for the last seat. Event soft locks now hold the slot like appointment locks do.
- **The wizard no longer logs a 403 when an anonymous visitor loads the page.** The account "current user" probe required login before it could return its guest response; it is now reachable anonymously and reports the visitor as logged-out.

## 1.4.2 - 2026-07-28

### Fixed
- **Blackout dates could not be deleted from their element index** on Craft 5.10+. `BlackoutDate` was the only Booked element type missing from the element-type registration, so Craft's element-index delete threw a JavaScript error before the delete request was sent and the dates stayed put. Registering the type also restores blackout-date garbage collection (purging trashed rows), `{blackoutDate:…}` reference tags, and primary-site changes.
- **The booking wizard no longer makes customers click through single-option steps.** When a service, location, or employee is the only choice, that step is auto-selected and skipped — a one-service/one-location/one-employee setup now opens straight on the calendar (the auto-selected values are still shown on the review step for confirmation).

## 1.4.1 - 2026-07-28

### Added
- **Payments settings tab** (Settings → Payments) — a Control-Panel UI to choose the payment mode (None / Direct / Commerce), enter Stripe keys (as `$ENV` references) and the webhook signing secret, set the currency and the pending-payment timeout, and see the exact webhook endpoint URL to register in Stripe. Previously these could only be set via the database or project config.

### Changed
- **The wizard now skips the service and employee steps when there is only one of them** ([#64](https://github.com/anvildevxyz/craft-booked/issues/64)). A lone service is selected automatically the way a lone location already was, and the employee step is shown only when there is more than one to choose between — so a one-service, one-location, one-employee setup opens directly on the calendar instead of on two single-card steps. Auto-selected values still appear on the review step. The deprecated Alpine wizard is unchanged.
- The **payment mode and currency are now chosen in one place** (Settings → Payments). The redundant "Enable Commerce" switch was removed from the Commerce tab, which is now Commerce-specific configuration (tax category, cart/checkout URLs, refund tiers) that applies when the mode is Commerce.

### Fixed
- **Payment concurrency hardening** (from an adversarial verification pass of the 1.4.0 payment code):
  - **Critical:** the pending-payment garbage collector could cancel a booking a webhook had just confirmed — leaving it paid-but-cancelled with the slot released. The cancel is now an atomic conditional UPDATE that only touches a still-`pending` row.
  - Confirmation (webhook vs. client poll) is now a single atomic transition, so a race can't double-fire confirmation emails/SMS/calendar invites.
  - Refunds serialize per reservation (mutex), so concurrent refunds can't lose an update and later exceed the refund policy.
  - Two distinct same-amount partial refunds now use distinct Stripe idempotency keys (previously Stripe silently replayed the first while Booked double-counted it).
  - Refund reconciliation from webhooks is monotonic — an out-of-order `charge.refunded` can no longer revert a full refund to partial.
  - Checkout UI: a transient confirm-poll error after the card was charged no longer re-enables "Pay" (which invited a double charge); and the soft-lock timer is stopped on entering payment so it can't expire mid-checkout and strand a paid booking.
- Removed stale "requires Pro edition" wording from SMS/reminder/calendar log messages left over from the edition removal.
- **Payment currency consistency:** the payment record, revenue reporting, and refunds now all resolve currency the same way (`auto` → Commerce primary → USD) instead of `createForReservation` forcing USD; direct-mode revenue sums within one currency; a CP refund converts using the payment's own stored currency.
- **Payment-lockout fix:** the per-reservation `payment/create` throttle now counts only after the confirmation-token check, so a bogus-token probe against an enumerable reservation id can't lock the real customer out.
- **Checkout resilience:** a failed Stripe.js load now removes its `<script>` so a later attempt can retry instead of hanging.

## 1.4.0 - 2026-07-27

> **Take paid bookings with Stripe — no Craft Commerce required.** Booked gains a native, Commerce-free payment path alongside the existing Commerce integration. Direct-payment pages must allow Stripe in their Content Security Policy (`js.stripe.com` / `api.stripe.com`) — see [docs/payments-setup.md](docs/payments-setup.md).

### Added
- **Direct (Commerce-free) payments** — take paid bookings through **Stripe** with no Craft Commerce dependency. A new payment mode (`none` / `direct` / `commerce`) drives an in-page **Stripe Payment Element** checkout: a priced booking is created *pending* and confirmed by a signature-verified **webhook** (the source of truth). Payments are stored in a new `booked_payments` table in minor units, authorized by signed per-reservation tokens, behind a pluggable `PaymentGatewayInterface` (Stripe at launch).
- **Refunds** — full or partial, policy-aware refunds issued from the booking edit screen. A new **payment panel** shows status, the amount, a Stripe-dashboard deep link, and a refund control gated by the new **`booked-manageRefunds`** permission. Refunds issued directly in the Stripe dashboard sync back to Booked automatically.
- **Payments-aware reporting** — in direct mode, revenue reflects **actually-captured** amounts net of refunds; the bookings CSV export gains gateway, external-ID, payment-status, and refunded columns.
- **Operational tooling** — `booked/doctor` now checks direct-payment configuration (key shapes, webhook secret, gateway, currency, and test/live-vs-environment mismatches); a new `booked/payments/reconcile` console command reconciles local records against Stripe as a safety net for missed webhooks; abandoned pending payments are garbage-collected after a configurable TTL (`pendingPaymentTtlMinutes`, default 30).
- **Docs & tests** — [docs/payments-setup.md](docs/payments-setup.md) (setup, webhook, CSP, testing, troubleshooting) and a Playwright browser E2E for the direct-payment checkout (`tests/e2e/`).

### Changed
- Hardened the payment webhook — gateway event-ID de-duplication and idempotent refund reconciliation — plus a stricter per-reservation rate-limit bucket on `payment/create`.
- The legacy-wizard deprecation notice now reads "deprecated as of Booked 1.3 and will be removed in 2.0" for clarity. Internal design docs reworded to past tense now that the vanilla wizard is the default.
- `schemaVersion` bumped to 1.4.1 (payments table + settings columns).

### Removed
- The short-lived Lite/Pro **edition split** was dropped before release: Booked ships as a single full-featured plugin, and direct payments are available to every install.

## 1.3.0 - 2026-07-26

> `{% include 'booked/frontend/wizard' %}` now renders the framework-free vanilla wizard **by default** — no template changes needed. The deprecated Alpine wizard is available for one release window via the **`legacyWizard`** setting (or `{% include … with { legacyWizard: true } %}`), logged as deprecated, and removed in 2.0.

### Added
- **Framework-free booking wizard** — a zero-runtime-dependency rewrite of the booking flow: a headless state-machine core (`BookedWizard.create()`) plus a vanilla renderer, replacing Alpine.js. Runs under a strict CSP (no `unsafe-eval`, no inline executable script — config is read from a JSON block), ~14.5 KB gzipped (core + renderer). See [VANILLA_WIZARD.md](docs/VANILLA_WIZARD.md).
- **Accessible calendar** replacing Flatpickr: WAI-ARIA date grid with roving tabindex, full keyboard navigation (arrows / Home-End / PageUp-Down / Enter-Space), month crossing, and a two-click range mode for multi-day services. Month, weekday, and full-date names are localized from the site language via `Intl`.
- **Booking-management mode** (`?manage=<token>`) — a self-service view to cancel, reduce, or increase the quantity of an existing booking, driven by the reservation's confirmation token.
- **Multi-seat quantity pickers** for slots, multi-day ranges, and events (bounded by remaining capacity), with required-extras enforcement.
- **Captcha widget support** — Turnstile, hCaptcha, and reCAPTCHA v3, with the token minted/refreshed before submit.
- **Versioned headless API** at `/booked/api/v1/…` — the wizard's REST surface as documented public API, aliased onto the existing controllers so the old paths keep working.
- **Soft-lock hold timer** — the wizard now shows and manages the reservation countdown, auto-extends once on submit, and cleanly enters an expired state (new `slot/extend-lock` action + `SoftLockService::extendLock()`, capped by `softLockMaxLifetimeMinutes`).
- **Bring-your-own-frontend** — the core ships as a headless ESM/UMD build (`booked-wizard-core`) drivable with no DOM, for custom/React/Vue frontends.

### Parity (vanilla wizard)
- Full feature parity with the Alpine wizard: service, extras, location, employee, single-slot / fixed-day / flexible-day date selection, multi-seat quantity, customer info, review summary, and Commerce redirect; event-date flow (incl. paid events and per-event quantity); booking management; waitlist branch; honeypot anti-spam.
- **Styling matches the previous wizard** — the same editorial black-and-white design (2px borders, square corners, uppercase controls), driven by `--booked-*` CSS custom properties for theming.
- The review summary and confirmation screen show only the rows that apply (no empty labels), and prices display with the Commerce currency symbol.

### Accessibility
- `role="region"` landmark, live-region step announcements, focus moved to each step heading, `role="alert"` errors, `aria-pressed`/`aria-selected` selection state, `aria-required` on required fields, and full-date accessible names on calendar days.

### Internationalization
- All runtime strings the core emits (announcements, countdown, validation, errors) and the calendar's navigation labels are translatable and shipped in en, de, fr, es, it, ja, nl, and pt.

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