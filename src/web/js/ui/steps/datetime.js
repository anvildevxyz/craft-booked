/**
 * Date & time step renderer — the accessible calendar + slot listbox.
 *
 * Configures the framework-free Calendar for the selected service:
 *  - standard services: single-date mode; a day → `loadSlots` → slot listbox →
 *    `selectSlot` (acquires the soft lock). No slots + waitlist open → waitlist.
 *  - fixed-day services: range mode; one pick computes the end from the service
 *    duration and `selectRange`s immediately.
 *  - flexible-day services: range mode; the start pick loads valid end dates
 *    (`loadEndDates`) and constrains the calendar, the end pick `selectRange`s.
 *
 * Step renderers are shared singletons, so per-mount state lives in a WeakMap
 * keyed by the region. The calendar is (re)built whenever the service type
 * changes, so switching services mid-flow reconfigures it correctly.
 */
import { Calendar } from '../calendar.js';
import { qs, delegate, setHidden } from '../dom.js';

const state = new WeakMap();

function pad(n) {
  return String(n).padStart(2, '0');
}

/** Fixed-day end date = start + (durationDays - 1), in UTC to avoid tz drift. */
function computeFixedEnd(startDate, durationDays) {
  const [y, m, d] = startDate.split('-').map(Number);
  const end = new Date(Date.UTC(y, m - 1, d));
  end.setUTCDate(end.getUTCDate() + Math.max(1, durationDays) - 1);
  return end.toISOString().slice(0, 10);
}

