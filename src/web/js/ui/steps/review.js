/**
 * Review step renderer — a read-only summary of the pending booking.
 *
 * Fills `data-booked-summary="…"` slots from the core's computed context so the
 * customer confirms what they're booking. Purely presentational; the shell owns
 * the submit action. Price is shown as the core's display total (the server
 * remains authoritative at submit). Slots absent from a given template are
 * simply skipped, so the booking and event templates share this one renderer.
 */
import { qs, setText, setHidden } from '../dom.js';
import { formatPrice } from '../format.js';

export const reviewStep = {
  render(region, wizard) {
    const { context } = wizard.getState();
    const svc = context.selectedService || {};
    const evt = context.selectedEvent || null;
    const currencySymbol = context.commerce?.currencySymbol;

    // Service (appointment flow) or event title (event flow).
    setText(qs('[data-booked-summary="service"]', region), evt ? evt.title ?? '' : svc.title ?? '');
    setText(qs('[data-booked-summary="employee"]', region), context.selectedEmployee?.name ?? '');
    setText(qs('[data-booked-summary="location"]', region), context.selectedLocation?.name ?? '');

    // Date/time — single date+time, or a date range with day count for day services.
    setText(qs('[data-booked-summary="date"]', region), context.date ?? (evt ? evt.formattedDate ?? evt.date ?? '' : ''));
    setText(qs('[data-booked-summary="time"]', region), context.time ?? (evt ? evt.formattedTimeRange ?? evt.startTime ?? '' : ''));
    if (context.isDayService && context.endDate) {
      setText(qs('[data-booked-summary="date-range"]', region), `${context.date} – ${context.endDate}`);
      setText(qs('[data-booked-summary="duration"]', region), context.durationDays);
    }

    setText(qs('[data-booked-summary="quantity"]', region), context.quantity);
    setText(qs('[data-booked-summary="customer-name"]', region), context.customer?.name ?? '');
    setText(qs('[data-booked-summary="customer-email"]', region), context.customer?.email ?? '');
    setText(qs('[data-booked-summary="extras-total"]', region), formatPrice(context.extrasTotal, currencySymbol));
    setText(qs('[data-booked-summary="total"]', region), formatPrice(context.totalPrice, currencySymbol));

    // Show the payment notice + swap the submit label when payment applies.
    const paymentNotice = qs('[data-booked-payment-notice]', region);
    if (paymentNotice) setHidden(paymentNotice, !context.requiresPayment);
  },
};
