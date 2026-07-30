import { test, expect, type Page, type BrowserContext, type Browser } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * Browser E2E for the full customer journey through a multi-seat slot.
 *
 * Where `schedule-capacity.spec.ts` asserts what the slot list renders, this
 * drives the wizard the way a customer does — clicking through service, date,
 * slot, quantity, details, review and confirmation against the **real** backend
 * (no stubbed API) — and checks that the seats a booking consumes are the seats
 * the next visitor loses. It also covers the concurrency, soft-lock and
 * accessibility behaviour that only shows up in a live browser.
 *
 * Run from the plugin root:
 *   BOOKED_E2E_URL="https://craft-plugin-dev.ddev.site/wizard/service" \
 *   BOOKED_E2E_PROJECT_ROOT="$(cd ../.. && pwd)" \
 *   ./node_modules/.bin/playwright test schedule-capacity-journey -c tests/e2e/playwright.config.ts
 */

const SERVICE_ID = process.env.BOOKED_E2E_SERVICE_ID ?? '1236';
const EMPLOYEE_SERVICE_ID = process.env.BOOKED_E2E_EMPLOYEE_SERVICE_ID ?? '2098';
const TIME = '09:00';
const CAPACITY = 4;

const PROJECT_ROOT = process.env.BOOKED_E2E_PROJECT_ROOT ?? '../../..';
const FIXTURE = 'plugins/craft-booked/tests/integration-live/capacity-fixture.php';

function run(...args: string[]): string {
  return execFileSync('ddev', ['exec', 'php', FIXTURE, ...args, `--service=${SERVICE_ID}`, `--time=${TIME}`], {
    cwd: PROJECT_ROOT,
    encoding: 'utf8',
  }).trim();
}

const DATE = process.env.BOOKED_E2E_DATE ?? run('pick-date');
const fixture = (...args: string[]) => run(...args, `--date=${DATE}`);

/** A day the employee-backed service actually opens — its staff keep their own shifts. */
const EMPLOYEE_DATE = execFileSync(
  'ddev',
  ['exec', 'php', FIXTURE, 'pick-date', `--service=${EMPLOYEE_SERVICE_ID}`, '--time=any'],
  { cwd: PROJECT_ROOT, encoding: 'utf8' },
).trim();

/** Reservations booked through the UI, so they can be cleaned up by hand. */
const bookedIds: string[] = [];

function purgeUiBookings() {
  if (bookedIds.length === 0) return;
  execFileSync('ddev', ['mysql', '-e', `delete from booked_reservations where id in (${bookedIds.join(',')})`], {
    cwd: PROJECT_ROOT,
    encoding: 'utf8',
  });
  bookedIds.length = 0;
}

// ---------------------------------------------------------------------------
// Navigation helpers
// ---------------------------------------------------------------------------

const slot = (page: Page, time = TIME) => page.locator(`[data-booked-time="${time}"]`);
const step = (page: Page, id: string) => page.locator(`[data-booked-step="${id}"]`);

/** Open the wizard on a service and reach the date/time step with the day chosen. */
async function openDateStep(page: Page, serviceId = SERVICE_ID): Promise<void> {
  await page.goto(`?serviceId=${serviceId}`);
  const day = page.locator(`[data-booked-date="${DATE}"]`);

  for (let i = 0; i < 36 && (await day.count()) === 0; i++) {
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('availability/calendar')),
      page.locator('[data-booked-cal="next"]').click(),
    ]);
  }

  await expect(day).toHaveCount(1);
  await expect(day).not.toHaveAttribute('aria-disabled', 'true');
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('availability/slots')),
    day.click(),
  ]);
}

/**
 * Seats the slot reports. This is the `data-booked-capacity` attribute, which is
 * present for every capped slot including a single-seat one — the visible
 * "N available" label is the thing that only appears above one seat.
 */
async function seatsOn(page: Page, time = TIME): Promise<number | null> {
  const value = await slot(page, time).getAttribute('data-booked-capacity');
  return value === null ? null : Number(value);
}

/**
 * Complete a booking through the visible UI and return the reservation id from
 * the success screen. `quantity` above 1 uses the stepper the group slot reveals.
 */
