/**
 * Review step renderer — a read-only summary of the pending booking.
 *
 * Fills `data-booked-summary="…"` slots from the core's computed context so the
 * customer confirms what they're booking. Rows whose value is empty are hidden
 * entirely (label included), matching the legacy Alpine wizard — so an
 * appointment with no chosen employee/location, no extras and quantity 1 shows
 * only the rows that actually apply. Purely presentational; the shell owns the
 * submit action. The same renderer serves the booking and event templates.
 */
import { qs, setText, setHidden } from '../dom.js';
import { formatPrice } from '../format.js';

/**
 * Set a summary row's value, hiding both the <dd> and its paired <dt> when the
 * value is empty so no orphaned label (e.g. "Choose an employee") is shown.
 */
function setRow(region, key, value) {
  const dd = qs(`[data-booked-summary="${key}"]`, region);
  if (!dd) return;
  const empty = value === null || value === undefined || value === '';
  setText(dd, empty ? '' : value);
  setHidden(dd, empty);
  const dt = dd.previousElementSibling;
  if (dt && dt.tagName === 'DT') setHidden(dt, empty);
}

export const reviewStep = {
  render(region, wizard) {
    const { context } = wizard.getState();
    const svc = context.selectedService || {};
    const evt = context.selectedEvent || null;
    const currencySymbol = context.commerce?.currencySymbol;

    // Service (appointment) or event title (event flow, or an event picked
    // from the booking flow's merged calendar).
    setRow(region, 'service', evt ? evt.title ?? '' : svc.title ?? '');
    // Events are never employee-dependent (EVENT_BOOKINGS.md), so any employee
    // browsed before switching to an event is irrelevant and must not show.
    setRow(region, 'employee', evt ? '' : context.selectedEmployee?.name ?? '');
    // An event's own location (if any) — never the appointment location the
    // customer may have browsed before picking the event instead.
    const evtLocationName = evt?.locationId != null
      ? (context.locations || []).find((l) => l.id === evt.locationId)?.name ?? ''
      : '';
    setRow(region, 'location', evt ? evtLocationName : context.selectedLocation?.name ?? '');

    // Single date+time, or a date range with day count for day services.
    const isRange = context.isDayService && context.endDate;
    setRow(region, 'date', isRange ? '' : context.date ?? (evt ? evt.formattedDate ?? evt.date ?? '' : ''));
    setRow(region, 'time', isRange ? '' : context.time ?? (evt ? evt.formattedTimeRange ?? evt.startTime ?? '' : ''));
    setRow(region, 'date-range', isRange ? `${context.date} – ${context.endDate}` : '');
    setRow(region, 'duration', isRange ? context.durationDays : '');

    // Quantity only when more than one seat; extras only when some were added.
    setRow(region, 'quantity', context.quantity > 1 ? context.quantity : '');
    setRow(region, 'extras-total', context.extrasTotal > 0 ? formatPrice(context.extrasTotal, currencySymbol) : '');

    setRow(region, 'customer-name', context.customer?.name ?? '');
    setRow(region, 'customer-email', context.customer?.email ?? '');

    // The total shows only when there is a price to confirm; a free service
    // (no price set) hides the row entirely rather than showing "0.00".
    setRow(region, 'total', context.totalPrice > 0 ? formatPrice(context.totalPrice, currencySymbol) : '');

    // Show the payment notice when payment applies.
    const paymentNotice = qs('[data-booked-payment-notice]', region);
    if (paymentNotice) setHidden(paymentNotice, !context.requiresPayment);
  },
};
