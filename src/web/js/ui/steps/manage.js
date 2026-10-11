/**
 * Manage step renderer (`?manage=` flow).
 *
 * Renders the loaded reservation into `data-booked-manage="…"` slots and toggles
 * the action controls by status. Cancel / reduce / increase are handled by the
 * shell's delegated `manage-*` actions; this renderer reflects the reservation
 * whenever it changes (`manage:loaded` / `manage:updated` / `manage:cancelled`).
 *
 * Reschedule gets its own calendar + slot picker here rather than a delegated
 * action, since picking a slot is itself a multi-step interaction (browse a
 * month, pick a day, pick a time) the shell's one-shot action bindings don't
 * model. Built lazily, once, the first time the loaded reservation reports
 * `canReschedule` — a booking that can't be rescheduled (past, cancelled, an
 * event, or inside the cancellation-policy window) never pays for the
 * calendar or its first availability request.
 */
import { qs, setText, setHidden, delegate } from '../dom.js';
import { Calendar } from '../calendar.js';

const cleanups = new WeakMap();
const state = new WeakMap();

function pad(n) {
  return String(n).padStart(2, '0');
}

function calendarLabels(wizard) {
  return {
    prevMonth: wizard.t('calendar.prevMonth'),
    nextMonth: wizard.t('calendar.nextMonth'),
  };
}

export const manageStep = {
  mount(region, wizard) {
    const s = { cal: null, calMap: {}, selectedDate: null, selected: null };
    state.set(region, s);

    const rerender = () => this.render(region, wizard);
    const offs = [
      wizard.on('manage:loaded', rerender),
      wizard.on('manage:updated', rerender),
      wizard.on('manage:cancelled', rerender),
      delegate(region, 'click', '[data-booked-reschedule-slot]', (e, el) => {
        this._selectSlot(region, s, el);
      }),
      delegate(region, 'click', '[data-booked-action="manage-reschedule-submit"]', async (e, el) => {
        if (!s.selected || el.disabled) return;
        el.disabled = true;
        const result = await wizard.manageReschedule(s.selected);
        // On success the reload re-renders and resets the picker (disabled
        // again, nothing selected). On failure nothing else re-enables it, so
        // the customer has to be able to try again without reloading the page.
        if (!result.ok) el.disabled = false;
      }),
    ];
    cleanups.set(region, () => {
      offs.forEach((off) => off());
      s.cal?.destroy();
    });
  },

  unmount(region) {
    const off = cleanups.get(region);
    if (off) {
      off();
      cleanups.delete(region);
    }
    state.delete(region);
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

    const reschedulePanel = qs('[data-booked-manage-reschedule]', region);
    if (reschedulePanel) {
      setHidden(reschedulePanel, !r.canReschedule);
      if (r.canReschedule) this._ensureCalendar(region, wizard, reschedulePanel);
    }

    // A changed date/time means a reschedule just succeeded (reduce/increase
    // also re-render here but never move the booking) — the just-picked slot
    // is gone, so clear the picker back to its unselected state rather than
    // leaving a stale, already-used slot looking selectable.
    const s = state.get(region);
    if (s && s.lastDateTime != null && s.lastDateTime !== r.formattedDateTime) {
      this._resetPicker(region, s);
    }
    if (s) s.lastDateTime = r.formattedDateTime;
  },

  /** Builds the reschedule calendar once; a re-render after a successful
   * reschedule reuses it rather than rebuilding, so the panel just shows the
   * new current month with the slot list cleared. */
  _ensureCalendar(region, wizard, panel) {
    const s = state.get(region);
    if (s.cal) return;

    const calContainer = qs('[data-booked-manage-reschedule-calendar]', panel);
    if (!calContainer) return;

    const isAvailable = (date) => s.calMap[date]?.isBookable === true;
    const now = new Date();
    const initialMonth =
      calContainer.getAttribute('data-booked-initial-month') || `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
    const [iy, im] = initialMonth.split('-').map(Number);

    const cal = new Calendar(calContainer, {
      month: initialMonth,
      mode: 'single',
      locale: wizard.getState()?.context?.locale,
      labels: calendarLabels(wizard),
      isAvailable,
      onMonthChange: async ({ year, month }) => {
        const map = await wizard.manageLoadCalendar({ year, month });
        if (map) {
          s.calMap = map;
          cal.setAvailability(isAvailable);
        }
      },
      onSelect: async (date) => {
        s.selectedDate = date;
        s.selected = null;
        this._setSubmitEnabled(region, false);
        const res = await wizard.manageLoadSlots({ date });
        this._renderSlots(region, res ? res.slots : []);
      },
    });
    s.cal = cal;

    wizard.manageLoadCalendar({ year: iy, month: im }).then((map) => {
      if (map) {
        s.calMap = map;
        cal.setAvailability(isAvailable);
      }
    });
  },

  /** Collapses the picker back to its unopened state: no day selected, no
   * slots shown, submit disabled. Used after a reschedule succeeds. */
  _resetPicker(region, s) {
    const list = qs('[data-booked-manage-reschedule-slots]', region);
    const empty = qs('[data-booked-manage-reschedule-empty]', region);
    list?.replaceChildren();
    setHidden(empty, true);
    s.selected = null;
    s.selectedDate = null;
    this._setSubmitEnabled(region, false);
    s.cal?.setSelected(null);
  },

  _renderSlots(region, slots) {
    const list = qs('[data-booked-manage-reschedule-slots]', region);
    const empty = qs('[data-booked-manage-reschedule-empty]', region);
    if (!list) return;
    list.replaceChildren();
    setHidden(empty, slots.length > 0);

    for (const slot of slots) {
      const cap = slot.availableCapacity;
      const unavailable = cap != null && cap < 1;

      const opt = document.createElement('button');
      opt.type = 'button';
      opt.setAttribute('role', 'option');
      opt.setAttribute('data-booked-reschedule-slot', '');
      opt.setAttribute('data-booked-reschedule-start', slot.time);
      opt.setAttribute('data-booked-reschedule-end', slot.endTime ?? '');
      opt.setAttribute('aria-selected', 'false');
      if (unavailable) opt.setAttribute('aria-disabled', 'true');
      opt.textContent = slot.time;
      list.appendChild(opt);
    }
  },

  _selectSlot(region, s, el) {
    if (el.getAttribute('aria-disabled') === 'true') return;
    const list = el.closest('[data-booked-manage-reschedule-slots]');
    for (const opt of list.querySelectorAll('[data-booked-reschedule-slot]')) {
      opt.setAttribute('aria-selected', opt === el ? 'true' : 'false');
    }
    s.selected = {
      date: s.selectedDate,
      startTime: el.getAttribute('data-booked-reschedule-start'),
      endTime: el.getAttribute('data-booked-reschedule-end'),
    };
    this._setSubmitEnabled(region, true);
  },

  _setSubmitEnabled(region, enabled) {
    const btn = qs('[data-booked-action="manage-reschedule-submit"]', region);
    if (btn) btn.disabled = !enabled;
  },
};