async function bookThroughUi(page: Page, { quantity = 1, name = 'Ada Lovelace' } = {}): Promise<string> {
  await slot(page).click();
  await expect(page.locator('[data-booked-slot-quantity]')).toBeVisible({ visible: quantity > 1 || undefined }).catch(() => {});

  for (let i = 1; i < quantity; i++) {
    await page.locator('[data-booked-action="qty-increment"]').click();
    await expect(page.locator('[data-booked-slot-qty-value]')).toHaveText(String(i + 1));
  }

  await step(page, 'datetime').locator('[data-booked-action="next"]').click();

  const info = step(page, 'info');
  await expect(info).toBeVisible();
  await info.locator('[data-booked-field="name"]').fill(name);
  await info.locator('[data-booked-field="email"]').fill(`capacity-journey-${Date.now()}@example.test`);
  await info.locator('[data-booked-action="next"]').click();

  const review = step(page, 'review');
  await expect(review).toBeVisible();
  await review.locator('[data-booked-action="submit"]').click();

  const bookingId = page.locator('[data-booked-summary="booking-id"]');
  await expect(bookingId).not.toBeEmpty();

  const id = (await bookingId.textContent())!.trim();
  bookedIds.push(id);
  return id;
}

// ---------------------------------------------------------------------------

test.describe('group slot — full customer journey', () => {
  test.beforeAll(() => {
    fixture('capacity', String(CAPACITY));
  });

  test.afterAll(() => {
    purgeUiBookings();
    fixture('reset');
  });

  test.beforeEach(() => {
    purgeUiBookings();
    fixture('clear');
  });

  test('books one seat end to end and leaves the rest for the next visitor', async ({ page }) => {
    await openDateStep(page);
    expect(await seatsOn(page)).toBe(CAPACITY);

    const id = await bookThroughUi(page);
    expect(id).toMatch(/^\d+$/);
    await expect(step(page, 'success')).toBeVisible();

    // A second visitor sees exactly one seat fewer.
    await openDateStep(page);
    expect(await seatsOn(page)).toBe(CAPACITY - 1);
  });

  test('books several seats at once and deducts all of them', async ({ page }) => {
    await openDateStep(page);
    await bookThroughUi(page, { quantity: 3 });
    await expect(step(page, 'success')).toBeVisible();

    await openDateStep(page);
    await expect(slot(page)).toHaveCount(1);
    expect(await seatsOn(page)).toBe(CAPACITY - 3);
    // One seat left, so the "N available" label drops away.
    await expect(slot(page).locator('[data-booked-slot-seats]')).toHaveCount(0);
  });

  test('the review step reports the quantity actually chosen', async ({ page }) => {
    await openDateStep(page);
    await slot(page).click();

    await page.locator('[data-booked-action="qty-increment"]').click();
    await step(page, 'datetime').locator('[data-booked-action="next"]').click();

    const info = step(page, 'info');
    await info.locator('[data-booked-field="name"]').fill('Grace Hopper');
    await info.locator('[data-booked-field="email"]').fill('grace@example.test');
    await info.locator('[data-booked-action="next"]').click();

    await expect(step(page, 'review').locator('[data-booked-summary="quantity"]')).toHaveText('2');
  });

  test('the quantity stepper is bounded by the seats that remain', async ({ page }) => {
    fixture('seed', String(CAPACITY - 2)); // two seats left
    await openDateStep(page);
    await slot(page).click();

    const increment = page.locator('[data-booked-action="qty-increment"]');
    const decrement = page.locator('[data-booked-action="qty-decrement"]');
    const value = page.locator('[data-booked-slot-qty-value]');

    await expect(decrement).toBeDisabled(); // floor at 1
    await increment.click();
    await expect(value).toHaveText('2');
    await expect(increment).toBeDisabled(); // ceiling at the remaining seats
  });

  test('booking the final seat withdraws the slot for everyone else', async ({ page }) => {
    fixture('seed', String(CAPACITY - 1));
    await openDateStep(page);
    await expect(slot(page)).toHaveCount(1);

    await bookThroughUi(page);
    await expect(step(page, 'success')).toBeVisible();

    await openDateStep(page);
    await expect(slot(page)).toHaveCount(0);
    // The rest of the day is untouched.
    await expect(page.locator('[data-booked-time]')).not.toHaveCount(0);
  });
});

