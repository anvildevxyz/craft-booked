# Developer Guide - Booked

Comprehensive guide for developers extending and customizing the Booked plugin.

## Architecture

### Service-Based Design

Booked uses a service-based architecture for separation of concerns:

```
anvildev\booked\
├── elements/          # Element types (Reservation, Service, Employee, etc.)
├── services/          # Business logic services
├── controllers/       # HTTP controllers
├── events/            # Event classes
├── records/           # Active Record models
├── queue/             # Background jobs
└── helpers/           # Utility classes
```

### Core Services

The plugin registers 28+ services via `setComponents()` in `Booked.php`. Key services:

| Handle | Service | Purpose |
|--------|---------|---------|
| `booking` | `BookingService` | Create, update, cancel bookings |
| `availability` | `AvailabilityService` | Calculate available time slots |
| `slotGenerator` | `SlotGeneratorService` | Generate time slots from availability windows |
| `capacity` | `CapacityService` | Capacity checking for service-level bookings |
| `scheduleAssignment` | `ScheduleAssignmentService` | Schedule-employee/service many-to-many relationships |
| `scheduleResolver` | `ScheduleResolverService` | Resolve active schedule for a date |
| `softLock` | `SoftLockService` | Race condition prevention via temporary slot locks |
| `bookingValidation` | `BookingValidationService` | Rate limits and business rule validation |
| `bookingNotification` | `BookingNotificationService` | Queue email/SMS notifications |
| `emailRender` | `EmailRenderService` | Render email templates with variables |
| `calendarSync` | `CalendarSyncService` | Two-way sync with Google/Outlook calendars |
| `virtualMeeting` | `VirtualMeetingService` | Generate Zoom/Meet/Teams meeting links |
| `reminder` | `ReminderService` | Send automated booking reminders |
| `blackoutDate` | `BlackoutDateService` | Manage blocked date ranges |
| `eventDate` | `EventDateService` | Manage one-time event bookings |
| `serviceExtra` | `ServiceExtraService` | Optional add-ons for services |
| `serviceLocation` | `ServiceLocationService` | Direct service-to-location assignments |
| `waitlist` | `WaitlistService` | Waitlist management and notifications |
| `twilioSms` | `TwilioSmsService` | SMS notifications via Twilio |
| `webhook` | `WebhookService` | Webhook dispatch with HMAC signing |
| `commerce` | `CommerceService` | Craft Commerce integration |
| `permission` | `PermissionService` | Staff scoping and managed employee resolution |
| `captcha` | `CaptchaService` | CAPTCHA verification (reCAPTCHA, hCaptcha, Turnstile) |
| `audit` | `AuditService` | Security and action audit logging |
| `reports` | `ReportsService` | Booking reports and statistics (uses `TagDependency`-based cache invalidation with the tag `'booked_reports'`) |
| `refund` | `RefundService` | Refund processing for Commerce bookings |
| `refundPolicy` | `RefundPolicyService` | Refund tier calculation |
| `timezone` | `TimezoneService` | Timezone conversion utilities |
| `maintenance` | `MaintenanceService` | Cleanup and maintenance tasks |
| `bookingSecurity` | `BookingSecurityService` | Request security validation (CAPTCHA, honeypot, IP blocking, time-based limits) |
| `timeWindow` | `TimeWindowService` | Time window calculations |
| `mutex` | `MutexFactory` | Mutex lock factory |
| `dashboard` | `DashboardService` | Dashboard widget data |

Access services via the plugin instance:

```php
use anvildev\booked\Booked;

$booking = Booked::getInstance()->booking;
$availability = Booked::getInstance()->availability;
$calendar = Booked::getInstance()->calendarSync;
$permission = Booked::getInstance()->permission;
```

## Services API

### BookingService

Create and manage bookings.

#### Create Booking

Two methods are available: `createBooking()` is a convenience wrapper that delegates to `createReservation()`, which is the primary method with full validation, soft lock handling, availability checks, and notification dispatch.

```php
use anvildev\booked\Booked;

$bookingService = Booked::getInstance()->booking;

// Convenience wrapper
$reservation = $bookingService->createBooking([
    'serviceId' => 1,
    'employeeId' => 2,
    'bookingDate' => '2025-12-26',
    'startTime' => '14:00',
    'userName' => 'John Doe',
    'userEmail' => 'john@example.com',
]);

// Full method with all options
$reservation = $bookingService->createReservation([
    'serviceId' => 1,
    'employeeId' => 2,
    'locationId' => 1,
    'bookingDate' => '2025-12-26',
    'startTime' => '14:00',
    'endTime' => '15:00',
    'userName' => 'John Doe',
    'userEmail' => 'john@example.com',
    'userPhone' => '+1-555-0123',
    'notes' => 'First time customer',
    'quantity' => 1,
    'source' => 'web',
]);

if ($reservation) {
    echo "Booking created: {$reservation->id}";
} else {
    echo "Booking failed";
}
```

#### Cancel Booking

```php
$success = $bookingService->cancelReservation(
    $reservation->id,
    'Customer requested cancellation'
);
```

#### Update Booking

```php
$reservation->startTime = '15:00';
$reservation->endTime = '16:00';

$success = Craft::$app->elements->saveElement($reservation);
```

### AvailabilityService

Calculate available time slots.

#### Get Available Slots

```php
use anvildev\booked\Booked;

$availabilityService = Booked::getInstance()->availability;

$slots = $availabilityService->getAvailableSlots(
    date: '2025-12-26',
    employeeId: 2,           // Optional
    locationId: 1,           // Optional
    serviceId: 1,            // Optional
    requestedQuantity: 1,    // Optional
    userTimezone: 'America/New_York' // Optional
);

foreach ($slots as $slot) {
    echo "{$slot['time']} - {$slot['endTime']} ({$slot['employeeName']})\n";
}
```

#### Check Slot Availability

```php
$isAvailable = $availabilityService->isSlotAvailable(
    date: '2025-12-26',
    startTime: '14:00',
    endTime: '15:00',
    employeeId: 2,
    serviceId: 1,
    requestedQuantity: 1
);
```

#### Performance Notes

The availability system uses batch queries to minimize database round-trips:

- **Schedule resolution**: Employee schedules are loaded in a single batch query via `ScheduleAssignmentService::getActiveSchedulesForDateBatch()` instead of one query per employee.
- **Capacity enrichment**: `CapacityService::enrichSlotsWithCapacity()` pre-loads all employees, schedules, and reservations in 3-4 queries, then does in-memory lookups per slot.
- **Service schedule memoization**: `getActiveScheduleForServiceOnDate()` is memoized within a request — repeated calls with the same service/date return cached results.
- **Session handling**: AJAX controllers (`SlotController`, `BookingDataController`) close the PHP session early to prevent file lock contention during parallel requests. If you extend these controllers, be aware that the session is read-only after `init()`.

#### Time Slot Interval

The slot interval (how often slots appear) is determined by a fallback chain:

1. **Service `timeSlotLength`** (if set) - Per-service setting
2. **Global `defaultTimeSlotLength`** (if set) - From Settings
3. **Service `duration`** (always available) - Falls back to duration

```php
use anvildev\booked\Booked;

$slotGenerator = Booked::getInstance()->slotGenerator;

// Get the effective slot interval for a service
$interval = $slotGenerator->getSlotInterval(
    serviceOrId: 1,
    duration: 60 // Fallback duration if no slot length is set
);

// This will return:
// - Service's timeSlotLength if set
// - Global defaultTimeSlotLength if set
// - Service duration (60) as final fallback
```

**Note**: The slot interval determines **when** slots appear (e.g., every 15 minutes), while service duration determines **how long** each booking lasts (e.g., 60 minutes). These can be different values.

### SoftLockService

Prevent race conditions when multiple users try to book the same slot simultaneously.

#### How Soft Locks Work

Soft locks temporarily reserve a time slot while a user completes the booking form. This prevents double-bookings when multiple users select the same slot.

**Booking Flow with Soft Locks:**

```
1. User browses available slots      → No lock
2. User selects a time slot          → Frontend calls create-lock → LOCK CREATED
3. User fills in booking form        → Lock active (default 5 min)
4. User submits booking              → Booking created, lock consumed
5. If user abandons page             → Lock expires automatically
```

#### Create Soft Lock (PHP)

```php
use anvildev\booked\Booked;

$softLockService = Booked::getInstance()->softLock;

$token = $softLockService->createLock([
    'date' => '2025-12-26',
    'startTime' => '14:00',
    'endTime' => '15:00',
    'serviceId' => 1,
    'employeeId' => 2,      // Optional
    'locationId' => 1,      // Optional
]);

if ($token) {
    // Lock created successfully
    // Store token to release later or include in booking
} else {
    // Slot already locked by another user
}
```

#### Release Soft Lock (PHP)

```php
$softLockService->releaseLock($token);
```

#### Check if Slot is Locked (PHP)

```php
$isLocked = $softLockService->isLocked(
    date: '2025-12-26',
    startTime: '14:00',
    serviceId: 1,
    employeeId: 2,
    slotEndTime: '15:00',
    excludeToken: $myToken // Optional: exclude own lock
);
```

#### Frontend HTTP Endpoints

**Create Lock** - `POST booked/slot/create-lock`

Call this when the user **selects a time slot**, before they fill in the booking form.

```javascript
// When user clicks on a time slot
async function selectSlot(date, startTime, serviceId, employeeId, locationId) {
    const response = await fetch('/actions/booked/slot/create-lock', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            date,
            startTime,
            serviceId,
            employeeId,    // optional
            locationId     // optional
        })
    });

    const result = await response.json();

    if (result.success) {
        // Store token for later use
        this.softLockToken = result.token;
        // Show booking form
    } else {
        // Slot already taken
        alert(result.error);
    }
}
```

**Release Lock** - `POST booked/slot/release-lock`

Call this if the user cancels or navigates away without booking.

```javascript
async function releaseLock(token) {
    await fetch('/actions/booked/slot/release-lock', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({ token })
    });
}

// Release on page unload
window.addEventListener('beforeunload', () => {
    if (this.softLockToken) {
        navigator.sendBeacon('/actions/booked/slot/release-lock',
            JSON.stringify({ token: this.softLockToken }));
    }
});
```

#### Configuration

Configure the soft lock duration in **Settings → Booked → Booking** (default: 5 minutes).

Soft locks are always enabled - there's no setting to disable them since they're essential for preventing double-bookings.

#### Best Practices

1. **Create lock on slot selection**, not on form submit
2. **Release lock** when user cancels or changes slot
3. **Include token** in booking submission for validation
4. **Handle lock failures gracefully** - show user-friendly message
5. **Use `beforeunload`** to release locks when user navigates away

### CalendarSyncService

