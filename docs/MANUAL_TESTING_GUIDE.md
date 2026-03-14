# Manual Testing Guide — Tier 1 Release Features

This guide covers manual QA for the four new features. Run through each section after deploying to a dev environment.

## Prerequisites

```bash
# Start environment
ddev start
ddev import-db --file=db.sql.gz   # Fresh database

# Run migrations (picks up new sessionNotes column)
ddev exec php craft migrate/all

# Login
ddev launch access
# Credentials: cc_admin / letmein
```

---

## 1. No-Show Tracking

### 1.1 Status Visibility in CP

- [X] Navigate to **Booked → Bookings**
- [X] Verify the left sidebar shows: All, Confirmed, Pending, Cancelled, **No Show**
- [X] Click "No Show" — should show empty list initially

### 1.2 Mark as No-Show (Element Action)

- [x] Create or find a **confirmed** booking (past date preferred)
- [x] In the bookings index, select the checkbox next to the booking
- [x] Click the gear icon (bulk actions) — verify **"Mark as No Show"** appears
- [X] Click it — confirm the dialog
- [X] Verify the booking now shows with **red** "No Show" status badge
- [X] Verify it appears in the "No Show" sidebar source
- [X] Verify the time slot is still **blocked** (not freed for new bookings)

### 1.3 Mark as No-Show — Edge Cases

- [X] Select a **cancelled** booking → Mark as No Show should skip it (no change)
- [X] Select a booking that's **already no-show** → should skip it
- [X] Select multiple bookings (mix of confirmed + cancelled) → only confirmed ones change
- [X] Verify the success message shows correct count

### 1.4 Console Command — Auto-Mark No-Shows

```bash
# Dry run first — shows what would be marked
ddev exec php craft booked/bookings/mark-no-shows --dry-run --grace-period=30

# Actually mark them
ddev exec php craft booked/bookings/mark-no-shows --grace-period=30

# Verify in CP that affected bookings changed to no-show
```