test.describe('group slot — concurrency and holds', () => {
  test.beforeAll(() => {
    fixture('capacity', String(CAPACITY));
  });

  test.afterAll(() => {
    purgeUiBookings();
    fixture('reset');
  });

  test.beforeEach(() => {
    purgeUiBookings();
    fixture('clear');
  });

  test('one visitor holding a seat reduces what another sees', async ({ browser }) => {
    const holder = await browser.newContext();
    const observer = await browser.newContext();

    try {
      const holderPage = await holder.newPage();
      await openDateStep(holderPage);
      expect(await seatsOn(holderPage)).toBe(CAPACITY);

      // Selecting the slot takes a soft lock on one seat.
      await Promise.all([
        holderPage.waitForResponse((r) => r.url().includes('locks/slot')),
        slot(holderPage).click(),
      ]);

      const observerPage = await observer.newPage();
      await openDateStep(observerPage);
      expect(await seatsOn(observerPage)).toBe(CAPACITY - 1);
    } finally {
      await holder.close();
      await observer.close();
    }
  });

  test('two visitors cannot both take the last seat', async ({ browser }) => {
    fixture('seed', String(CAPACITY - 1)); // exactly one seat left

    const [first, second] = await Promise.all([browser.newContext(), browser.newContext()]);

    try {
      const pages = await Promise.all([first.newPage(), second.newPage()]);
      await Promise.all(pages.map((p) => openDateStep(p)));

      // Both still see the slot; only one may end up with it.
      for (const p of pages) {
        await expect(slot(p)).toHaveCount(1);
      }

      const outcomes = await Promise.all(
        pages.map((p) =>
          bookThroughUi(p)
            .then(() => 'booked' as const)
            .catch(() => 'refused' as const),
        ),
      );

      expect(outcomes.filter((o) => o === 'booked')).toHaveLength(1);
      expect(outcomes.filter((o) => o === 'refused')).toHaveLength(1);
    } finally {
      await Promise.all([first.close(), second.close()]);
    }
  });
});

test.describe('group slot — presentation and neighbouring behaviour', () => {
  test.beforeAll(() => {
    fixture('capacity', String(CAPACITY));
  });

  test.afterAll(() => {
    purgeUiBookings();
    fixture('reset');
  });

  test.beforeEach(() => {
    purgeUiBookings();
    fixture('clear');
  });

  test('exposes the slot list as an accessible listbox', async ({ page }) => {
    await openDateStep(page);

    await expect(page.locator('[data-booked-slots]')).toHaveAttribute('role', 'listbox');
    await expect(slot(page)).toHaveAttribute('role', 'option');
    await expect(slot(page)).toHaveAttribute('aria-selected', 'false');

    await slot(page).click();
    await expect(slot(page)).toHaveAttribute('aria-selected', 'true');
  });

  test('omits the scheduled break and keeps the surrounding slots', async ({ page }) => {
    await openDateStep(page);

    await expect(slot(page, '12:00')).toHaveCount(0); // the 12:00–13:00 break
    await expect(slot(page, '11:00')).toHaveCount(1);
    await expect(slot(page, '13:00')).toHaveCount(1);
  });

  test('a busy slot does not disturb its neighbours', async ({ page }) => {
    fixture('seed', String(CAPACITY - 1));
    await openDateStep(page);

    expect(await seatsOn(page)).toBe(1); // last seat on the booked slot
    expect(await seatsOn(page, '10:00')).toBe(CAPACITY);
    expect(await seatsOn(page, '13:00')).toBe(CAPACITY);
  });

  test('keeps the day selectable while any slot has a seat', async ({ page }) => {
    fixture('seed', String(CAPACITY));
    await openDateStep(page);

    // 09:00 is gone, but the day itself stays open because later slots are free.
    await expect(slot(page)).toHaveCount(0);
    await expect(page.locator(`[data-booked-date="${DATE}"]`)).not.toHaveAttribute('aria-disabled', 'true');
  });

  test('single-seat services are unaffected by the group-slot rendering', async ({ page }) => {
    fixture('capacity', '1');

    try {
      await openDateStep(page);
      await expect(slot(page)).toHaveCount(1);
      // Capacity 1 carries no seat annotation and no quantity stepper.
      expect(await seatsOn(page)).toBe(1);
      await expect(slot(page).locator('[data-booked-slot-seats]')).toHaveCount(0);

      await slot(page).click();
      await expect(page.locator('[data-booked-slot-quantity]')).toBeHidden();
    } finally {
      fixture('capacity', String(CAPACITY));
    }
  });
});

