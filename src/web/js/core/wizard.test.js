import { describe, it, expect, vi } from 'vitest';
import { Wizard, create } from './wizard.js';
import { STATES } from './machine.js';

/**
 * A fake v1 API client covering the endpoints the wizard drives. Individual
 * tests override methods to exercise branches. This stands in for a running
 * Craft instance — the M1 exit criterion is a headless booking with no renderer.
 */
function fakeApi(overrides = {}) {
  return {
    commerceSettings: vi.fn(async () => ({ commerceEnabled: false })),
    services: vi.fn(async () => ({ services: [{ id: 12, name: 'Haircut', price: 40, durationType: 'minutes' }] })),
    serviceExtras: vi.fn(async () => ({ extras: [] })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1, name: 'Main' }], serviceHasSchedule: true })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 'lock-abc', expiresIn: 300 })),
    createRangeLock: vi.fn(async () => ({ success: true, token: 'lock-range', expiresIn: 300 })),
    createEventLock: vi.fn(async () => ({ success: true, token: 'lock-evt', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    createBooking: vi.fn(async () => ({ success: true, reservation: { id: 999, reference: 'BKD-999' } })),
    joinWaitlist: vi.fn(async () => ({ success: true })),
    joinEventWaitlist: vi.fn(async () => ({ success: true })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

function newWizard(apiOverrides = {}, options = {}) {
  const api = fakeApi(apiOverrides);
  const wizard = new Wizard({ apiClient: api, flow: 'booking', ...options });
  return { wizard, api };
}

describe('Wizard — bootstrap', () => {
  it('starts idle, then reaches browsing on the service step after start()', async () => {
    const { wizard } = newWizard();
    expect(wizard.state).toBe(STATES.IDLE);
    await wizard.start();
    expect(wizard.state).toBe(STATES.BROWSING);
    expect(wizard.stepId).toBe('service');
  });

  it('loads services and applies commerce settings', async () => {
    const { wizard } = newWizard({ commerceSettings: vi.fn(async () => ({ commerceEnabled: true, currency: 'CHF' })) });
    await wizard.start();
    const state = wizard.getState();
    expect(state.context.commerce.enabled).toBe(true);
    expect(state.context.commerce.currency).toBe('CHF');
  });

  it('preselects a service passed in options', async () => {
    const { wizard, api } = newWizard({}, { serviceId: 12 });
    await wizard.start();
    expect(api.serviceExtras).toHaveBeenCalledWith(12);
    expect(wizard.getState().context.serviceId).toBe(12);
  });

  it('surfaces an error state if services fail to load', async () => {
    const onError = vi.fn();
    const { wizard } = newWizard({
      services: vi.fn(async () => {
        throw new Error('boom');
      }),
    });
    wizard.on('error', onError);
    await wizard.start();
    expect(wizard.state).toBe(STATES.ERROR);
    expect(onError).toHaveBeenCalled();
  });
});

describe('Wizard — waitlist conversion', () => {
  it('prefills customer + service and lands on datetime from a conversion token', async () => {
    const convert = vi.fn(async () => ({
      success: true,
      serviceId: 12,
      userName: 'Ada Lovelace',
      userEmail: 'ada@example.com',
      userPhone: '555',
    }));
    const { wizard } = newWizard({ waitlistConvert: convert }, { conversionToken: 'cv-123' });
    const onConv = vi.fn();
    wizard.on('conversion:loaded', onConv);
    await wizard.start();
    expect(convert).toHaveBeenCalledWith({ conversionToken: 'cv-123' });
    const state = wizard.getState();
    expect(state.context.serviceId).toBe(12);
    expect(state.context.customer).toMatchObject({ name: 'Ada Lovelace', email: 'ada@example.com', phone: '555' });
    expect(state.stepId).toBe('datetime');
    expect(onConv).toHaveBeenCalled();
  });

  it('ignores a bad token and still opens normally', async () => {
    const { wizard } = newWizard(
      { waitlistConvert: vi.fn(async () => ({ success: false })) },
      { conversionToken: 'bad' },
    );
    await wizard.start();
    expect(wizard.state).toBe(STATES.BROWSING);
    expect(wizard.stepId).toBe('service');
  });
});

describe('Wizard — headless booking (M1 exit demo)', () => {
  it('drives service → datetime → info → review → confirmed with no renderer', async () => {
    const { wizard, api } = newWizard();
    const seen = [];
    wizard.on('state:change', (e) => seen.push(e.to));
    wizard.on('booking:confirmed', (e) => seen.push(`confirmed:${e.reservation.reference}`));

    await wizard.start(); // browsing @ service

    // Select the service (single location auto-selected, service has own schedule → no employee step).
    await wizard.selectService(12);
    expect(wizard.stepId).toBe('service');

    // service → datetime (extras empty, 1 location, serviceHasSchedule hides employee)
    expect(wizard.goNext()).toEqual({ ok: true, stepId: 'datetime' });

    // Pick a slot → acquires the lock, enters holdingLock.
    const slot = await wizard.selectSlot({ date: '2026-08-01', time: '10:00', quantity: 1 });
    expect(slot.acquired).toBe(true);
    expect(wizard.state).toBe(STATES.HOLDING_LOCK);

    // datetime → info
    expect(wizard.goNext().stepId).toBe('info');

    // Must fill customer info before leaving info.
    expect(wizard.goNext().ok).toBe(false);
    wizard.setCustomer({ name: 'Ada Lovelace', email: 'ada@example.com' });
    expect(wizard.goNext().stepId).toBe('review');

    // Submit → confirmed.
    const result = await wizard.submit();
    expect(result).toMatchObject({ ok: true, confirmed: true });
    expect(wizard.state).toBe(STATES.CONFIRMED);

    // The booking body carried the lock token and customer fields.
    const body = api.createBooking.mock.calls[0][0];
    expect(body).toMatchObject({
      serviceId: 12,
      date: '2026-08-01',
      time: '10:00',
      customerName: 'Ada Lovelace',
      customerEmail: 'ada@example.com',
      softLockToken: 'lock-abc',
    });

    expect(seen).toContain(STATES.CONFIRMED);
    expect(seen).toContain('confirmed:BKD-999');
  });

  it('serializes extras as an object for PHP array encoding', async () => {
    const { wizard, api } = newWizard({ serviceExtras: vi.fn(async () => ({ extras: [{ id: 5, price: 10, duration: 0 }] })) });
    await wizard.start();
    await wizard.selectService(12);
    wizard.selectExtra(5, 2);
    // extras step is now visible
    expect(wizard.goNext().stepId).toBe('extras');
    wizard.goNext(); // extras → datetime
    await wizard.selectSlot({ date: '2026-08-02', time: '09:00' });
    wizard.goNext(); // info
    wizard.setCustomer({ name: 'Grace', email: 'grace@example.com' });
    wizard.goNext(); // review
    await wizard.submit();
    expect(api.createBooking.mock.calls[0][0].extras).toEqual({ 5: 2 });
  });
});

describe('Wizard — payment (Commerce redirect)', () => {
  it('enters paying and emits payment:redirect when the backend returns a redirectUrl', async () => {
    const onRedirect = vi.fn();
    const { wizard } = newWizard({
      commerceSettings: vi.fn(async () => ({ commerceEnabled: true })),
      createBooking: vi.fn(async () => ({ success: true, commerce: true, redirectUrl: '/shop/cart' })),
    });
    wizard.on('payment:redirect', onRedirect);
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext();
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext();
    const result = await wizard.submit({ addToCart: true });
    expect(result).toMatchObject({ ok: true, paying: true, redirectUrl: '/shop/cart' });
    expect(wizard.state).toBe(STATES.PAYING);
    expect(onRedirect).toHaveBeenCalledWith({ url: '/shop/cart' });
  });
});

describe('Wizard — lock expiry at submit', () => {
  it('transitions to expired when the backend reports the lock is gone', async () => {
    const onExpired = vi.fn();
    const { wizard } = newWizard({
      createBooking: vi.fn(async () => {
        const e = new Error('lock expired');
        e.code = 'expired';
        throw e;
      }),
    });
    wizard.on('lock:expired', onExpired);
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext();
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext();
    const result = await wizard.submit();
    expect(result).toMatchObject({ ok: false, expired: true });
    expect(wizard.state).toBe(STATES.EXPIRED);
    expect(onExpired).toHaveBeenCalled();
  });
});

describe('Wizard — lock expiry recovery', () => {
  it('recovers from an expired lock: sent back to datetime, re-pick, submit succeeds', async () => {
    let bookingCalls = 0;
    const { wizard } = newWizard({
      createBooking: vi.fn(async () => {
        bookingCalls++;
        if (bookingCalls === 1) {
          const e = new Error('lock expired');
          e.code = 'expired';
          throw e;
        }
        return { success: true, reservation: { reference: 'BKD-2' } };
      }),
    });
    const errors = [];
    wizard.on('error', (e) => errors.push(e.code));
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext();
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext();

    const first = await wizard.submit();
    expect(first).toMatchObject({ ok: false, expired: true });
    expect(wizard.state).toBe(STATES.EXPIRED);
    expect(wizard.stepId).toBe('datetime'); // pushed back to re-pick
    expect(wizard.getState().context.lock).toBeNull();
    expect(errors).toContain('lock_expired');

    // Re-pick recovers to holdingLock (EXPIRED → HOLDING_LOCK).
    const re = await wizard.selectSlot({ date: '2026-08-02', time: '11:00' });
    expect(re.acquired).toBe(true);
    expect(wizard.state).toBe(STATES.HOLDING_LOCK);
    wizard.goNext();
    wizard.goNext();
    const second = await wizard.submit();
    expect(second).toMatchObject({ ok: true, confirmed: true });
  });

  it('a failed re-acquire clears the stale lock and drops back to browsing', async () => {
    let n = 0;
    const { wizard } = newWizard({
      createSlotLock: vi.fn(async () => {
        n++;
        return n === 1 ? { success: true, token: 'lock-1', expiresIn: 300 } : { success: false, message: 'taken' };
      }),
    });
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' }); // holds lock-1
    expect(wizard.state).toBe(STATES.HOLDING_LOCK);

    const res = await wizard.selectSlot({ date: '2026-08-01', time: '11:00' }); // fails
    expect(res.acquired).toBe(false);
    expect(wizard.getState().context.lock).toBeNull(); // not stale
    expect(wizard.state).toBe(STATES.BROWSING); // demoted, no phantom hold
  });
});

describe('Wizard — back navigation releases the lock', () => {
  it('going back from datetime after holding a lock releases it and returns to browsing', async () => {
    const { wizard, api } = newWizard();
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    expect(wizard.state).toBe(STATES.HOLDING_LOCK);
    const back = wizard.goBack(); // datetime → service
    expect(back.stepId).toBe('service');
    expect(api.releaseLock).toHaveBeenCalledWith({ token: 'lock-abc' });
    expect(wizard.state).toBe(STATES.BROWSING);
  });
});

describe('Wizard — slot already taken', () => {
  it('emits a slot_reserved error and does not enter holdingLock', async () => {
    const onError = vi.fn();
    const { wizard } = newWizard({
      createSlotLock: vi.fn(async () => ({ success: false, message: 'slot reserved' })),
    });
    wizard.on('error', onError);
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    const res = await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    expect(res.acquired).toBe(false);
    expect(wizard.state).toBe(STATES.BROWSING);
    expect(onError.mock.calls[0][0]).toMatchObject({ code: 'slot_reserved' });
  });

  it('treats a 400/409 lock response as slot_reserved, not a fatal error', async () => {
    const onError = vi.fn();
    const { wizard } = newWizard({
      createSlotLock: vi.fn(async () => {
        const e = new Error('That time was just taken.');
        e.name = 'ApiError';
        e.status = 400;
        throw e;
      }),
    });
    wizard.on('error', onError);
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    const res = await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    expect(res.acquired).toBe(false);
    expect(wizard.state).toBe(STATES.BROWSING); // not ERROR
    expect(onError.mock.calls[0][0]).toMatchObject({ code: 'slot_reserved' });
  });
});

describe('Wizard — factory & teardown', () => {
  it('create() returns a Wizard', () => {
    const { api } = newWizard();
    expect(create({ apiClient: api })).toBeInstanceOf(Wizard);
  });

  it('reset() returns to idle with a fresh context', async () => {
    const { wizard } = newWizard();
    await wizard.start();
    await wizard.selectService(12);
    wizard.reset();
    expect(wizard.state).toBe(STATES.IDLE);
    expect(wizard.getState().context.serviceId).toBeNull();
  });

  it('destroy() aborts requests and releases the lock', async () => {
    const { wizard, api } = newWizard();
    await wizard.start();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.destroy();
    expect(api.abortAll).toHaveBeenCalled();
    expect(api.releaseLock).toHaveBeenCalled();
  });

  it('rejects an unknown flow', () => {
    expect(() => new Wizard({ apiClient: fakeApi(), flow: 'nope' })).toThrow(/unknown flow/);
  });
});