- [ ] Dry run lists bookings without changing them
- [ ] Real run marks confirmed bookings whose end time + grace period has passed
- [ ] Future bookings are NOT marked (even if on today's date)
- [ ] Already cancelled bookings are NOT affected

### 1.5 No-Show Does NOT Free Waitlist

- [ ] Create a service with waitlist enabled
- [ ] Fill a time slot completely
- [ ] Add someone to the waitlist for that slot
- [ ] Mark the booking as no-show
- [ ] Verify the waitlist entry is NOT notified (unlike cancellation, which does notify)

### 1.6 Availability

- [ ] Mark a booking as no-show
- [ ] Check the front-end availability for that same slot
- [ ] Verify the slot is still **unavailable** (no-show occupies it)

---

## 2. CSV Element Exporters

### 2.1 Enhanced Reservation Export

- [ ] Navigate to **Booked → Bookings**
- [ ] Click the **Export** button (top right of element index)
- [ ] Select **"Bookings CSV"**
- [ ] Download and open the CSV
- [ ] Verify these columns exist: ID, Name, Email, Phone, **Service, Employee, Location**, Date, Start Time, End Time, **Duration (min)**, Status, **Quantity, Price**, Notes, Created
- [ ] Verify Service/Employee/Location names are populated (not blank or IDs)
- [ ] Verify Duration is in minutes (e.g., "60")
- [ ] Verify Price is formatted with 2 decimal places

### 2.2 Reservation Export — Filters

- [ ] Filter bookings by status (e.g., Confirmed only)
- [ ] Export — verify only confirmed bookings appear in CSV
- [ ] Filter by date range — verify export respects the filter
- [ ] Staff user (non-admin) — verify export only shows their managed bookings

### 2.3 Employee Schedule Export

- [ ] Navigate to **Booked → Employees**
- [ ] Click **Export** → select **"Employee Schedules CSV"**
- [ ] Download and open
- [ ] Verify columns: Employee, Email, Location, Monday, Tuesday, ..., Sunday, **Total Hours/Week**
- [ ] Verify working days show time ranges (e.g., "09:00 - 17:00")
- [ ] Verify off days show "Off"
- [ ] Verify Total Hours/Week is calculated correctly (e.g., 5 × 8h = 40.0)

### 2.4 Service Catalog Export

- [ ] Navigate to **Booked → Services**
- [ ] Click **Export** → select **"Service Catalog CSV"**
- [ ] Download and open
- [ ] Verify columns: ID, Title, Description, Duration (min), Buffer Before (min), Buffer After (min), Time Slot (min), Price, Min Advance (min), Waitlist, Cancellation Allowed, Cancellation Hours, Status, Created
- [ ] Verify prices show 2 decimal places
- [ ] Verify boolean fields show "Yes"/"No"
- [ ] Verify disabled services show "Disabled" status

### 2.5 CSV Security

- [ ] Create a booking with notes starting with `=SUM(` or `+cmd`
- [ ] Export to CSV — verify the value is prefixed with a single quote (CSV injection prevention)

---

## 3. Dashboard Widget

### 3.1 Add Widget to Craft Dashboard

- [ ] Go to the **Craft Dashboard** (home page of admin, not Booked's dashboard)
- [ ] Click **"+ New widget"**
- [ ] Verify **"Today's Bookings"** appears in the widget type list (with Booked icon)
- [ ] Select it

### 3.2 Widget Settings

- [ ] In the widget settings, verify a **Lookahead** dropdown with options: Today, Next 3 Days, Next 7 Days
- [ ] Set to "Today" → Save
- [ ] Verify the widget shows

### 3.3 Widget Content — Stats

- [ ] Verify three stat cards at the top: **Total**, **Confirmed** (green), **Pending** (orange)
- [ ] Create a confirmed booking for today → refresh dashboard → count should increase
- [ ] Change lookahead to "Next 7 Days" → counts should include bookings within that range
- [ ] Verify counts are accurate against the bookings index

### 3.4 Widget Content — Upcoming Table

- [ ] Verify the table shows upcoming bookings with: Name (linked to edit), Date/Time, Status
- [ ] Click a customer name → should navigate to the booking edit page
- [ ] Verify bookings are sorted by date/time ascending
- [ ] Maximum 10 bookings shown
- [ ] With no bookings: verify "No upcoming bookings" empty state

### 3.5 Staff Scoping

- [ ] Log in as a **staff user** (non-admin, linked to an employee)
- [ ] Add the widget to their dashboard
- [ ] Verify counts and table only show bookings for their managed employees
- [ ] Admin user should see all bookings

### 3.6 Multiple Widgets

- [ ] Add two Booked widgets — one set to "Today", another to "Next 7 Days"
- [ ] Verify both show independently correct data

---

## 4. Session Notes

### 4.1 Field Visibility — Admin

- [ ] As admin, navigate to **Booked → Bookings → [any booking]**
- [ ] Scroll down past the regular notes field
- [ ] Verify a **"Session Notes"** section appears with:
  - Heading: "Session Notes"
  - Description text explaining it's for staff only
  - A textarea field
- [ ] Enter some text → Save → reload → verify it persists

### 4.2 Field Visibility — Assigned Employee

- [ ] Log in as a **staff user** whose employee is assigned to a booking
- [ ] Navigate to that booking's edit page
- [ ] Verify the Session Notes field IS visible
- [ ] Add/edit notes → Save → verify persistence

### 4.3 Field Visibility — Other Staff

- [ ] Log in as a **staff user** whose employee is NOT assigned to the booking
- [ ] Navigate to the booking edit page (if accessible)
- [ ] Verify the Session Notes field is **NOT visible**

### 4.4 Session Notes vs Customer Notes

- [ ] Verify the existing **"Notes"** field (customer-facing) is still present and independent
- [ ] Edit Session Notes — verify customer Notes are unchanged
- [ ] Edit customer Notes — verify Session Notes are unchanged

### 4.5 New Bookings

- [ ] Create a new booking through the front-end wizard
- [ ] Open it in the CP — Session Notes should be empty/null
- [ ] Add session notes after the appointment → Save → verify

### 4.6 CSV Export

- [ ] Export bookings to CSV
- [ ] Verify `sessionNotes` is **NOT** included in the CSV export (it's staff-private)
- [ ] Verify the existing customer `Notes` column is still exported

### 4.7 Migration

```bash
# Verify migration runs cleanly on existing database
ddev exec php craft migrate/all --track=plugin:booked

# Verify column exists
ddev exec php craft db/query "DESCRIBE booked_reservations sessionNotes"
```

- [ ] Migration adds `sessionNotes` TEXT column after `notes`
- [ ] Existing bookings have NULL sessionNotes (not broken)

---

## Cross-Feature Tests

### No-Show + CSV Export

- [ ] Mark several bookings as no-show
- [ ] Export bookings CSV — verify no-show bookings show "No Show" in Status column
- [ ] Filter to "No Show" source → export → verify only no-show bookings

### No-Show + Dashboard Widget

- [ ] With some no-show bookings, check the widget
- [ ] Verify no-show bookings are NOT counted in Total/Confirmed/Pending (they are a separate status)
- [ ] No-show bookings should NOT appear in the upcoming table

### Session Notes + No-Show

- [ ] Add session notes to a booking
- [ ] Mark it as no-show
- [ ] Verify session notes are preserved after status change

### All Features — Non-Primary Site

- [ ] Switch to a non-primary site
- [ ] Verify all features work correctly (bookings, exports, widget, session notes)
- [ ] Verify exports contain correct data scoped to the site

---

## Automated Test Verification

```bash
cd plugins/booked

# Run full test suite
composer test

# Run only new feature tests
./vendor/bin/phpunit tests/Unit/Records/ReservationRecordTest.php -v
./vendor/bin/phpunit tests/Unit/Elements/Actions/ -v
./vendor/bin/phpunit tests/Unit/Elements/Exporters/ -v
./vendor/bin/phpunit tests/Unit/Widgets/ -v
./vendor/bin/phpunit tests/Unit/Elements/ReservationSessionNotesTest.php -v

# Code quality
composer check   # ECS + PHPStan
```

- [ ] All tests pass (expect ~38 skipped requiring Craft init)
- [ ] No new ECS violations
- [ ] No new PHPStan errors beyond the existing ~428 baseline