Sync with external calendars.

#### Get Authorization URL

```php
use anvildev\booked\elements\Employee;

$employee = Employee::find()->id(2)->one();
$authUrl = Booked::getInstance()->calendarSync->getAuthUrl(
    $employee,
    'google' // or 'outlook'
);

// Redirect user to $authUrl for OAuth
```

#### Handle OAuth Callback

```php
$success = Booked::getInstance()->calendarSync->handleCallback(
    $stateToken,   // From query parameter
    $code,         // From query parameter
    $redirectUri   // Optional: custom redirect URI
);
```

#### Sync to External Calendar

```php
use anvildev\booked\elements\Reservation;

$reservation = Reservation::find()->id(123)->one();

$success = Booked::getInstance()->calendarSync->syncToExternal($reservation);
```

### VirtualMeetingService

Create virtual meeting links.

#### Generate Meeting

```php
$virtualMeeting = Booked::getInstance()->virtualMeeting;

$meetingData = $virtualMeeting->createMeeting(
    reservation: $reservation,
    provider: 'zoom' // or 'google', 'teams'
);

if ($meetingData) {
    echo "Join URL: {$meetingData['url']}";
    echo "Meeting ID: {$meetingData['id']}";
    echo "Provider: {$meetingData['provider']}";
}
```

#### Delete Meeting

Called automatically via event listeners when a booking is cancelled. Can also be called manually:

```php
$virtualMeeting->deleteMeeting($reservation);
```

#### Update Meeting

Called automatically via event listeners when a booking is rescheduled. Can also be called manually:

```php
$virtualMeeting->updateMeeting($reservation);
```

### WaitlistService

Manage customer waitlist entries for fully booked time slots. Waitlist entries are stored as plain ActiveRecord rows (`WaitlistRecord`) — not Craft Elements — to avoid unnecessary overhead for what are essentially temporary queue entries.

```php
$waitlist = Booked::getInstance()->waitlist;

// Add to waitlist (returns WaitlistRecord or null on failure)
$entry = $waitlist->addToWaitlist([
    'serviceId' => 1,
    'employeeId' => 2,           // Optional
    'locationId' => 1,           // Optional
    'preferredDate' => '2025-12-26',
    'preferredTimeStart' => '14:00',
    'preferredTimeEnd' => '15:00',
    'userName' => 'Jane Doe',
    'userEmail' => 'jane@example.com',
    'userPhone' => '+1-555-0124', // Optional
    'notes' => 'Flexible on time', // Optional
]);

// Check if customer is already on waitlist
$isWaiting = $waitlist->isOnWaitlist('jane@example.com', $serviceId);

// Get active entries for a service
$entries = $waitlist->getActiveEntriesForService($serviceId);

// Cancel a waitlist entry
$waitlist->cancelEntry($entryId);

// Manually send a general availability notification (from CP)
$waitlist->manualNotify($entryId);

// Cleanup expired entries (returns count removed)
$expired = $waitlist->cleanupExpired();

// Get statistics
$stats = $waitlist->getStats();
// Returns: ['active' => int, 'notified' => int, 'converted' => int, 'expired' => int, 'cancelled' => int, 'total' => int]
```

**Automatic notifications:** When a booking is cancelled (via CP, frontend, console, or GraphQL), `checkAndNotifyWaitlist()` is called automatically to notify matching active waitlist entries about the newly available slot. The notification email includes the cancelled slot's date/time details. Entries are matched by service, and optionally by employee, location, and preferred date.

**Manual notifications:** Admins can manually notify a waitlist entry from the CP. This sends a general "slots may be available" email without specific slot details. The customer is expected to visit the booking page and book through the normal wizard.

**WaitlistRecord helpers:** The record provides convenience methods for loading related elements:
```php
$record = WaitlistRecord::findOne($id);
$record->getService();   // ?Service
$record->getEmployee();  // ?Employee (uses siteId('*'))
$record->getLocation();  // ?Location (uses siteId('*'))
$record->getUser();      // ?User
$record->canBeNotified(); // bool (status is 'active')
```

### WebhookService

Send webhooks to external systems when booking events occur.

```php
$webhook = Booked::getInstance()->webhook;

// Get all available event types
$eventTypes = WebhookService::getEventTypes();
// Returns: ['booking.created' => 'Booking Created', 'booking.cancelled' => 'Booking Cancelled', ...]

// Get all webhooks
$webhooks = $webhook->getAllWebhooks();

// Get webhook by ID
$hook = $webhook->getWebhookById($id);

// Save a webhook (auto-generates secret if not set)
$record = new WebhookRecord();
$record->name = 'My Webhook';
$record->url = 'https://example.com/webhook';
$record->events = ['booking.created', 'booking.cancelled'];
$webhook->saveWebhook($record);

// Delete a webhook
$webhook->deleteWebhook($id);

// Test a webhook (sends sample payload)
$result = $webhook->test($hook);

// Get delivery logs for a webhook
$logs = $webhook->getLogs($webhookId, 50);

// Get webhook statistics
$stats = $webhook->getWebhookStats($webhookId, 7);
// Returns: ['total' => int, 'success' => int, 'failed' => int, 'successRate' => float, 'avgDuration' => float]

// Retry a failed delivery
$webhook->retryFromLog($logId);
```

#### Verifying Webhook Signatures

Webhooks are signed with HMAC SHA256. Verify incoming webhooks:

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_BOOKED_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_BOOKED_TIMESTAMP'] ?? '';

$isValid = Booked::getInstance()->webhook->verifySignature(
    $payload,
    $signature,
    $webhookSecret,
    (int) $timestamp
);
```

See [WEBHOOKS.md](WEBHOOKS.md) for payload formats and event types.

### BookingValidationService

Enforce rate limits and business rules on bookings.

```php
$validation = Booked::getInstance()->bookingValidation;

// Check email rate limit (max bookings per email per hour)
$isLimited = $validation->checkEmailRateLimit('customer@example.com');

// Check IP rate limit (max bookings per IP per hour)
// @deprecated — use checkAllRateLimits() instead
$isLimited = $validation->checkIPRateLimit($ipAddress);

// Check all rate limits at once
$result = $validation->checkAllRateLimits($email, $ipAddress);
// Returns: ['allowed' => bool, 'reason' => string|null]

// Check customer booking limit for a service
$isLimited = $validation->checkCustomerBookingLimit(
    'customer@example.com',
    $service,
    '2025-12-26'
);
```

### BlackoutDateService

Check and manage blocked date ranges.

```php
$blackout = Booked::getInstance()->blackoutDate;

// Check if a date is blacked out
$isBlocked = $blackout->isDateBlackedOut('2025-12-25');

// Check for a specific employee/location
$isBlocked = $blackout->isDateBlackedOut('2025-12-25', $employeeId, $locationId);

// Get all blackout records for a date
$blackouts = $blackout->getBlackoutsForDate('2025-12-25');
```

### ReminderService

Send automated reminders for upcoming bookings.

```php
$reminder = Booked::getInstance()->reminder;

// Send all pending reminders (called by console command)
$sentCount = $reminder->sendReminders();

// Get reservations that need reminders
$pending = $reminder->getPendingReminders();
```

Reminders are typically sent via console commands:
```bash
php craft booked/reminders/send    # Send immediately
php craft booked/reminders/queue   # Queue for async processing
```

### BookingNotificationService

Queue email and SMS notifications for bookings.

```php
$notification = Booked::getInstance()->bookingNotification;

// Queue booking email (confirmation, cancellation, status_change, reminder)
$notification->queueBookingEmail($reservationId, 'confirmation');

// Queue with priority (lower = higher priority)
$notification->queueBookingEmail($reservationId, 'confirmation', null, 512);

// Queue owner notification
$notification->queueOwnerNotification($reservationId, 512);

// Queue cancellation notification
$notification->queueCancellationNotification($reservationId);

// Queue SMS (if SMS is configured)
$notification->queueSmsConfirmation($reservation);
$notification->queueSmsCancellation($reservation);
```

### ServiceExtraService

Manage optional add-ons for services.

```php
$extras = Booked::getInstance()->serviceExtra;

// Get extras for a service
$serviceExtras = $extras->getExtrasForService($serviceId);

// Get a single extra
$extra = $extras->getExtraById($id);

// Get all extras
$allExtras = $extras->getAllExtras(enabledOnly: true);

// Get extras selected for a reservation
$selected = $extras->getExtrasForReservation($reservationId);

// Save extras for a reservation
$extras->saveExtrasForReservation($reservationId, [
    ['extraId' => 1, 'quantity' => 2],
    ['extraId' => 3, 'quantity' => 1],
]);

// Validate required extras
$missing = $extras->validateRequiredExtras($serviceId, $selectedExtras);

// Calculate total extras duration
$additionalMinutes = $extras->calculateExtrasDuration($selectedExtras);

// Get extras summary string
$summary = $extras->getExtrasSummary($reservationId);

// Get total extras price
$totalPrice = $extras->getTotalExtrasPrice($reservationId);
```

### ServiceLocationService

Manage direct service-to-location assignments (many-to-many). This enables employee-less services (using service-level schedules) to be associated with specific locations.

```php
$serviceLocation = Booked::getInstance()->serviceLocation;

// Get locations assigned to a service
$locations = $serviceLocation->getLocationsForService($serviceId);

// Set locations for a service (replaces existing assignments)
$serviceLocation->setLocationsForService($serviceId, [10, 20, 30]);

// Batch-load location IDs for multiple services (avoids N+1)
$map = $serviceLocation->getLocationIdMapForServices([1, 2, 3]);
// Returns: [1 => [10, 20], 2 => [30], 3 => []]
```

**Element query filter:**

```php
// Find services available at a specific location
$services = Service::find()->locationId(5)->all();
```

## Event System

Booked fires events at critical points in the booking lifecycle. See [EVENT_SYSTEM.md](EVENT_SYSTEM.md) for complete documentation.

### Available Events

**BookingService Events:**
- `EVENT_BEFORE_BOOKING_SAVE` - Before saving a booking
- `EVENT_AFTER_BOOKING_SAVE` - After booking is saved
- `EVENT_BEFORE_BOOKING_CANCEL` - Before canceling a booking
- `EVENT_AFTER_BOOKING_CANCEL` - After booking is canceled

**CalendarSyncService Events:**
- `EVENT_BEFORE_CALENDAR_SYNC` - Before syncing to external calendar
- `EVENT_AFTER_CALENDAR_SYNC` - After calendar sync completes

**AvailabilityService Events:**
- `EVENT_BEFORE_AVAILABILITY_CHECK` - Before calculating availability
- `EVENT_AFTER_AVAILABILITY_CHECK` - After availability is calculated

### Event Handler Example

```php
use yii\base\Event;
use anvildev\booked\services\BookingService;
use anvildev\booked\events\BeforeBookingSaveEvent;

