# Live payment integration tests

Behavioural tests for direct payments that run the **real plugin code against a
real database and Stripe test mode**. They assert actual persisted state —
covering the money/concurrency behaviours the unit suite can't (there is no
in-process Craft test harness, so DB-bound payment paths are otherwise only
source-scan-tested). They complement the Playwright browser E2E in `tests/e2e/`.

## What `payments.sh` covers

1. **Happy path** — `payment/create` → confirm the PaymentIntent → **webhook** →
   the reservation is confirmed and the payment marked paid.
2. **Currency consistency** — the stored `payment.currency` matches the install
   currency resolver (no forced-USD divergence).
3. **GC paid-exclusion (critical)** — the pending-payment garbage collector does
   **not** cancel a booking that has a paid payment record (the paid-but-cancelled
   race guard).
4. **GC cancels the abandoned** — an unpaid stale `pending` booking *is* GC'd.
5. **Reconcile** — `booked/payments/reconcile` flips a `pending` record whose
   Stripe intent is actually paid (missed-webhook recovery).
6. **Dashboard refund sync** — a `charge.refunded` webhook reconciles the record's
   `refundedAmount`/status (absolute, monotonic).

## Prerequisites

- DDEV running, with the plugin in **`direct`** payment mode and Stripe **test**
  keys + a webhook secret matching an active `stripe listen`.
- The Stripe CLI authenticated (test mode), and a listener forwarding the webhook:

  ```bash
  stripe listen --forward-to https://your-site.ddev.site/index.php?p=booked/api/v1/payment/webhook/stripe
  ```

- A bookable priced service (defaults to id `1236`; override with `SERVICE_ID`).

## Run

```bash
bash tests/integration-live/payments.sh
```

The script seeds its own reservations/payments, asserts, and cleans up after
itself. Exit code is non-zero on any failure.

> These require live infrastructure (DDEV + Stripe test mode), so they run
> out-of-band rather than in the unit `composer test` run — the same model as the
> Playwright E2E. Wire them into CI behind a job that provisions both.
