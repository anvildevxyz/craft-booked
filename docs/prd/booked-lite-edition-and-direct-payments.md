# PRD: Booked Lite Edition + Direct Payments (Commerce-Free)

Status: **Draft v2** (reconciles the "Direct Payments" draft v1 with a Lite-edition packaging strategy)
Owner: Anvil / Booked
Date: 2026-07-26
Target release: **Booked 1.4.0** (minor — introduces a second edition + a native payment layer)

> ⚠️ **Decisions still open** — the feature matrix in §6 and the open questions in §13 need sign-off before we break this into issues. Everything marked _(PROPOSED)_ is a starting point, not a commitment.

---

## 1. Summary

Two connected moves that make Booked competitive at the low end without weakening the full product:

1. **Booked Lite** — a lower-priced, lightweight edition for solo/small service businesses who want *simple paid bookings* and nothing else. Competes head-on with lightweight entrants (see §2).
2. **Direct payments** — a native payment path (Stripe first) that takes paid bookings through payment gateways **with zero Craft Commerce dependency**. This is the anchor capability of Lite and is *also* available in the full edition. Commerce integration remains fully supported.

Store one-liner (Lite): _"Take paid bookings with Stripe — no Commerce, no bloat."_

## 2. Background & competitive context

Booked's only paid-booking path today routes through Craft Commerce (cart → checkout → order): Commerce Pro licensing, cart/checkout templating, tax and order setup — all for what is functionally "pay for one appointment." (Recon: payment state is derived from Commerce order status; there is no native payment layer — `CommerceService`, `commerceEnabled`, `booked_order_reservations`.)

A new competitor (**Stub**, July 2026) leads on exactly this gap: direct Stripe, no Commerce, lightweight. Its feature depth is far below Booked's, but "no Commerce + cheaper + simple" wins the plugin-store comparison for the price-sensitive buyer. **Booked Lite** meets that buyer on price and simplicity; the full edition keeps every differentiator (waitlist, calendar sync, SMS, multi-location, GraphQL, refund policies, reports, MCP).

> **Packaging note (reversal of Direct-Payments draft v1 §11).** Draft v1 recommended *against* a Lite edition, arguing the threat is positioning not price, and that a second edition doubles the support/QA matrix. That tradeoff is real and accepted here: the product owner has chosen the **edition** strategy to compete on price as well as positioning. §11 records the accepted costs and how we contain them.

## 3. Problem statement

Service businesses that only need "let customers book and pay" must adopt a full e-commerce stack (Commerce) *and* pay full-Booked pricing. That loses the sale at the comparison stage to a cheaper, simpler competitor. We need (a) a payment path that needs no Commerce, and (b) a price/edition tier matched to the simple use case.

## 4. Goals / non-goals

### Goals
- A customer can complete a paid booking end-to-end with **only Booked Lite** installed (no Commerce).
- Payment provider is pluggable behind one interface; **Stripe** ships at launch; a second gateway (Mollie or PayPal) within two minor releases.
- A clean **two-edition** model (`lite` / full) with a single, well-documented feature boundary and gating that fails safe.
- **Zero breakage** for existing installs: they remain the full edition; payment mode and edition are settings/state, not destructive migrations.
- Existing refund policies work identically in direct mode. Direct checkout inherits all soft-lock + anti-abuse protections.

### Non-goals
- Cart with multiple bookings/products per checkout (stays Commerce-mode territory).
- Invoicing, tax engines, coupon/discount systems, subscriptions/recurring billing.
- Replacing Commerce mode or deprecating `CommerceService`.
- A third edition. Two editions only.

## 5. Editions model _(NEW)_

**Current state (recon):** `Booked.php:99` declares `EDITION_PRO = 'pro'`; `editions()` returns `['pro']`; **no `is()`/`requireEdition` gating exists anywhere** — the constant is inert. So gating is greenfield.