Event::on(
    BookingService::class,
    BookingService::EVENT_BEFORE_BOOKING_SAVE,
    function(BeforeBookingSaveEvent $event) {
        // Access event data
        $reservation = $event->reservation;
        $isNew = $event->isNew;
        $bookingData = $event->bookingData;

        // Custom validation
        if ($reservation->userEmail && !filter_var($reservation->userEmail, FILTER_VALIDATE_EMAIL)) {
            $event->isValid = false;
            $event->errorMessage = 'Invalid email address';
            return;
        }

        // Send to external CRM
        $crm = new CRMService();
        $crm->createLead([
            'name' => $reservation->userName,
            'email' => $reservation->userEmail,
            'phone' => $reservation->userPhone,
        ]);

        // Modify reservation data
        $reservation->notes = 'CRM Lead ID: ' . $crm->getLeadId();

        // Log to custom system
        Craft::info("New booking created by {$reservation->userName}", 'custom-booking-log');
    }
);
```

### Register Events in Plugin

Create a custom module and bootstrap it in `config/app.php`:

```php
// modules/CustomBookingModule.php
namespace modules;

use yii\base\Event;
use yii\base\Module as BaseModule;
use anvildev\booked\services\BookingService;
use anvildev\booked\events\AfterBookingSaveEvent;

class CustomBookingModule extends BaseModule
{
    public function init()
    {
        parent::init();

        Event::on(
            BookingService::class,
            BookingService::EVENT_AFTER_BOOKING_SAVE,
            function(AfterBookingSaveEvent $event) {
                if ($event->success && $event->isNew) {
                    // Your custom logic here
                }
            }
        );
    }
}
```

```php
// config/app.php
return [
    'modules' => ['custom-booking' => \modules\CustomBookingModule::class],
    'bootstrap' => ['custom-booking'],
];
```

## REST API Endpoints

All endpoints use the Craft action URL format: `/actions/booked/{controller}/{action}`.

CSRF tokens are required by default (configurable via `enableCsrfValidation` setting).

### Create Booking

`POST /actions/booked/booking/create-booking` (anonymous)

**Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `serviceId` | int | Yes | Service ID |
| `date` | string | Yes | Date (Y-m-d). Alias: `bookingDate` |
| `time` | string | Yes | Start time (HH:mm). Alias: `startTime` |
| `customerName` | string | Yes | Customer name. Alias: `userName` |
| `customerEmail` | string | Yes | Customer email. Alias: `userEmail` |
| `endTime` | string | No | End time (auto-calculated from service duration) |
| `employeeId` | int | No | Employee ID |
| `locationId` | int | No | Location ID |
| `customerPhone` | string | No | Customer phone. Alias: `userPhone` |
| `notes` | string | No | Customer-facing booking notes. Alias: `customerNotes` |
| `quantity` | int | No | Number of slots (default: 1) |
| `extras[extraId]` | int | No | Service extras with quantities |
| `userTimezone` | string | No | IANA timezone (e.g. `America/New_York`). Falls back to Craft system timezone. Used for formatted date/time display. |
| `softLockToken` | string | No | Soft lock token from slot selection |
| `captchaToken` | string | No | CAPTCHA token (if enabled) |

> **Parameter aliases:** When both a parameter and its alias are sent, the primary name takes precedence (e.g. `date` wins over `bookingDate`). The aliases exist for backward compatibility.

**Response:**

```json
{
  "success": true,
  "message": "booking.created",
  "data": {
    "reservation": {
      "id": 123,
      "formattedDateTime": "Thursday, Dec 26 at 2:00 PM",
      "status": "confirmed"
    }
  }
}
```

**Error Response:**

```json
{
  "success": false,
  "message": "booking.validationError",
  "errors": { "userEmail": ["Invalid email address"] }
}
```

### Get Available Slots

`POST /actions/booked/slot/get-available-slots` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `date` | string | Yes | Date (Y-m-d) |
| `serviceId` | int | No | Service ID |
| `employeeId` | int | No | Employee ID |
| `locationId` | int | No | Location ID |
| `quantity` | int | No | Requested quantity (default: 1) |

**Response:**

```json
{
  "success": true,
  "data": {
    "slots": [
      { "startTime": "09:00", "endTime": "10:00", "available": true },
      { "startTime": "10:00", "endTime": "11:00", "available": true }
    ],
    "waitlistAvailable": false
  }
}
```

### Get Availability Calendar

`GET /actions/booked/slot/get-availability-calendar` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `startDate` | string | No | Start date (default: today) |
| `endDate` | string | No | End date (default: +90 days) |
| `serviceId` | int | No | Service ID |
| `employeeId` | int | No | Employee ID |
| `locationId` | int | No | Location ID |

**Response:**

```json
{
  "success": true,
  "data": {
    "calendar": {
      "2025-12-26": { "hasAvailability": true, "isBlackedOut": false, "isBookable": true },
      "2025-12-27": { "hasAvailability": false, "isBlackedOut": true, "isBookable": false }
    }
  }
}
```

### Get Event Dates

`GET /actions/booked/slot/get-event-dates` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `dateFrom` | string | No | Start date range |
| `dateTo` | string | No | End date range |

**Response:**

```json
{
  "success": true,
  "data": {
    "hasEvents": true,
    "eventDates": [
      {
        "id": 1, "title": "Workshop", "date": "2025-12-26",
        "startTime": "10:00", "endTime": "12:00",
        "capacity": 20, "remainingCapacity": 5, "isFullyBooked": false
      }
    ]
  }
}
```

### Soft Lock (Create / Release)

`POST /actions/booked/slot/create-lock` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `date` | string | Yes | Date (Y-m-d) |
| `startTime` | string | Yes | Start time (HH:mm) |
| `serviceId` | int | Yes | Service ID |
| `employeeId` | int | No | Employee ID |
| `locationId` | int | No | Location ID |

```json
{ "success": true, "data": { "token": "abc123...", "expiresIn": 300 } }
```

`POST /actions/booked/slot/release-lock` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `token` | string | Yes | Lock token to release |

### Booking Data

`GET /actions/booked/booking-data/get-services` (anonymous)

Returns all enabled services with title, duration, price, buffers, and extras flag.

`GET /actions/booked/booking-data/get-service-extras?serviceId=1` (anonymous)

Returns extras for a service: id, title, description, price, duration, maxQuantity, isRequired.

`GET /actions/booked/booking-data/get-employees` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `serviceId` | int | No | Filter by service |
| `locationId` | int | No | Filter by location |

Returns employees, locations, and whether employees/schedules are required.

`GET /actions/booked/booking-data/get-commerce-settings` (anonymous)

Returns `commerceEnabled`, `currency`, `cartUrl`, `checkoutUrl`. The URL values are resolved from the `commerceCartUrl` and `commerceCheckoutUrl` plugin settings.

### Waitlist

`POST /actions/booked/waitlist/join-waitlist` (anonymous)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `serviceId` | int | Yes | Service ID |
| `userName` | string | Yes | Customer name |
| `userEmail` | string | Yes | Customer email |
| `employeeId` | int | No | Preferred employee |
| `locationId` | int | No | Preferred location |
| `preferredDate` | string | No | Preferred date |
| `preferredTimeStart` | string | No | Preferred start time |
| `preferredTimeEnd` | string | No | Preferred end time |
| `userPhone` | string | No | Phone number |
| `notes` | string | No | Notes |

```json
{ "success": true, "message": "waitlist.addedShort", "data": { "waitlistId": 42 } }
```

### Booking Management (Token-Based)

These endpoints use **confirmation tokens** for authorization (see [Security & Authorization](#security--authorization)).

`POST /actions/booked/booking-management/cancel-booking`

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | int | Yes | Reservation ID |
| `token` | string | Yes | Confirmation token |
| `reason` | string | No | Cancellation reason |

`GET /booking/manage/{token}` — Renders booking management page (view details, reschedule, cancel).

`GET /booking/cancel/{token}` — Renders cancellation confirmation page.

### Rescheduling

Rescheduling is handled via the booking management page. Customers access it through the token-based URL in their confirmation email.

`POST /actions/booked/booking-management/manage-booking`

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | Must be `reschedule` |
| `id` | int | Yes | Reservation ID |
| `token` | string | Yes | Confirmation token |
| `newDate` | string | Yes | New date (YYYY-MM-DD) |
| `newStartTime` | string | Yes | New start time (HH:MM) |
| `newEndTime` | string | Yes | New end time (HH:MM) |

**Constraints:**
- Cannot reschedule past bookings
- Cannot reschedule cancelled or completed bookings
- The new slot must pass `AvailabilityService::isSlotAvailable()` (same employee, location, service, quantity)
- The cancellation policy deadline applies to the original booking time

**Response:**

```json
{
  "success": true,
  "message": "booking.rescheduled",
  "data": {
    "reservation": {
      "id": 123,
      "formattedDateTime": "Thursday, Mar 20 at 3:00 PM",
      "status": "confirmed"
    }
  }
}
```

### REST API Error Codes

All REST endpoints return consistent error responses:

| HTTP Status | `message` | When |
|-------------|-----------|------|
| 200 | `booking.created` | Booking created successfully |
| 200 | `booking.cancelled` | Booking cancelled successfully |
| 200 | `booking.rescheduled` | Booking rescheduled successfully |
| 400 | `booking.validationError` | Input validation failed (missing fields, invalid format) |
| 400 | `booking.conflict` | Slot is no longer available |
| 400 | `booking.capacityExceeded` | Not enough capacity for requested quantity |
| 403 | `booking.forbidden` | Invalid confirmation token or unauthorized |
| 403 | `booking.captchaFailed` | CAPTCHA verification failed |
| 404 | `booking.notFound` | Reservation not found |
| 429 | `booking.rateLimited` | Email or IP rate limit exceeded |

Error responses include an `errors` object with field-level details:

```json
{
  "success": false,
  "message": "booking.validationError",
  "errors": {
    "userEmail": ["Invalid email address"],
    "startTime": ["This time slot is no longer available"]
  }
}
```

### Customer Account

All account endpoints require user login. See [Customer Account Portal](#customer-account-portal) for customization options.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/booked/account` | GET | Dashboard with upcoming bookings and stats |
| `/booked/account/bookings` | GET | All bookings |
| `/booked/account/upcoming` | GET | Upcoming bookings |
| `/booked/account/past` | GET | Past bookings |
| `/booked/account/{id}` | GET | Single booking detail (IDOR-protected) |
| `/actions/booked/account/cancel` | POST | Cancel booking (requires `id` param) |
| `/actions/booked/account/current-user` | GET | JSON: current user info for AJAX pre-fill |

