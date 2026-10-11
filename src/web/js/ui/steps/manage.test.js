import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Wizard } from '../../core/wizard.js';
import { Renderer } from '../renderer.js';
import { manageStep } from './manage.js';

function fakeApi(overrides = {}) {
  return {
    manageLoad: vi.fn(async () => ({
      success: true,
      id: 55,
      serviceName: 'Haircut',
      formattedDateTime: 'Aug 1, 2026 · 10:00',
      status: 'confirmed',
      statusLabel: 'Confirmed',
      quantity: 2,
      customerName: 'Ada',
      canCancel: true,
    })),
    manageCancel: vi.fn(async () => ({ success: true })),
    manageReduce: vi.fn(async () => ({ success: true })),
    manageIncrease: vi.fn(async () => ({ success: true })),
    manageReschedule: vi.fn(async () => ({ success: true })),
    calendar: vi.fn(async () => ({ success: true, calendar: { '2026-08-10': { isBookable: true } } })),
    slots: vi.fn(async () => ({ success: true, slots: [{ time: '10:00', endTime: '10:30', availableCapacity: 1 }] })),
    abortAll: vi.fn(),
    beaconRelease: vi.fn(),
    ...overrides,
  };
}

function manageWizard(apiOverrides = {}) {
  const api = fakeApi(apiOverrides);
  const wizard = new Wizard({ apiClient: api, mode: 'manage', manageToken: 'mtok' });
  return { wizard, api };
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('Wizard — management mode (core)', () => {
  it('loads the reservation on start and lands on the manage step', async () => {
    const { wizard, api } = manageWizard();
    const onLoaded = vi.fn();
    wizard.on('manage:loaded', onLoaded);
    await wizard.start();
    expect(api.manageLoad).toHaveBeenCalledWith({ token: 'mtok' });
    expect(wizard.stepId).toBe('manage');
    expect(wizard.getState().context.reservation.id).toBe(55);
    expect(onLoaded).toHaveBeenCalled();
  });

  it('surfaces an error for an invalid manage token', async () => {
    const { wizard } = manageWizard({ manageLoad: vi.fn(async () => ({ success: false, message: 'invalid' })) });
    const onError = vi.fn();
    wizard.on('error', onError);
    await wizard.start();
    expect(onError).toHaveBeenCalled();
    expect(wizard.getState().context.reservation).toBeNull();
  });

  it('cancel posts with the token and reloads to the cancelled reservation', async () => {
    let loads = 0;
    const { wizard, api } = manageWizard({
      manageLoad: vi.fn(async () => {
        loads += 1;
        return { success: true, id: 55, quantity: 2, canCancel: loads === 1, status: loads > 1 ? 'cancelled' : 'confirmed', statusLabel: 'x' };
      }),
    });
    await wizard.start();
    const onCancelled = vi.fn();
    wizard.on('manage:cancelled', onCancelled);
    const res = await wizard.manageCancel({ reason: 'plans changed' });
    expect(res.ok).toBe(true);
    expect(api.manageCancel).toHaveBeenCalledWith({ token: 'mtok', reason: 'plans changed' });
    expect(wizard.getState().context.reservation.status).toBe('cancelled');
    expect(onCancelled).toHaveBeenCalled();
  });

  it('reduce and increase post id + token + amount', async () => {
    const { wizard, api } = manageWizard();
    await wizard.start();
    await wizard.manageReduce(1);
    expect(api.manageReduce).toHaveBeenCalledWith({ id: 55, token: 'mtok', reduceBy: 1 });
    await wizard.manageIncrease(3);
    expect(api.manageIncrease).toHaveBeenCalledWith({ id: 55, token: 'mtok', increaseBy: 3 });
  });

  it('reschedule posts id + token + the new date/time', async () => {
    const { wizard, api } = manageWizard();
    await wizard.start();
    await wizard.manageReschedule({ date: '2026-08-11', startTime: '14:00', endTime: '14:30' });
    expect(api.manageReschedule).toHaveBeenCalledWith({
      id: 55,
      token: 'mtok',
      newDate: '2026-08-11',
      newStartTime: '14:00',
      newEndTime: '14:30',
    });
  });

  it('manageLoadCalendar/manageLoadSlots pass the manage token, never the booking flow\'s selection params', async () => {
    const { wizard, api } = manageWizard();
    await wizard.start();
    const calendar = await wizard.manageLoadCalendar({ year: 2026, month: 8 });
    expect(api.calendar).toHaveBeenCalledWith({ year: 2026, month: 8, manageToken: 'mtok' });
    expect(calendar).toEqual({ '2026-08-10': { isBookable: true } });

    const slots = await wizard.manageLoadSlots({ date: '2026-08-10' });
    expect(api.slots).toHaveBeenCalledWith({ date: '2026-08-10', manageToken: 'mtok' });
    expect(slots.slots).toHaveLength(1);
  });

  it('manageLoadSlots fails soft — a rejected request returns null, not a wizard-wide error', async () => {
    const { wizard } = manageWizard({ slots: vi.fn(async () => { throw new Error('boom'); }) });
    await wizard.start();
    const onError = vi.fn();
    wizard.on('error', onError);
    const result = await wizard.manageLoadSlots({ date: '2026-08-10' });
    expect(result).toBeNull();
    expect(onError).not.toHaveBeenCalled();
  });
});

const MARKUP = `
  <div data-booked-wizard>
    <section data-booked-step="manage">
      <h2 data-booked-step-heading>Manage</h2>
      <dd data-booked-manage="service"></dd>
      <dd data-booked-manage="status"></dd>
      <dd data-booked-manage="quantity"></dd>
      <div data-booked-manage-actions hidden>
        <button data-booked-action="manage-reduce">−</button>
        <button data-booked-action="manage-increase">+</button>
        <button data-booked-action="manage-cancel">Cancel</button>
      </div>
      <p data-booked-manage-cancelled hidden>Cancelled</p>
    </section>
  </div>`;

describe('manageStep (DOM)', () => {
  it('renders the reservation and drives cancel via the shell', async () => {
    document.body.innerHTML = MARKUP;
    const root = document.querySelector('[data-booked-wizard]');
    const region = document.querySelector('[data-booked-step="manage"]');
    let loads = 0;
    const api = fakeApi({
      manageLoad: vi.fn(async () => {
        loads += 1;
        return { success: true, id: 55, serviceName: 'Haircut', quantity: 2, canCancel: loads === 1, status: loads > 1 ? 'cancelled' : 'confirmed', statusLabel: 'x' };
      }),
    });
    const wizard = new Wizard({ apiClient: api, mode: 'manage', manageToken: 'mtok' });
    const renderer = new Renderer(wizard, root);
    renderer.registerStep('manage', manageStep);
    manageStep.mount(region, wizard);
    await wizard.start();
    renderer.syncInitial();

    expect(region.querySelector('[data-booked-manage="service"]').textContent).toBe('Haircut');
    expect(region.querySelector('[data-booked-manage="quantity"]').textContent).toBe('2');
    expect(region.querySelector('[data-booked-manage-actions]').hidden).toBe(false);

    region.querySelector('[data-booked-action="manage-cancel"]').click();
    await vi.waitFor(() => expect(region.querySelector('[data-booked-manage-cancelled]').hidden).toBe(false));
    expect(region.querySelector('[data-booked-manage-actions]').hidden).toBe(true);
    renderer.destroy();
  });

  it('disables reduce when quantity is 1', async () => {
    document.body.innerHTML = MARKUP;
    const region = document.querySelector('[data-booked-step="manage"]');
    const wizard = new Wizard({
      apiClient: fakeApi({ manageLoad: vi.fn(async () => ({ success: true, id: 1, quantity: 1, canCancel: true, status: 'confirmed', statusLabel: 'x' })) }),
      mode: 'manage',
      manageToken: 't',
    });
    manageStep.mount(region, wizard);
    await wizard.start();
    manageStep.render(region, wizard);
    expect(region.querySelector('[data-booked-action="manage-reduce"]').disabled).toBe(true);
  });
});

const RESCHEDULE_MARKUP = `
  <div data-booked-wizard>
    <section data-booked-step="manage">
      <h2 data-booked-step-heading>Manage</h2>
      <dd data-booked-manage="service"></dd>
      <dd data-booked-manage="datetime"></dd>
      <dd data-booked-manage="status"></dd>
      <dd data-booked-manage="quantity"></dd>
      <div data-booked-manage-reschedule hidden>
        <div data-booked-manage-reschedule-calendar data-booked-initial-month="2026-08"></div>
        <p data-booked-manage-reschedule-empty hidden>No times</p>
        <div data-booked-manage-reschedule-slots></div>
        <button data-booked-action="manage-reschedule-submit" disabled>Confirm</button>
      </div>
      <div data-booked-manage-actions hidden>
        <button data-booked-action="manage-reduce">−</button>
        <button data-booked-action="manage-increase">+</button>
        <button data-booked-action="manage-cancel">Cancel</button>
      </div>
      <p data-booked-manage-cancelled hidden>Cancelled</p>
    </section>
  </div>`;

async function mountReschedule(apiOverrides = {}, loadOverrides = {}) {
  document.body.innerHTML = RESCHEDULE_MARKUP;
  const region = document.querySelector('[data-booked-step="manage"]');
  const api = fakeApi({
    manageLoad: vi.fn(async () => ({
      success: true,
      id: 55,
      serviceName: 'Haircut',
      formattedDateTime: 'Aug 1, 2026 · 10:00',
      status: 'confirmed',
      statusLabel: 'Confirmed',
      quantity: 1,
      customerName: 'Ada',
      canCancel: true,
      canReschedule: true,
      ...loadOverrides,
    })),
    ...apiOverrides,
  });
  const wizard = new Wizard({ apiClient: api, mode: 'manage', manageToken: 'mtok' });
  manageStep.mount(region, wizard);
  await wizard.start();
  manageStep.render(region, wizard);
  await vi.waitFor(() => expect(api.calendar).toHaveBeenCalled());
  return { region, wizard, api };
}

const day = (region, d) => region.querySelector(`[data-booked-date="${d}"]`);

describe('manageStep — reschedule (DOM)', () => {
  it('keeps the panel hidden when the reservation cannot be rescheduled', async () => {
    document.body.innerHTML = RESCHEDULE_MARKUP;
    const region = document.querySelector('[data-booked-step="manage"]');
    const api = fakeApi({
      manageLoad: vi.fn(async () => ({
        success: true, id: 55, quantity: 1, canCancel: true, canReschedule: false, status: 'confirmed', statusLabel: 'x',
      })),
    });
    const wizard = new Wizard({ apiClient: api, mode: 'manage', manageToken: 'mtok' });
    manageStep.mount(region, wizard);
    await wizard.start();
    manageStep.render(region, wizard);
    expect(region.querySelector('[data-booked-manage-reschedule]').hidden).toBe(true);
    expect(api.calendar).not.toHaveBeenCalled();
  });

  it('shows the panel and loads the calendar scoped to the manage token when reschedulable', async () => {
    const { region, api } = await mountReschedule();
    expect(region.querySelector('[data-booked-manage-reschedule]').hidden).toBe(false);
    expect(api.calendar).toHaveBeenCalledWith(expect.objectContaining({ manageToken: 'mtok' }));
    expect(day(region, '2026-08-10')).not.toBeNull();
  });

  it('picking an available day loads and renders its slots', async () => {
    const { region, api } = await mountReschedule();
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(api.slots).toHaveBeenCalledWith(expect.objectContaining({ date: '2026-08-10', manageToken: 'mtok' })));
    await vi.waitFor(() => expect(region.querySelector('[data-booked-reschedule-slot]')).not.toBeNull());
    expect(region.querySelector('[data-booked-reschedule-slot]').textContent).toBe('10:00');
  });

  it('picking a slot enables submit, which reschedules to that date/time', async () => {
    const { region, api } = await mountReschedule();
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(region.querySelector('[data-booked-reschedule-slot]')).not.toBeNull());

    const submit = region.querySelector('[data-booked-action="manage-reschedule-submit"]');
    expect(submit.disabled).toBe(true);
    region.querySelector('[data-booked-reschedule-slot]').click();
    expect(submit.disabled).toBe(false);

    submit.click();
    await vi.waitFor(() =>
      expect(api.manageReschedule).toHaveBeenCalledWith({
        id: 55,
        token: 'mtok',
        newDate: '2026-08-10',
        newStartTime: '10:00',
        newEndTime: '10:30',
      }),
    );
  });

  it('disables submit immediately on click, so a double-click cannot fire two reschedules', async () => {
    let resolveReschedule;
    const { region, api } = await mountReschedule({
      manageReschedule: vi.fn(() => new Promise((resolve) => { resolveReschedule = resolve; })),
    });
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(region.querySelector('[data-booked-reschedule-slot]')).not.toBeNull());
    region.querySelector('[data-booked-reschedule-slot]').click();

    const submit = region.querySelector('[data-booked-action="manage-reschedule-submit"]');
    submit.click();
    expect(submit.disabled).toBe(true);
    submit.click(); // a second click while the first request is still in flight
    expect(api.manageReschedule).toHaveBeenCalledTimes(1);

    resolveReschedule({ success: true });
  });

  it('re-enables submit after a failed reschedule, so the customer can retry', async () => {
    const { region } = await mountReschedule({
      manageReschedule: vi.fn(async () => ({ success: false, message: 'That time was just taken.' })),
    });
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(region.querySelector('[data-booked-reschedule-slot]')).not.toBeNull());
    region.querySelector('[data-booked-reschedule-slot]').click();

    const submit = region.querySelector('[data-booked-action="manage-reschedule-submit"]');
    submit.click();
    await vi.waitFor(() => expect(submit.disabled).toBe(false));
  });

  it('a disabled (fully booked) slot cannot be selected', async () => {
    const { region } = await mountReschedule({
      slots: vi.fn(async () => ({ success: true, slots: [{ time: '10:00', endTime: '10:30', availableCapacity: 0 }] })),
    });
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(region.querySelector('[data-booked-reschedule-slot]')).not.toBeNull());
    const opt = region.querySelector('[data-booked-reschedule-slot]');
    expect(opt.getAttribute('aria-disabled')).toBe('true');
    opt.click();
    expect(opt.getAttribute('aria-selected')).toBe('false');
    expect(region.querySelector('[data-booked-action="manage-reschedule-submit"]').disabled).toBe(true);
  });

  it('resets the picker once the reschedule actually moves the booking', async () => {
    let loads = 0;
    const { region, api } = await mountReschedule({
      manageLoad: vi.fn(async () => {
        loads += 1;
        return {
          success: true,
          id: 55,
          quantity: 1,
          canCancel: true,
          canReschedule: true,
          status: 'confirmed',
          statusLabel: 'x',
          formattedDateTime: loads === 1 ? 'Aug 1, 2026 · 10:00' : 'Aug 10, 2026 · 10:00',
        };
      }),
    });
    day(region, '2026-08-10').click();
    await vi.waitFor(() => expect(region.querySelector('[data-booked-reschedule-slot]')).not.toBeNull());
    region.querySelector('[data-booked-reschedule-slot]').click();

    const submit = region.querySelector('[data-booked-action="manage-reschedule-submit"]');
    submit.click();
    await vi.waitFor(() => expect(api.manageReschedule).toHaveBeenCalled());
    await vi.waitFor(() => expect(submit.disabled).toBe(true));
    expect(region.querySelector('[data-booked-reschedule-slot]')).toBeNull();
  });
});