### 5.1 Editions
- `editions()` → `['lite', 'pro']` (ascending: `lite` < `pro`). Keep the existing **`pro`** handle for the full edition to avoid churn and preserve existing installs' stored edition. (Marketing name can be "Booked" / "Booked Pro"; store copy TBD.)
- Existing installs already have edition `pro` stored → they stay full-featured, no action needed.
- New installs default to the purchased edition; dev/unlicensed defaults to `pro` for local parity (Craft convention) — confirm in §13.

### 5.2 Gating mechanism
A single helper — `Booked::getInstance()->is(self::EDITION_PRO)` / a `requirePro()` guard — enforced at these seams (all already per-feature in the codebase, so gating is additive, not a refactor):
- **CP nav**: filter the `$navDefs` list (`Booked.php:803-817`) by edition.
- **CP routes / controllers**: `beforeAction` edition guard on Pro-only CP controllers (Reports, Webhooks, Calendar sync, etc.).
- **Service/feature registration**: skip registering Pro-only event listeners/services (calendar sync, SMS, webhooks, MCP) under Lite.
- **Settings tabs**: hide Pro-only tabs (`safeAttributesForTab`, `Settings.php:360-417`).
- **GraphQL / front-end**: Pro-only queries/mutations return an edition error; Lite front-end templates never surface Pro affordances.
- **Fail-safe:** gating is deny-by-default for Pro features when edition is Lite; a mis-set edition never *exposes* a paid feature, and never *breaks* a Lite booking.

### 5.3 Upgrade path
Lite → Pro is a Craft edition change (no data migration); Pro features light up on upgrade. Doctor console check reports current edition + any settings referencing gated features.

## 6. Feature matrix _(PROPOSED — needs sign-off)_

The boundary that makes Lite "simple paid bookings" and keeps every advanced capability in Pro. **This table is the key decision.**

| Capability | Lite | Pro |
|---|:--:|:--:|
| Core booking wizard (services, availability, slots, customer info, review) | ✅ | ✅ |
| **Direct payments (Stripe)** — the anchor | ✅ | ✅ |
| Email confirmation + reminder | ✅ | ✅ |
| Cancellation + refund (single default policy) | ✅ | ✅ |
| Soft-lock hold + anti-abuse (captcha/honeypot/rate limits) | ✅ | ✅ |
| Employees & schedules | ✅ (single?) | ✅ |
| Extras / add-ons | ✅ | ✅ |
| Multiple locations | ❌ | ✅ |
| Waitlist | ❌ | ✅ |
| Calendar sync (Google / Outlook) | ❌ | ✅ |
| SMS notifications (Twilio) | ❌ | ✅ |
| Virtual meetings (Zoom / Meet / Teams) | ❌ | ✅ |
| Event dates (group/ticketed events) | ❓ | ✅ |
| Multi-day / recurring bookings | ❌ | ✅ |
| Tiered refund policies | ❌ | ✅ |
| Webhooks | ❌ | ✅ |
| Reports & dashboard | ❌ (basic list only) | ✅ |
| GraphQL API | ❌ | ✅ |
| MCP tools | ❌ | ✅ |
| Craft Commerce payment mode | ❌ | ✅ |

