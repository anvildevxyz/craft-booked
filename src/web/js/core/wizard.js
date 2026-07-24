/**
 * BookedWizard — the headless facade that composes the core.
 *
 * Ties the lifecycle machine, step cursor, context, API client, lock timer,
 * i18n and validation into one driveable object. It makes NO DOM assumptions:
 * a renderer (M2) subscribes to its events; a headless caller drives it with
 * the programmatic methods. This is the semver'd public surface from
 * docs/WIZARD_CORE_DESIGN.md §4.
 */
import { Emitter } from './emitter.js';
import { Machine, STATES } from './machine.js';
import { Flow } from './flow.js';
import { Context } from './context.js';
import { BookedApi, ApiError } from './api.js';
import { LockController } from './lock.js';
import { I18n } from './i18n.js';
import { canLeaveStep } from './validation.js';
import { bookingFlow } from './flows/booking.js';
import { eventFlow } from './flows/event.js';
import { manageFlow } from './flows/manage.js';

const FLOWS = { booking: bookingFlow, event: eventFlow, manage: manageFlow };

/** Extract a list from a `{[key]: [...]}` JSON envelope. */
function list(payload, key) {
  return payload && Array.isArray(payload[key]) ? payload[key] : [];
}

export class Wizard {
  constructor(options = {}) {
    this._options = options;
    this._emitter = new Emitter();

    this._config = {
      requirePhone: false,
      showNotes: true,
      defaultQuantity: 1,
      siteHandle: options.api?.site ?? null,
      ...(options.config ?? {}),
    };

    this._i18n = new I18n(
      { ...(options.labels ?? {}), ...(options.messages ?? {}) },
      { locale: options.locale ?? null },
    );

    // API client: accept an injected instance (tests) or build from config.
    this._api =
      options.apiClient ||
      new BookedApi({
        baseUrl: options.api?.baseUrl,
        csrf: options.api?.csrf,
        site: options.api?.site,
        fetch: options.api?.fetch,
      });

    this._ctx = new Context({
      serviceId: options.serviceId ?? null,
      quantity: options.config?.defaultQuantity ?? 1,
      customer: options.customer ?? {},
    });

    // `?manage=` runs the management flow; otherwise the booking/event flow.
    this._mode = options.mode === 'manage' ? 'manage' : 'book';
    this._manageToken = options.manageToken ?? (this._mode === 'manage' ? options.token : null);
    const flowName = this._mode === 'manage' ? 'manage' : (options.flow ?? 'booking');
    const flowDef = FLOWS[flowName];
    if (!flowDef) throw new Error(`Wizard: unknown flow "${options.flow}"`);
    this._flow = new Flow(flowDef, this._ctx);

    this._machine = new Machine(({ from, to, meta }) => {
      this._emitter.emit('state:change', { from, to, stepId: this._flow.currentId, meta });
    });

    this._lock = new LockController({ api: this._api, emit: (e, p) => this._emitter.emit(e, p) });

    // React to the hold timer: a timer-driven expiry must clear the selection,
    // move the machine to `expired`, and send the user back to re-pick — none of
    // which happens on its own. `lock:expiring` drives the aria-live countdown.
    this._emitter.on('lock:expired', () => this._onLockExpired());
    this._emitter.on('lock:expiring', ({ remainingMs }) => {
      const minutes = Math.max(1, Math.ceil((remainingMs || 0) / 60000));
      this._emitter.emit('announce', { message: this._i18n.t('lock.expiring', { minutes }), politeness: 'polite' });
    });

    // Best-effort lock release on page unload (browser only).
    this._onUnload = null;
    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
      this._onUnload = () => {
        const payload = this._lock.beaconPayload();
        if (payload) this._api.beaconRelease(payload.token);
      };
      window.addEventListener('beforeunload', this._onUnload);
    }
  }

  // ---- Subscriptions ==================================================
  on(event, handler) {
    return this._emitter.on(event, handler);
  }
  once(event, handler) {
    return this._emitter.once(event, handler);
  }
  off(event, handler) {
    this._emitter.off(event, handler);
  }

  // ---- Introspection ==================================================
  get state() {
    return this._machine.state;
  }
  get stepId() {
    return this._flow.currentId;
  }
  getState() {
    return {
      lifecycle: this._machine.state,
      stepId: this._flow.currentId,
      position: this._flow.position,
      total: this._flow.total,
      context: this._ctx.snapshot(),
    };
  }

  // ---- Lifecycle ======================================================

  /** Bootstrap: load commerce settings + services, resolve preselects. */
  async start() {
    if (this._machine.state !== STATES.IDLE) return this.getState();
    if (this._mode === 'manage') return this._startManage();
    this._machine.transition(STATES.LOADING);
    try {
      const [commerce, services] = await Promise.all([
        this._api.commerceSettings().catch(() => null),
        this._api.services(),
      ]);
      if (commerce) this._applyCommerce(commerce);
      this._ctx.services = list(services, 'services');
      this._flow.setContext(this._ctx);
      this._emitter.emit('data:loaded', { kind: 'services', items: this._ctx.services });

      if (this._options.serviceId != null) {
        await this._loadServiceData(this._options.serviceId);
      }

      // Waitlist conversion: a "your slot is open" link prefills who/what.
      const conversionToken = this._options.conversionToken ?? this._options.waitlist;
      if (conversionToken) {
        await this._applyConversion(conversionToken);
      }

      this._machine.transition(STATES.BROWSING);
      this._announceStep('init');
      return this.getState();
    } catch (err) {
      this._toError(err);
      return this.getState();
    }
  }

  /**
   * Prefill from a waitlist-conversion token: the customer's details and the
   * originally-requested service/location/employee, then land on the date/time
   * step so they reconfirm an available slot (a fresh lock is acquired there,
   * not carried over from the waitlist entry). Best-effort — a bad/expired
   * token is ignored so the wizard still opens normally.
   */
  async _applyConversion(token) {
    let entry;
    try {
      entry = await this._api.waitlistConvert({ conversionToken: token });
    } catch {
      return;
    }
    if (!entry || entry.success === false) return;

    this._ctx.setCustomer({
      name: entry.userName ?? '',
      email: entry.userEmail ?? '',
      phone: entry.userPhone ?? '',
    });
    if (entry.serviceId) await this._loadServiceData(entry.serviceId);
    if (entry.locationId != null) {
      this._ctx.locationId = entry.locationId;
      this._ctx.selectedLocation = this._ctx.locations.find((l) => l.id === entry.locationId) ?? null;
    }
    if (entry.employeeId != null) {
      this._ctx.employeeId = entry.employeeId;
      this._ctx.selectedEmployee = this._ctx.employees.find((e) => e.id === entry.employeeId) ?? null;
    }
    this._flow.setContext(this._ctx);
    this._flow.goTo('datetime');
    this._emitter.emit('conversion:loaded', { entry });
  }

  _applyCommerce(payload) {
    this._ctx.commerce = {
      enabled: !!payload.commerceEnabled,
      currency: payload.currency ?? null,
      currencySymbol: payload.currencySymbol ?? null,
      cartUrl: payload.cartUrl ?? null,
      checkoutUrl: payload.checkoutUrl ?? null,
    };
  }

  // ---- Selection ======================================================

  /** Load extras + employees/locations for a service and set it in context. */
  async _loadServiceData(id) {
    const service = this._ctx.services.find((s) => s.id === id) ?? { id };
    this._ctx.setService(service);

    const [extras, employees] = await Promise.all([
      this._api.serviceExtras(id).catch(() => null),
      this._api.employees(id).catch(() => null),
    ]);

    this._ctx.extras = list(extras, 'extras');
    this._ctx.employees = list(employees, 'employees');
    this._ctx.locations = list(employees, 'locations');
    this._ctx.serviceHasSchedule = !!(employees && employees.serviceHasSchedule);

    // Required add-ons start selected at quantity 1 (mirrors the legacy wizard).
    for (const extra of this._ctx.extras) {
      if (extra.isRequired) this._ctx.setExtraQuantity(extra.id, 1);
    }

    // Auto-select the only option, mirroring the current wizard.
    if (this._ctx.locations.length === 1) {
      this._ctx.selectedLocation = this._ctx.locations[0];
      this._ctx.locationId = this._ctx.locations[0].id;
    }
    if (this._ctx.employees.length === 1) {
      this._ctx.selectedEmployee = this._ctx.employees[0];
      this._ctx.employeeId = this._ctx.employees[0].id;
    }

    this._flow.setContext(this._ctx);
    this._emitter.emit('data:loaded', { kind: 'service', items: { extras: this._ctx.extras, employees: this._ctx.employees } });
  }

  async selectService(id) {
    await this._loadServiceData(id);
    this._emitter.emit('service:selected', { serviceId: id });
    return this.getState();
  }

  selectExtra(id, quantity = 1) {
    this._ctx.setExtraQuantity(id, quantity);
    this._flow.setContext(this._ctx);
    return this.getState();
  }
  clearExtra(id) {
    this._ctx.setExtraQuantity(id, 0);
    this._flow.setContext(this._ctx);
    return this.getState();
  }

  selectLocation(id) {
    this._ctx.locationId = id;
    this._ctx.selectedLocation = this._ctx.locations.find((l) => l.id === id) ?? null;
    return this.getState();
  }
  selectEmployee(id) {
    this._ctx.employeeId = id;
    this._ctx.selectedEmployee = this._ctx.employees.find((e) => e.id === id) ?? null;
    return this.getState();
  }

  setCustomer(fields) {
    this._ctx.setCustomer(fields);
    return this.getState();
  }

  // ---- Slot / range / event selection (acquire lock) ==================

  async selectSlot({ date, time, quantity = 1 } = {}) {
    this._ctx.date = date;
    this._ctx.time = time;
    this._ctx.slotQuantity = quantity;
    const body = this._pruned({
      date,
      startTime: time,
      serviceId: this._ctx.serviceId,
      employeeId: this._ctx.employeeId,
      locationId: this._ctx.locationId,
      extrasDuration: this._ctx.extrasDuration || null,
      quantity: quantity > 1 ? quantity : null,
    });
    return this._acquire('slot', body, () => this._emitter.emit('slot:selected', { date, time, quantity }));
  }

  async selectRange({ startDate, endDate, quantity = 1 } = {}) {
    this._ctx.date = startDate;
    this._ctx.endDate = endDate;
    this._ctx.slotQuantity = quantity;
    const body = this._pruned({
      date: startDate,
      endDate,
      serviceId: this._ctx.serviceId,
      employeeId: this._ctx.employeeId,
      locationId: this._ctx.locationId,
      quantity: quantity > 1 ? quantity : null,
    });
    return this._acquire('range', body, () => this._emitter.emit('range:selected', { startDate, endDate, quantity }));
  }

  async selectEventDate(id, { quantity = 1 } = {}) {
    this._ctx.eventDateId = id;
    this._ctx.slotQuantity = quantity;
    const body = this._pruned({ eventDateId: id, quantity });
    // Event seat locks are best-effort server-side: the selection stands even
    // when the lock can't be held, matching the legacy event wizard.
    return this._acquire('event', body, () => this._emitter.emit('event:selected', { eventDateId: id, quantity }), {
      bestEffort: true,
    });
  }

  async _acquire(kind, body, onSuccess, { bestEffort = false } = {}) {
    let res;
    try {
      res = await this._lock.acquire(kind, body);
    } catch (err) {
      this._syncLockAfterFailure();
      // Best-effort (events): the selection stands without a held lock.
      if (bestEffort) {
        onSuccess();
        return { acquired: false, bestEffort: true };
      }
      // The backend returns 400 (jsonError default) for a taken slot — surface it
      // as recoverable and stay on the step, not a fatal error.
      if (err && err.status === 400) {
        this._emitter.emit('error', {
          message: err.message || this._i18n.t('error.slotReserved'),
          code: 'slot_reserved',
          recoverable: true,
        });
        return { acquired: false, message: err.message };
      }
      this._toError(err);
      return { acquired: false, error: err.message };
    }
    // A concurrent acquisition was already in flight — drop this one silently.
    if (res.busy) return res;

    if (res.acquired) {
      this._ctx.lock = { token: res.token, expiresAt: res.expiresAt };
      this._machine.transition(STATES.HOLDING_LOCK);
      onSuccess();
    } else {
      // The prior lock (if any) was already released by acquire(); resync so
      // ctx.lock and the machine don't advertise a hold that no longer exists.
      this._syncLockAfterFailure();
      if (bestEffort) {
        onSuccess();
      } else {
        this._emitter.emit('error', {
          message: res.message || this._i18n.t('error.slotReserved'),
          code: 'slot_reserved',
          recoverable: true,
        });
      }
    }
    return res;
  }

  /** Resync ctx.lock + machine to the LockController's real state after a failed acquire. */
  _syncLockAfterFailure() {
    this._ctx.lock = this._lock.held ? { token: this._lock.token, expiresAt: this._lock.expiresAt } : null;
    if (!this._lock.held && this._machine.state === STATES.HOLDING_LOCK) {
      this._machine.transition(STATES.BROWSING);
    }
  }

  /** Central handler for a lost lock (timer expiry or a 410 at submit). */
  _onLockExpired() {
    this._ctx.lock = null;
    const s = this._machine.state;
    if (s === STATES.HOLDING_LOCK || s === STATES.SUBMITTING || s === STATES.PAYING) {
      this._machine.transition(STATES.EXPIRED);
    }
    // Send the booking flow back to re-pick a slot.
    if (this._flow.id === 'booking') {
      const from = this._flow.currentId;
      if (from !== 'datetime' && this._flow.goTo('datetime')) {
        this._emitter.emit('step:change', { from, to: 'datetime', direction: 'back' });
      }
    }
    // Surface after the step change (which clears stale errors), so the banner sticks.
    const message = this._i18n.t('lock.expired');
    this._emitter.emit('error', { message, code: 'lock_expired', recoverable: true });
    this._emitter.emit('announce', { message, politeness: 'assertive' });
  }

  // ---- Availability data (for the calendar / slot UI) =================

  /**
   * Load the month availability map for the current selection. Returns a
   * `{ 'YYYY-MM-DD': { isBookable, hasAvailability, isBlackedOut } }` map (or
   * null if the request was superseded). Emits `data:loaded` (kind 'calendar').
   */
  async loadCalendar({ year, month } = {}) {
    let data;
    try {
      data = await this._api.calendar(
        this._pruned({
          serviceId: this._ctx.serviceId,
          employeeId: this._ctx.employeeId,
          locationId: this._ctx.locationId,
          year,
          month,
          quantity: this._ctx.slotQuantity > 1 ? this._ctx.slotQuantity : null,
          extrasDuration: this._ctx.extrasDuration || null,
        }),
      );
    } catch (err) {
      if (err && err.aborted) return null;
      this._toError(err);
      return null;
    }
    const calendar = (data && data.calendar) || {};
    this._emitter.emit('data:loaded', { kind: 'calendar', items: calendar });
    return calendar;
  }

  /**
   * Load bookable time slots for a date. Returns `{ slots, waitlistAvailable }`
   * (or null if superseded). Emits `data:loaded` (kind 'slots'). Records the
   * date on the context so a subsequent `selectSlot` matches.
   */
  async loadSlots({ date } = {}) {
    let data;
    try {
      data = await this._api.slots(
        this._pruned({
          date,
          serviceId: this._ctx.serviceId,
          employeeId: this._ctx.employeeId,
          locationId: this._ctx.locationId,
          quantity: this._ctx.slotQuantity > 1 ? this._ctx.slotQuantity : null,
          extrasDuration: this._ctx.extrasDuration || null,
        }),
      );
    } catch (err) {
      if (err && err.aborted) return null;
      this._toError(err);
      return null;
    }
    const slots = (data && data.slots) || [];
    const waitlistAvailable = !!(data && data.waitlistAvailable);
    this._ctx.date = date;
    this._emitter.emit('data:loaded', { kind: 'slots', items: slots, waitlistAvailable });
    return { slots, waitlistAvailable };
  }

  /**
   * Load selectable start dates for a day-service month (`YYYY-MM`). Returns an
   * array of date strings (or null if superseded). Emits `data:loaded` (dates).
   */
  async loadDates({ month } = {}) {
    let data;
    try {
      data = await this._api.dates(
        this._pruned({
          serviceId: this._ctx.serviceId,
          employeeId: this._ctx.employeeId,
          locationId: this._ctx.locationId,
          month,
          quantity: this._ctx.slotQuantity > 1 ? this._ctx.slotQuantity : null,
          extrasDuration: this._ctx.extrasDuration || null,
        }),
      );
    } catch (err) {
      if (err && err.aborted) return null;
      this._toError(err);
      return null;
    }
    const availableDates = (data && data.availableDates) || [];
    this._emitter.emit('data:loaded', { kind: 'dates', items: availableDates });
    return availableDates;
  }

  /**
   * Load valid end dates for a flexible-day service given a start date. Returns
   * an array of date strings (or null if superseded). Emits `data:loaded`.
   */
  async loadEndDates({ startDate } = {}) {
    let data;
    try {
      data = await this._api.endDates(
        this._pruned({
          serviceId: this._ctx.serviceId,
          employeeId: this._ctx.employeeId,
          locationId: this._ctx.locationId,
          startDate,
          quantity: this._ctx.slotQuantity > 1 ? this._ctx.slotQuantity : null,
        }),
      );
    } catch (err) {
      if (err && err.aborted) return null;
      this._toError(err);
      return null;
    }
    const validEndDates = (data && data.validEndDates) || [];
    this._emitter.emit('data:loaded', { kind: 'endDates', items: validEndDates });
    return validEndDates;
  }

  /**
   * Remaining capacity for a multi-day range (tightest day wins). Returns
   * `{ remainingCapacity, startDate, endDate }` or null if superseded.
   */
  async loadRangeCapacity({ startDate, endDate } = {}) {
    let data;
    try {
      data = await this._api.rangeCapacity(
        this._pruned({
          serviceId: this._ctx.serviceId,
          employeeId: this._ctx.employeeId,
          locationId: this._ctx.locationId,
          startDate,
          endDate,
        }),
      );
    } catch (err) {
      if (err && err.aborted) return null;
      this._toError(err);
      return null;
    }
    if (!data) return null;
    return {
      remainingCapacity: data.remainingCapacity,
      startDate: data.startDate,
      endDate: data.endDate,
    };
  }

  /**
   * Load event dates for the event flow. Returns the list (or null if
   * superseded) and stores it on the context. Emits `data:loaded` (eventDates).
   */
  async loadEventDates(query = {}) {
    let data;
    try {
      data = await this._api.eventDates(this._pruned(query));
    } catch (err) {
      if (err && err.aborted) return null;
      this._toError(err);
      return null;
    }
    const eventDates = (data && data.eventDates) || [];
    this._ctx.eventDates = eventDates;
    this._emitter.emit('data:loaded', { kind: 'eventDates', items: eventDates });
    return eventDates;
  }

  // ---- Management mode (?manage=) =====================================

  /** Bootstrap the manage flow: load the reservation for the manage token. */
  async _startManage() {
    this._machine.transition(STATES.LOADING);
    try {
      await this._reloadReservation();
      this._machine.transition(STATES.BROWSING);
      this._announceStep('init');
      return this.getState();
    } catch (err) {
      this._toError(err);
      return this.getState();
    }
  }

  async _reloadReservation() {
    const data = await this._api.manageLoad({ token: this._manageToken });
    if (!data || data.success === false) {
      throw new ApiError((data && (data.message || data.error)) || this._i18n.t('error.generic'), { code: 'not_found' });
    }
    this._ctx.reservation = data;
    this._emitter.emit('manage:loaded', { reservation: data });
  }

  /** Cancel the managed booking. */
  async manageCancel({ reason } = {}) {
    if (!this._ctx.reservation) return { ok: false };
    try {
      const result = await this._api.manageCancel({ token: this._manageToken, reason });
      if (result && result.success === false) {
        throw new ApiError(result.message || result.error || this._i18n.t('error.generic'), { code: 'manage' });
      }
      await this._reloadReservation().catch(() => {});
      this._emitter.emit('manage:cancelled', { reservation: this._ctx.reservation });
      return { ok: true };
    } catch (err) {
      this._emitter.emit('error', { message: err.message, code: err.code || 'error', recoverable: true });
      return { ok: false, error: err.message };
    }
  }

  manageReduce(reduceBy = 1) {
    return this._manageQuantity('manageReduce', { reduceBy });
  }

  manageIncrease(increaseBy = 1) {
    return this._manageQuantity('manageIncrease', { increaseBy });
  }

  async _manageQuantity(method, extra) {
    const res = this._ctx.reservation;
    if (!res) return { ok: false };
    try {
      const result = await this._api[method]({ id: res.id, token: this._manageToken, ...extra });
      if (result && result.success === false) {
        throw new ApiError(result.message || result.error || this._i18n.t('error.generic'), { code: 'manage' });
      }
      await this._reloadReservation().catch(() => {});
      this._emitter.emit('manage:updated', { reservation: this._ctx.reservation });
      return { ok: true };
    } catch (err) {
      this._emitter.emit('error', { message: err.message, code: err.code || 'error', recoverable: true });
      return { ok: false, error: err.message };
    }
  }

  // ---- Navigation =====================================================

  goNext() {
    const stepId = this._flow.currentId;
    const check = canLeaveStep(stepId, this._ctx, { requirePhone: this._config.requirePhone });
    if (!check.ok) {
      this._emitValidationError(check.errors);
      return { ok: false, errors: check.errors };
    }
    const to = this._flow.next();
    if (to === null) return { ok: false, atEnd: true };
    this._emitter.emit('step:change', { from: stepId, to, direction: 'next' });
    this._announceStep();
    return { ok: true, stepId: to };
  }

  goBack() {
    const stepId = this._flow.currentId;
    const to = this._flow.back();
    if (to === null) return { ok: false, atStart: true };
    // Leaving a slot step releases the hold and returns to browsing.
    if (this._lock.held) {
      this._lock.release('back-nav');
      this._ctx.lock = null;
      this._machine.transition(STATES.BROWSING);
    }
    this._emitter.emit('step:change', { from: stepId, to, direction: 'back' });
    this._announceStep();
    return { ok: true, stepId: to };
  }

  /** Emit a validation error with resolved per-field messages + a summary message. */
  _emitValidationError(errors) {
    const messages = {};
    for (const [field, key] of Object.entries(errors)) {
      messages[field] = this._i18n.t(key);
    }
    const first = Object.values(messages)[0];
    this._emitter.emit('error', {
      code: 'validation',
      errors, // field → i18n key (for field targeting)
      messages, // field → resolved text
      message: first || this._i18n.t('error.generic'),
      recoverable: true,
    });
  }

  _announceStep() {
    this._emitter.emit('announce', {
      message: this._i18n.t('announce.stepChanged', {
        position: this._flow.position,
        total: this._flow.total,
        title: this._flow.currentId,
      }),
      politeness: 'polite',
    });
  }

  // ---- Submit =========================================================

  /**
   * Validate, refresh the hold, and create the booking. Renderer-supplied
   * fields (captchaToken, honeypot) merge into the body; the core never reads
   * the DOM. Resolves to a result object; expected domain failures surface as
   * states/events, not exceptions.
   */
  async submit({ fields = {}, addToCart = false } = {}) {
    const check = canLeaveStep('info', this._ctx, { requirePhone: this._config.requirePhone });
    if (!check.ok) {
      this._emitValidationError(check.errors);
      return { ok: false, errors: check.errors };
    }

    await this._lock.ensureFresh();
    if (!this._machine.transition(STATES.SUBMITTING)) {
      return { ok: false, code: 'bad_state', state: this._machine.state };
    }

    try {
      const result = await this._api.createBooking(this._buildBookingBody(fields, addToCart));
      if (result && result.success === false) {
        throw new ApiError(result.message || result.error || this._i18n.t('error.booking'), { code: 'booking' });
      }
      if (result && (result.redirectUrl || result.commerce)) {
        this._machine.transition(STATES.PAYING);
        this._emitter.emit('payment:redirect', { url: result.redirectUrl });
        return { ok: true, paying: true, redirectUrl: result.redirectUrl };
      }
      this._ctx.lock = null;
      this._lock.destroy();
      this._machine.transition(STATES.CONFIRMED);
      const reservation = result.reservation;
      this._emitter.emit('booking:confirmed', { reservation });
      return { ok: true, confirmed: true, reservation };
    } catch (err) {
      if (err && err.code === 'expired') {
        // The lock is gone server-side: tear down the client hold and emit the
        // same `lock:expired` a timer expiry would — the internal handler runs
        // the recovery (clears lock, → expired, back to re-pick).
        this._lock.destroy();
        this._emitter.emit('lock:expired', {});
        return { ok: false, expired: true };
      }
      this._machine.transition(STATES.ERROR);
      this._emitter.emit('error', { message: err.message, code: err.code || 'error', recoverable: true });
      return { ok: false, error: err.message };
    }
  }

  _buildBookingBody(fields, addToCart) {
    const extras = {};
    for (const [id, qty] of Object.entries(this._ctx.selectedExtras)) {
      if (qty > 0) extras[id] = qty;
    }
    const body = this._pruned({
      serviceId: this._ctx.serviceId,
      employeeId: this._ctx.employeeId,
      locationId: this._ctx.locationId,
      date: this._ctx.date,
      time: this._ctx.time,
      quantity: this._ctx.quantity,
      customerName: this._ctx.customer.name,
      customerEmail: this._ctx.customer.email,
      customerPhone: this._ctx.customer.phone,
      notes: this._ctx.customer.notes,
      softLockToken: this._lock.token,
      addToCart: addToCart ? '1' : '0',
      siteHandle: this._config.siteHandle || '',
    });
    if (Object.keys(extras).length > 0) body.extras = extras;
    if (this._ctx.isDayService && this._ctx.endDate) {
      body.endDate = this._ctx.endDate;
      delete body.time;
    }
    return { ...body, ...fields };
  }

  // ---- Waitlist =======================================================
  async joinWaitlist(payload = {}) {
    try {
      const method = this._flow.id === 'event' ? 'joinEventWaitlist' : 'joinWaitlist';
      const result = await this._api[method](this._pruned(payload));
      this._emitter.emit('waitlist:joined', { result });
      return { ok: true, result };
    } catch (err) {
      this._emitter.emit('error', { message: err.message, code: err.code || 'error', recoverable: true });
      return { ok: false, error: err.message };
    }
  }

  // ---- Lock passthrough ===============================================
  async releaseLock() {
    await this._lock.release('manual');
    this._ctx.lock = null;
    if (this._machine.state === STATES.HOLDING_LOCK) this._machine.transition(STATES.BROWSING);
  }

  // ---- Teardown / reset ===============================================
  reset() {
    this._lock.release('reset').catch(() => {});
    this._ctx = new Context({ quantity: this._config.defaultQuantity });
    this._flow.setContext(this._ctx);
    this._flow.reset();
    this._machine.hardReset();
    return this.getState();
  }

  destroy() {
    if (this._onUnload && typeof window !== 'undefined') {
      window.removeEventListener('beforeunload', this._onUnload);
      this._onUnload = null;
    }
    this._lock.release('destroy').catch(() => {});
    this._api.abortAll();
    this._emitter.clear();
  }

  // ---- helpers ========================================================
  _toError(err) {
    // AbortedError means a superseded request — ignore rather than surfacing.
    if (err && err.aborted) return;
    this._machine.transition(STATES.ERROR);
    const message = err instanceof ApiError ? err.message : this._i18n.t('error.generic');
    this._emitter.emit('error', {
      message,
      code: (err && err.code) || 'error',
      recoverable: true,
    });
  }

  /** Drop null/undefined keys so the encoder and backend see only real values. */
  _pruned(obj) {
    const out = {};
    for (const [k, v] of Object.entries(obj)) {
      if (v !== null && v !== undefined) out[k] = v;
    }
    return out;
  }
}

/** Public factory — the documented entry point. */
export function create(options) {
  return new Wizard(options);
}
