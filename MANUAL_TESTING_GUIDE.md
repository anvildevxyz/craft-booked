# Multi-Day Bookings — Manual Testing Guide

## Prerequisites

1. Run `ddev start` and `ddev import-db --file=db.sql.gz`
2. Run the migration: `ddev exec php craft migrate/all --plugin=booked`
3. Login to CP: `ddev launch access` (cc_admin / letmein)
4. Build frontend: `npm run build` from the project root

---

## Test 1: Create a Day-Based Service

1. Go to **Booked → Services → New Service**
2. Set **Duration Type** = "Days"
3. Set **Duration** = 3
4. Set **Pricing Mode** = "Per day/minute"
5. Set **Price** = 200
6. Set a title (e.g., "3-Day Motorcycle Tour")
7. Save

**Expected:**
- Duration field label shows "Duration (days)"
- Time Slot Length field is hidden
- Service index shows "3 days" in the Duration column
- Service saves without errors

---

## Test 2: Create a Standard (Minute-Based) Service — Regression

1. Create a new service with Duration Type = "Minutes", Duration = 60
2. Save and verify it works exactly as before

**Expected:**
- Duration shows "60 min" in the index
- Time Slot Length is visible
- Booking flow works with time slots (no changes)

---

## Test 3: Frontend Booking — Day-Based Service

1. Navigate to the booking page on the frontend
2. Select the day-based service ("3-Day Motorcycle Tour")
3. Complete extras/location/employee steps

**Expected at Date step:**
- Calendar shows available start dates (highlighted)
- No time slot selection appears
- Clicking a date shows "June 10 – June 12 (3 days)" preview
- Unavailable dates (blackouts, existing bookings) are greyed out

4. Select a start date and proceed to Customer Info
5. Fill in name, email, and submit

**Expected at Review step:**
- Shows date range: "June 10 – June 12, 2026 (3 days)"
- Shows total price: $600 (3 days × $200)
- No time is displayed

6. Confirm the booking

**Expected:**
- Booking created successfully
- Confirmation page shows date range

---

## Test 4: Confirmation Email

1. After creating a multi-day booking, check Mailhog: `ddev launch -m`

**Expected:**
- Email shows "Date Range: June 10, 2026 – June 12, 2026"
- Duration shows "3 days" (not "0 minutes")
- No time row is displayed
- All other email content (customer info, service name, etc.) renders correctly

---

## Test 5: CP Reservation Display

1. Go to **Booked → Bookings** in the CP

**Expected:**
- Multi-day bookings show "06/10/26 – 06/12/26" in the Date column
- Duration column shows "3 days"
- Clicking to edit shows start date + end date fields (no time fields)

---

## Test 6: Pricing — Flat vs Per-Unit

1. Create Service A: durationType=days, duration=3, pricingMode=flat, price=500
2. Create Service B: durationType=days, duration=3, pricingMode=per_unit, price=200
3. Book each with quantity=2

**Expected:**
- Service A total: $1,000 (500 × 2 people)
- Service B total: $1,200 (200/day × 3 days × 2 people)

---

## Test 7: Availability — Blocking

1. Create a day-based service with duration=3
2. Create a booking for June 10–12
3. Check the calendar for the same employee

**Expected:**
- June 10, 11, 12 are NOT available as start dates
- June 9 is NOT available if bufferBefore > 0
- June 13 is NOT available if bufferAfter > 0
- June 8 IS available (if no buffer)
- June 13 IS available (if no buffer)

---

## Test 8: Availability — Single-Day Blocking

1. Book a regular (minute-based) appointment for the same employee on June 11
2. Check the day-based service calendar

**Expected:**
- June 10 is NOT available as a start date for a 3-day tour (because June 11 has a booking)
- June 9 is NOT available either (3-day span June 9-11 conflicts)

---

## Test 9: Cancellation

1. Cancel a multi-day booking via the management URL or CP

**Expected:**
- Cancellation email shows date range, not times
- Cancelled booking shows correct date range in CP
- The dates become available again on the calendar

---

## Test 10: Webhooks

1. Configure a webhook for booking.created
2. Create a multi-day booking
3. Check the webhook delivery log

**Expected payload includes:**
- `booking.endDate`: "2026-06-12"
- `booking.isMultiDay`: true
- `booking.durationDays`: 3
- `booking.startTime`: null
- `booking.endTime`: null

---

## Test 11: Calendar Sync (if configured)

1. Connect Google Calendar or Outlook
2. Create a multi-day booking

**Expected:**
- Calendar event appears as an all-day event spanning the correct dates
- Not as a timed event

---

## Test 12: GraphQL (if enabled)

