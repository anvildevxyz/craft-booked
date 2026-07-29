import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../../core/wizard.js';
import { Renderer } from '../renderer.js';
import { eventDateStep } from './event-date.js';

function fakeApi(overrides = {}) {
  return {
    commerceSettings: vi.fn(async () => ({ commerceEnabled: false })),
    services: vi.fn(async () => ({ services: [] })),
    eventDates: vi.fn(async () => ({
      hasEvents: true,
      eventDates: [
        { id: 2208, title: 'Yoga Retreat', formattedDate: 'Sep 15, 2026', formattedTimeRange: '9–12', remainingCapacity: 25, isFullyBooked: false, price: 80 },
        { id: 2209, title: 'Sold Out Gala', formattedDate: 'Sep 20, 2026', formattedTimeRange: '18–22', remainingCapacity: 0, isFullyBooked: true, price: 120 },
      ],
    })),
    createEventLock: vi.fn(async () => ({ success: true, token: 'evt-lock', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

const MARKUP = `
  <div data-booked-wizard>
    <section data-booked-step="event">
      <template data-booked-template="event-card">
        <button data-booked-action="select-event">
          <span data-booked-field="title"></span>
          <span data-booked-field="date"></span>
          <span data-booked-field="capacity"></span>
          <span data-booked-waitlist-hint hidden>Join waitlist</span>
        </button>
      </template>
      <div data-booked-list="events"></div>
      <p data-booked-events-empty hidden>No events</p>
      <div data-booked-waitlist hidden>
        <p data-booked-waitlist-event></p>
        <div data-booked-waitlist-form>
          <input data-booked-field="name">
          <input data-booked-field="email">
          <input data-booked-field="phone">
          <button data-booked-action="join-waitlist">Join</button>
        </div>
        <p data-booked-waitlist-success hidden>You're on the list</p>
      </div>
    </section>
  </div>`;

async function setup(apiOverrides = {}) {
  document.body.innerHTML = MARKUP;
  const root = document.querySelector('[data-booked-wizard]');
  const region = document.querySelector('[data-booked-step="event"]');
  const wizard = new Wizard({ apiClient: fakeApi(apiOverrides), flow: 'event' });
  await wizard.start();
  const renderer = new Renderer(wizard, root);
  renderer.registerStep('event', eventDateStep);
  return { root, region, wizard, renderer };
}

const card = (region, id) => region.querySelector(`[data-booked-id="${id}"]`);

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('eventDateStep', () => {
  it('loads and renders event-date cards on mount', async () => {
    const { region, wizard } = await setup();
    eventDateStep.mount(region, wizard);
    await vi.waitFor(() => expect(region.querySelectorAll('[data-booked-id]')).toHaveLength(2));
    expect(card(region, 2208).querySelector('[data-booked-field="title"]').textContent).toBe('Yoga Retreat');
    expect(card(region, 2208).querySelector('[data-booked-field="date"]').textContent).toBe('Sep 15, 2026');
  });

  it('offers a waitlist on a fully-booked event instead of a dead end', async () => {
    const { region, wizard } = await setup();
    eventDateStep.mount(region, wizard);
    await vi.waitFor(() => expect(card(region, 2209)).not.toBeNull());
    const soldOut = card(region, 2209);
    expect(soldOut.getAttribute('data-booked-soldout')).toBe('true');
    // Sold-out cards use the waitlist action (not select-event) and show the hint.
    expect(soldOut.getAttribute('data-booked-action')).toBe('waitlist-event');
    expect(soldOut.querySelector('[data-booked-waitlist-hint]').hidden).toBe(false);
  });

  it('joining a sold-out event waitlist reveals the form and posts eventDateId', async () => {
    const joinEventWaitlist = vi.fn(async () => ({ success: true }));
    const { region, wizard } = await setup({ joinEventWaitlist });
    eventDateStep.mount(region, wizard);
    await vi.waitFor(() => expect(card(region, 2209)).not.toBeNull());

    card(region, 2209).click(); // sold-out → reveal waitlist form
    expect(region.querySelector('[data-booked-waitlist]').hidden).toBe(false);

    region.querySelector('[data-booked-waitlist] [data-booked-field="name"]').value = 'Ada';
    region.querySelector('[data-booked-waitlist] [data-booked-field="email"]').value = 'ada@example.com';
    region.querySelector('[data-booked-action="join-waitlist"]').click();

    await vi.waitFor(() => expect(joinEventWaitlist).toHaveBeenCalled());
    expect(joinEventWaitlist).toHaveBeenCalledWith(
      expect.objectContaining({ eventDateId: 2209, userName: 'Ada', userEmail: 'ada@example.com' })
    );
    await vi.waitFor(() =>
      expect(region.querySelector('[data-booked-waitlist-success]').hidden).toBe(false)
    );
  });

  it('selecting an event acquires the event lock and marks it selected', async () => {
    const { root, region, wizard } = await setup();
    eventDateStep.mount(region, wizard);
    await vi.waitFor(() => expect(card(region, 2208)).not.toBeNull());
    card(region, 2208).click(); // delegated select-event on the shell
    await vi.waitFor(() => expect(wizard.state).toBe('holdingLock'));
    expect(wizard.getState().context.eventDateId).toBe(2208);
    expect(wizard.getState().context.lock.token).toBe('evt-lock');
    expect(card(region, 2208).getAttribute('aria-pressed')).toBe('true');
    root.remove();
  });

  it('does not select a fully-booked event', async () => {
    const { region, wizard } = await setup();
    eventDateStep.mount(region, wizard);
    await vi.waitFor(() => expect(card(region, 2209)).not.toBeNull());
    card(region, 2209).click();
    // still browsing — no lock
    expect(wizard.state).toBe('browsing');
  });

  it('reveals a quantity picker for an event with capacity > 1 and re-selects at the new count', async () => {
    document.body.innerHTML = `
      <div data-booked-wizard>
        <section data-booked-step="event">
          <template data-booked-template="event-card">
            <button data-booked-action="select-event">
              <span data-booked-field="title"></span>
              <span data-booked-field="price"></span>
            </button>
          </template>
          <div data-booked-list="events"></div>
          <div data-booked-event-quantity hidden>
            <button data-booked-action="qty-decrement">−</button>
            <output data-booked-event-qty-value>1</output>
            <button data-booked-action="qty-increment">+</button>
          </div>
        </section>
      </div>`;
    const root = document.querySelector('[data-booked-wizard]');
    const region = document.querySelector('[data-booked-step="event"]');
    const wizard = new Wizard({
      apiClient: fakeApi({ commerceSettings: vi.fn(async () => ({ commerceEnabled: true, currencySymbol: 'CHF' })) }),
      flow: 'event',
    });
    await wizard.start();
    const renderer = new Renderer(wizard, root);
    renderer.registerStep('event', eventDateStep);
    eventDateStep.mount(region, wizard);
    await vi.waitFor(() => expect(card(region, 2208)).not.toBeNull());

    // Price is formatted with the currency symbol.
    expect(card(region, 2208).querySelector('[data-booked-field="price"]').textContent).toBe('80.00 CHF');

    const box = region.querySelector('[data-booked-event-quantity]');
    expect(box.hidden).toBe(true); // hidden until an event is picked

    card(region, 2208).click(); // capacity 25 > 1
    await vi.waitFor(() => expect(box.hidden).toBe(false));

    region.querySelector('[data-booked-action="qty-increment"]').click();
    await vi.waitFor(() => expect(wizard.getState().context.quantity).toBe(2));
    expect(region.querySelector('[data-booked-event-qty-value]').textContent).toBe('2');
    root.remove();
  });

  it('best-effort: a failed event lock still selects the date and lets the flow proceed', async () => {
    const lockFails = vi.fn(async () => {
      const e = new Error('This time slot is temporarily reserved.');
      e.status = 400;
      throw e;
    });
    const onError = vi.fn();
    const { region, wizard } = await setup({
      createEventLock: lockFails,
      createBooking: vi.fn(async () => ({ success: true, reservation: { reference: 'EVT-1' } })),
    });
    wizard.on('error', onError);
    eventDateStep.mount(region, wizard);
    await vi.waitFor(() => expect(card(region, 2208)).not.toBeNull());
    card(region, 2208).click();
    await vi.waitFor(() => expect(wizard.getState().context.eventDateId).toBe(2208));

    // Selection stands, no blocking error, still browsing (no hard lock).
    expect(onError).not.toHaveBeenCalled();
    expect(wizard.state).toBe('browsing');

    // The flow proceeds and submits straight from browsing.
    expect(wizard.goNext().stepId).toBe('info');
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    expect(wizard.goNext().stepId).toBe('review');
    const res = await wizard.submit();
    expect(res).toMatchObject({ ok: true, confirmed: true });
  });
});
