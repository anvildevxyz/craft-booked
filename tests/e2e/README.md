# Browser E2E — direct-payment checkout

A [Playwright](https://playwright.dev) test that drives the **real booking wizard
in a browser** through to the **Stripe Payment Element**, pays with a test card,
and asserts the booking confirms. It covers the one layer the PHP/JS unit tests
and the server-side smoke can't: the actual Stripe Element rendering, card entry,
and 3-D-Secure-free confirmation.

## Prerequisites

1. A booking page **in `direct` payment mode** with Stripe **test** keys
   (`pk_test_…` / `sk_test_…`) configured — see `docs/payments-setup.md`.
2. A service with a **price** so the flow requires payment.
3. Recommended: `stripe listen` forwarding to the webhook so the booking confirms
   the way it does in production. (Without it, the wizard's client confirm-poll
   still finalizes the booking, so the test passes either way.)

   ```bash
   stripe listen --forward-to https://your-site/booked/api/v1/payment/webhook/stripe
   ```

## Run

From the plugin root:

```bash
npm install -D @playwright/test
npx playwright install chromium

BOOKED_E2E_URL="https://your-site/wizard/service" \
  npx playwright test -c tests/e2e/playwright.config.ts
```

Add `--headed` to watch it, or `--debug` to step through.

Test card: `4242 4242 4242 4242`, any future expiry, any CVC, any ZIP. See
[Stripe test cards](https://stripe.com/docs/testing) for decline / 3-D-Secure
variants.

## Note on the Stripe iframe selectors

The spec locates the Payment Element's card fields by placeholder inside Stripe's
iframe. Stripe occasionally changes the Element's DOM/layout (single vs. split
fields, field labels). If the card fields don't fill, run with `--headed` and
adjust the `frameLocator` / `getByPlaceholder` calls in `booking-payment.spec.ts`
to match what Stripe renders for your account.
