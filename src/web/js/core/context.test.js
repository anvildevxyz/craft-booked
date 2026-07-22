import { describe, it, expect } from 'vitest';
import { Context } from './context.js';

describe('Context — extras math', () => {
  const extras = [
    { id: 1, price: 10, duration: 15 },
    { id: 2, price: 25, duration: 0 },
    { id: 3, price: 5, duration: 30 },
  ];

  it('sums extras price × quantity', () => {
    const c = new Context({ extras, selectedExtras: { 1: 2, 2: 1 } });
    expect(c.extrasTotal).toBe(45); // 10*2 + 25*1
  });

  it('sums extras duration × quantity, treating missing duration as 0', () => {
    const c = new Context({ extras, selectedExtras: { 1: 2, 2: 3, 3: 1 } });
    expect(c.extrasDuration).toBe(60); // 15*2 + 0*3 + 30*1
  });

  it('ignores extras with non-positive quantity', () => {
    const c = new Context({ extras, selectedExtras: { 1: 0 } });
    expect(c.extrasTotal).toBe(0);
  });

  it('matches string and numeric extra keys', () => {
    const c = new Context({ extras, selectedExtras: { '1': 1 } });
    expect(c.extrasTotal).toBe(10);
  });
});

describe('Context — duration days', () => {
  it('is 0 until both ends are set', () => {
    expect(new Context({ date: '2026-08-01' }).durationDays).toBe(0);
    expect(new Context({ endDate: '2026-08-03' }).durationDays).toBe(0);
  });

  it('is inclusive of both endpoints', () => {
    const c = new Context({ date: '2026-08-01', endDate: '2026-08-03' });
    expect(c.durationDays).toBe(3);
  });

  it('is 1 for a single-day range', () => {
    const c = new Context({ date: '2026-08-01', endDate: '2026-08-01' });
    expect(c.durationDays).toBe(1);
  });
});

describe('Context — price', () => {
  it('uses the flat service price for a standard service', () => {
    const c = new Context({ selectedService: { id: 1, price: 80 }, quantity: 1 });
    expect(c.servicePrice).toBe(80);
    expect(c.totalPrice).toBe(80);
  });

  it('multiplies price by quantity', () => {
    const c = new Context({ selectedService: { id: 1, price: 80 }, quantity: 3 });
    expect(c.totalPrice).toBe(240);
  });

  it('applies per-unit day pricing across the range', () => {
    const c = new Context({
      selectedService: { id: 1, price: 50, pricingMode: 'per_unit', durationType: 'days' },
      isDayService: true,
      date: '2026-08-01',
      endDate: '2026-08-03', // 3 days
      quantity: 1,
    });
    expect(c.servicePrice).toBe(150); // 50 × 3 days
  });

  it('does not multiply by days when pricingMode is not per_unit', () => {
    const c = new Context({
      selectedService: { id: 1, price: 50, pricingMode: 'flat', durationType: 'days' },
      isDayService: true,
      date: '2026-08-01',
      endDate: '2026-08-03',
    });
    expect(c.servicePrice).toBe(50);
  });

  it('adds extras to the total', () => {
    const c = new Context({
      selectedService: { id: 1, price: 80 },
      extras: [{ id: 1, price: 10, duration: 0 }],
      selectedExtras: { 1: 2 },
      quantity: 1,
    });
    expect(c.totalPrice).toBe(100); // 80 + 20
  });

  it('handles a missing service price as 0', () => {
    expect(new Context({ selectedService: { id: 1 } }).servicePrice).toBe(0);
  });
});

describe('Context — requiresPayment', () => {
  it('is false when Commerce is disabled', () => {
    const c = new Context({ selectedService: { price: 80 }, commerce: { enabled: false } });
    expect(c.requiresPayment).toBe(false);
  });

  it('is false for a zero total even with Commerce enabled', () => {
    const c = new Context({ selectedService: { price: 0 }, commerce: { enabled: true } });
    expect(c.requiresPayment).toBe(false);
  });

  it('is true when Commerce is enabled and the total is positive', () => {
    const c = new Context({ selectedService: { price: 80 }, commerce: { enabled: true }, quantity: 1 });
    expect(c.requiresPayment).toBe(true);
  });
});

describe('Context — mutators', () => {
  it('setService resets downstream selections and derives day flags', () => {
    const c = new Context({
      selectedExtras: { 1: 1 },
      employeeId: 5,
      date: '2026-08-01',
    });
    c.setService({ id: 7, price: 40, durationType: 'flexible_days' });
    expect(c.serviceId).toBe(7);
    expect(c.selectedExtras).toEqual({});
    expect(c.employeeId).toBeNull();
    expect(c.date).toBeNull();
    expect(c.isDayService).toBe(true);
    expect(c.isFlexibleDayService).toBe(true);
  });

  it('setService with a standard service clears day flags', () => {
    const c = new Context();
    c.setService({ id: 7, price: 40, durationType: 'minutes' });
    expect(c.isDayService).toBe(false);
    expect(c.isFlexibleDayService).toBe(false);
  });

  it('setExtraQuantity adds and removes', () => {
    const c = new Context();
    c.setExtraQuantity(3, 2);
    expect(c.selectedExtras[3]).toBe(2);
    c.setExtraQuantity(3, 0);
    expect(c.selectedExtras[3]).toBeUndefined();
  });

  it('setCustomer merges fields', () => {
    const c = new Context();
    c.setCustomer({ name: 'Ada' });
    c.setCustomer({ email: 'ada@example.com' });
    expect(c.customer).toMatchObject({ name: 'Ada', email: 'ada@example.com' });
  });

  it('snapshot includes computed values and is decoupled from live state', () => {
    const c = new Context({ selectedService: { price: 80 }, commerce: { enabled: true }, quantity: 1 });
    const snap = c.snapshot();
    expect(snap.totalPrice).toBe(80);
    expect(snap.requiresPayment).toBe(true);
    c.quantity = 2; // mutate after snapshot
    expect(snap.totalPrice).toBe(80); // snapshot unchanged
  });
});