Query:
```graphql
{
  bookedServices {
    title
    duration
    durationType
    pricingMode
  }
}
```

**Expected:** Returns durationType and pricingMode for each service.

Query:
```graphql
{
  bookedReservations {
    bookingDate
    endDate
    isMultiDay
    durationDays
    startTime
    endTime
  }
}
```

**Expected:** Multi-day bookings show endDate, isMultiDay=true, durationDays=3, startTime=null, endTime=null.

---

## Test 13: Commerce Integration (if Commerce installed)

1. Create a day-based service with Commerce enabled and price > 0
2. Book it

**Expected:**
- Line item added to cart with correct total (per-unit pricing calculated correctly)
- Order completion confirms the booking
- Pending → Confirmed status transition works

---

## Test 14: SMS Notifications (if Twilio configured)

1. Create a multi-day booking with SMS enabled

**Expected:**
- SMS uses the multi-day template: "Your booking is confirmed! 3-Day Tour from 06/10 to 06/12 (3 days)."
- No time information in the SMS

---

## Test 15: Reminder Emails

1. Create a multi-day booking starting tomorrow
2. Trigger reminder processing: `ddev exec php craft booked/reminders/send`

**Expected:**
- Reminder email shows date range, not times
- Subject references the correct start date

---

## Test 16: Create a Flexible Day Service

1. Go to **Booked → Services → New Service**
2. Set **Duration Type** = "Flexible Days"
3. Set **Minimum Days** = 2
4. Set **Maximum Days** = 7
5. Set **Price** = 150
6. Set a title (e.g., "Cabin Rental")
7. Save

**Expected:**
- Duration field is hidden (customer chooses range)
- Pricing Mode dropdown is hidden (forced to per-unit)
- Min Days and Max Days fields are visible
- Time Slot Length field is hidden
- Service saves without errors

---

## Test 17: CP Field Toggling — Flexible Days

1. Edit an existing minute-based service
2. Switch Duration Type to "Flexible Days"

**Expected:**
- Duration field hides
- Min Days / Max Days fields appear (defaults: 1 / 7)
- Pricing Mode hides
- Time Slot Length hides

3. Switch back to "Minutes"

**Expected:**
- Duration field reappears
- Min Days / Max Days fields hide
- Time Slot Length reappears
- Pricing Mode stays hidden (minutes = always flat)

4. Switch to "Days"

**Expected:**
- Duration field visible with "days" label
- Pricing Mode dropdown appears
- Min Days / Max Days fields hide

---

## Test 18: Frontend Booking — Flexible Day Service (Two-Click Range)

1. Navigate to the booking page on the frontend
2. Select the flexible day service ("Cabin Rental")
3. Complete extras/location/employee steps

**Expected at Date step — Start date selection:**
- Calendar shows available start dates (green dots)
- Unavailable dates are greyed out

4. Click a start date (e.g., June 10)

**Expected — End date selection mode:**
- Calendar stays open, switches to end-date mode
- "Select your end date" announcement appears
- Loading spinner while valid end dates are fetched
- Valid end dates (June 11 through June 16) are highlighted as available
- Dates beyond max range (June 17+) are greyed out
- Start date (June 10) has a special "range start" marker (dark circle)
- Hovering over valid end dates highlights the range between start and hovered date

5. Hover over June 14

**Expected:**
- June 10–14 are highlighted as a continuous range
- June 10 has rounded left corners, June 14 has rounded right corners

6. Click June 14 as end date

**Expected:**
- Range "June 10 – June 14 (5 days)" is locked in
- Wizard advances past time step to customer info
- Soft lock is created for the date range

---

## Test 19: Flexible Day — Review & Pricing

1. Complete a flexible day booking (June 10–14, 5 days, price $150/day)

**Expected at Review step:**
- Shows date range: "June 10 – June 14, 2026"
- Shows duration: "5 days"
- Shows total price: $750 (5 days × $150)
- No time is displayed

2. Change quantity to 2 before confirming

**Expected:**
- Total price: $1,500 (5 days × $150 × 2 people)

---

## Test 20: Flexible Day — Min/Max Enforcement

1. Create a flexible day service with minDays=3, maxDays=5
2. Start a booking, select a start date

**Expected:**
- End dates before startDate+2 (3-day minimum) are NOT selectable
- End dates after startDate+4 (5-day maximum) are NOT selectable
- Only 3 possible end dates (for 3, 4, or 5 day bookings) can be valid

---

## Test 21: Flexible Day — Availability Blocking

1. Create a flexible day service (min 2, max 7)
2. Book a reservation for June 10–14
3. Check the calendar for another booking attempt

**Expected:**
- June 10–14 are NOT available as start dates
- June 8 as start date: end dates that would overlap June 10+ are invalid
- June 15 as start date: should be available (no overlap)