export const datetimeStep = {
  mount(region, wizard) {
    const slotList = qs('[data-booked-slots]', region);
    const s = { calMap: {}, availSet: new Set(), validEndSet: new Set(), pickingEnd: false, selectedDate: null, cal: null, calSig: null };
    state.set(region, s);

    this._buildCalendar(region, wizard, s);

    // Slot selection (delegated once; survives slot re-renders).
    if (slotList) {
      slotList.setAttribute('role', 'listbox');
      delegate(slotList, 'click', '[data-booked-time]', async (event, el) => {
        if (el.getAttribute('aria-disabled') === 'true') return;
        const time = el.getAttribute('data-booked-time');
        const res = await wizard.selectSlot({ date: s.selectedDate, time });
        if (res && res.acquired) {
          for (const opt of slotList.querySelectorAll('[role="option"]')) {
            opt.setAttribute('aria-selected', opt.getAttribute('data-booked-time') === time ? 'true' : 'false');
          }
        }
      });
    }

    // Waitlist branch: join when a chosen day has no slots but waitlist is open.
    const waitlist = qs('[data-booked-waitlist]', region);
    if (waitlist) {
      delegate(region, 'click', '[data-booked-action="join-waitlist"]', async (event) => {
        event.preventDefault();
        const val = (f) => {
          const el = qs(`[data-booked-waitlist] [data-booked-field="${f}"]`, region);
          return el ? el.value : '';
        };
        const { context } = wizard.getState();
        const res = await wizard.joinWaitlist({
          serviceId: context.serviceId,
          employeeId: context.employeeId,
          locationId: context.locationId,
          preferredDate: s.selectedDate,
          userName: val('name'),
          userEmail: val('email'),
          userPhone: val('phone'),
        });
        if (res && res.ok) {
          setHidden(qs('[data-booked-waitlist-form]', region), true);
          setHidden(qs('[data-booked-waitlist-success]', region), false);
        }
      });
    }
  },

  /** (Re)build the calendar for the current service type when it changes. */
  _buildCalendar(region, wizard, s) {
    const calContainer = qs('[data-booked-calendar]', region);
    if (!calContainer) return;

    const { context } = wizard.getState();
    const isDay = !!context.isDayService;
    const isFlexible = !!context.isFlexibleDayService;
    const sig = `${context.serviceId}:${isDay ? (isFlexible ? 'flex' : 'fixed') : 'single'}`;
    if (s.calSig === sig && s.cal) return;
    s.calSig = sig;

    const now = new Date();
    const initialMonth =
      calContainer.getAttribute('data-booked-initial-month') || `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
    const [iy, im] = initialMonth.split('-').map(Number);

    if (isDay) {
      this._buildDayCalendar(region, wizard, s, calContainer, initialMonth, iy, im, isFlexible);
    } else {
      this._buildSingleCalendar(region, wizard, s, calContainer, initialMonth, iy, im);
    }
  },

  _buildSingleCalendar(region, wizard, s, calContainer, initialMonth, iy, im) {
    const cal = new Calendar(calContainer, {
      month: initialMonth,
      mode: 'single',
      locale: wizard.getState()?.context?.locale,
      isAvailable: (date) => s.calMap[date] && s.calMap[date].isBookable === true,
      onMonthChange: async ({ year, month }) => {
        const map = await wizard.loadCalendar({ year, month });
        if (map) {
          s.calMap = map;
          cal.setAvailability((d) => s.calMap[d] && s.calMap[d].isBookable === true);
        }
      },
      onSelect: async (date) => {
        s.selectedDate = date;
        const res = await wizard.loadSlots({ date });
        if (res) {
          s.waitlistAvailable = res.waitlistAvailable;
          this._renderSlots(region, res.slots, s, wizard);
        }
      },
    });
    s.cal = cal;
    wizard.loadCalendar({ year: iy, month: im }).then((map) => {
      if (map) {
        s.calMap = map;
        cal.setAvailability((d) => s.calMap[d] && s.calMap[d].isBookable === true);
      }
    });
  },

  _buildDayCalendar(region, wizard, s, calContainer, initialMonth, iy, im, isFlexible) {
    const startAvailability = (d) => s.availSet.has(d);
    const applyStartAvailability = () => {
      s.pickingEnd = false;
      s.cal.setAvailability(startAvailability);
    };

    const cal = new Calendar(calContainer, {
      month: initialMonth,
      mode: 'range',
      locale: wizard.getState()?.context?.locale,
      isAvailable: (date) => (s.pickingEnd ? s.validEndSet.has(date) : s.availSet.has(date)),
      onMonthChange: async ({ year, month }) => {
        const dates = await wizard.loadDates({ month: `${year}-${pad(month)}` });
        if (dates) {
          s.availSet = new Set(dates);
          if (!s.pickingEnd) cal.setAvailability(startAvailability);
        }
      },
      onRangeStart: async (start) => {
        s.selectedDate = start;
        if (!isFlexible) {
          // Fixed-day: compute the end from the service duration and book it.
          const duration = wizard.getState().context.selectedService?.duration || 1;
          const end = computeFixedEnd(start, duration);
          cal.setRange(start, end);
          await wizard.selectRange({ startDate: start, endDate: end });
          return;
        }
        // Flexible-day: constrain the calendar to valid end dates.
        s.pickingEnd = true;
        const ends = await wizard.loadEndDates({ startDate: start });
        s.validEndSet = new Set(ends || []);
        cal.setAvailability((d) => s.validEndSet.has(d));
      },
      onRangeComplete: async ({ start, end }) => {
        await wizard.selectRange({ startDate: start, endDate: end });
        applyStartAvailability();
      },
    });
    s.cal = cal;

    // Day services have no time slots; keep the slot list clear.
    const list = qs('[data-booked-slots]', region);
    if (list) list.replaceChildren();

    wizard.loadDates({ month: `${iy}-${pad(im)}` }).then((dates) => {
      if (dates) {
        s.availSet = new Set(dates);
        cal.setAvailability(startAvailability);
      }
    });
  },

  render(region, wizard) {
    const s = state.get(region);
    if (!s) return;
    // Reconfigure the calendar if the service type changed since it was built.
    this._buildCalendar(region, wizard, s);
    const { context } = wizard.getState();
    if (!context.isDayService && context.date && s.cal && s.selectedDate !== context.date) {
      s.cal.setSelected(context.date);
      s.selectedDate = context.date;
    }
  },

  _renderSlots(region, slots, s, wizard) {
    const list = qs('[data-booked-slots]', region);
    if (!list) return;
    list.replaceChildren();
    const selectedTime = wizard.getState().context.time;
    for (const slot of slots) {
      const opt = document.createElement('button');
      opt.type = 'button';
      opt.setAttribute('role', 'option');
      opt.setAttribute('data-booked-time', slot.time);
      const cap = slot.availableCapacity;
      const unavailable = cap != null && cap < 1;
      opt.setAttribute('aria-selected', slot.time === selectedTime ? 'true' : 'false');
      if (unavailable) opt.setAttribute('aria-disabled', 'true');
      opt.textContent = slot.time;
      list.appendChild(opt);
    }

    // Reveal the waitlist branch only when there are no slots and it's offered.
    const waitlist = qs('[data-booked-waitlist]', region);
    if (waitlist) {
      const offer = slots.length === 0 && !!s.waitlistAvailable;
      setHidden(waitlist, !offer);
      if (offer) {
        setHidden(qs('[data-booked-waitlist-form]', region), false);
        setHidden(qs('[data-booked-waitlist-success]', region), true);
      }
    }
  },
};
