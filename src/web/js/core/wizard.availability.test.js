import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from './wizard.js';

function fakeApi(overrides = {}) {
  return {
    commerceSettings: vi.fn(async () => ({ commerceEnabled: false })),
    services: vi.fn(async () => ({ services: [{ id: 12, name: 'Cut', price: 40, durationType: 'minutes' }] })),
    serviceExtras: vi.fn(async () => ({ extras: [] })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1 }], serviceHasSchedule: true })),
    calendar: vi.fn(async () => ({
      success: true,
      calendar: { '2026-08-10': { isBookable: true }, '2026-08-11': { isBookable: false } },
    })),
    slots: vi.fn(async () => ({ success: true, slots: [{ time: '10:00', availableCapacity: 2 }], waitlistAvailable: false })),
    dates: vi.fn(async () => ({ availableDates: ['2026-08-03', '2026-08-04'], month: '2026-08' })),
    endDates: vi.fn(async () => ({ validEndDates: ['2026-08-05', '2026-08-06'] })),
    rangeCapacity: vi.fn(async () => ({ remainingCapacity: 3, startDate: '2026-08-03', endDate: '2026-08-05' })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 300 })),
    abortAll: vi.fn(),
    ...overrides,
  };
}

async function started(apiOverrides = {}) {
  const api = fakeApi(apiOverrides);
  const wizard = new Wizard({ apiClient: api, flow: 'booking' });
  await wizard.start();
  await wizard.selectService(12);
  return { wizard, api };
}

beforeEach(() => {});

describe('Wizard.loadCalendar', () => {
  it('returns the availability map and passes the current selection', async () => {
    const { wizard, api } = await started();
    const map = await wizard.loadCalendar({ year: 2026, month: 8 });
    expect(map['2026-08-10'].isBookable).toBe(true);
    expect(api.calendar).toHaveBeenCalledWith(expect.objectContaining({ serviceId: 12, year: 2026, month: 8 }));
  });

  it('emits data:loaded with kind calendar', async () => {
    const { wizard } = await started();
    const seen = [];
    wizard.on('data:loaded', (e) => seen.push(e.kind));
    await wizard.loadCalendar({ year: 2026, month: 8 });
    expect(seen).toContain('calendar');
  });

  it('returns null (not an error) when the request is superseded', async () => {
    const aborted = new Error('superseded');
    aborted.aborted = true;
    const { wizard } = await started({ calendar: vi.fn(async () => { throw aborted; }) });
    const onError = vi.fn();
    wizard.on('error', onError);
    const map = await wizard.loadCalendar({ year: 2026, month: 8 });
    expect(map).toBeNull();
    expect(onError).not.toHaveBeenCalled();
  });
});

describe('Wizard.loadSlots', () => {
  it('returns slots + waitlist flag and records the date', async () => {
    const { wizard, api } = await started();
    const res = await wizard.loadSlots({ date: '2026-08-10' });
    expect(res.slots).toHaveLength(1);
    expect(res.slots[0].time).toBe('10:00');
    expect(res.waitlistAvailable).toBe(false);
    expect(api.slots).toHaveBeenCalledWith(expect.objectContaining({ date: '2026-08-10', serviceId: 12 }));
    expect(wizard.getState().context.date).toBe('2026-08-10');
  });

  it('emits data:loaded with kind slots', async () => {
    const { wizard } = await started();
    const seen = [];
    wizard.on('data:loaded', (e) => seen.push(e));
    await wizard.loadSlots({ date: '2026-08-10' });
    const slotsEvent = seen.find((e) => e.kind === 'slots');
    expect(slotsEvent.items).toHaveLength(1);
  });
});

describe('Wizard multi-day availability', () => {
  it('loadDates passes month (YYYY-MM) and returns start dates', async () => {
    const { wizard, api } = await started();
    const dates = await wizard.loadDates({ month: '2026-08' });
    expect(dates).toEqual(['2026-08-03', '2026-08-04']);
    expect(api.dates).toHaveBeenCalledWith(expect.objectContaining({ serviceId: 12, month: '2026-08' }));
  });

  it('loadEndDates passes startDate and returns valid end dates', async () => {
    const { wizard, api } = await started();
    const ends = await wizard.loadEndDates({ startDate: '2026-08-03' });
    expect(ends).toEqual(['2026-08-05', '2026-08-06']);
    expect(api.endDates).toHaveBeenCalledWith(expect.objectContaining({ serviceId: 12, startDate: '2026-08-03' }));
  });

  it('loadRangeCapacity returns the tightest-day remaining capacity', async () => {
    const { wizard } = await started();
    const cap = await wizard.loadRangeCapacity({ startDate: '2026-08-03', endDate: '2026-08-05' });
    expect(cap).toMatchObject({ remainingCapacity: 3, startDate: '2026-08-03', endDate: '2026-08-05' });
  });

  it('multi-day loaders return null (not error) when superseded', async () => {
    const aborted = Object.assign(new Error('x'), { aborted: true });
    const { wizard } = await started({ dates: vi.fn(async () => { throw aborted; }) });
    const onError = vi.fn();
    wizard.on('error', onError);
    expect(await wizard.loadDates({ month: '2026-08' })).toBeNull();
    expect(onError).not.toHaveBeenCalled();
  });
});
