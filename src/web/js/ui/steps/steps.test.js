import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../../core/wizard.js';
import { serviceListStep } from './service-list.js';
import { customerInfoStep } from './customer-info.js';
import { reviewStep } from './review.js';

function fakeApi(overrides = {}) {
  return {
    commerceSettings: vi.fn(async () => ({ commerceEnabled: false })),
    services: vi.fn(async () => ({
      services: [
        { id: 12, name: 'Haircut', price: 40, duration: 30, durationType: 'minutes' },
        { id: 13, name: 'Color', price: 90, duration: 90, durationType: 'minutes' },
      ],
    })),
    serviceExtras: vi.fn(async () => ({ extras: [] })),
    employees: vi.fn(async () => ({ employees: [], locations: [{ id: 1 }], serviceHasSchedule: true })),
    createSlotLock: vi.fn(async () => ({ success: true, token: 't', expiresIn: 300 })),
    extendLock: vi.fn(async () => ({ success: true, expiresIn: 300 })),
    releaseLock: vi.fn(async () => ({ success: true })),
    createBooking: vi.fn(async () => ({ success: true, reservation: { reference: 'X' } })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

async function startedWizard(apiOverrides = {}) {
  const w = new Wizard({ apiClient: fakeApi(apiOverrides), flow: 'booking' });
  await w.start();
  return w;
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('serviceListStep', () => {
  const REGION = `
    <section>
      <template data-booked-template="service-card">
        <button data-booked-action="select-service">
          <span data-booked-field="name"></span>
          <span data-booked-field="price"></span>
        </button>
      </template>
      <div data-booked-list="services"></div>
    </section>`;

  it('renders a card per service with id + fields filled', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    serviceListStep.render(region, wizard);
    const cards = region.querySelectorAll('[data-booked-action="select-service"]');
    expect(cards).toHaveLength(2);
    expect(cards[0].getAttribute('data-booked-id')).toBe('12');
    expect(cards[0].querySelector('[data-booked-field="name"]').textContent).toBe('Haircut');
    expect(cards[1].querySelector('[data-booked-field="price"]').textContent).toBe('90');
  });

  it('reflects the selected service via aria-pressed', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    await wizard.selectService(13);
    serviceListStep.render(region, wizard);
    const selected = region.querySelector('[data-booked-id="13"]');
    const other = region.querySelector('[data-booked-id="12"]');
    expect(selected.getAttribute('aria-pressed')).toBe('true');
    expect(other.getAttribute('aria-pressed')).toBe('false');
  });

  it('rebuilds cleanly on re-render (no duplicates)', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    serviceListStep.render(region, wizard);
    serviceListStep.render(region, wizard);
    expect(region.querySelectorAll('[data-booked-action="select-service"]')).toHaveLength(2);
  });
});

describe('customerInfoStep', () => {
  const REGION = `
    <section>
      <input data-booked-field="name" />
      <input data-booked-field="email" />
      <input data-booked-field="phone" />
      <p id="err-name" data-booked-field-error="name" hidden></p>
      <p id="err-email" data-booked-field-error="email" hidden></p>
    </section>`;

  it('pushes input changes into the core via setCustomer', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    customerInfoStep.mount(region, wizard);
    const name = region.querySelector('[data-booked-field="name"]');
    name.value = 'Ada';
    name.dispatchEvent(new window.Event('input', { bubbles: true }));
    expect(wizard.getState().context.customer.name).toBe('Ada');
  });

  it('reflects current values on render', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    wizard.setCustomer({ email: 'ada@example.com' });
    customerInfoStep.render(region, wizard);
    expect(region.querySelector('[data-booked-field="email"]').value).toBe('ada@example.com');
  });

  it('surfaces per-field validation messages with aria-invalid', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    customerInfoStep.mount(region, wizard);
    // Reach the info step, then leave it empty to trigger validation.
    await wizard.selectService(12);
    wizard.goNext(); // datetime
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.goNext(); // info
    wizard.goNext(); // leaving info without data → validation error
    const nameErr = region.querySelector('[data-booked-field-error="name"]');
    expect(nameErr.hidden).toBe(false);
    expect(nameErr.textContent.length).toBeGreaterThan(0);
    expect(region.querySelector('[data-booked-field="name"]').getAttribute('aria-invalid')).toBe('true');
  });
});

describe('reviewStep', () => {
  const REGION = `
    <section>
      <span data-booked-summary="service"></span>
      <span data-booked-summary="date"></span>
      <span data-booked-summary="customer-name"></span>
      <span data-booked-summary="total"></span>
      <div data-booked-payment-notice hidden></div>
    </section>`;

  it('fills the summary from context', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    await wizard.selectService(12);
    wizard.goNext();
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00' });
    wizard.setCustomer({ name: 'Ada', email: 'ada@example.com' });
    reviewStep.render(region, wizard);
    expect(region.querySelector('[data-booked-summary="service"]').textContent).toBe('Haircut');
    expect(region.querySelector('[data-booked-summary="date"]').textContent).toBe('2026-08-01');
    expect(region.querySelector('[data-booked-summary="customer-name"]').textContent).toBe('Ada');
    expect(region.querySelector('[data-booked-summary="total"]').textContent).toBe('40');
  });

  it('keeps the payment notice hidden when no payment is required', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard();
    await wizard.selectService(12);
    reviewStep.render(region, wizard);
    expect(region.querySelector('[data-booked-payment-notice]').hidden).toBe(true);
  });

  it('shows the payment notice when Commerce requires payment', async () => {
    document.body.innerHTML = REGION;
    const region = document.body.firstElementChild;
    const wizard = await startedWizard({ commerceSettings: vi.fn(async () => ({ commerceEnabled: true })) });
    await wizard.selectService(12);
    reviewStep.render(region, wizard);
    expect(region.querySelector('[data-booked-payment-notice]').hidden).toBe(false);
  });
});
