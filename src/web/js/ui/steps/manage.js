/**
 * Manage step renderer (`?manage=` flow).
 *
 * Renders the loaded reservation into `data-booked-manage="…"` slots and toggles
 * the action controls by status. Cancel / reduce / increase are handled by the
 * shell's delegated `manage-*` actions; this renderer reflects the reservation
 * whenever it changes (`manage:loaded` / `manage:updated` / `manage:cancelled`).
 */
import { qs, setText, setHidden } from '../dom.js';

const cleanups = new WeakMap();

export const manageStep = {
  mount(region, wizard) {
    const rerender = () => this.render(region, wizard);
    const offs = [
      wizard.on('manage:loaded', rerender),
      wizard.on('manage:updated', rerender),
      wizard.on('manage:cancelled', rerender),
    ];
    cleanups.set(region, () => offs.forEach((off) => off()));
  },

  unmount(region) {
    const off = cleanups.get(region);
    if (off) {
      off();
      cleanups.delete(region);
    }
  },

  render(region, wizard) {
    const r = wizard.getState().context.reservation;
    if (!r) return;

    // serviceName is null for event bookings (the manage endpoint has no event title field).
    setText(qs('[data-booked-manage="service"]', region), r.serviceName);
    setText(qs('[data-booked-manage="datetime"]', region), r.formattedDateTime);
    setText(qs('[data-booked-manage="status"]', region), r.statusLabel);
    setText(qs('[data-booked-manage="quantity"]', region), r.quantity);
    setText(qs('[data-booked-manage="customer"]', region), r.customerName);

    const cancelled = r.status === 'cancelled';
    // Actions available only for a live, cancellable booking.
    setHidden(qs('[data-booked-manage-actions]', region), cancelled || !r.canCancel);
    setHidden(qs('[data-booked-manage-cancelled]', region), !cancelled);

    // Quantity controls make sense only above 1 (reduce) / for capacity (increase).
    const dec = qs('[data-booked-action="manage-reduce"]', region);
    if (dec) dec.disabled = !(r.quantity > 1);
  },
};
