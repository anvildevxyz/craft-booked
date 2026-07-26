import { describe, it, expect } from 'vitest';
import { Flow } from './flow.js';
import { bookingFlow } from './flows/booking.js';

/**
 * A context whose flags we can flip to exercise every skip permutation. Mirrors
 * the shape the booking flow's predicates read.
 */
function ctx(overrides = {}) {
  return {
    extras: [],
    locations: [{ id: 1 }],
    employees: [],
    serviceHasSchedule: false,
    ...overrides,
  };
}

describe('Flow — booking step cursor', () => {
  it('starts on the first visible step', () => {
    const f = new Flow(bookingFlow, ctx());
    expect(f.currentId).toBe('service');
  });

  it('skips extras/location/employee when none apply (minimal path)', () => {
    const f = new Flow(bookingFlow, ctx());
    const path = ['service'];
    let id;
    while ((id = f.next())) path.push(id);
    expect(path).toEqual(['service', 'datetime', 'info', 'review']);
  });

  it('shows extras only when the service has add-ons', () => {
    const f = new Flow(bookingFlow, ctx({ extras: [{ id: 9 }] }));
    expect(f.peekNext()).toBe('extras');
  });

  it('shows location only when more than one exists', () => {
    const f = new Flow(bookingFlow, ctx({ locations: [{ id: 1 }, { id: 2 }] }));
    f.goTo('service');
    // service -> location (extras skipped, >1 location shown)
    expect(f.next()).toBe('location');
  });

  it('shows employee when employees exist and the service has no own schedule', () => {
    const f = new Flow(bookingFlow, ctx({ employees: [{ id: 1 }] }));
    const visible = f.visibleIds;
    expect(visible).toContain('employee');
  });

  it('shows employee when employees exist even for a schedule-carrying service (legacy parity)', () => {
    const f = new Flow(bookingFlow, ctx({ employees: [{ id: 1 }], serviceHasSchedule: true }));
    expect(f.visibleIds).toContain('employee');
  });

  it('hides employee only when there are no employees', () => {
    const f = new Flow(bookingFlow, ctx({ employees: [], serviceHasSchedule: true }));
    expect(f.visibleIds).not.toContain('employee');
  });

  it('forward and back are symmetric across every skip permutation', () => {
    const permutations = [
      ctx(),
      ctx({ extras: [{ id: 1 }] }),
      ctx({ locations: [{ id: 1 }, { id: 2 }] }),
      ctx({ employees: [{ id: 1 }] }),
      ctx({ extras: [{ id: 1 }], locations: [{ id: 1 }, { id: 2 }], employees: [{ id: 1 }] }),
      ctx({ employees: [{ id: 1 }], serviceHasSchedule: true }),
    ];
    for (const c of permutations) {
      const f = new Flow(bookingFlow, c);
      const forward = [f.currentId];
      let id;
      while ((id = f.next())) forward.push(id);
      // now walk back to the start and assert we retrace the identical steps
      const backward = [f.currentId];
      while ((id = f.back())) backward.push(id);
      expect(backward.reverse()).toEqual(forward);
    }
  });

  it('peekNext/peekPrev do not move the cursor', () => {
    const f = new Flow(bookingFlow, ctx());
    const before = f.currentId;
    f.peekNext();
    f.peekPrev();
    expect(f.currentId).toBe(before);
  });

  it('canGoBack is false on the first step, canGoNext false on the last', () => {
    const f = new Flow(bookingFlow, ctx());
    expect(f.canGoBack).toBe(false);
    let id;
    while ((id = f.next())) {}
    expect(f.currentId).toBe('review');
    expect(f.canGoNext).toBe(false);
  });

  it('next() past the last visible step returns null and holds position', () => {
    const f = new Flow(bookingFlow, ctx());
    while (f.next()) {}
    expect(f.next()).toBeNull();
    expect(f.currentId).toBe('review');
  });

  it('position and total reflect only visible steps', () => {
    const f = new Flow(bookingFlow, ctx()); // 4 visible: service, datetime, info, review
    expect(f.total).toBe(4);
    expect(f.position).toBe(1);
    f.next();
    expect(f.position).toBe(2);
  });

  it('goTo() jumps to a visible step and refuses a hidden one', () => {
    const f = new Flow(bookingFlow, ctx()); // employee hidden (no employees)
    expect(f.goTo('info')).toBe(true);
    expect(f.currentId).toBe('info');
    expect(f.goTo('employee')).toBe(false);
    expect(f.currentId).toBe('info');
  });

  it('deepestVisibleUpTo lands as far in as the context allows', () => {
    // Deep link asks for `employee`, but there are no employees → land on the
    // deepest visible step at or before it, which is `service`.
    const f = new Flow(bookingFlow, ctx());
    expect(f.deepestVisibleUpTo('employee')).toBe('service');
  });

  it('reset() returns the cursor to the first visible step', () => {
    const f = new Flow(bookingFlow, ctx());
    f.next();
    f.next();
    f.reset();
    expect(f.currentId).toBe('service');
  });

  it('throws when no step is visible for the initial context', () => {
    const alwaysHidden = { id: 'x', steps: [{ id: 'only', visible: () => false }] };
    expect(() => new Flow(alwaysHidden, {})).toThrow();
  });

  it('throws on an empty definition', () => {
    expect(() => new Flow({ id: 'x', steps: [] }, {})).toThrow(TypeError);
  });
});
