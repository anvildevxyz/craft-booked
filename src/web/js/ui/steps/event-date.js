/**
 * Event-date step renderer (event flow).
 *
 * On first show, loads event dates and renders them as a card list. Each card
 * clones an `event-card` template and fills date/time/capacity/price fields.
 * Selection is handled by the shell's `select-event` action, which calls
 * `selectEventDate` (acquiring the event-seat lock). Fully-booked dates are
 * marked disabled.
 *
 * When the chosen event has more than one seat left, a quantity stepper is
 * revealed (bounded by `remainingCapacity`) and re-selects the event at the new
 * count — parity with the legacy wizard's multi-seat event booking.
 */
import { qs, qsa, cloneTemplate, setText, setHidden } from '../dom.js';
import { formatPrice } from '../format.js';

const state = new WeakMap();

export const eventDateStep = {
  mount(region, wizard) {
    const s = { qtyValue: 1, qtyMax: 1 };
    state.set(region, s);

    // Quantity stepper for events with capacity > 1: re-selects the held event
    // date at the new count (the shell owns select-event; qty lives here).
    const adjust = async (delta) => {
      const selectedId = wizard.getState().context.eventDateId;
      if (selectedId == null) return;
      const next = Math.min(s.qtyMax, Math.max(1, s.qtyValue + delta));
      if (next === s.qtyValue) return;
      s.qtyValue = next;
      this._renderQuantity(region, s);
      await wizard.selectEventDate(selectedId, { quantity: next });
    };
    region.addEventListener('click', (event) => {
      const inc = event.target.closest('[data-booked-action="qty-increment"]');
      const dec = event.target.closest('[data-booked-action="qty-decrement"]');
      if (inc && region.contains(inc)) adjust(1);
      else if (dec && region.contains(dec)) adjust(-1);
    });

    // Load the event dates once the step first mounts.
    wizard.loadEventDates().then(() => this.render(region, wizard));
  },

  render(region, wizard) {
    const list = qs('[data-booked-list="events"]', region);
    if (!list) return;
    const { context } = wizard.getState();
    const events = context.eventDates || [];
    const selectedId = context.eventDateId;
    const currencySymbol = context.commerce?.currencySymbol;

    list.replaceChildren();
    for (const event of events) {
      const frag = cloneTemplate(region, 'event-card');
      if (!frag) break;
      const card = frag.querySelector('[data-booked-action="select-event"]') || frag.firstElementChild;
      if (card) {
        card.setAttribute('data-booked-id', String(event.id));
        card.setAttribute('aria-pressed', 'false');
        if (event.isFullyBooked) card.setAttribute('aria-disabled', 'true');
      }
      setText(frag.querySelector('[data-booked-field="title"]'), event.title);
      setText(frag.querySelector('[data-booked-field="date"]'), event.formattedDate ?? event.date);
      setText(frag.querySelector('[data-booked-field="time"]'), event.formattedTimeRange ?? event.startTime);
      setText(frag.querySelector('[data-booked-field="capacity"]'), event.remainingCapacity);
      setText(frag.querySelector('[data-booked-field="price"]'), formatPrice(event.price, currencySymbol));
      list.appendChild(frag);
    }

    for (const card of qsa('[data-booked-action="select-event"]', region)) {
      card.setAttribute('aria-pressed', Number(card.getAttribute('data-booked-id')) === selectedId ? 'true' : 'false');
    }

    // Empty state, if the template provides one.
    setHidden(qs('[data-booked-events-empty]', region), events.length > 0);

    // Quantity picker: shown only when a selected event still has >1 seat.
    const s = state.get(region);
    if (s) {
      const selected = context.selectedEvent;
      s.qtyMax = selected && selected.remainingCapacity > 1 ? selected.remainingCapacity : 1;
      s.qtyValue = Math.min(s.qtyValue, s.qtyMax);
      if (selectedId == null) s.qtyValue = 1;
      this._renderQuantity(region, s);
    }
  },

  /** Reflect the event quantity picker (shown only when capacity > 1). */
  _renderQuantity(region, s) {
    const box = qs('[data-booked-event-quantity]', region);
    if (!box) return;
    const active = s.qtyMax > 1;
    setHidden(box, !active);
    if (!active) return;
    setText(qs('[data-booked-event-qty-value]', region), s.qtyValue);
    const dec = qs('[data-booked-event-quantity] [data-booked-action="qty-decrement"]', region);
    const inc = qs('[data-booked-event-quantity] [data-booked-action="qty-increment"]', region);
    if (dec) dec.disabled = s.qtyValue <= 1;
    if (inc) inc.disabled = s.qtyValue >= s.qtyMax;
  },
};
