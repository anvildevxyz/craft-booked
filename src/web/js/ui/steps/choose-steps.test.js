import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../../core/wizard.js';
import { Renderer } from '../renderer.js';
import { locationStep } from './location.js';
import { employeeStep } from './employee.js';
import { extrasStep } from './extras.js';

function fakeApi(overrides = {}) {
  return {
    commerceSettings: vi.fn(async () => ({ commerceEnabled: false })),
    services: vi.fn(async () => ({ services: [{ id: 12, name: 'Cut', price: 40, durationType: 'minutes' }] })),
    serviceExtras: vi.fn(async () => ({ extras: [{ id: 5, name: 'Wash', price: 10, duration: 10 }] })),
    employees: vi.fn(async () => ({
      employees: [
        { id: 1, name: 'Ada', bio: 'Senior' },
        { id: 2, name: 'Grace', bio: 'Lead' },
      ],
      locations: [
        { id: 7, name: 'Downtown' },
        { id: 8, name: 'Uptown' },
      ],
      serviceHasSchedule: false,
    })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 300 })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

async function startedWizard(apiOverrides = {}) {
  const w = new Wizard({ apiClient: fakeApi(apiOverrides), flow: 'booking' });
  await w.start();
  await w.selectService(12);
  return w;
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('locationStep', () => {
  const REGION = `
    <section>
      <template data-booked-template="location-card">
        <button data-booked-action="select-location"><span data-booked-field="name"></span></button>
      </template>
      <div data-booked-list="locations"></div>
    </section>`;

  it('renders a card per location with ids', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    locationStep.render(region, wizard);
    const cards = region.querySelectorAll('[data-booked-action="select-location"]');
    expect(cards).toHaveLength(2);
    expect(cards[0].getAttribute('data-booked-id')).toBe('7');
    expect(cards[0].querySelector('[data-booked-field="name"]').textContent).toBe('Downtown');
  });

  it('reflects selection via aria-pressed', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    wizard.selectLocation(8);
    locationStep.render(region, wizard);
    expect(region.querySelector('[data-booked-id="8"]').getAttribute('aria-pressed')).toBe('true');
    expect(region.querySelector('[data-booked-id="7"]').getAttribute('aria-pressed')).toBe('false');
  });
});

describe('employeeStep', () => {
  const REGION = `
    <section>
      <template data-booked-template="employee-card">
        <button data-booked-action="select-employee">
          <span data-booked-field="name"></span><span data-booked-field="bio"></span>
        </button>
      </template>
      <div data-booked-list="employees"></div>
    </section>`;

  it('renders employees with name + bio', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    employeeStep.render(region, wizard);
    const cards = region.querySelectorAll('[data-booked-action="select-employee"]');
    expect(cards).toHaveLength(2);
    expect(cards[1].querySelector('[data-booked-field="name"]').textContent).toBe('Grace');
    expect(cards[0].querySelector('[data-booked-field="bio"]').textContent).toBe('Senior');
  });
});

describe('extrasStep + shell stepper', () => {
  const MARKUP = `
    <div data-booked-wizard>
      <section data-booked-step="extras">
        <template data-booked-template="extra-card">
          <div>
            <span data-booked-field="name"></span>
            <span data-booked-field="price"></span>
            <button data-booked-action="extra-decrement">−</button>
            <output data-booked-extra-qty>0</output>
            <button data-booked-action="extra-increment">+</button>
          </div>
        </template>
        <div data-booked-list="extras"></div>
        <span data-booked-extras-total></span>
      </section>
    </div>`;

  it('renders an add-on card with name and price', async () => {
    document.body.innerHTML = MARKUP;
    const region = document.querySelector('[data-booked-step="extras"]');
    const wizard = await startedWizard();
    extrasStep.render(region, wizard);
    expect(region.querySelector('[data-booked-field="name"]').textContent).toBe('Wash');
    expect(region.querySelector('[data-booked-field="price"]').textContent).toBe('10');
    expect(region.querySelector('[data-booked-extra-qty]').getAttribute('data-booked-extra-id')).toBe('5');
  });

  it('increment/decrement via the shell adjusts quantity and total', async () => {
    document.body.innerHTML = MARKUP;
    const root = document.querySelector('[data-booked-wizard]');
    const region = document.querySelector('[data-booked-step="extras"]');
    const wizard = await startedWizard();
    wizard.goNext(); // service → extras, so the active step matches the region
    expect(wizard.stepId).toBe('extras');
    const renderer = new Renderer(wizard, root);
    renderer.registerStep('extras', extrasStep);
    extrasStep.render(region, wizard); // initial paint

    region.querySelector('[data-booked-action="extra-increment"]').click();
    expect(wizard.getState().context.selectedExtras[5]).toBe(1);
    expect(region.querySelector('[data-booked-extra-qty]').textContent).toBe('1');
    expect(region.querySelector('[data-booked-extras-total]').textContent).toBe('10');

    region.querySelector('[data-booked-action="extra-increment"]').click();
    expect(wizard.getState().context.selectedExtras[5]).toBe(2);

    region.querySelector('[data-booked-action="extra-decrement"]').click();
    expect(wizard.getState().context.selectedExtras[5]).toBe(1);
    renderer.destroy();
  });

  it('auto-selects required extras and clamps them at a minimum of 1', async () => {
    document.body.innerHTML = MARKUP;
    const root = document.querySelector('[data-booked-wizard]');
    const region = document.querySelector('[data-booked-step="extras"]');
    const wizard = await startedWizard({
      serviceExtras: vi.fn(async () => ({ extras: [{ id: 9, name: 'Insurance', price: 5, isRequired: true }] })),
    });
    // Required extra is selected at qty 1 as soon as the service loads.
    expect(wizard.getState().context.selectedExtras[9]).toBe(1);

    wizard.goNext(); // → extras
    const renderer = new Renderer(wizard, root);
    renderer.registerStep('extras', extrasStep);
    extrasStep.render(region, wizard);
    // Decrement is disabled and can't drop a required extra below 1.
    expect(region.querySelector('[data-booked-action="extra-decrement"]').disabled).toBe(true);
    region.querySelector('[data-booked-action="extra-decrement"]').click();
    expect(wizard.getState().context.selectedExtras[9]).toBe(1);
    renderer.destroy();
  });

  it('caps an extra at its maxQuantity', async () => {
    document.body.innerHTML = MARKUP;
    const root = document.querySelector('[data-booked-wizard]');
    const region = document.querySelector('[data-booked-step="extras"]');
    const wizard = await startedWizard({
      serviceExtras: vi.fn(async () => ({ extras: [{ id: 3, name: 'Towel', price: 2, maxQuantity: 2 }] })),
    });
    wizard.goNext();
    const renderer = new Renderer(wizard, root);
    renderer.registerStep('extras', extrasStep);
    extrasStep.render(region, wizard);

    const inc = () => region.querySelector('[data-booked-action="extra-increment"]').click();
    inc();
    inc();
    inc(); // third increment should be ignored (max 2)
    expect(wizard.getState().context.selectedExtras[3]).toBe(2);
    expect(region.querySelector('[data-booked-action="extra-increment"]').disabled).toBe(true);
    renderer.destroy();
  });
});