test.describe('employee-based services keep one seat per employee', () => {
  test.beforeAll(() => {
    // A generous schedule capacity must not leak into per-employee availability.
    fixture('capacity', '10');
  });

  test.afterAll(() => {
    purgeUiBookings();
    fixture('reset');
  });

  test('offers slots and a single seat per employee', async ({ page }) => {
    // The capacity fixture flushes every cache, so this test pays for a cold
    // 90-day availability calendar across all of the service's employees.
    test.slow();

    await page.goto(`?serviceId=${EMPLOYEE_SERVICE_ID}`);

    // The `serviceId` query param preselects the service and the wizard then
    // auto-advances through any step with a single choice. Let that settle
    // before touching anything — racing it means clicking cards mid-teardown.
    await page.waitForFunction(() => !!(document.querySelector('[data-booked-wizard]') as any)?.__bookedController);

    const currentStep = () =>
      page.evaluate(() => (document.querySelector('[data-booked-wizard]') as any).__bookedController.wizard.stepId);

    for (let i = 0; i < 4 && (await currentStep()) !== 'datetime'; i++) {
      const stepId = await currentStep();
      const region = step(page, stepId);

      // Pick this service by id — taking the first card would silently land on
      // a different, capacity-enabled service.
      const choice =
        stepId === 'service'
          ? region.locator(`[data-booked-id="${EMPLOYEE_SERVICE_ID}"]`)
          : region.locator('[data-booked-action^="select-"]').first();

      // The step may auto-advance out from under us, which is a pass, not a failure.
      await choice.click({ timeout: 10_000 }).catch(() => {});

      // Choice steps that don't auto-advance wait on their own Next button.
      if ((await currentStep()) === stepId) {
        await region.locator('[data-booked-action="next"]').click({ timeout: 10_000 }).catch(() => {});
      }

      if ((await currentStep()) === stepId) {
        throw new Error(`stuck on the "${stepId}" step — neither selecting nor Next advanced it`);
      }
    }

    await expect.poll(currentStep, { timeout: 30_000 }).toBe('datetime');

    const selectedServiceId = await page.evaluate(
      () => (document.querySelector('[data-booked-wizard]') as any).__bookedController.wizard.getState().context.serviceId,
    );
    expect(String(selectedServiceId)).toBe(EMPLOYEE_SERVICE_ID);

    // Navigate to a date the server has already confirmed is bookable for these
    // employees. Polling the calendar instead is unreliable: a cold 90-day
    // calendar for a multi-employee service can take longer to compute than any
    // sensible per-month wait, and every cell renders disabled until it lands.
    const day = page.locator(`[data-booked-date="${EMPLOYEE_DATE}"]`);
    for (let i = 0; i < 36 && (await day.count()) === 0; i++) {
      await Promise.all([
        page.waitForResponse((r) => r.url().includes('availability/calendar')),
        page.locator('[data-booked-cal="next"]').click(),
      ]);
    }

    await expect(day).toHaveCount(1);
    await expect(day).not.toHaveAttribute('aria-disabled', 'true', { timeout: 30_000 });
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('availability/slots')),
      day.click(),
    ]);

    await expect(page.locator('[data-booked-time]').first()).toBeVisible();
    // Each employee is one seat, so no group annotation appears anywhere.
    await expect(page.locator('[data-booked-slot-seats]')).toHaveCount(0);
  });
});
