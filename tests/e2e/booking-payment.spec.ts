import { test, expect, type Page } from '@playwright/test';

/**
 * End-to-end browser smoke of the direct-payment booking flow: drive the vanilla
 * wizard through to the Stripe Payment Element, pay with a test card, and assert
 * the booking confirms. This is the one layer the headless/server smoke can't
 * cover — the real Stripe Element rendering + card entry + confirmation.
 *
 * Prerequisites (see tests/e2e/README.md):
 *   - A booking page in **direct** payment mode with Stripe **test** keys.
 *   - BOOKED_E2E_URL pointing at that page (defaults to the DDEV dev site).
 *   - Ideally `stripe listen` forwarding to the webhook; the client confirm-poll
 *     is the fallback, so success still shows without it.
 */

const BOOKING_URL =
  process.env.BOOKED_E2E_URL || 'https://craft-plugin-dev.ddev.site/wizard/service';

/** Run `action` only if the given wizard step is currently visible (steps are conditional). */
async function ifStepVisible(page: Page, stepId: string, action: () => Promise<void>) {
  const region = page.locator(`[data-booked-step="${stepId}"]`);
  if (await region.isVisible().catch(() => false)) {
    await action();
  }
}

test('direct payment: wizard → Stripe Element → booking confirmed', async ({ page }) => {
  await page.goto(BOOKING_URL);

  // 1. Service
  const serviceStep = page.locator('[data-booked-step="service"]');
  await expect(serviceStep).toBeVisible();
  await serviceStep.locator('[data-booked-action="select-service"]').first().click();
  await serviceStep.locator('[data-booked-action="next"]').click();

  // 2. Optional steps — pick the first option (if any) and advance.
  for (const step of ['extras', 'location', 'employee']) {
    await ifStepVisible(page, step, async () => {
      const region = page.locator(`[data-booked-step="${step}"]`);
      const pick = region.locator('[data-booked-action^="select-"]').first();
      if (await pick.isVisible().catch(() => false)) await pick.click();
      await region.locator('[data-booked-action="next"]').click();
    });
  }

  // 3. Date + time
  const datetime = page.locator('[data-booked-step="datetime"]');
  await expect(datetime).toBeVisible();
  await page.locator('[data-booked-date]:not([aria-disabled="true"])').first().click();
  await page.locator('[data-booked-slots] button:not([disabled])').first().click();
  await datetime.locator('[data-booked-action="next"]').click();

  // 4. Customer info
  const info = page.locator('[data-booked-step="info"]');
  await expect(info).toBeVisible();
  await info.locator('[data-booked-field="name"]').fill('E2E Tester');
  await info.locator('[data-booked-field="email"]').fill('e2e@example.com');
  await info.locator('[data-booked-action="next"]').click();

  // 5. Review → submit. Creates the *pending* booking and enters the payment step.
  const review = page.locator('[data-booked-step="review"]');
  await expect(review).toBeVisible();
  await review.locator('[data-booked-action="submit"]').click();

  // 6. Stripe Payment Element (rendered in a Stripe-hosted iframe). The Payment
  //    Element combines fields; selectors may need adjusting for your Stripe
  //    layout (see README). Test card: always-succeeds Visa.
  const payment = page.locator('[data-booked-step="payment"]');
  await expect(payment).toBeVisible();

  const stripe = page
    .frameLocator('iframe[title="Secure payment input frame"], iframe[name^="__privateStripeFrame"]')
    .first();
  await stripe.getByPlaceholder('Card number').fill('4242 4242 4242 4242');
  await stripe.getByPlaceholder(/MM ?\/ ?YY/).fill('12 / 34');
  await stripe.getByPlaceholder('CVC').fill('123');
  const zip = stripe.getByPlaceholder(/ZIP|Postal/);
  if (await zip.isVisible().catch(() => false)) await zip.fill('12345');

  await payment.locator('[data-booked-action="pay"]').click();

  // 7. Confirmed — the success step shows once the payment is confirmed (webhook,
  //    or the client confirm-poll as fallback).
  await expect(page.locator('[data-booked-step="success"]')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('[data-booked-summary="status"]')).not.toBeEmpty();
});
