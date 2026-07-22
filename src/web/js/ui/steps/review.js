/**
 * Review step renderer — a read-only summary of the pending booking.
 *
 * Fills `data-booked-summary="…"` slots from the core's computed context so the
 * customer confirms what they're booking. Purely presentational; the shell owns
 * the submit action. Price is shown as the core's display total (the server
 * remains authoritative at submit).
 */
import { qs, setText, setHidden } from '../dom.js';

export const reviewStep = {
  render(region, wizard) {
    const { context } = wizard.getState();
    const svc = context.selectedService || {};

    setText(qs('[data-booked-summary="service"]', region), svc.name ?? svc.title ?? '');
    setText(qs('[data-booked-summary="date"]', region), context.date ?? '');
    setText(qs('[data-booked-summary="time"]', region), context.time ?? '');
    setText(qs('[data-booked-summary="customer-name"]', region), context.customer?.name ?? '');
    setText(qs('[data-booked-summary="customer-email"]', region), context.customer?.email ?? '');
    setText(qs('[data-booked-summary="total"]', region), context.totalPrice);

    // Show the payment notice + swap the submit label when payment applies.
    const paymentNotice = qs('[data-booked-payment-notice]', region);
    if (paymentNotice) setHidden(paymentNotice, !context.requiresPayment);
  },
};