## Element Types

### Reservation Element

```php
use anvildev\booked\elements\Reservation;

// Query bookings
$reservations = Reservation::find()
    ->bookingDate('2025-12-26')
    ->status('confirmed')
    ->employeeId(2)
    ->all();

// Access properties
foreach ($reservations as $reservation) {
    echo $reservation->userName;
    echo $reservation->userEmail;
    echo $reservation->startTime;
    echo $reservation->endTime;

    // Related elements
    $service = $reservation->getService();
    $employee = $reservation->getEmployee();
    $location = $reservation->getLocation();
}

// Create new reservation
$reservation = new Reservation();
$reservation->serviceId = 1;
$reservation->employeeId = 2;
$reservation->bookingDate = '2025-12-26';
$reservation->startTime = '14:00';
$reservation->endTime = '15:00';
$reservation->userName = 'John Doe';
$reservation->userEmail = 'john@example.com';

$success = Craft::$app->elements->saveElement($reservation);
```

#### Notes vs Session Notes

Reservations have two separate text fields for notes:

| Field | Property | Purpose | Who can access |
|-------|----------|---------|----------------|
| **Notes** | `notes` | Customer-provided context set at booking time (e.g. "I have a dog allergy") | Everyone |
| **Session Notes** | `sessionNotes` | Staff-written post-appointment notes (e.g. "Follow-up in 2 weeks") | Assigned employee + admins only |

Session notes are access-controlled in the CP: only the employee assigned to the booking and administrators can view or edit them. They are excluded from CSV exports to preserve confidentiality.

### Service Element

```php
use anvildev\booked\elements\Service;

// Query services
$services = Service::find()
    ->enabled()
    ->orderBy('title ASC')
    ->all();

// Access properties
foreach ($services as $service) {
    echo $service->title;
    echo $service->duration;
    echo $service->price;
    echo $service->bufferBefore;
    echo $service->bufferAfter;

    // Get employees assigned to this service
    $employees = Booked::getInstance()->getScheduleAssignment()->getEmployeesForService($service->id);
}
```

### Employee Element

```php
use anvildev\booked\elements\Employee;

// Query employees
$employees = Employee::find()
    ->locationId(1)
    ->status('active')
    ->all();

// Access properties
foreach ($employees as $employee) {
    echo $employee->title;
    echo $employee->email;

    // Related elements
    $location = $employee->getLocation();
    $services = $employee->getServices();
}
```

### Element Query Methods

```php
// Reservation queries
Reservation::find()
    ->bookingDate('2025-12-26')
    ->startTime('14:00')
    ->endTime('15:00')
    ->serviceId(1)
    ->employeeId(2)
    ->locationId(1)
    ->status('confirmed') // or ['confirmed', 'pending']
    ->userId(10)
    ->userEmail('john@example.com')
    ->limit(10)
    ->orderBy('bookingDate DESC, startTime ASC')
    ->all();

// Service queries
Service::find()
    ->enabled()
    ->price(50.0)
    ->duration(60)
    ->all();

// Employee queries
Employee::find()
    ->status('active')
    ->locationId(1)
    ->serviceId(1) // Employees offering this service
    ->all();
```

## GraphQL API

The Booked plugin provides a full GraphQL API for headless implementations. See [GRAPHQL.md](GRAPHQL.md) for the complete schema reference including all queries, mutations, types, and filter arguments.

### Quick Reference

Enable schema components in **Settings → GraphQL** under the **Booked** section:

| Permission | Description |
|------------|-------------|
| `bookedServices:read` | Query services |
| `bookedReservations:read` | Query reservations |
| `bookedEmployees:read` | Query employees |
| `bookedServiceExtras:read` | Query service extras |
| `bookedLocations:read` | Query locations |
| `bookedEventDates:read` | Query event dates |
| `bookedBlackoutDates:read` | Query blackout dates |
| `bookedReservations:create` | Create reservations |
| `bookedReservations:update` | Update reservations (requires confirmation token) |
| `bookedReservations:cancel` | Cancel reservations (requires confirmation token) |

Schedule queries are always available (no schema permission required).

### Example: Create a Booking via GraphQL

```graphql
mutation {
  createBookedReservation(input: {
    serviceId: "1"
    bookingDate: "2026-03-15"
    startTime: "14:00"
    userName: "Jane Doe"
    userEmail: "jane@example.com"
    quantity: 1
  }) {
    success
    reservation { id bookingDate startTime status }
    errors { field message code }
  }
}
```

### Pagination

All queries support standard Craft element arguments for pagination:

```graphql
query {
  bookedReservations(limit: 10, offset: 20, orderBy: "bookingDate DESC") {
    id
    bookingDate
    userName
  }
}
```

For complete query/mutation reference, type definitions, and filter arguments, see [GRAPHQL.md](GRAPHQL.md).

## Security & Authorization

### Confirmation Tokens

Every reservation is assigned a cryptographically secure confirmation token on creation. Tokens are 64-character hex strings generated from `random_bytes(32)` with database-level uniqueness enforcement.

Tokens authorize public-facing operations without requiring user authentication:

| Operation | Endpoint | Token Usage |
|-----------|----------|-------------|
| View/manage booking | `GET /booking/manage/{token}` | Token in URL |
| Cancel booking (REST) | `POST /actions/booked/booking-management/cancel-booking` | Token in POST body, verified against reservation |
| Update booking (GraphQL) | `updateBookedReservation` mutation | `token` argument required |
| Cancel booking (GraphQL) | `cancelBookedReservation` mutation | `token` argument required |
| Download ICS | `GET /booking/ics/{token}` | Token in URL |

Token verification prevents IDOR (Insecure Direct Object Reference) attacks. Failed authorization attempts are logged via the audit service:

```php
// Example: REST cancellation verifies token matches reservation
if ($reservation->getConfirmationToken() !== $token) {
    Booked::getInstance()->getAudit()->logAuthFailure('invalid_cancel_token', [
        'reservationId' => $id,
    ]);
    throw new ForbiddenHttpException();
}
```

### CAPTCHA Verification

Three CAPTCHA providers are supported. Enable via `Settings::enableCaptcha` and configure the provider:

| Provider | Setting | Verification Endpoint |
|----------|---------|----------------------|
| Google reCAPTCHA v3 | `captchaProvider: 'recaptcha'` | `google.com/recaptcha/api/siteverify` |
| hCaptcha | `captchaProvider: 'hcaptcha'` | `hcaptcha.com/siteverify` |
| Cloudflare Turnstile | `captchaProvider: 'turnstile'` | `challenges.cloudflare.com/turnstile/v0/siteverify` |

Each provider requires a site key and secret key. reCAPTCHA v3 uses a score threshold of 0.5 (requests below this are rejected).

CAPTCHA is validated in `BookingSecurityService::validateRequest()` before the booking is processed.

### Rate Limiting

Two complementary rate limits protect against abuse:

**Email-based:** Maximum bookings per email address per day (default: 5). Counts non-cancelled reservations created today.

**IP-based:** Maximum bookings per IP address per day (default: 10). Uses Craft's cache with a 24-hour sliding window.

Configure rate limits in **Settings → Booked → Security**.

Rate limit checks run in `BookingService::createReservation()` and return specific error reasons (`email_rate_limit` or `ip_rate_limit`).

**Per-service customer limits** can also be configured on individual services to restrict how many bookings one customer can make for that service on a given date.

### Honeypot Protection

A hidden form field traps spam bots. Enabled by default with field name `website`:

```php
'enableHoneypot' => true,
'honeypotFieldName' => 'website',
```

If the honeypot field contains any value, the submission is rejected as spam. Customize the field name to match your form markup.

### CSRF Protection

CSRF validation is enabled by default on all booking controllers via Craft's built-in token validation. Disable only in development:

```php
'enableCsrfValidation' => true, // Default; disable only in devMode
```

A production warning is raised if CSRF is disabled outside of `devMode`.

### Soft Locks (Race Condition Prevention)

Soft locks temporarily reserve time slots while a customer completes a booking form, preventing double-booking in concurrent sessions.

**How it works:**

