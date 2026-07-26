import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../core/wizard.js';
import { Renderer } from './renderer.js';
import { create } from './index.js';

/** Fake v1 API — a service with its own schedule + single location, no extras. */
function fakeApi(overrides = {}) {
  return {
    commerceSettings: vi.fn(async () => ({ commerceEnabled: false })),
    services: vi.fn(async () => ({ services: [{ id: 12, name: 'Haircut', price: 40, durationType: 'minutes' }] })),
    serviceExtras: vi.fn(async () => ({ extras: [] })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1 }], serviceHasSchedule: true })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 'lock-abc', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    createBooking: vi.fn(async () => ({ success: true, reservation: { reference: 'BKD-1' } })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

/** The DOM contract a Twig include would render. */
const MARKUP = `
  <div data-booked-wizard>
    <div data-booked-loading hidden>Loading…</div>
    <p data-booked-error hidden></p>
    <div data-booked-progress><span data-booked-progress-current></span>/<span data-booked-progress-total></span></div>
    <div aria-live="polite" data-booked-live></div>

    <section data-booked-step="service"><h2 data-booked-step-heading>Service</h2>
      <button data-booked-action="select-service" data-booked-id="12">Haircut</button>
      <button data-booked-action="next">Next</button>
    </section>
    <section data-booked-step="datetime" hidden><h2 data-booked-step-heading>Date</h2>
      <button data-booked-action="back">Back</button>
      <button data-booked-action="next">Next</button>
    </section>
    <section data-booked-step="info" hidden><h2 data-booked-step-heading>Info</h2>
      <button data-booked-action="back">Back</button>
      <button data-booked-action="next">Next</button>
    </section>
    <section data-booked-step="review" hidden><h2 data-booked-step-heading>Review</h2>
      <button data-booked-action="submit">Book</button>
    </section>
    <section data-booked-step="success" hidden><h2 data-booked-step-heading>Done</h2></section>
  </div>`;

function setup(apiOverrides = {}) {
  document.body.innerHTML = MARKUP;
  const root = document.querySelector('[data-booked-wizard]');
  const wizard = new Wizard({ apiClient: fakeApi(apiOverrides), flow: 'booking' });
  const renderer = new Renderer(wizard, root);
  return { wizard, renderer, root };
}

const visibleStep = (root) =>
  Array.from(root.querySelectorAll('[data-booked-step]')).find((el) => !el.hidden)?.getAttribute('data-booked-step');

describe('Renderer — step visibility & focus', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('shows the initial step WITHOUT stealing focus on load', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    expect(visibleStep(root)).toBe('service');
    // Initial render must not move focus into the wizard.
    expect(document.activeElement).not.toBe(root.querySelector('[data-booked-step="service"] [data-booked-step-heading]'));
  });

  it('swaps the visible region and moves focus on step:change', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext(); // service → datetime
    expect(visibleStep(root)).toBe('datetime');
    expect(document.activeElement).toBe(root.querySelector('[data-booked-step="datetime"] [data-booked-step-heading]'));
  });

  it('reflects the progress indicator', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    expect(root.querySelector('[data-booked-progress-current]').textContent).toBe('1');
    expect(root.querySelector('[data-booked-progress-total]').textContent).toBe('4'); // service,datetime,info,review
  });
});

describe('Renderer — DOM actions drive the core', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('clicking select-service then Next advances via delegation', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    root.querySelector('[data-booked-action="select-service"]').click();
    // selectService is async; allow the microtask to settle
    await Promise.resolve();
    await Promise.resolve();
    expect(wizard.getState().context.serviceId).toBe(12);
    root.querySelector('[data-booked-step="service"] [data-booked-action="next"]').click();
    expect(wizard.stepId).toBe('datetime');
    expect(visibleStep(root)).toBe('datetime');
  });

  it('Back returns to the previous step', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    root.querySelector('[data-booked-step="datetime"] [data-booked-action="back"]').click();
    expect(wizard.stepId).toBe('service');
    expect(visibleStep(root)).toBe('service');
  });
});

describe('Renderer — announcements & errors', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('mirrors announce events into the live region', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext();
    expect(root.querySelector('[data-booked-live]').textContent).toContain('Step');
  });

  it('shows and then clears the error region', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    wizard.goNext(); // info
    // Leaving info without customer data → validation error surfaces
    wizard.goNext();
    const err = root.querySelector('[data-booked-error]');
    expect(err.hidden).toBe(false);
  });
});

describe('Renderer — anti-spam passthrough', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('sends the honeypot field (name + value) and captcha token with the booking', async () => {
    const { wizard, renderer, root } = setup();
    // Inject a honeypot + captcha token into the wizard root.
    root.insertAdjacentHTML(
      'afterbegin',
      '<input data-booked-honeypot name="website" value="spam.example"><input type="hidden" data-booked-captcha-token value="cap-123">',
    );
    const submitSpy = vi.spyOn(wizard, 'submit');
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext(); // info
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext(); // review
    root.querySelector('[data-booked-step="review"] [data-booked-action="submit"]').click();
    expect(submitSpy).toHaveBeenCalledWith(
      expect.objectContaining({ fields: { website: 'spam.example', captchaToken: 'cap-123' } }),
    );
  });

  it('sends empty fields when no honeypot/captcha is present', async () => {
    const { wizard, renderer, root } = setup();
    const submitSpy = vi.spyOn(wizard, 'submit');
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext();
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext();
    root.querySelector('[data-booked-step="review"] [data-booked-action="submit"]').click();
    expect(submitSpy).toHaveBeenCalledWith(expect.objectContaining({ fields: {} }));
  });
});

describe('Renderer — success on confirmed', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('shows the success step when the booking confirms', async () => {
    const { wizard, renderer, root } = setup();
    await wizard.start();
    renderer.syncInitial();
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext(); // info
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    wizard.goNext(); // review
    await wizard.submit();
    expect(visibleStep(root)).toBe('success');
  });
});

describe('ui/index create()', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('returns a bare headless wizard when no mount is given', () => {
    const wizard = create({ apiClient: fakeApi() });
    expect(wizard).toBeInstanceOf(Wizard);
  });

  it('mounts, auto-starts, and shows the first step', async () => {
    document.body.innerHTML = MARKUP;
    const controller = create({ apiClient: fakeApi(), mount: '[data-booked-wizard]' });
    expect(controller.wizard).toBeInstanceOf(Wizard);
    // start() was fired; await it settling
    await controller.start();
    await Promise.resolve();
    expect(visibleStep(document.querySelector('[data-booked-wizard]'))).toBe('service');
    controller.destroy();
  });
});