**Open matrix questions:** single vs multiple employees in Lite? Event dates in Lite (they're a strong solo use case) or Pro-only? Basic-list reports vs none in Lite? — see §13.

## 7. Direct payments — technical design

_(Carried from Direct-Payments draft v1 §6, unchanged in substance — edition-agnostic. Payments work identically in Lite and Pro; only the *presence* of Commerce mode differs by edition.)_

### 7.1 Payment modes
New setting `paymentMode: 'none' | 'direct' | 'commerce'`, replacing `commerceEnabled` with an automatic settings migration (`commerceEnabled=true` → `'commerce'`, else `'none'`). Exactly one mode active per install. **Lite** offers only `none` | `direct` (Commerce mode is Pro-gated).

### 7.2 Gateway abstraction
`contracts/PaymentGatewayInterface` — `getHandle()`, `createPayment()`, `confirmPayment()`, `refund()`, `verifyWebhook()`, `getFrontendConfig()`, `supportsPartialRefunds()`. Gateways register via `EVENT_REGISTER_PAYMENT_GATEWAYS` (matches Booked's existing extensibility story). Launch adapter: **Stripe** (Payment Element, `stripe/stripe-php`, SCA/3DS via Payment Intents). Fast follows: **Mollie**, then **PayPal** (redirect flow — validates the abstraction handles redirect-style gateways). Spec Mollie/PayPal against the interface *before* freezing it in M1.

### 7.3 Checkout flow (direct mode)
1. Wizard reaches payment step → soft lock already held.
2. Front-end → `booked/payment/create`: validate lock + reservation state, `gateway->createPayment()`, persist `booked_payments` row (status `pending`), return client config + a **signed payment token** (HMAC over reservation UID + payment ID, keyed with Craft's security key) required on all subsequent payment endpoints (prevents reservation-ID enumeration).
3. Customer pays in the gateway UI component.
4. **Confirmation is webhook-driven as source of truth** (`payment_intent.succeeded` etc.); client `payment/confirm` poll is UX-only. Reservation → `confirmed` **only** on verified webhook or verified server-side retrieval — never on client say-so.
5. Soft lock released on confirmation; existing notification pipeline (email/SMS/webhooks/ICS/calendar sync) fires unchanged.
6. Failure/abandonment: soft lock expires as today; pending `booked_payments` rows GC'd by a queue job after a configurable TTL.

Free services and mode `none` bypass the payment step (current behavior).

### 7.4 Refunds
`RefundService` gains a strategy split: Commerce path (existing) vs direct path (`gateway->refund()`). `RefundPolicyService` stays gateway-agnostic and feeds the amount into either path. Partial refunds gated on `supportsPartialRefunds()`. (Tiered policies are Pro per §6; Lite ships a single default policy.)

### 7.5 Data model
New table **`booked_payments`**: `id, uid, dateCreated, dateUpdated`; `reservationId` (FK → reservation, CASCADE); `gateway` (handle); `externalId` (indexed); `status` (`pending`/`authorized`/`paid`/`failed`/`refunded`/`partiallyRefunded`); `amount`, `currency`, `refundedAmount` (**minor units, integer**); `payload` (JSON snapshot). Reservation gets a computed `paymentStatus` sourced from Commerce order **or** payments table by mode, so CP columns, conditions, GraphQL, and exports need no per-mode branching. Adds a migration → **bump `schemaVersion`** (currently `1.2.1`).

### 7.6 Settings & CP
- Settings → **Payments** tab: mode selector; per-gateway credential fields with `$ENV_VAR` autosuggest; currency; deposit toggle + % (see §13); webhook endpoint URL with copy button + "Send test webhook / verify credentials" action.
- Reservation edit screen: payment detail panel (gateway, status, external ID deep-link, policy-aware refund button).
- New permission **`booked:manageRefunds`** (money-out — not bundled into `manageBookings`).
- Reports: revenue reads from payments table in direct mode; CSV exports gain gateway + external-ID columns (Pro).

### 7.7 Security
Mandatory webhook signature verification per gateway (unverifiable events dropped + logged; CSRF exempt only on the webhook route). Reuse `BookingSecurityService` (captcha/honeypot/min-time/rate limits) on `payment/create` in a **separate stricter bucket**. Signed payment token on every payment endpoint. Idempotency keys on gateway calls; webhook handling idempotent by `externalId` + event ID. Secrets via env-var syntax only (settings warns on plaintext). **Amounts always computed server-side** from service + extras + refund policy; client never sends an amount.

## 8. Compatibility & migration
- Settings migration maps `commerceEnabled` → `paymentMode` (no data migration).
- Existing installs stay edition **`pro`** → no feature loss.
- `CommerceService`, cart URLs, tax categories untouched in Commerce mode.
- Switching modes on a live site allowed, with a CP warning that in-flight pending payments resolve via their original path (webhooks route by the payment record's gateway).
- GraphQL: `paymentStatus` semantics unchanged; new permission-gated `payments` sub-query on reservations (Pro).

## 9. Rollout plan

| Phase | Scope | Exit criteria |
|---|---|---|
| **M0** | Editions scaffolding: `editions()` → `[lite, pro]`, `is()`/`requirePro()` helper, gating at nav/route/service/settings/GraphQL seams, default-edition + existing-install handling | Lite install hides Pro features cleanly; existing installs remain full; edition tests green |
| **M1** | `PaymentGatewayInterface`, `booked_payments` table, Stripe adapter, direct checkout happy path, `paymentMode` setting + migration | Paid booking end-to-end on a clean Craft 5 install **without Commerce**, integration-tested against Stripe test mode |
| **M2** | Refunds (full + partial + policy), reservation payment panel, reports/exports (Pro) | Refund-matrix tests green; docs draft |
| **M3** | Hardening: webhook idempotency, GC job, rate limits, doctor payment-config checks, `booked/payments/reconcile` command | Pen-test checklist pass; beta with 3–5 customers |
| **M4** | **GA in 1.4.0** — Lite + direct payments; plugin-store Lite listing + copy/screenshots | Release |
| **M5** | Mollie adapter (validates the abstraction) | Second gateway live |

## 10. Success metrics
- ≥30% of new Lite licenses activate direct mode within 90 days of GA.
- Plugin-store conversion (view → purchase) up after the Lite listing + copy refresh.
- Payment-related tickets <15% of ticket volume in the first quarter after GA.
- **Zero incidents of unpaid-but-confirmed reservations** (webhook/state-machine correctness).
- Lite→Pro upgrade rate (validates the tier as an on-ramp, not just a discount).

## 11. Packaging decision & accepted costs
Chosen: **two editions (`lite`, `pro`)**, direct payments in both, Commerce + advanced features Pro-only. Accepted costs (raised in draft v1) and containment:
- **Doubled support/QA matrix** → keep the boundary *coarse and setting-aligned* (whole feature areas, not micro-flags); an edition test suite asserts Lite hides/denies every Pro feature; doctor reports edition.
- **"Booked is heavy" perception** → Lite's store listing leads with simplicity + "no Commerce"; Pro listing leads with depth.
- **Cannibalization risk** → price Lite as an on-ramp; track Lite→Pro upgrades (§10). Revisit if data shows price-driven churn without upgrades.

## 12. Risks
- **Payment-state bugs are trust-killers** → webhook-as-source-of-truth, idempotency, `reconcile` command, integration tests against real test-mode APIs (not mocks only).
- **Support surface widens** (Stripe account issues become our tickets) → credential-verification action, doctor checks, troubleshooting doc.
- **Abstraction leaks Stripe assumptions** → spec Mollie/PayPal (redirect!) against the interface before freezing in M1.
- **Edition gating gaps** (a Pro feature leaking into Lite, or a Lite booking broken by a guard) → deny-by-default + an edition test suite is a release gate.

## 13. Open questions
**Payments (from draft v1):**
1. **Deposits** — partial prepayment (e.g. 20% now, rest on-site): M2 or later? Strong salon differentiator; adds refund math.
2. **Currency** — per-location vs single install-wide in direct mode?
3. **Stripe Payment Links** — support as a zero-frontend fallback for very simple sites (natural Lite fit)?
4. **Second gateway** — Mollie vs PayPal; decide by beta-customer survey?
5. **Minor units** — normalize the integer/decimal shape difference vs Commerce at the reports layer or at storage?

**Editions (new):**
6. **Matrix boundary** (§6): single vs multiple employees in Lite? Event dates in Lite or Pro? Basic-list reports in Lite or none?
7. **Edition naming** — keep `pro` for the full edition (least churn) vs rename to `standard` to match earlier copy? Store display names?
8. **Default edition** for new/unlicensed/dev installs.
9. **Trial/upgrade UX** — in-CP prompts to upgrade Lite→Pro when a gated feature is touched?
