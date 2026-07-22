import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../../core/wizard.js';
import { datetimeStep } from './datetime.js';

function fakeApi(overrides = {}) {
  return {
    commerceSettings: vi.fn(async () => ({ commerceEnabled: false })),
    services: vi.fn(async () => ({ services: [{ id: 12, name: 'Cut', price: 40, durationType: 'minutes' }] })),
    serviceExtras: vi.fn(async () => ({ extras: [] })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1 }], serviceHasSchedule: true })),
    calendar: vi.fn(async () => ({
      calendar: { '2026-08-10': { isBookable: true }, '2026-08-11': { isBookable: false } },
    })),
    slots: vi.fn(async () => ({ slots: [{ time: '10:00', availableCapacity: 2 }, { time: '11:00', availableCapacity: 0 }] })),
    joinWaitlist: vi.fn(async () => ({ success: true })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 'lock-1', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

const REGION = `
  <section>
    <div data-booked-calendar data-booked-initial-month="2026-08"></div>
    <div data-booked-slots></div>
    <div data-booked-slot-quantity hidden>
      <button data-booked-action="qty-decrement">−</button>
      <output data-booked-slot-qty-value>1</output>
      <button data-booked-action="qty-increment">+</button>
    </div>
    <div data-booked-waitlist hidden>
      <div data-booked-waitlist-form>
        <input data-booked-field="name">
        <input data-booked-field="email">
        <button data-booked-action="join-waitlist">Join</button>
      </div>
      <p data-booked-waitlist-success hidden>Joined</p>
    </div>
  </section>`;

async function setup(apiOverrides = {}) {
  document.body.innerHTML = REGION;
  const region = document.body.firstElementChild;
  const wizard = new Wizard({ apiClient: fakeApi(apiOverrides), flow: 'booking' });
  await wizard.start();
  await wizard.selectService(12);
  datetimeStep.mount(region, wizard);
  return { region, wizard };
}

const day = (region, d) => region.querySelector(`[data-booked-date="${d}"]`);
const slot = (region, t) => region.querySelector(`[data-booked-time="${t}"]`);

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('datetimeStep — calendar', () => {
  it('builds the calendar for the initial month', async () => {
    const { region } = await setup();
    expect(region.querySelector('[role="grid"]')).not.toBeNull();
    expect(region.querySelector('[data-booked-cal="label"]').textContent).toBe('August 2026');
  });

  it('marks bookable days available and others disabled from the loaded map', async () => {
    const { region } = await setup();
    await vi.waitFor(() => {
      expect(day(region, '2026-08-11').getAttribute('aria-disabled')).toBe('true');
    });
    expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false);
  });

  it('reloads availability when the month changes', async () => {
    const { region, wizard } = await setup();
    const spy = vi.spyOn(wizard, 'loadCalendar');
    region.querySelector('[data-booked-cal="next"]').click();
    expect(spy).toHaveBeenCalledWith({ year: 2026, month: 9 });
  });
});

describe('datetimeStep — slots', () => {
  it('selecting an available day loads and renders the slot listbox', async () => {
    const { region } = await setup();
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '10:00')).not.toBeNull());
    const list = region.querySelector('[data-booked-slots]');
    expect(list.getAttribute('role')).toBe('listbox');
    expect(slot(region, '10:00').getAttribute('role')).toBe('option');
    // Zero-capacity slot is disabled.
    expect(slot(region, '11:00').getAttribute('aria-disabled')).toBe('true');
  });

  it('picking a slot acquires the lock and marks it selected', async () => {
    const { region, wizard } = await setup();
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '10:00')).not.toBeNull());
    slot(region, '10:00').click();
    await vi.waitFor(() => expect(wizard.state).toBe('holdingLock'));
    expect(slot(region, '10:00').getAttribute('aria-selected')).toBe('true');
    expect(wizard.getState().context.lock.token).toBe('lock-1');
  });

  it('does not select a zero-capacity slot', async () => {
    const { region, wizard } = await setup();
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '11:00')).not.toBeNull());
    slot(region, '11:00').click();
    // still browsing — no lock acquired
    expect(wizard.state).toBe('browsing');
  });
});

describe('datetimeStep — slot quantity picker', () => {
  const qtyBox = (region) => region.querySelector('[data-booked-slot-quantity]');

  it('reveals the quantity picker for a slot with capacity > 1 and re-locks on change', async () => {
    const { region, wizard } = await setup();
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '10:00')).not.toBeNull()); // capacity 2
    slot(region, '10:00').click();
    await vi.waitFor(() => expect(qtyBox(region).hidden).toBe(false));

    expect(region.querySelector('[data-booked-slot-qty-value]').textContent).toBe('1');
    region.querySelector('[data-booked-action="qty-increment"]').click();
    await vi.waitFor(() => expect(region.querySelector('[data-booked-slot-qty-value]').textContent).toBe('2'));
    expect(wizard.getState().context.slotQuantity).toBe(2);
    // Capped at capacity 2 → increment disabled.
    expect(region.querySelector('[data-booked-action="qty-increment"]').disabled).toBe(true);
  });

  it('hides the quantity picker for a capacity-1 slot', async () => {
    const { region, wizard } = await setup({
      slots: vi.fn(async () => ({ slots: [{ time: '09:00', availableCapacity: 1 }] })),
    });
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '09:00')).not.toBeNull());
    slot(region, '09:00').click();
    await vi.waitFor(() => expect(wizard.state).toBe('holdingLock'));
    expect(qtyBox(region).hidden).toBe(true);
  });
});