1. When a customer selects a time slot, a soft lock is created (default: 5 minutes)
2. Other customers see the slot as unavailable during the lock period
3. The lock token is sent with the booking form submission
4. On booking creation, the system checks for conflicting locks (excluding the customer's own lock)
5. Expired locks are automatically cleaned up

Configure the lock duration in **Settings → Booked → Booking**.

The booking service also uses a database-level mutex lock (`Craft::$app->getMutex()->acquire()`) during reservation creation to prevent race conditions at the database level.

### Webhook Signatures

Webhook payloads are signed with HMAC-SHA256. See [WEBHOOKS.md](WEBHOOKS.md) for signature format, verification examples, and payload details.

### Audit Logging

When `enableAuditLog` is enabled, security events are logged to `@storage/logs/booked-audit.log`:

- Rate limit triggers (`email_rate_limit`, `ip_rate_limit`)
- Failed token authorization (`invalid_cancel_token`, `invalid_update_token`)
- CAPTCHA failures (`captcha_failed`, `captcha_missing`)
- Honeypot triggers (`honeypot_triggered`)

### Staff Permissions & Managed Employees

Booked supports a role-based staff model where a Craft user linked to an Employee record can view and manage bookings for their own employee and any additional employees assigned to them.

#### Concepts

| Concept | Description |
|---------|-------------|
| **Employee.userId** | 1:1 link between an Employee and a Craft user — "this employee IS this user". Enforced unique (one user per employee). |
| **Managed Employees** | Additional employees whose bookings a staff employee can view/manage. Configured on the employee edit page via the "Managed Employees" field. |
| **Staff member** | A Craft user with `booked-viewBookings` but NOT `booked-manageBookings`, linked to at least one Employee. Sees only their own + managed employees' bookings. |
| **Supervisor/Admin** | A Craft user with `booked-manageBookings` or admin status. Sees all bookings. |

#### How It Works

1. A Craft user account is linked to an Employee via `userId` (1:1, set on the employee edit page)
2. On that employee's edit page, additional employees are assigned under **Managed Employees**
3. When the staff user logs in, `PermissionService` resolves their visible employees:
   - The employee they ARE (via `userId`)
   - All employees assigned in **Managed Employees**
4. Booking queries, the calendar, and the dashboard are automatically scoped to those employees

This means you only need a few Craft user accounts for staff — each staff employee can manage multiple other employees who don't need their own accounts.

#### Craft Permissions

| Permission | Effect |
|------------|--------|
| `booked-viewBookings` | Can view bookings (scoped to own employees if not a manager) |
| `booked-manageBookings` | Full access to all bookings (no scoping) |
| `booked-manageEmployees` | Can edit employee records and manage assignments |

#### PermissionService API

```php
use anvildev\booked\Booked;

$permissionService = Booked::getInstance()->getPermission();

// Get all employees the current user can manage
$employees = $permissionService->getEmployeesForCurrentUser();

// Check if the current user is a staff member (scoped access)
$isStaff = $permissionService->isStaffMember();

// Get employee IDs for query scoping (null = full access)
$employeeIds = $permissionService->getStaffEmployeeIds();

// Automatically scope a reservation query
$query = $permissionService->scopeReservationQuery($reservationQuery);
```

#### Database: `booked_employee_managers`

Junction table linking a staff employee (manager) to the employees they oversee:

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Primary key |
| `employeeId` | int (FK → elements.id) | The staff employee (manager) |
| `managedEmployeeId` | int (FK → elements.id) | The employee being managed |
| `dateCreated` | datetime | |
| `dateUpdated` | datetime | |
| `uid` | string | |

Unique index on `(employeeId, managedEmployeeId)`.

## Twig API

The Booked plugin provides a comprehensive Twig API for building custom booking interfaces in your templates.

### Element Queries

Query employees, services, locations, and reservations using Craft's element query syntax:

```twig
{# Query services #}
{% set services = craft.booked.services()
    .enabled()
    .orderBy('title ASC')
    .all() %}

{# Query employees #}
{% set employees = craft.booked.employees()
    .locationId(location.id)
    .serviceId(service.id)
    .enabled()
    .status(null)
    .all() %}

{# Query locations #}
{% set locations = craft.booked.locations()
    .enabled()
    .all() %}

{# Query reservations #}
{% set reservations = craft.booked.reservations()
    .bookingDate('2025-12-26')
    .status('confirmed')
    .employeeId(employee.id)
    .locationId(location.id)
    .serviceId(service.id)
    .orderBy('startTime ASC')
    .all() %}
```

### Availability Methods

Get available time slots for booking:

```twig
{# Get available slots with simple date #}
{% set slots = craft.booked.getAvailableSlots('2025-01-15') %}

{# Get available slots with filters #}
{% set slots = craft.booked.getAvailableSlots({
    date: '2025-01-15',
    serviceId: service.id,
    employeeId: employee.id,
    locationId: location.id,
    requestedQuantity: 1,
    userTimezone: 'America/New_York'
}) %}

{# Check if a specific slot is available #}
{% if craft.booked.isSlotAvailable(
    '2025-01-15',
    '14:00',
    '15:00',
    employee.id,
    location.id,
    service.id,
    1
) %}
    {# Slot is available #}
{% endif %}

{# Get next available date #}
{% set nextDate = craft.booked.getNextAvailableDate() %}

{# Get availability calendar for date range #}
{% set calendar = craft.booked.getAvailabilityCalendar('2025-01-01', '2025-01-31') %}
```

### Helper Methods

Common helper methods for working with bookings:

```twig
{# Check if service is bookable #}
{% if craft.booked.isServiceBookable(service) %}
    {# Service has employees or its own schedule #}
{% endif %}

{# Get employee schedules #}
{% set schedules = craft.booked.getEmployeeSchedules(employee.id) %}

{# Get service employees #}
{% set employees = craft.booked.getServiceEmployees(service.id) %}

{# Get location employees #}
{% set employees = craft.booked.getLocationEmployees(location.id) %}

{# Check if employee is available on a date #}
{% if craft.booked.isEmployeeAvailable(employee.id, '2025-01-15') %}
    {# Employee has schedules for this date #}
{% endif %}

{# Get upcoming reservations #}
{% set upcoming = craft.booked.getUpcomingReservations(10) %}

{# Get booking statistics #}
{% set stats = craft.booked.getStats() %}

{# Get plugin settings #}
{% set settings = craft.booked.getSettings() %}

{# Get currency code #}
{% set currency = craft.booked.getCurrency() %}
```

### Formatting Helpers

Use Twig's built-in filters and the Twig variable methods for formatting booking data:

```twig
{# Format duration using service properties #}
{{ service.duration }} min

{# Format time using Craft's date/time filters #}
{{ slot.time|date('g:i A') }}
{# Output: "2:00 PM" #}

{# Format currency using the Twig variable #}
{{ craft.booked.getCurrency() }} {{ service.price|number_format(2) }}
{# Output: "CHF 50.00" #}

{# Format booking date #}
{{ reservation.bookingDate|date('l, M j') }}
{# Output: "Monday, Jan 15" #}

{# Format booking status (translated) #}
{{ reservation.getStatusLabel() }}
{# Output: "Confirmed" (translated label) #}
```

### Complete Example: Custom Booking Form

```twig
{# Get services #}
{% set services = craft.booked.services().enabled().all() %}

<form action="{{ actionUrl('booked/booking/create-booking') }}" method="post">
    {{ csrfInput() }}

    {# Service selection #}
    <select name="serviceId" required>
        <option value="">Select a service</option>
        {% for service in services %}
            <option value="{{ service.id }}">
                {{ service.title }} - {{ service.duration }} min - {{ craft.booked.getCurrency() }} {{ service.price|number_format(2) }}
            </option>
        {% endfor %}
    </select>

    {# Get employees for selected service #}
    {% set employees = craft.booked.getServiceEmployees(service.id) %}
    {% if employees|length > 0 %}
        <select name="employeeId">
            <option value="">Any available</option>
            {% for employee in employees %}
                <option value="{{ employee.id }}">{{ employee.title }}</option>
            {% endfor %}
        </select>
    {% endif %}

    {# Date selection #}
    <input type="date" name="date" required min="{{ 'now'|date('Y-m-d') }}">

    {# Get available slots (via JavaScript/AJAX) #}
    <div id="available-slots"></div>

    {# Customer information #}
    <input type="text" name="userName" placeholder="Your Name" required>
    <input type="email" name="userEmail" placeholder="Your Email" required>
    <input type="tel" name="userPhone" placeholder="Your Phone">

    <button type="submit">Book Appointment</button>
</form>

{# Example: Display upcoming bookings #}
<h2>Upcoming Bookings</h2>
{% set upcoming = craft.booked.getUpcomingReservations(5) %}
{% if upcoming|length > 0 %}
    <ul>
        {% for reservation in upcoming %}
            <li>
                <strong>{{ reservation.service.title }}</strong>
                on {{ reservation.bookingDate|date('l, M j') }}
                at {{ reservation.startTime|date('g:i A') }}
                with {{ reservation.employee.title }}
                - Status: {{ reservation.getStatusLabel() }}
            </li>
        {% endfor %}
    </ul>
{% else %}
    <p>No upcoming bookings.</p>
{% endif %}
```

### Customer Account Portal

#### Built-in Portal

The plugin ships with a ready-to-use account portal at the following routes (all require login):

| Route | Description |
|-------|-------------|
| `/booked/account` | Dashboard with stats and upcoming bookings |
| `/booked/account/bookings` | All bookings list |
| `/booked/account/upcoming` | Upcoming bookings only |
| `/booked/account/past` | Past bookings only |
| `/booked/account/{id}` | Single booking detail view |

These routes render the plugin's built-in templates located at `src/templates/frontend/account/`. They work out of the box for quick prototyping, but most projects will want a custom implementation that matches the site's design.

#### Customizing the Portal

You have two options:

1. **Copy & restyle the built-in templates** — Copy the plugin's `frontend/account/` templates to your site's `templates/` directory and modify them to match your layout and design.

2. **Build from scratch** — Use the Twig variables below to create fully custom account pages with complete control over markup, styling, and URL structure.

#### Available Twig Methods

```twig
{# Check if user is logged in (native Craft variable) #}
{% if currentUser %}

    {# Access user info via native Craft variable #}
    {{ currentUser.email }}
    {{ currentUser.fullName }}
    {{ currentUser.firstName }}

    {# Get all bookings for current user (returns query) #}
    {% set allBookings = craft.booked.myBookings().all() %}

    {# Get upcoming bookings (convenience method) #}
    {% set upcoming = craft.booked.myUpcomingBookings(5) %}

    {# Get past bookings (convenience method) #}
    {% set past = craft.booked.myPastBookings(10) %}

    {# Get booking count #}
    {% set totalBookings = craft.booked.myBookingCount() %}

{% endif %}
```

#### Custom Query with forCurrentUser()

For more control, use the `forCurrentUser()` query method:

```twig
{# Query with custom filters #}
{% set confirmedBookings = craft.booked.reservations()
    .forCurrentUser()
    .status('confirmed')
    .orderBy('booked_reservations.bookingDate DESC')
    .all() %}

{# Get bookings for a specific service #}
{% set massageBookings = craft.booked.reservations()
    .forCurrentUser()
    .serviceId(5)
    .all() %}

{# Upcoming bookings with custom date filter #}
{% set nextWeek = craft.booked.reservations()
    .forCurrentUser()
    .andWhere(['>=', 'booked_reservations.bookingDate', 'now'|date('Y-m-d')])
    .andWhere(['<=', 'booked_reservations.bookingDate', 'now'|date_modify('+7 days')|date('Y-m-d')])
    .all() %}
```

#### Complete Custom Account Page Example

```twig
{# templates/account/bookings.twig #}
{% extends "_layout" %}

{% requireLogin %}

{% block content %}
    <h1>My Bookings</h1>

    <p>Welcome back, {{ currentUser.fullName ?? currentUser.email }}!</p>

    {# Stats #}
    <div class="booking-stats">
        <div>Total: {{ craft.booked.myBookingCount() }}</div>
        <div>Upcoming: {{ craft.booked.myUpcomingBookings(100)|length }}</div>
    </div>

        {# Upcoming Bookings #}
        <h2>Upcoming</h2>
        {% set upcoming = craft.booked.myUpcomingBookings(10) %}
        {% if upcoming|length %}
            {% for booking in upcoming %}
                <div class="booking-card">
                    <h3>{{ booking.service.title ?? 'Booking' }}</h3>
                    <p>{{ booking.getFormattedDateTime() }}</p>
                    {% if booking.employee %}
                        <p>with {{ booking.employee.title }}</p>
                    {% endif %}
                    <span class="status status--{{ booking.status }}">
                        {{ booking.getStatusLabel() }}
                    </span>

                    {# Action buttons #}
                    <a href="{{ booking.getIcsUrl() }}">Add to Calendar</a>
                    <a href="{{ booking.getManagementUrl() }}">Manage</a>

                    {% if booking.canBeCancelled() %}
                        <form method="post">
                            {{ csrfInput() }}
                            {{ actionInput('booked/account/cancel') }}
                            {{ hiddenInput('id', booking.id) }}
                            {{ redirectInput(craft.app.request.url) }}
                            <button type="submit" onclick="return confirm('Cancel this booking?')">
                                Cancel
                            </button>
                        </form>
                    {% endif %}
                </div>
            {% endfor %}
        {% else %}
            <p>No upcoming bookings. <a href="/book">Book now</a></p>
        {% endif %}

        {# Past Bookings #}
        <h2>Past Bookings</h2>
        {% set past = craft.booked.myPastBookings(5) %}
        {% for booking in past %}
            <div class="booking-card booking-card--past">
                <p>{{ booking.service.title ?? 'Booking' }} - {{ booking.bookingDate }}</p>
            </div>
        {% endfor %}

    {% endblock %}
```

#### Pre-fill Booking Form with User Data

```twig
{# On your booking page #}
{% if currentUser %}
    <input type="hidden" name="userName" value="{{ currentUser.fullName }}">
    <input type="hidden" name="userEmail" value="{{ currentUser.email }}">
    {# Phone field depends on your user field layout #}
{% endif %}
```

#### JavaScript: Check Login Status

```javascript
// Check if user is logged in via AJAX
fetch('/actions/booked/account/current-user', {
    headers: { 'Accept': 'application/json' }
})
.then(response => response.json())
.then(data => {
    if (data.loggedIn) {
        // Pre-fill form fields
        document.querySelector('[name="userName"]').value = data.user.name;
        document.querySelector('[name="userEmail"]').value = data.user.email;
        if (data.user.phone) {
            document.querySelector('[name="userPhone"]').value = data.user.phone;
        }
    }
});
```

#### User-Linked Bookings

When a logged-in user creates a booking, it's automatically linked to their account via `userId`. This allows:

- Querying all bookings by user (even if email changes)
- Fallback to email matching for legacy bookings
- User-specific booking history in the account portal

**Employee user linking** is separate from customer user linking. An Employee's `userId` field links the employee to a Craft user account (1:1), enabling that user to log in as staff and view their employee's bookings. See [Staff Permissions & Managed Employees](#staff-permissions--managed-employees) for details on how staff users can manage multiple employees.

### JavaScript/AJAX Example: Fetch Available Slots

```javascript
// Fetch available slots when service/date changes
function fetchAvailableSlots(serviceId, employeeId, locationId, date) {
    const params = new URLSearchParams();
    params.append('date', date);
    if (serviceId) params.append('serviceId', serviceId);
    if (employeeId) params.append('employeeId', employeeId);
    if (locationId) params.append('locationId', locationId);

    fetch(`/actions/booked/slot/get-available-slots?${params.toString()}`, {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.slots) {
            const slotsContainer = document.getElementById('available-slots');
            slotsContainer.innerHTML = data.slots.map(slot => `
                <label>
                    <input type="radio" name="startTime" value="${slot.time}" required>
                    ${slot.time} - ${slot.endTime}
                    ${slot.employeeName ? `(${slot.employeeName})` : ''}
                </label>
            `).join('');
        }
    });
}
```

### Pre-built Templates

The plugin provides pre-built templates for quick implementation:

```twig
{# Render booking wizard #}
{{ craft.booked.getWizard() }}

{# Render booking form with options #}
{{ craft.booked.getForm({
    title: 'Book Your Appointment',
    text: 'Select your preferred date and time',
    viewMode: 'wizard'
}) }}

{# Render event wizard #}
{% include 'booked/frontend/event-wizard' %}
```

### Customizing Wizard Appearance

Both the booking wizard and event wizard support three levels of CSS customization:

#### 1. CSS Variable Overrides (`customStyles`)

The fastest way to retheme the wizard. Pass a `customStyles` object to override design tokens directly on the wizard element:

```twig
{# Booking wizard with custom colors #}
{{ craft.booked.getWizard({
    customStyles: {
        '--bk-black': '#1e3a5f',
        '--bk-white': '#f8fafc',
        '--bk-muted': '#64748b',
        '--bk-border-light': '#cbd5e1',
        '--bk-green': '#059669',
    }
}) }}

{# Event wizard with custom colors #}
{% include 'booked/frontend/event-wizard' with {
    customStyles: {
        '--bk-black': '#7c3aed',
        '--bk-red': '#e11d48',
    }
} %}
```

**Available CSS tokens:**

| Token | Default | Description |
|-------|---------|-------------|
| `--bk-black` | `#000000` | Primary color — buttons, borders, headings, hover fills |
| `--bk-white` | `#ffffff` | Background color for cards, inputs, wizard body |
| `--bk-dark` | `#1a1a1a` | Slightly lighter than black — secondary text |
| `--bk-hover` | `#333333` | Hover state for interactive elements |
| `--bk-muted` | `#666666` | Secondary text, labels, descriptions |
| `--bk-placeholder` | `#999999` | Placeholder text, disabled headings |
| `--bk-disabled` | `#cccccc` | Disabled borders, inactive elements |
| `--bk-border-light` | `#e0e0e0` | Light borders, dividers |
| `--bk-bg-light` | `#f0f0f0` | Light background areas |
| `--bk-bg-lighter` | `#f5f5f5` | Lighter background areas |
| `--bk-bg-soft` | `#fafafa` | Softest background tone |
| `--bk-red` | `#dc2626` | Error states, cancellation badges |
| `--bk-red-dark` | `#991b1b` | Dark red for hover states |
| `--bk-red-bg` | `#fef2f2` | Light red background for error messages |
| `--bk-green` | `#16a34a` | Success states, available indicators |
| `--bk-green-dark` | `#166534` | Dark green for hover states |
| `--bk-green-bg` | `#f0fdf4` | Light green background |
| `--bk-green-hover` | `#dcfce7` | Green hover state |

**Example: Dark theme**

```twig
{{ craft.booked.getWizard({
    customStyles: {
        '--bk-black': '#e2e8f0',
        '--bk-white': '#0f172a',
        '--bk-dark': '#cbd5e1',
        '--bk-muted': '#94a3b8',
        '--bk-placeholder': '#64748b',
        '--bk-disabled': '#475569',
        '--bk-border-light': '#334155',
        '--bk-bg-light': '#1e293b',
        '--bk-bg-lighter': '#1e293b',
        '--bk-bg-soft': '#0f172a',
    }
}) }}
```

**Example: Brand color accent**

```twig
{# Only override --bk-black to change the accent color #}
{{ craft.booked.getWizard({
    customStyles: {
        '--bk-black': '#4f46e5',
    }
}) }}
```

#### 2. Wrapper Class (`cssWrapperClass`)

Add a custom class to the wizard root element for targeted CSS overrides:

```twig
{{ craft.booked.getWizard({
    cssWrapperClass: 'my-custom-wizard'
}) }}
```

```css
/* Your stylesheet */
.my-custom-wizard .booked-card {
    border-radius: 12px;
}
.my-custom-wizard .booked-slot {
    font-family: 'My Custom Font', sans-serif;
}
```

#### 3. CSS Prefix (`cssPrefix`)

Replace the default `booked` class prefix entirely. Useful when embedding multiple wizards with different styles on the same page:

```twig
{% include 'booked/frontend/event-wizard' with {
    cssPrefix: 'my-events'
} %}
```

This changes all class names from `booked-wizard`, `booked-card`, etc. to `my-events-wizard`, `my-events-card`, etc. You must provide your own CSS for all classes when using a custom prefix.

### Template Variables

The `craft.booked` variable provides access to all booking functionality.

## Commerce Integration

Booked supports Craft Commerce for paid bookings. When enabled, reservations become purchasable line items that go through Commerce's cart and checkout flow.

### Enabling Commerce

Enable Commerce in **Settings → Booked → Commerce**.

Both conditions must be true for Commerce features to activate:
1. `commerceEnabled` setting is `true`
2. The `craft-commerce` plugin is installed and enabled

Check at runtime:

```php
Booked::getInstance()->getSettings()->isCommerceEnabled(); // bool
Booked::getInstance()->getSettings()->canUseCommerce();    // alias
```

### The ReservationFactory Pattern

The plugin uses a factory pattern to transparently switch between two Reservation implementations:

| Mode | Class | Base | When |
|------|-------|------|------|
| Commerce | `elements\Reservation` | `Element` + `PurchasableInterface` | Commerce enabled |
| Standard | `models\ReservationModel` | `Model` | Commerce disabled |

Both implement `ReservationInterface`, so all service code works identically regardless of mode.

```php
use anvildev\booked\factories\ReservationFactory;

// Create — returns Element or Model based on settings
$reservation = ReservationFactory::create(['userName' => 'Jane']);

// Query — returns ReservationQuery or ReservationModelQuery
$reservations = ReservationFactory::find()->status('confirmed')->all();

// Lookup
$reservation = ReservationFactory::findById(123);
$reservation = ReservationFactory::findByToken('abc-def-ghi');

// Check current mode
ReservationFactory::isElementMode();      // true if Commerce
ReservationFactory::isActiveRecordMode(); // true if no Commerce
```

> **Important:** Always use `ReservationFactory` instead of instantiating `Reservation` or `ReservationModel` directly. This ensures your code works in both modes.

### Booking Flow: Standard vs Commerce

**Standard flow** (Commerce disabled or free booking):

```
Customer submits booking
  → Reservation created with status = 'confirmed'
  → Confirmation email sent immediately
```

**Commerce flow** (Commerce enabled and total price > 0):

```
Customer submits booking
  → Reservation created with status = 'pending'
  → Reservation added to Commerce cart as line item
  → Customer redirected to cart or checkout
  → Customer completes payment
  → Order::EVENT_AFTER_COMPLETE_ORDER fires
  → Booked listener updates status: 'pending' → 'confirmed'
  → Confirmation email sent
```

The controller decides which flow to use automatically:

```php
// Internal logic in BookingController::actionCreateBooking()
// Total price = service price + selected extras prices
$useCommerce = $settings->canUseCommerce() && $totalPrice > 0;
```

Free bookings (total = 0) always use the standard flow, even when Commerce is enabled. A free service with paid extras will trigger the Commerce flow.

### Purchasable Implementation

When Commerce is enabled, the `Reservation` element implements `PurchasableInterface`:

```php
class Reservation extends Element implements PurchasableInterface, ReservationInterface
```

Key methods:

| Method | Returns | Description |
|--------|---------|-------------|
| `getPrice()` | `float` | Service price × quantity + extras total |
| `getSku()` | `string` | `'BOOKING-123'` (reservation ID) |
| `getDescription()` | `string` | `'Massage - 26.12.2025 at 14:00'` |
| `getStore()` | `Store` | Store for the reservation's site |
| `getIsShippable()` | `false` | Bookings don't ship |
| `hasFreeShipping()` | `true` | No shipping costs |
| `getSnapshot()` | `array` | Immutable booking data for order history |

#### Price Calculation

```php
// Reservation::getPrice()
$total = $service->price * $quantity;

foreach ($extras as $extra) {
    $total += $extra['totalPrice']; // extra.price × extra.quantity
}

return $total;
```

Example: Service (€100) × qty 2 + Extra A (€30 × 1) + Extra B (€15 × 2) = **€260**

#### Tax Categories

Tax categories determine how bookings are taxed. The resolution order is:

1. **Service-level** — `taxCategoryId` set on the Service element
2. **Global fallback** — `commerceTaxCategoryId` in plugin settings (Settings → Commerce)
3. **Commerce default** — the default tax category configured in Craft Commerce

```php
// In Reservation::getTaxCategory()
// 1. Check service override
$service->taxCategoryId  // e.g. "Wellness Services" tax category

// 2. Check global plugin setting
$settings->commerceTaxCategoryId  // e.g. "Services" tax category

// 3. Fall back to Commerce default
Commerce::getInstance()->getTaxCategories()->getDefaultTaxCategory();
```

Configure per-service in the CP under the service edit screen (only visible when Commerce is installed). Configure the global fallback under Settings → Commerce.

> **Note:** Extras (add-ons) share the service's tax category. The entire booking — service + extras — is a single Commerce line item.

#### Line Item Population

When added to the cart, Commerce calls `populateLineItem()`:

```php
public function populateLineItem(LineItem $lineItem): void
{
    $lineItem->price = $this->getPrice();
    $lineItem->sku = $this->getSku();
    $lineItem->description = $this->getDescription();
}
```

### CommerceService

The `CommerceService` handles cart operations and order-reservation linking:

```php
$commerceService = Booked::getInstance()->getCommerce();

// Add reservation to current cart
$success = $commerceService->addReservationToCart($reservation);

// Look up linked reservation/order
$reservation = $commerceService->getReservationByOrderId($orderId);
$order = $commerceService->getOrderByReservationId($reservationId);

// Manual linking (rarely needed)
$commerceService->linkOrderToReservation($orderId, $reservationId);
```

The junction table `{{%booked_order_reservations}}` stores the order ↔ reservation relationship with a unique constraint on `(orderId, reservationId)`.

### Order Completion Listener

Booked registers a listener on `Order::EVENT_AFTER_COMPLETE_ORDER` that confirms pending reservations when payment completes:

```php
// Registered automatically in Booked::init() when Commerce is enabled
Event::on(
    Order::class,
    Order::EVENT_AFTER_COMPLETE_ORDER,
    function(Event $event) {
        $order = $event->sender;
        $reservation = $this->commerce->getReservationByOrderId($order->id);

        if ($reservation) {
            $reservation->status = 'confirmed';
            Craft::$app->elements->saveElement($reservation);
        }
    }
);
```

### Status Flow

```
Standard:   creation → confirmed
Commerce:   creation → pending → confirmed (after payment)
                               → cancelled (by user/admin)
```

The `pending` status is exclusive to the Commerce flow. In the standard flow, reservations are confirmed immediately on creation.

### Cart Abandonment & Slot Availability

Pending reservations in unpaid Commerce carts **block availability** — other customers cannot book the same time slot while it is in someone's cart. This prevents double-booking during the payment window.

Stale pending reservations are cleaned up automatically via Craft's garbage collection (`Gc::EVENT_RUN`). The `MaintenanceService` handles two cases:

| Scenario | Action |
|----------|--------|
| Commerce purged the cart/order | Reservation cancelled, order link removed |
| Cart inactive for configured expiration period | Reservation cancelled, slot released |

The expiration period is controlled by the `pendingCartExpirationHours` setting (default: 48 hours), configurable in **Settings → Booked → Commerce**. When a pending reservation is cancelled, the time slot becomes available again for other customers.

Each auto-cancelled reservation is logged in the audit trail with the reason (e.g. "Commerce cart inactive for more than 48 hours").

> **Note:** Commerce's default `purgeInactiveCartsDuration` is 90 days. The plugin's cleanup runs independently and much sooner, keeping the reservations table clean without waiting for Commerce to purge.

### Cancellations & Refunds

When a Commerce booking is cancelled, the `AfterBookingCancelEvent` includes payment context:

```php
Event::on(
    BookingService::class,
    BookingService::EVENT_AFTER_BOOKING_CANCEL,
    function(AfterBookingCancelEvent $event) {
        if (!$event->success || !$event->wasPaid) {
            return;
        }

        // Look up the Commerce order
        $order = Booked::getInstance()
            ->getCommerce()
            ->getOrderByReservationId($event->reservation->getId());

        if ($order) {
            // Implement your refund logic here
            // Commerce refund API, partial refund, store credit, etc.
        }
    }
);
```

The plugin fires the event with `wasPaid` and `shouldRefund` flags but does **not** process refunds automatically — this is left to your implementation since refund policies vary.

### Frontend: Cart vs Checkout

The booking wizard supports two Commerce submission modes:

```javascript
// In the wizard's Alpine.js component
async addToCart() {
    this.addToCartOnly = true;    // sends addToCart=1
    await this.submitBooking();   // redirects to cart
}

async proceedToCheckout() {
    this.addToCartOnly = false;   // sends addToCart=0
    await this.submitBooking();   // redirects to checkout
}
```

The controller response includes Commerce-specific data:

```json
{
    "success": true,
    "commerce": {
        "addedToCart": true,
        "cartUrl": "/shop/cart",
        "checkoutUrl": "/shop/checkout",
        "cartItemCount": 2
    },
    "redirectToCheckout": true,
    "redirectUrl": "/shop/checkout"
}
```

### Conditional Element Registration

The `Reservation` element type is only registered with Craft when Commerce is enabled:

```php
// In Booked::registerElementTypes()
if ($this->isCommerceEnabled()) {
    $event->types[] = Reservation::class;
}
```

When Commerce is disabled, `ReservationModel` is used instead — it doesn't register as an element type, avoiding overhead in the elements table for high-volume booking scenarios.

### Configuration Reference

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `commerceEnabled` | `bool` | `false` | Enable Commerce integration |
| `commerceTaxCategoryId` | `?int` | `null` | Global fallback tax category ID; overridden by per-service setting |
| `pendingCartExpirationHours` | `int` | `48` | Hours before an unpaid cart reservation is cancelled and its slot released (1–168) |
| `commerceCartUrl` | `string` | `'shop/cart'` | Relative URL path for the Commerce cart page; used for post-booking redirects |
| `commerceCheckoutUrl` | `string` | `'shop/checkout'` | Relative URL path for the Commerce checkout page; used for post-booking redirects |

Both `commerceEnabled = true` **and** Commerce plugin installed are required. If Commerce is uninstalled, the setting is safely ignored and the standard flow is used.

Tax category priority: Service `taxCategoryId` → Global `commerceTaxCategoryId` → Commerce default.

## Multi-Site Support

Booked has two categories of elements with different multi-site behavior. Understanding this distinction is critical when querying elements in custom code.

### Site-Aware Elements (Localized)

**Service**, **ServiceExtra**, and **EventDate** override `isLocalized()` to return `true` and support Craft's propagation methods:

| Propagation Method | Behavior |
|---|---|
| `None` | Exists on one site only |
| `All` | Propagates to all sites |
| `SiteGroup` | Propagates within the same site group |
| `Language` | Propagates to sites with the same language |

These elements have translatable fields (title, description) and will return site-specific content when queried with a site ID.

### Non-Site-Aware Elements

**Employee**, **Location**, **Reservation**, **BlackoutDate**, and **Schedule** do NOT override `isLocalized()`. Craft stores them on the primary site by default.

> **Note:** Waitlist entries are not Craft Elements — they are stored as plain `WaitlistRecord` ActiveRecord rows and are not affected by site scoping.

**This has a critical implication:** when querying these elements from a non-primary site, Craft's default site scoping returns no results. You **must** use `->siteId('*')` to search across all sites:

```php
// ❌ WRONG — returns nothing on non-primary sites
$employee = Employee::find()->id($employeeId)->one();

// ✅ CORRECT — works from any site
$employee = Employee::find()->siteId('*')->id($employeeId)->one();
```

All internal services (AvailabilityService, BookingService, CapacityService, ScheduleResolverService, PermissionService, etc.) already apply `->siteId('*')` when querying non-localized elements. If you write custom queries against these elements, you must do the same.

### ElementQueryHelper

The plugin provides `ElementQueryHelper` for standardized site filtering:

```php
use anvildev\booked\helpers\ElementQueryHelper;

// Search across all sites (use for Employee, Location, Reservation, etc.)
ElementQueryHelper::forAllSites($query);   // ->siteId('*')

// Current site only (use for Service when you want localized content)
ElementQueryHelper::forCurrentSite($query);

// Specific site
ElementQueryHelper::forSite($query, $siteId);
```

### Email Language

Emails render in the language of the site where the booking originated. The `EmailRenderService` temporarily switches `Craft::$app->language` to the booking's site language before rendering templates, then restores it. This ensures customers receive emails in the correct language regardless of which site the queue worker runs on.

### Quick Reference

| Element | Localized | Needs `siteId('*')` | Has propagation |
|---|---|---|---|
| Service | Yes | No (site-scoped by default) | Yes |
| ServiceExtra | Yes | No (site-scoped by default) | Yes |
| EventDate | Yes | No (site-scoped by default) | Yes |
| Employee | No | **Yes** | No |
| Location | No | **Yes** | No |
| Reservation | No | **Yes** | No |
| Schedule | No | **Yes** | No |
| BlackoutDate | No | **Yes** | No |

## Console Commands

Booked provides 20+ CLI commands for diagnostics, email previews, data management, and more.

See **[CONSOLE_COMMANDS.md](CONSOLE_COMMANDS.md)** for the full reference.

## Scheduled Tasks (Cron Jobs)

Booked requires two cron jobs for full functionality, plus an optional one for low-traffic sites.

### Required

**1. Process the Craft queue** — emails, SMS, webhooks, and calendar sync all run asynchronously:

```bash
*/5 * * * * php /path/to/craft queue/run
```

Without this, booking confirmations, reminders, and webhook notifications won't be delivered. Alternatively, use a persistent queue daemon (`queue/listen`).

**2. Send appointment reminders** — checks for upcoming bookings within the reminder window and queues email/SMS notifications:

```bash
*/15 * * * * php /path/to/craft booked/reminders/queue
```

Reminders are flag-guarded (`emailReminder24hSent` / `smsReminder24hSent`), so running frequently is safe — each reminder is only sent once. The window is configured via `emailReminderHoursBefore` (default: 24) and `smsReminderHoursBefore` (default: 24) in plugin settings.

### Recommended

**3. Force Craft garbage collection** — triggers `MaintenanceService::runAll()` which handles:

| Task | What it cleans up |
|------|-------------------|
| Expired soft locks | Abandoned slot reservations in `booked_soft_locks` |
| Expired waitlist entries | Entries past `waitlistExpirationDays` (default: 30) |
| Old webhook logs | Logs older than `webhookLogRetentionDays` (default: 30) |
| Stale Commerce carts | Pending reservations with abandoned carts past `pendingCartExpirationHours` (default: 48) |
| Expired OAuth tokens | Stale OAuth state tokens (1-hour TTL) |
| Expired calendar invites | Old calendar invite records (7+ days past expiry) |

```bash
0 * * * * php /path/to/craft gc
```

Craft fires GC probabilistically during web requests (~1 in 100,000), so on high-traffic sites this cron may be unnecessary. On low-traffic sites, this ensures cleanup actually runs.

> **Note:** Soft locks also self-clean on every new lock creation, so they won't block availability even without GC.

### Minimal production setup

```bash
# /etc/cron.d/booked (adjust paths to your environment)
*/5  * * * * www-data php /path/to/craft queue/run
*/15 * * * * www-data php /path/to/craft booked/reminders/queue
0    * * * * www-data php /path/to/craft gc
```

## Best Practices

### 1. Use Events for Custom Logic

Don't modify core plugin files. Use events instead:

```php
// ❌ Bad
class BookingService extends Component
{
    public function createReservation(array $data): ?Reservation
    {
        // Modified core method
        $this->sendToCustomCRM($data); // Don't do this
        // ...
    }
}

// ✅ Good
Event::on(
    BookingService::class,
    BookingService::EVENT_AFTER_BOOKING_SAVE,
    function($event) {
        $this->sendToCustomCRM($event->reservation);
    }
);
```

### 2. Optimize Queries

Use eager loading for related elements:

```php
// ❌ Bad (N+1 problem)
$reservations = Reservation::find()->all();
foreach ($reservations as $reservation) {
    echo $reservation->getService()->title; // Extra query per reservation
}

// ✅ Good
$reservations = Reservation::find()
    ->with(['service', 'employee', 'location'])
    ->all();

foreach ($reservations as $reservation) {
    echo $reservation->service->title; // No extra queries
}
```

## Configuration Reference

All settings are configured through **Settings → Booked** in the Craft control panel.

### General

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `defaultCurrency` | `?string` | `null` | ISO 4217 currency code; auto-detects from Commerce or falls back to CHF |
| `softLockDurationMinutes` | `int` | `5` | Soft lock duration in minutes for race condition prevention |
| `minimumAdvanceBookingHours` | `int` | `0` | Minimum hours before appointment that booking is allowed |
| `maximumAdvanceBookingDays` | `int` | `90` | Maximum days in advance a booking can be made |
| `cancellationPolicyHours` | `int` | `24` | Hours before appointment that cancellation is allowed. Set to `0` to allow cancellation at any time. |
| `enableVirtualMeetings` | `bool` | `false` | Enable virtual meeting functionality globally |
| `defaultTimeSlotLength` | `?int` | `null` | Default time slot length in minutes; `null` uses service duration |
| `commerceEnabled` | `bool` | `false` | Enable Craft Commerce integration for paid bookings |
| `commerceTaxCategoryId` | `?int` | `null` | Default tax category for bookings; can be overridden per service |
| `pendingCartExpirationHours` | `int` | `48` | Hours before an unpaid cart reservation is cancelled and its slot released (1–168) |
| `commerceCartUrl` | `string` | `'shop/cart'` | Relative URL path for the Commerce cart page |
| `commerceCheckoutUrl` | `string` | `'shop/checkout'` | Relative URL path for the Commerce checkout page |

### Security

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `enableRateLimiting` | `bool` | `true` | Enable rate limiting for booking submissions |
| `rateLimitPerEmail` | `int` | `5` | Maximum bookings per email per day |
| `rateLimitPerIp` | `int` | `10` | Maximum bookings per IP per day |
| `enableCaptcha` | `bool` | `false` | Enable CAPTCHA verification |
| `captchaProvider` | `?string` | `null` | `'recaptcha'`, `'hcaptcha'`, or `'turnstile'` |
| `recaptchaSiteKey` | `?string` | `null` | Google reCAPTCHA v3 site key |
| `recaptchaSecretKey` | `?string` | `null` | Google reCAPTCHA v3 secret key |
| `hcaptchaSiteKey` | `?string` | `null` | hCaptcha site key |
| `hcaptchaSecretKey` | `?string` | `null` | hCaptcha secret key |
| `turnstileSiteKey` | `?string` | `null` | Cloudflare Turnstile site key |
| `turnstileSecretKey` | `?string` | `null` | Cloudflare Turnstile secret key |
| `enableHoneypot` | `bool` | `true` | Enable honeypot spam protection |
| `honeypotFieldName` | `string` | `'website'` | Hidden field name for honeypot trap |
| `enableCsrfValidation` | `bool` | `true` | Enable CSRF validation on booking forms |
| `enableIpBlocking` | `bool` | `false` | Enable IP address blocking |
| `blockedIps` | `?string` | `null` | JSON-encoded array of blocked IPs |
| `enableTimeBasedLimits` | `bool` | `true` | Enable minimum time between form submissions |
| `minimumSubmissionTime` | `int` | `3` | Minimum seconds between form submissions |
| `enableAuditLog` | `bool` | `false` | Enable security audit logging |

### Email Notifications

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `ownerNotificationEnabled` | `bool` | `true` | Send notification emails to owner on new bookings |
| `ownerEmail` | `?string` | `null` | Owner email (falls back to Craft's `fromEmail`) |
| `ownerName` | `?string` | `null` | Owner name (falls back to Craft's `fromName`) |
| `ownerNotificationSubject` | `?string` | `null` | Custom owner notification subject |
| `bookingConfirmationSubject` | `?string` | `null` | Custom booking confirmation subject |
| `reminderEmailSubject` | `?string` | `null` | Custom reminder email subject |
| `cancellationEmailSubject` | `?string` | `null` | Custom cancellation email subject |
| `emailRemindersEnabled` | `bool` | `true` | Send email reminders to customers |
| `emailReminderHoursBefore` | `int` | `24` | Hours before appointment to send reminder |
| `sendCancellationEmail` | `bool` | `true` | Send cancellation email to customer |

### SMS Notifications (Twilio)

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `smsEnabled` | `bool` | `false` | Enable SMS notifications |
| `smsProvider` | `?string` | `null` | SMS provider (`'twilio'`) |
| `twilioAccountSid` | `?string` | `null` | Twilio Account SID |
| `twilioAuthToken` | `?string` | `null` | Twilio Auth Token |
| `twilioPhoneNumber` | `?string` | `null` | Twilio sending phone number |
| `smsRemindersEnabled` | `bool` | `false` | Send SMS reminders |
| `smsReminderHoursBefore` | `int` | `24` | Hours before appointment for SMS reminder |
| `smsConfirmationEnabled` | `bool` | `false` | Send SMS booking confirmations |
| `smsCancellationEnabled` | `bool` | `false` | Send SMS cancellation notifications |
| `smsConfirmationTemplate` | `?string` | `null` | Custom SMS confirmation template |
| `smsReminderTemplate` | `?string` | `null` | Custom SMS reminder template |
| `smsCancellationTemplate` | `?string` | `null` | Custom SMS cancellation template |
| `smsMaxRetries` | `int` | `3` | Maximum SMS retry attempts |
| `defaultCountryCode` | `?string` | `'US'` | Default country code for phone normalization |

### Google Calendar

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `googleCalendarEnabled` | `bool` | `false` | Enable Google Calendar sync |
| `googleCalendarClientId` | `?string` | `null` | OAuth 2.0 Client ID |
| `googleCalendarClientSecret` | `?string` | `null` | OAuth 2.0 Client Secret |

### Microsoft Outlook Calendar

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `outlookCalendarEnabled` | `bool` | `false` | Enable Outlook Calendar sync |
| `outlookCalendarClientId` | `?string` | `null` | OAuth 2.0 Client ID |
| `outlookCalendarClientSecret` | `?string` | `null` | OAuth 2.0 Client Secret |

### Zoom

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `zoomEnabled` | `bool` | `false` | Enable Zoom meetings |
| `zoomAccountId` | `?string` | `null` | Zoom Account ID (Server-to-Server OAuth) |
| `zoomClientId` | `?string` | `null` | OAuth Client ID |
| `zoomClientSecret` | `?string` | `null` | OAuth Client Secret |

### Google Meet

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `googleMeetEnabled` | `bool` | `false` | Enable Google Meet |

### Webhooks

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `webhooksEnabled` | `bool` | `false` | Enable webhooks |
| `webhookTimeout` | `int` | `30` | HTTP timeout in seconds |
| `webhookLogEnabled` | `bool` | `true` | Enable webhook delivery logging |
| `webhookLogRetentionDays` | `int` | `30` | Days to retain webhook logs |

### Waitlist

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `enableWaitlist` | `bool` | `true` | Enable waitlist for fully booked slots |
| `waitlistExpirationDays` | `int` | `30` | Days before waitlist entries expire (0 = never) |
| `waitlistNotificationLimit` | `int` | `10` | Max customers to notify per available slot |

### Sensitive Settings

The following settings are excluded from Craft's project config and stored only in the database to protect credentials:

- `googleCalendarClientSecret`
- `outlookCalendarClientSecret`
- `zoomClientSecret`
- `twilioAuthToken`

### Helper Methods

The `Settings` model provides convenience methods for checking feature availability:

```php
$settings = Booked::getInstance()->getSettings();

$settings->isCommerceEnabled();          // Commerce installed and enabled?
$settings->isGoogleCalendarConfigured(); // Google Calendar credentials present?
$settings->isOutlookCalendarConfigured();
$settings->isZoomConfigured();
$settings->isSmsConfigured();            // Twilio credentials present?

$settings->canUseCommerce();             // Commerce available and ready?
$settings->canUseCalendarSync();         // Any calendar sync available?
$settings->canUseVirtualMeetings();      // Any virtual meeting provider?
$settings->canUseWebhooks();

$settings->getEffectiveEmail();          // Owner email or Craft's fromEmail
$settings->getEffectiveName();           // Owner name or Craft's fromName
```

## Resources

- [Event System Documentation](EVENT_SYSTEM.md) - Complete event reference
- [Availability System](AVAILABILITY.md) - Availability calculation details
- [GraphQL Schema Reference](GRAPHQL.md) - Full GraphQL schema
- [Webhook Configuration](WEBHOOKS.md) - Webhook setup and payload formats
- [SMS Notifications](SMS_NOTIFICATIONS.md) - Twilio SMS setup
- [Craft CMS Documentation](https://craftcms.com/docs) - Craft CMS reference

