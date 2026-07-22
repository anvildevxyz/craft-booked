/**
 * Extras step renderer — add-on cards with quantity steppers.
 *
 * Clones an `extra-card` template per add-on, fills its fields, stamps the
 * extra id onto the increment/decrement controls (handled by the shell's
 * `extra-increment` / `extra-decrement` actions), and reflects the currently
 * selected quantity. The running extras total is shown in
 * `data-booked-extras-total` when present.
 */
import { qs, cloneTemplate, setText } from '../dom.js';

export const extrasStep = {
  render(region, wizard) {
    const container = qs('[data-booked-list="extras"]', region);
    if (!container) return;
    const { context } = wizard.getState();
    const extras = context.extras || [];
    const selected = context.selectedExtras || {};

    container.replaceChildren();
    for (const extra of extras) {
      const frag = cloneTemplate(region, 'extra-card');
      if (!frag) break;
      const qty = selected[extra.id] || 0;
      const min = extra.isRequired ? 1 : 0;
      const max = extra.maxQuantity ? extra.maxQuantity : Infinity;

      setText(frag.querySelector('[data-booked-field="name"]'), extra.name ?? extra.title);
      setText(frag.querySelector('[data-booked-field="price"]'), extra.price);
      setText(frag.querySelector('[data-booked-extra-qty]'), qty);

      const card = frag.firstElementChild;
      if (card && extra.isRequired) card.setAttribute('data-booked-required', 'true');

      // Stamp the extra id onto the card and its stepper controls.
      for (const el of frag.querySelectorAll('[data-booked-extra-id], [data-booked-action^="extra-"]')) {
        el.setAttribute('data-booked-extra-id', String(extra.id));
      }
      const qtyEl = frag.querySelector('[data-booked-extra-qty]');
      if (qtyEl) qtyEl.setAttribute('data-booked-extra-id', String(extra.id));

      // Disable steppers at the bounds so the UI matches the enforced limits.
      const dec = frag.querySelector('[data-booked-action="extra-decrement"]');
      const inc = frag.querySelector('[data-booked-action="extra-increment"]');
      if (dec) dec.disabled = qty <= min;
      if (inc) inc.disabled = qty >= max;

      container.appendChild(frag);
    }

    setText(qs('[data-booked-extras-total]', region), context.extrasTotal);
  },
};
