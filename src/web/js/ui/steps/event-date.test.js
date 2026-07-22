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
        </button>
      </template>
      <div data-booked-list="events"></div>
      <p data-booked-events-empty hidden>No events</p>
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
    await vi.waitFor(() => expect(region.querySelectorAll('[data-booked-action="select-event"]')).toHaveLength(2));
    expect(card(region, 2208).querySelector('[data-booked-field="title"]').textContent).toBe('Yoga Retreat');
    expect(card(region, 2208).querySelector('[data-booked-field="date"]').textContent).toBe('Sep 15, 2026');
  });

  it('marks a fully-booked event disabled', async () => {
    const { region, wizard } = await setup();
    eventDateStep.mount(region, wizard);
    await vi.waitFor(() => expect(card(region, 2209)).not.toBeNull());
    expect(card(region, 2209).getAttribute('aria-disabled')).toBe('true');
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
