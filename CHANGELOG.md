# Changelog

## Unreleased

### Fixed
- **A schedule's capacity now applies to employees, not only to services without them.** Issue #85 taught the availability calculation to keep a slot open until every seat of the day's capacity was taken, but only where the service had no employees. A slot belonging to an employee kept exactly one seat, so a schedule set to three still closed after the first booking. Where the capacity sat on the service's schedule the numbers disagreed with what the calendar did: the slot reported three free seats and vanished anyway, because the employee's booking was cut out of their working hours before the seats were ever counted. A booking on the service being offered now takes one seat of the employee's capacity, and a booking that employee holds on any *other* service still blocks their whole range — someone busy elsewhere cannot host a seat here. Seats are counted per employee, matching how capacity already works per service, so two employees on a capacity-3 schedule offer six seats in parallel rather than three shared ones. Merged "any available" slots now report the seats of every employee behind them rather than of whichever one came first, and a party booking is measured against seats instead of head count (#109).

- **Whole-day and flexible-day services honour capacity for an employee too.** A booking with no time still built `activeSlotKey` from an empty time segment — `2026-10-01||42` — so the second booking of a date collided on the unique index and an employee's multi-day service could never take more than one booking, whatever its schedule granted. Timeless bookings now take their seats from the day's capacity, which `ScheduleResolverService::getCapacityForDay()` already resolved correctly for the availability side. A multi-day service with no capacity set still takes exactly one booking per date (#109).
- **A party is only offered a slot one employee can seat.** Seats were added up across staff, so two employees with two seats each advertised four — but a booking is one reservation held by one employee, and the wizard reads that same number as the largest party it will let a customer pick. The calendar offered a party of four and the booking service then refused it, which is the dead end this issue is about pointing the other way. Merged "any available" slots now advertise the roomiest single employee, and the quantity filter asks whether any one of them can seat the party. Single bookings still drain every employee, so the pool is unchanged (#109).
- **The Control Panel accepts the seats the wizard sells.** `checkForBookingConflicts()` treated any overlapping booking for the employee as a clash, so an admin adding the second booking of a capacity-3 slot was refused while the wizard was still selling the third. It now measures the booking against the same per-day capacity, and an unresolvable capacity still falls back to one seat rather than waving the booking through (#109).
- **A soft lock holds one seat of a group slot, not the whole employee.** In "any available" mode the lock was charged twice — `filterSoftLockedSlots` took the held seats off the slot, then the post-deduplication filter zeroed the same employee out for holding a lock — so a slot with free seats left the calendar the moment anyone opened the booking form on it. Seats reaching that filter are already lock-adjusted, so it no longer re-tests the lock (#109).
- **The booking service accepts the seats the calendar offers.** Fixing availability alone left the write path one booking behind: `activeSlotKey` — the unique index that stops an employee being double-booked — is `date|time|employeeId`, so the second booking of a group slot collided with the first and came back as "This time slot was just booked by another user", even though the calendar was still offering it. One row per employee per slot stops being the invariant once a schedule grants seats, so a multi-seat booking now leaves that key `NULL` and is guarded the way employee-less group slots always have been: `BookingService` takes a mutex on the slot and re-checks the remaining capacity inside it. Single-seat bookings keep the index exactly as before. Covered by a concurrency check that three attempts at a two-seat slot leave two bookings holding it (#109).

### Changed
- The capacity field's help text on a Schedule now says that the number applies per employee as well as per service, and the Assigned Schedules field on an Employee says that a schedule's capacity governs that employee's slots. One capacity control can be attached in two places, and until now only the wording for one of them existed.
- `php craft booked/availability/check` reports the same windows the calculation uses. Steps 5 and 6 had their own copy of the subtraction rule, which knew nothing about capacity, so on a group slot the command hid a slot that its own "Final Result" section listed — misleading on precisely the setup someone would run it to debug. Both paths now call the real subtraction, and the seats per slot are printed alongside the bookings.

### Internal
- Employees' working-hour rows no longer carry `capacity` and `simultaneousSlots`. Both were written as a hard-coded `1` and never read, and they looked like the place per-employee capacity lived.
- **The PHP 8.4 CI job was reporting success while running no tests at all.** An implicit-nullable parameter in `AvailabilityCapacityTest` stopped the file loading on 8.4, which took down the whole run before the first test — and PHPUnit exited 0 anyway, so the job went green. The exit code came from Yii: it defaults `silentExitOnException` to `YII_ENV_TEST`, so an uncaught throwable renders and returns rather than exiting non-zero. The suite now turns that off, and a run that dies while loading its test files fails. Verified both ways: a clean run still exits 0, and a deliberately reintroduced load failure exits 1.
- The suite's error handler now ignores `E_DEPRECATED` raised from files under `vendor/`. `phpunit.xml` sets `error_reporting` to `-1` and Yii turns anything in that mask into an exception, so on 8.4 every implicit-nullable parameter in a package we do not own became fatal — `craft\console\Controller::output(string $string = null)` is one, still present in Craft 5.10.13.2, and it took down six tests that load a console controller. Deprecations in Booked's own code stay fatal.

## 1.4.7 - 2026-08-13

### Added
- **Booking data can be imported from the Slots plugin.** Slots is Booked's smaller sibling — the same availability engine and booking wizard with the integrations stripped out — and a site that outgrows it now has a way in: `php craft booked/import/from-slots [--dry-run] [--append] [--prefix=]`. Both plugins install into the same Craft project under different table prefixes, so the command reads Slots' tables directly; there is no export file, and Slots need not be uninstalled first. It imports services, staff, locations, schedules, blackout dates, their join rows, bookings and payments in one transaction, and refuses to run when Booked already holds data unless `--append` is given (#87).

### Fixed
- **Booked installs again next to plugins that cap the Stripe SDK lower.** Booked required `stripe/stripe-php` `^21.0`, which no version of Solspace Freeform accepts — Freeform 5 caps the same SDK at `^15`, Formie at `^16`, and Craft Commerce's Stripe gateway at `^13` — so Composer refused the install with "found stripe/stripe-php[v21.0.0, ..., v21.2.0] but these were not loaded, likely because it conflicts with another require". The requirement is now a range, `^13.0` through `^21.0`. Booked only uses Payment Intents create/retrieve, refunds create and webhook signature verification, and those signatures are identical across that range (#105).
- **A trashed booking keeps its data.** `Reservation::afterDelete()` deleted the `booked_reservations` row outright, so a booking that was only soft-deleted was emptied of its data while its element sat in the trash, and restoring it produced a blank reservation. A soft delete now also releases the slot — a booking in the trash should not hold a seat against everyone else — and restoring reclaims it, or returns the booking without it when the slot has been taken meanwhile (#93).
- **Two confirmed bookings could hold the same employee at the same time.** `activeSlotKey` interpolated `startTime` raw, which is `"14:00"` on a reservation built from a request and `"14:00:00"` on one read back from the `TIME` column. Two spellings of one slot are two different keys, and the unique index that guards against double-booking cannot see a collision between strings that differ — so the only requirement for a clash was that one of the two bookings had been re-saved, and a Control Panel edit is enough. The key is now normalised to `H:i`, and a migration rewrites existing rows. It leaves any genuine double bookings it uncovers alone and names them, rather than silently deciding which one loses its slot.

### Security
- **Dependency lock refreshed, clearing 84 advisories across 13 packages**, including two high-severity Craft remote-code-execution advisories, a stored XSS, and CVE-2026-55599 in phpseclib — X.509 validation follows Authority Information Access URLs, so certificate parsing can be steered into making outbound requests on the server's behalf. Nothing about what the plugin requires changed; the lock had simply never moved (#100).

### Changed
- **The Packagist archive no longer carries the whole repository.** Booked had no `.gitattributes`, so 1.4.6 shipped 180 test files, the CI workflows, the PHPStan/ECS/Rector/vitest configuration, the build script and several documents written for maintainers rather than for the people installing the plugin.
- Documentation caught up with the code: `EVENT_SYSTEM.md` gained the two payment events it was missing — `EVENT_PAYMENT_REFUNDED`, whose amounts are integers in the currency's minor unit rather than floats, and `EVENT_REGISTER_PAYMENT_GATEWAYS`, the extension point for adding a gateway beyond the bundled Stripe one — and the README's feature list gained Session Notes and No-Show Tracking.

### Internal
- The repository has a CI workflow for the first time, running ECS, PHPStan and the test suites on PHP 8.2, 8.3 and 8.4. A `stripe-floor` job installs the lowest supported Stripe SDK and re-runs the analysis and the suite against it, because the committed lock pins the ceiling and nothing else would notice the floor breaking.
- `m260803_000000_reservation_element_fk` is now a no-op. It added a foreign key from `booked_reservations.id` onto `elements.id` and, because a constraint cannot be added while rows violate it, deleted every reservation without a matching element first — on an install without Craft Commerce that is every booking on the site, since reservations are only Craft elements when Commerce is enabled. Neither that migration nor its removal was ever released, so no upgrade from 1.4.6 or earlier was affected, but installs tracking the default branch may have applied it (#102).

## 1.4.6 - 2026-07-30

### Changed
- **The Capacity field on a schedule now describes what it actually does.** It said an empty value meant unlimited, and offered an "∞" placeholder, when an empty value has always meant one booking per time slot. It also now states that capacity is counted **per service** — several services sharing one schedule each get their own seats rather than drawing on a common pool, so a shared room can take more bookings than the number set here — and that a service's buffers can shift the start times offered later in the day once a slot fills.

### Fixed
- **A Schedule's per-day capacity is honored again when generating slots.** A single booking withdrew the time slot outright, however many seats the schedule granted, so group bookings on an employee-less service were impossible — the remaining seats were unreachable rather than merely unlisted, because booking creation validates against the same slot list. Bookings now consume seats, and a slot only leaves the working hours once every seat is taken (#85).
- **The booking wizard shows how many seats a group slot has left.** The count was carried in the markup but never displayed, so a customer could not tell a nearly-full slot from an empty one. Slots with a single seat, and unlimited slots, stay unannotated as before.
- **A no-show no longer frees a seat on a group slot.** Availability treats a no-show as still occupying its slot, but the seat count did not, so a slot with a no-show advertised a seat that was already taken. Both now agree: everything but a cancellation holds its seat.
- **Rescheduling a booking to its own time works again.** The booking being moved was excluded from availability for staff-based services but not for employee-less ones, and never from the seat count, so moving a booking onto the slot it already occupied was refused as a conflict. The per-request slot cache also ignored the exclusion, so a reschedule check could be answered from a non-reschedule result.
- **One service's group bookings no longer close another service's staff slots.** Employee-less bookings were counted against every service on the day rather than the one they were made against, so an "any available" slot with free staff behind it disappeared whenever an unrelated group booking overlapped it.
- **A booking near midnight no longer breaks availability.** Applying a buffer that ran before 00:00 or past 24:00 threw instead of clamping to the day.
- **Day-capacity and waitlist-eligibility lookups no longer fail** for services whose staff have no active schedule on the requested date.

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