# Booked Vanilla Wizard — Developer Guide

The booking wizard is a **framework-free** component: a headless core (state
machine + API client, zero runtime dependencies) plus a vanilla renderer. This
guide covers the three ways to use it — drop-in Twig, template customization,
and fully headless — plus the public JS API and the `data-booked-*` contract.

> Status: 1.3.0. `{% include 'booked/frontend/wizard' %}` renders the vanilla
> wizard by default; the deprecated Alpine wizard is restored with the
> `legacyWizard` setting (removed in 2.0).

---

## 1. Drop-in (no code)

```twig
{% include 'booked/frontend/wizard' %}       {# booking flow #}
{% include 'booked/frontend/event-wizard' %} {# event flow #}
```

Optional include params: `serviceId` (preselect), `labels` (override any label).
The include registers the asset bundle, renders the markup, and emits a JSON
config block the bundle auto-initializes from — **no inline script**, so it runs
under a strict `script-src 'self' 'nonce-…'` with no `unsafe-eval`.

Theme with CSS custom properties (no framework, no build):

```css
.booked-wizard {
  --booked-primary: #0b5cff;
  --booked-radius: 6px;
  --booked-accent: #0b5cff;
}
```

---

## 2. Customize the markup (Twig, no build step)

Copy the template into your project's `templates/` and edit the markup. Behavior
is driven entirely by `data-booked-*` attributes — the stable contract between
your HTML and the bundle. Keep those; change everything else (classes, layout,
copy). Card lists are `<template>` elements cloned per item.

Key hooks:

| Attribute | Purpose |
|---|---|
| `[data-booked-wizard][data-booked-auto]` | Root; auto-initialized on load |
| `<script type="application/json" data-booked-config>` | Config (CSRF, site, labels, flow) |
| `[data-booked-step="service\|extras\|location\|employee\|datetime\|event\|info\|review\|success"]` | Step regions (one shown at a time) |
| `[data-booked-step-heading]` | Focus target on step change |
| `[data-booked-template="service-card\|extra-card\|location-card\|employee-card\|event-card"]` | `<template>` cloned per item (inside its step) |
| `[data-booked-list="services\|extras\|locations\|employees\|events"]` | Card container |
| `[data-booked-action="next\|back\|submit\|select-service\|select-location\|select-employee\|select-event\|extra-increment\|extra-decrement\|join-waitlist"]` | Delegated actions |
| `[data-booked-field="…"]` | Card/summary field slots and info inputs |
| `[data-booked-calendar]` / `[data-booked-slots]` | Calendar mount / slot listbox |
| `[data-booked-progress]`, `[data-booked-live]`, `[data-booked-error]`, `[data-booked-loading]` | Chrome |
| `[data-booked-honeypot]`, `[data-booked-captcha-token]` | Anti-spam fields sent with submit |

---

## 3. Headless / bring-your-own-frontend

Drive the core directly — no renderer, no DOM. The core is published as an
ESM/UMD build (`dist/booked-wizard-core.*`).

```js
import { create } from '@anvildev/booked-wizard/core';

const wizard = create({
  // omit `mount` for headless
  flow: 'booking',                 // 'booking' | 'event'
  serviceId: 12,                   // optional preselect
  locale: 'de',
  api: { baseUrl: '/booked/api/v1', csrf: { name, value }, site: 'default' },
  config: { requirePhone: false },
  labels: { /* same keys as Twig */ },
});

wizard.on('state:change', ({ from, to, stepId }) => { /* … */ });
wizard.on('booking:confirmed', ({ reservation }) => { /* … */ });

await wizard.start();
await wizard.selectService(12);
wizard.goNext();
await wizard.selectSlot({ date: '2026-08-01', time: '10:00' }); // acquires the hold
wizard.goNext();
wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
wizard.goNext();
const result = await wizard.submit();  // { confirmed } | { paying, redirectUrl } | { expired } | { error }
```

### Public methods (semver'd)

`start()`, `getState()`, `goNext()` / `goBack()`, `selectService(id)`,
`selectExtra(id, qty)` / `clearExtra(id)`, `selectLocation(id)` /
`selectEmployee(id)`, `selectSlot({date,time,quantity})`,
`selectRange({startDate,endDate,quantity})`, `selectEventDate(id,{quantity})`,
`setCustomer({name,email,phone,notes})`, `submit({fields,addToCart})`,
`joinWaitlist(payload)`, `releaseLock()`, `reset()`, `destroy()`.

Availability loaders (for custom calendars): `loadCalendar({year,month})`,
`loadSlots({date})`, `loadDates({month})`, `loadEndDates({startDate})`,
`loadRangeCapacity({startDate,endDate})`, `loadEventDates()`.

### Events

`state:change`, `step:change`, `data:loaded`, `slot:selected`, `range:selected`,
`event:selected`, `lock:acquired`, `lock:expiring`, `lock:extended`,
`lock:expired`, `lock:released`, `payment:redirect`, `payment:mount`,
`booking:confirmed`, `waitlist:joined`, `announce`, `error`.

Expected domain failures (validation, taken slot, expired lock) surface as
states/events — the promise methods don't throw for them. `announce` events
carry i18n'd strings for an `aria-live` region.

---

## 4. Lifecycle states

```
idle → loading → browsing ⇄ holdingLock → submitting → paying → confirmed
                     │            │            │           │
                     └──────── error ◄─────────┴──── expired ◄──┘
```

`holdingLock` means a soft-lock is held with a live countdown; the core
auto-extends it once when the user commits, then expires cleanly. Event
bookings, whose server-side seat lock is best-effort, submit directly from
`browsing`.

---

## 5. Migrating from the Alpine wizard

- The include path and documented config variables are unchanged — existing
  drop-in usage keeps working, now rendering the vanilla wizard.
- Need the old wizard while you migrate a heavy customization? Enable the
  **`legacyWizard`** setting (or `{% include 'booked/frontend/wizard' with
  { legacyWizard: true } %}`). It's deprecated and removed in 2.0.
- Forked the old Alpine wizard? The structure maps to **Twig template overrides
  + JS events**: markup you edited in `x-*` attributes becomes `data-booked-*`
  markup; logic you patched becomes an event listener on the core. There is no
  Alpine to fork — behavior lives in the core, markup in your Twig.
- The REST endpoints the wizard calls are now the versioned, documented
  `/booked/api/v1/…` surface; pin to it for custom frontends.
