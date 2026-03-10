# Booked — Roadmap

Planned features and improvements for the Booked plugin.

---

## Recurring Bookings

- Weekly, biweekly, and monthly recurring patterns
- RRULE-based recurrence engine with conflict detection
- Bulk cancel/modify for recurring series
- Custom recurrence rules (e.g. "every 2nd Tuesday")

## Package & Multi-Session Bookings

- Package element type — define bundles (e.g. "10 sessions for $X")
- Session tracking — remaining/used count per customer
- Commerce line item integration for package purchases
- Expiration policies (use within 90 days, etc.)

## Payment Improvements

- Deposit/partial payment support — collect percentage upfront, remainder at appointment
- Stripe direct integration — payment links without requiring full Commerce installation
- Refund automation on cancellation

## No-Show Tracking

- Add `no_show` as a formal reservation status
- Auto-mark as no-show after configurable grace period post-appointment
- No-show statistics in reports dashboard
- No-show penalties — flag repeat offenders, require prepayment

## Audit Log UI

- CP interface to browse and filter audit events
- Search by customer, employee, date range, event type
- Retention policies and log rotation settings

## Testing & Code Quality

- Controller test coverage
- Integration tests — end-to-end booking flows with database
- Reduce PHPStan errors (currently ~428 at level 5)
- GraphQL mutation tests
- AvailabilityService + BookingService edge case coverage

## Additional Integrations

- Apple Calendar (CalDAV) sync
- Marketing automation hooks — Mailchimp, ActiveCampaign post-booking triggers
- CRM sync — push customer data to external systems
- Slack/Discord notifications for team alerting

## Developer Experience

- Webhook expansion — additional event types (waitlist changes, schedule modifications, reminder sent)
- Headless booking widget — framework-agnostic JS component for custom frontends
- Dedicated REST API — beyond existing controller actions
- Plugin settings API — programmatic configuration for multi-environment setups

## Dashboard Widget

- "Today's Bookings" widget — stat cards (today count, pending, confirmed) + upcoming reservations table
- Configurable lookahead (1 / 3 / 7 days)
- Staff-scoped view — filter to managed employees via PermissionService

## CSV Element Exporters

- Reservation exporter — customer name, email, phone, service, employee, location, date, time, duration, status, price, notes
- Employee schedule exporter — working hours per employee per week for payroll/shift planning
- Service catalog exporter — all services with pricing, duration, and availability settings

## Operations & Scale

- Bulk operations — mass cancel, mass reschedule from CP
- Calendar view filters — filter by service, employee, and location in the CP calendar
- Conflict visualization — highlight overlapping bookings in calendar views
- Drag-and-drop reschedule from calendar view (with availability validation)
- Opt-in availability caching — configurable for high-volume installations
- Multi-location aggregate dashboard

## Language Support

- Add Chinese (Simplified), Korean, Arabic, Swedish, Polish, Turkish
- Community contribution workflow for user-submitted translations

## Captcha Providers

- Friendly Captcha — privacy-focused, GDPR-friendly alternative
- Craft-native captcha plugin bridge — delegate to existing Craft captcha plugins
- Custom provider API — register captcha providers via events

## Documentation

- Consolidated troubleshooting guide
- Multi-site deployment guide
- Performance and scaling guide for high-volume installations

## Intake Forms & Custom Booking Questions

- Custom field definitions attached to services
- Field types: text, textarea, select, checkbox, file upload
- Responses stored against the reservation, visible in CP
- Conditional logic (show/hide fields based on answers)
- Digital consent/waiver forms with timestamped acceptance

## Customer Self-Service Portal

- Front-end portal page (email + confirmation code auth, or magic link)
- View upcoming and past bookings
- Reschedule and cancel from portal
- Optional Craft user account linking for logged-in customers

## Round-Robin & Smart Assignment

- Round-robin assignment mode per service (rotation-based)
- Workload-balanced assignment (fewest bookings gets next)
- Skill-weighted routing based on employee-service assignments
- Customer preference memory (assign same employee as last time)

## Shared Resource Management

- Resource element type (rooms, equipment, stations) with quantity
- Resource-service assignments (service X requires resource Y)
- Availability engine considers resource capacity alongside employee availability
- Resource-only bookings (no employee — e.g. meeting room rental)

## Collective / Multi-Host Bookings

- Multi-employee reservation type (all must be free)
- Availability intersection calculation
- "Any N of M" mode (e.g. need 2 of 5 available trainers)

## Booking Rescheduling

- Reschedule action on manage-booking page — reuse date/time picker from booking wizard
- Availability re-validation against current slots (respecting buffers, blackouts, capacity)
- Cancellation policy enforcement — same cancellation window applies to reschedule
- Email notifications for rescheduled bookings (customer + employee)
- Calendar sync update (ICS regeneration, Google/Microsoft calendar event update)
- Reschedule limit — cap how many times a booking can be rescheduled
- CP-side reschedule from reservation edit screen

## Per-Service Booking Windows

- Per-service minimum advance time override
- Per-service maximum advance window override
- Per-employee overrides on top of per-service rules

## Coupons & Promo Codes

- Coupon code system (percentage or fixed discount)
- Configurable rules: expiry date, usage limit, specific services
- Applied at review step in booking wizard
- Auto-generated codes for marketing campaigns

## Gift Certificates

- Gift certificate purchase flow (amount or service-specific)
- Unique redemption codes with balance tracking
- Commerce line item integration for gift purchases
- Email delivery with branded certificate template

## Walk-In / POS Mode

- Simplified CP booking creation (employee-facing quick-add)
- Pre-filled employee/location based on logged-in staff
- Tablet-optimized layout for reception desks

## Session Notes

- Post-appointment notes field on reservations (rich text)
- Access-controlled: only assigned employee and admins can view/edit
- Structured note templates per service type

## Per-Service Notification Controls

- Per-service SMS toggle (confirmation, reminder, cancellation) overriding global defaults
- Per-service email notification overrides (disable confirmation emails for specific services)
- Per-service notification templates — custom SMS/email content per service

## Social Channel Booking

- "Book Now" buttons on Google Business Profile, Facebook, and Instagram
- Shareable direct-link URLs with pre-selected service/employee
