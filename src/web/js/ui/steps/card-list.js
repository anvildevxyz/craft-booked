/**
 * Shared card-list helper for the choose-one steps (location, employee, and the
 * service list). Clones a named template per item, fills its `data-booked-field`
 * slots, stamps `data-booked-id`, and reflects the selected item via
 * `aria-pressed`. Keeps the individual step renderers tiny.
 */
import { qs, qsa, cloneTemplate, setText } from '../dom.js';

/**
 * @param {Element} region
 * @param {Object} opts
 * @param {string} opts.template  data-booked-template name to clone per item
 * @param {string} opts.list      data-booked-list container name
 * @param {string} opts.action    data-booked-action value on each card
 * @param {Array} opts.items
 * @param {number|null} opts.selectedId
 * @param {string[]} [opts.fields] field names to fill from each item
 */
export function renderCardList(region, { template, list, action, items, selectedId, fields = ['name', 'price'] }) {
  const container = qs(`[data-booked-list="${list}"]`, region);
  if (!container) return;

  container.replaceChildren();
  for (const item of items || []) {
    const frag = cloneTemplate(region, template);
    if (!frag) break;
    const card = frag.querySelector(`[data-booked-action="${action}"]`) || frag.firstElementChild;
    if (card) {
      card.setAttribute('data-booked-id', String(item.id));
      card.setAttribute('aria-pressed', 'false');
    }
    for (const field of fields) {
      setText(frag.querySelector(`[data-booked-field="${field}"]`), item[field]);
    }
    container.appendChild(frag);
  }

  for (const card of qsa(`[data-booked-action="${action}"]`, region)) {
    card.setAttribute('aria-pressed', Number(card.getAttribute('data-booked-id')) === selectedId ? 'true' : 'false');
  }
}
