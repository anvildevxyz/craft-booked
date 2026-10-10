/**
 * Success step renderer — the confirmation screen shown after a booking confirms.
 *
 * Fills `data-booked-summary="…"` slots from the reservation the core stored on
 * the context at confirm time (id, status, appointment) plus the customer email
 * for the "you'll receive a confirmation" note. Every slot is optional, so a
 * minimal success template that only shows a heading still works.
 */
import { qs, setText, setHidden } from '../dom.js';

/** Set a summary row's value, hiding both the <dd> and its paired <dt> when empty. */
function setRow(region, key, value) {
  const dd = qs(`[data-booked-summary="${key}"]`, region);
  if (!dd) return;
  const empty = value === null || value === undefined || value === '';
  setText(dd, empty ? '' : value);
  setHidden(dd, empty);
  const dt = dd.previousElementSibling;
  if (dt && dt.tagName === 'DT') setHidden(dt, empty);
}

export const successStep = {
  render(region, wizard) {
    const { context } = wizard.getState();
    const r = context.reservation || {};

    setText(qs('[data-booked-summary="status"]', region), r.statusLabel ?? r.status ?? '');
    setText(qs('[data-booked-summary="booking-id"]', region), r.id ?? r.reference ?? '');

    // Prefer the server-formatted date/time; fall back to the picked values.
    const evt = context.selectedEvent;
    const appointment =
      r.formattedDateTime ??
      (context.isDayService && context.endDate
        ? `${context.date} – ${context.endDate}`
        : [context.date ?? evt?.formattedDate ?? evt?.date, context.time ?? evt?.formattedTimeRange ?? evt?.startTime]
            .filter(Boolean)
            .join(' '));
    setText(qs('[data-booked-summary="appointment"]', region), appointment);

    // Same rule as the review step: always for an event (confirm how many
    // tickets were just booked), only above 1 for a regular appointment.
    setRow(region, 'quantity', evt || context.quantity > 1 ? context.quantity : '');

    setText(qs('[data-booked-summary="customer-email"]', region), context.customer?.email ?? '');
  },
};
