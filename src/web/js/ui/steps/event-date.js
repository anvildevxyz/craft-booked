/**
 * Event-date step renderer (event flow).
 *
 * On first show, loads event dates and renders them as a card list. Each card
 * clones an `event-card` template and fills date/time/capacity/price fields.
 * Selection is handled by the shell's `select-event` action, which calls
 * `selectEventDate` (acquiring the event-seat lock). Fully-booked dates are
 * marked disabled.
 */
import { qs, qsa, cloneTemplate, setText, setHidden } from '../dom.js';

export const eventDateStep = {
  mount(region, wizard) {
    // Load the event dates once the step first mounts.
    wizard.loadEventDates().then(() => this.render(region, wizard));
  },

  render(region, wizard) {
    const list = qs('[data-booked-list="events"]', region);
    if (!list) return;
    const { context } = wizard.getState();
    const events = context.eventDates || [];
    const selectedId = context.eventDateId;

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
      setText(frag.querySelector('[data-booked-field="price"]'), event.price);
      list.appendChild(frag);
    }

    for (const card of qsa('[data-booked-action="select-event"]', region)) {
      card.setAttribute('aria-pressed', Number(card.getAttribute('data-booked-id')) === selectedId ? 'true' : 'false');
    }

    // Empty state, if the template provides one.
    setHidden(qs('[data-booked-events-empty]', region), events.length > 0);
  },
};
