/**
 * Wizard context — the selection state plus read-only computed getters.
 *
 * This is the single object the flow predicates and the renderer read. The
 * pricing/duration getters compute a client-side display total; the server
 * stays authoritative at booking time.
 *
 * The context holds no DOM and emits nothing; mutations go through small
 * setters so the wizard can react and the flow predicates see live values.
 */

export class Context {
  constructor(initial = {}) {
    // Selection
    this.serviceId = initial.serviceId ?? null;
    this.selectedService = initial.selectedService ?? null;
    this.locationId = initial.locationId ?? null;
    this.selectedLocation = initial.selectedLocation ?? null;
    this.employeeId = initial.employeeId ?? null;
    this.selectedEmployee = initial.selectedEmployee ?? null;
    // Set when the integrator preselected the service/location (deep link /
    // config), so that step is skipped even when several options exist.
    this.servicePreselected = initial.servicePreselected ?? false;
    this.locationPreselected = initial.locationPreselected ?? false;

    // Data lists (drive flow visibility predicates)
    this.services = initial.services ?? [];
    this.extras = initial.extras ?? [];
    this.locations = initial.locations ?? [];
    this.employees = initial.employees ?? [];
    this.eventDates = initial.eventDates ?? [];

    // Event flow selection
    this.eventDateId = initial.eventDateId ?? null;

    // Site locale (BCP-47) — drives the calendar's localized month/weekday names.
    this.locale = initial.locale ?? null;

    // Add-on selection: { [extraId]: quantity }
    this.selectedExtras = initial.selectedExtras ?? {};

    // Date/time
    this.date = initial.date ?? null;
    this.time = initial.time ?? null;
    this.endDate = initial.endDate ?? null;
    this.quantity = initial.quantity ?? 1;
    this.slotQuantity = initial.slotQuantity ?? 1;

    // Flags resolved from service/employee data loads
    this.serviceHasSchedule = initial.serviceHasSchedule ?? false;
    this.isDayService = initial.isDayService ?? false;
    this.isFlexibleDayService = initial.isFlexibleDayService ?? false;

    // Customer
    this.customer = { name: '', email: '', phone: '', notes: '', ...(initial.customer ?? {}) };

    // Commerce / payment context (from commerce-settings)
    this.commerce = {
      enabled: false,
      currency: null,
      currencySymbol: null,
      cartUrl: null,
      checkoutUrl: null,
      ...(initial.commerce ?? {}),
    };

    // Soft-lock: { token, expiresAt } | null
    this.lock = initial.lock ?? null;

    // Manage mode: the loaded reservation | null
    this.reservation = initial.reservation ?? null;
  }

  // ---- Computed: extras ================================================

  /** Σ extra.price × quantity over selected add-ons. */
  get extrasTotal() {
    let total = 0;
    for (const [extraId, quantity] of Object.entries(this.selectedExtras)) {
      const extra = this.extras.find((e) => e.id === parseInt(extraId, 10));
      if (extra && quantity > 0) total += extra.price * quantity;
    }
    return total;
  }

  /** Σ (extra.duration||0) × quantity — added to slot duration on availability calls. */
  get extrasDuration() {
    let total = 0;
    for (const [extraId, quantity] of Object.entries(this.selectedExtras)) {
      const extra = this.extras.find((e) => e.id === parseInt(extraId, 10));
      if (extra && quantity > 0) total += (extra.duration || 0) * quantity;
    }
    return total;
  }

  // ---- Computed: duration / price =====================================

  /** Inclusive day count for a multi-day range; 0 until both ends are set. */
  get durationDays() {
    if (!this.date || !this.endDate) return 0;
    const start = new Date(this.date);
    const end = new Date(this.endDate);
    return Math.round((end - start) / (1000 * 60 * 60 * 24)) + 1;
  }

  /** The chosen event date (event flow), resolved from the loaded list, or null. */
  get selectedEvent() {
    if (this.eventDateId == null) return null;
    return this.eventDates.find((e) => e.id === this.eventDateId) ?? null;
  }

  /** Per-service price, applying per-unit day pricing when applicable. */
  get servicePrice() {
    const basePrice = Number(this.selectedService?.price) || 0;
    if (this.isDayService && this.selectedService?.pricingMode === 'per_unit' && this.durationDays > 0) {
      return basePrice * this.durationDays;
    }
    return basePrice;
  }

  /**
   * Per-unit price of the current selection: the event date's price in the event
   * flow, otherwise the service price. The event's own price is never zero-rated
   * away like `selectedService` (null for events) would.
   */
  get unitPrice() {
    if (this.selectedEvent) return Number(this.selectedEvent.price) || 0;
    return this.servicePrice;
  }

  /** Display total: unitPrice × quantity + extras. Server remains authoritative. */
  get totalPrice() {
    return this.unitPrice * this.quantity + this.extrasTotal;
  }

  /** Whether the payment branch applies (Commerce enabled and a non-zero total). */
  get requiresPayment() {
    return this.commerce.enabled && this.totalPrice > 0;
  }

  // ---- Mutators (thin; the wizard drives these) =======================

  setService(service) {
    this.selectedService = service ?? null;
    this.serviceId = service?.id ?? null;
    // Selecting a service invalidates downstream selections.
    this.selectedExtras = {};
    this.employeeId = null;
    this.selectedEmployee = null;
    this.locationId = null;
    this.selectedLocation = null;
    this.date = null;
    this.time = null;
    this.endDate = null;
    this.isDayService = ['days', 'flexible_days'].includes(service?.durationType);
    this.isFlexibleDayService = service?.durationType === 'flexible_days';
  }

  setExtraQuantity(extraId, quantity) {
    const id = parseInt(extraId, 10);
    if (quantity > 0) this.selectedExtras[id] = quantity;
    else delete this.selectedExtras[id];
  }

  setCustomer(fields) {
    this.customer = { ...this.customer, ...fields };
  }

  /** Immutable-ish snapshot for `getState()`/events (no methods/getters). */
  snapshot() {
    return {
      serviceId: this.serviceId,
      selectedService: this.selectedService,
      locationId: this.locationId,
      selectedLocation: this.selectedLocation,
      employeeId: this.employeeId,
      selectedEmployee: this.selectedEmployee,
      servicePreselected: this.servicePreselected,
      locationPreselected: this.locationPreselected,
      // Data lists the renderer needs to populate step content.
      services: this.services,
      extras: this.extras,
      locations: this.locations,
      employees: this.employees,
      eventDates: this.eventDates,
      eventDateId: this.eventDateId,
      selectedEvent: this.selectedEvent,
      locale: this.locale,
      selectedExtras: { ...this.selectedExtras },
      date: this.date,
      time: this.time,
      endDate: this.endDate,
      quantity: this.quantity,
      slotQuantity: this.slotQuantity,
      isDayService: this.isDayService,
      isFlexibleDayService: this.isFlexibleDayService,
      customer: { ...this.customer },
      commerce: { ...this.commerce },
      lock: this.lock ? { ...this.lock } : null,
      reservation: this.reservation ? { ...this.reservation } : null,
      // computed, included for renderer convenience
      extrasTotal: this.extrasTotal,
      extrasDuration: this.extrasDuration,
      durationDays: this.durationDays,
      totalPrice: this.totalPrice,
      requiresPayment: this.requiresPayment,
    };
  }
}
