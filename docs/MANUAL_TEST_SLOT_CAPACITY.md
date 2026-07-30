# Manual Test — Schedule time-slot capacity in the wizard (issue #85)

Reproduces and verifies the reported bug end to end, from an empty control panel
to a customer-facing booking. Follow it top to bottom: **§1–§3 build the fixture,
§4 is the actual bug**, and §5 onward covers the neighbouring behaviour that must
not have shifted.

Symptom being tested: *"Even a single booking for a time slot will stop the time
slot from showing up anymore, even if the capacity for the time slot is more than 1."*

**Prerequisites**

```bash
ddev start
ddev launch admin        # CP login, e.g. cc_admin / letmein
```

Have two browser windows ready — the **control panel** and the **front-end
wizard** (`/wizard/service`). You will switch between them.

---

## 1. Create the schedule that grants more than one seat

1. Go to **Booked → Schedules → New schedule**.
2. Name it `Group room — 3 seats`.
3. Leave **Start date** and **End date** empty (a "forever" schedule).
4. In the weekly table, for **Monday through Friday**:
   - **Enabled**: on
   - **Start** `09:00`, **End** `17:00`
   - **Break start** `12:00`, **Break end** `13:00`
   - **Capacity**: `3`  ← the field this test is about
5. Turn Saturday and Sunday **off**.
6. **Save**.

> The Capacity column is per day, and applies to every slot generated on that
> day: `3` means three separate bookings may share the same time slot.

- [ ] The schedule saves and re-opens showing `3` in the Capacity column for Mon–Fri.

## 2. Create an employee-less service

Capacity on a schedule only governs services that have **no employees** attached
— with employees, each employee is their own single seat (see §7).

1. Go to **Booked → Services → New service**.
2. Name it `Group session`.
3. **Duration**: `60` minutes.
4. **Buffer before / after**: `0` (buffers get their own test in §6).
5. Do **not** assign any employees.
6. In the service's **Availability / Schedules** section, assign the schedule
   `Group room — 3 seats`.
7. **Save**, and note the service's ID from the URL (e.g. `…/services/1236`).

- [ ] The service saves with the schedule attached and no employees.

## 3. Confirm a clean starting point

1. Open the wizard: `/wizard/service?serviceId=<your service id>`.
2. Step through to the date picker and choose a **weekday at least a week out**
   that is not a blackout date.
3. Look at the time slots.

- [ ] Slots appear at `09:00, 10:00, 11:00, 13:00, 14:00, 15:00, 16:00`
      (12:00 is missing — that's the lunch break).
- [ ] Each slot is labelled **"3 available"**.
- [ ] The chosen day is **not** greyed out in the calendar.

> If the day is greyed out: the availability calendar only covers **90 days from
> today** and caches each day's bookability for **5 minutes**. Pick a nearer date,
> and after any change made outside the wizard give it a moment or clear caches.

---

## 4. The bug — one booking must not remove the slot

1. In the wizard, select **09:00** and complete a booking (any name/email).
2. Reload the wizard and navigate back to the **same date**.

- [ ] **09:00 is still offered.**
      *Before the fix it disappeared entirely — that is the reported bug.*
- [ ] 09:00 is labelled **"2 available"** instead of "3 available".
- [ ] Every other slot still reads "3 available".

Repeat the booking a second time:

- [ ] 09:00 is still offered, and the label is now **gone** — one seat left is
      the ordinary one-on-one case and carries no count.

Book a third time:

- [ ] **09:00 is now gone** — all three seats are taken.
- [ ] `10:00` and the rest are untouched and still show 3.

### 4b. Check it in the control panel

1. Go to **Booked → Bookings** and filter to that date.

- [ ] Three separate confirmed bookings exist, all at `09:00`, for the same service.

### 4c. Cancelling frees a seat

1. Cancel one of the three bookings.
2. Reload the wizard on that date.

- [ ] `09:00` is offered again (with one seat free, so no count is shown).

---

## 5. Group size on a single booking

1. Cancel all bookings on the test date so the slot is empty again.
2. In the wizard, select `09:00`. Because more than one seat is free, a
   **quantity stepper** appears under the slot list.
3. Increase it to **2** and complete the booking.

- [ ] The stepper will not go above 3 (the schedule capacity).
- [ ] After booking, `09:00` still appears with **1 remaining**.
- [ ] Selecting that last seat shows **no** stepper — one seat cannot be split.

## 6. Buffers

1. In the CP, set the service's **Buffer before** and **Buffer after** to `15`.
2. Clear the bookings on the test date, then book **one** seat at `09:00`.

- [ ] `10:00` is **still offered** — a partly filled group slot must not block
      its neighbours with a buffer.

3. Book the remaining two seats at `09:00`.

- [ ] `09:00` is gone.
- [ ] `10:00` is **also gone** — with the slot full, the 15-minute buffer after
      the 09:00–10:00 session now genuinely blocks a 10:00 start.

4. Set both buffers back to `0` when done.

## 7. Regressions that must still hold

### 7a. Capacity 1 behaves as before

1. Edit `Group room — 3 seats` and set every weekday's **Capacity** to `1`.
2. Clear the bookings on the test date and book `09:00` once.

- [ ] `09:00` disappears immediately after that single booking.
- [ ] Other slots are unaffected.

### 7b. Empty capacity means one seat, not unlimited

1. Clear the Capacity field entirely for the weekdays (leave it blank).
2. Clear the bookings and book `09:00` once.

- [ ] `09:00` disappears after one booking — a blank capacity must **not** be
      read as unlimited.

### 7c. Employee-based services are unchanged

1. Open any service that **does** have employees assigned.
2. Book one slot with a specific employee.

- [ ] That slot disappears for that employee after a single booking, regardless
      of any capacity set on the employee's schedule. Each employee is one seat.
- [ ] With **"Any available"** selected, the slot remains while another employee
      is still free.

### 7d. Overbooking is refused server-side

1. Set capacity back to `3`, fill `09:00` with three bookings.
2. In a second browser tab that still has the wizard open from *before* the slot
   filled up, try to submit a booking for `09:00`.

- [ ] The booking is **rejected** with a conflict message rather than being
      accepted as a fourth seat.

---

## Automated equivalents

Everything above is covered by automated tests, if you would rather run them than
click through:

```bash
# Pure logic (no database)
ddev exec -d /var/www/html/plugins/craft-booked ./vendor/bin/phpunit --filter AvailabilityCapacityTest

# Real services against the real database, self-seeding and self-cleaning
ddev exec php plugins/craft-booked/tests/integration-live/schedule-capacity.php

# The real wizard in a real browser
cd plugins/craft-booked
BOOKED_E2E_URL="https://craft-plugin-dev.ddev.site/wizard/service" \
BOOKED_E2E_PROJECT_ROOT="$(cd ../.. && pwd)" \
./node_modules/.bin/playwright test schedule-capacity -c tests/e2e/playwright.config.ts
```