---

## Test 22: Flexible Day — Edge Case: Blackout in Range

1. Create a blackout date on June 12
2. Start a flexible day booking, select June 10 as start

**Expected:**
- End dates that would include June 12 (June 12+) are NOT valid
- June 11 IS valid as end date (2-day booking avoids blackout)
- If minDays=3, NO end dates are valid (range must cross June 12)
- Calendar shows appropriate "unavailable" state

---

## Test 23: Flexible Day — Emails & Notifications

1. Complete a flexible day booking
2. Check Mailhog: `ddev launch -m`

**Expected:**
- Confirmation email shows date range (not times)
- Duration shows "5 days" (matching the chosen range)
- Price reflects per-day × days × quantity calculation

---

## Test 24: Flexible Day — Soft Lock Prevents Double-Booking

1. Open two browser tabs to the booking page
2. In both, select the same flexible day service
3. In Tab A, select June 10 as start, then June 14 as end
4. In Tab B, select June 12 as start

**Expected:**
- Tab B should show June 12 as unavailable (or the lock request fails)
- Only one booking can proceed for overlapping date ranges

---

## Test 25: Employee-Specific Multi-Day Availability

1. Create a day-based or flexible-day service with 2 employees (e.g., Alice, Bob)
2. Book Alice for June 10–12
3. Start a new booking for the same service

**Expected:**
- With Alice selected: June 10, 11, 12 are NOT available as start dates
- With Bob selected: June 10, 11, 12 ARE available (different employee)
- Switch from Bob to Alice mid-flow: dates reset, calendar refreshes with Alice's availability

---

## Test 26: Location-Specific Multi-Day Availability

1. Create a day-based service with 2 locations (e.g., Downtown, Uptown)
2. Book at Downtown for June 10–12
3. Start a new booking for the same service

**Expected:**
- With Downtown selected: June 10–12 blocked
- With Uptown selected: June 10–12 available
- Switch location after selecting dates: dates reset, calendar refreshes

---

## Test 27: Employee Change Resets Date State

1. Start a flexible-day booking, select Employee A
2. Select start date June 10, then end date June 14 (range locked, soft lock created)
3. Go back and change to Employee B

**Expected:**
- Date and end date are cleared
- Old soft lock is released (Employee A's dates become available again)
- Calendar refreshes with Employee B's availability
- Must re-select start and end dates

---

## Test 28: Location Change Resets Date State

1. Start a multi-day booking, select Location A
2. Select dates (soft lock created)
3. Go back and change to Location B

**Expected:**
- Same as Test 27 — dates cleared, old lock released, calendar refreshes

---

## Test 29: Multi-Day Booking Requires Employee Selection

1. Create a day-based service that has employees assigned
2. Start a booking, skip the employee step ("Any available")
3. Select dates and submit

**Expected:**
- Booking fails with error: "Please select an employee for this service."
- The user must go back and explicitly pick an employee

---

## Test 30: Employee-Less Multi-Day Service

1. Create a day-based service with NO employees (only a service-level schedule)
2. Start a booking

**Expected:**
- Employee step is skipped automatically
- Calendar shows availability based on the service schedule
- Booking saves successfully with `employeeId = null`
- No "employee required" error

---

## Test 31: Buffer Days with Employee/Location

1. Create a day-based service with `bufferBefore = 60` (1 hour → rounds up to 1 day) and `bufferAfter = 1440` (24 hours = 1 day)
2. Book Employee A for June 10–12

**Expected on calendar for Employee A:**
- June 9 is NOT available (1-day buffer before)
- June 13 is NOT available (1-day buffer after)
- June 8 and June 14 ARE available
- Employee B is unaffected by these buffers

---

## Regression Checklist

- [ ] Standard minute-based bookings still work end-to-end
- [ ] Fixed-duration day bookings still work end-to-end
- [ ] Event-based bookings still work
- [ ] Waitlist functionality unaffected
- [ ] Account/booking management page displays all three types correctly
- [ ] Multi-site: bookings work from non-primary sites
- [ ] Soft locks prevent double-booking on multi-day slots
- [ ] Switching duration types in CP preserves/clears fields correctly
- [ ] Flexible day service with quantity > 1 prices correctly
- [ ] CSRF tokens work on all booking actions (no "Unable to verify" errors)
- [ ] Changing employee mid-flow resets dates and releases soft lock
- [ ] Changing location mid-flow resets dates and releases soft lock
- [ ] Multi-day booking with employees requires explicit employee selection
- [ ] Employee-less multi-day services book without error
- [ ] Buffer days are enforced per-employee (don't bleed across employees)