function dayApi(service, overrides = {}) {
  return {
    commerceSettings: vi.fn(async () => ({ commerceEnabled: false })),
    services: vi.fn(async () => ({ services: [service] })),
    serviceExtras: vi.fn(async () => ({ extras: [] })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1 }], serviceHasSchedule: true })),
    dates: vi.fn(async () => ({ availableDates: ['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-10'] })),
    endDates: vi.fn(async () => ({ validEndDates: ['2026-08-04', '2026-08-05', '2026-08-06'] })),
    rangeCapacity: vi.fn(async () => ({ remainingCapacity: 1, startDate: '2026-08-03', endDate: '2026-08-05' })),
    createRangeLock: vi.fn(async () => ({ success: true, token: 'range-lock', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

async function setupDay(service, overrides = {}) {
  document.body.innerHTML = REGION;
  const region = document.body.firstElementChild;
  const wizard = new Wizard({ apiClient: dayApi(service, overrides), flow: 'booking' });
  await wizard.start();
  await wizard.selectService(service.id);
  datetimeStep.mount(region, wizard);
  return { region, wizard };
}

describe('datetimeStep — fixed-day service', () => {
  it('picking a start computes the end from duration and acquires the range lock', async () => {
    const { region, wizard } = await setupDay({ id: 20, durationType: 'days', duration: 3, price: 100 });
    await vi.waitFor(() => expect(day(region, '2026-08-03').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-03').click();
    await vi.waitFor(() => expect(wizard.state).toBe('holdingLock'));
    expect(day(region, '2026-08-03').getAttribute('data-range-start')).toBe('true');
    expect(day(region, '2026-08-05').getAttribute('data-range-end')).toBe('true'); // 03 + (3-1)
    expect(day(region, '2026-08-04').getAttribute('data-in-range')).toBe('true');
    expect(wizard.getState().context.lock.token).toBe('range-lock');
    expect(wizard.getState().context.endDate).toBe('2026-08-05');
  });
});

describe('datetimeStep — flexible-day service', () => {
  it('start loads valid ends and constrains the calendar; end completes the range', async () => {
    const { region, wizard } = await setupDay({ id: 21, durationType: 'flexible_days', minDays: 2, maxDays: 5, price: 100 });
    await vi.waitFor(() => expect(day(region, '2026-08-03').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-03').click(); // start → loads valid ends
    // Wait for the valid-end constraint to apply: a start-date that is NOT a
    // valid end (08-10) becomes disabled. A valid end (08-05) stays enabled.
    await vi.waitFor(() => expect(day(region, '2026-08-10').getAttribute('aria-disabled')).toBe('true'));
    expect(day(region, '2026-08-05').hasAttribute('aria-disabled')).toBe(false);
    day(region, '2026-08-05').click(); // end → completes range + books
    await vi.waitFor(() => expect(wizard.state).toBe('holdingLock'));
    expect(wizard.getState().context.endDate).toBe('2026-08-05');
    expect(day(region, '2026-08-04').getAttribute('data-in-range')).toBe('true');
  });
});

describe('datetimeStep — waitlist branch', () => {
  const noSlots = {
    calendar: vi.fn(async () => ({ calendar: { '2026-08-10': { isBookable: true } } })),
    slots: vi.fn(async () => ({ slots: [], waitlistAvailable: true })),
  };

  it('reveals the waitlist form when a day has no slots but waitlist is open', async () => {
    const { region } = await setup(noSlots);
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(region.querySelector('[data-booked-waitlist]').hidden).toBe(false));
  });

  it('keeps the waitlist hidden when slots exist', async () => {
    const { region } = await setup();
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(slot(region, '10:00')).not.toBeNull());
    expect(region.querySelector('[data-booked-waitlist]').hidden).toBe(true);
  });

  it('joins the waitlist with the customer + date payload and shows success', async () => {
    const { region, wizard } = await setup(noSlots);
    const spy = vi.spyOn(wizard, 'joinWaitlist');
    await vi.waitFor(() => expect(day(region, '2026-08-10').hasAttribute('aria-disabled')).toBe(false));
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(region.querySelector('[data-booked-waitlist]').hidden).toBe(false));

    region.querySelector('[data-booked-waitlist] [data-booked-field="name"]').value = 'Ada';
    region.querySelector('[data-booked-waitlist] [data-booked-field="email"]').value = 'ada@example.com';
    region.querySelector('[data-booked-action="join-waitlist"]').click();

    await vi.waitFor(() =>
      expect(region.querySelector('[data-booked-waitlist-success]').hidden).toBe(false),
    );
    expect(spy).toHaveBeenCalledWith(
      expect.objectContaining({ serviceId: 12, preferredDate: '2026-08-10', userName: 'Ada', userEmail: 'ada@example.com' }),
    );
    expect(region.querySelector('[data-booked-waitlist-form]').hidden).toBe(true);
  });
});
