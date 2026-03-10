# Event-Based Bookings

The Booked plugin supports **event-based bookings**, allowing you to create one-time events that customers can register for. This is perfect for workshops, seminars, classes, and other events that have a specific date and time.

## Overview

Event-based bookings differ from regular service bookings in several ways:

- **Fixed Date & Time**: Events have a specific date and time that cannot be changed by customers
- **Capacity Management**: Events can have a maximum capacity (number of registrations)
- **Standalone Events**: Events are independent and can optionally be linked to a location
- **Element-Based**: Events are Craft CMS elements, making them easy to manage in the Control Panel
- **Multi-Site Propagation**: Events support Craft's propagation methods for sharing across sites

## Creating Event Dates

### In the Control Panel

1. Navigate to **Booked → Event Dates** in the Craft control panel
2. Click **"New Event Date"**
3. Fill in the event details:
   - **Enabled**: Toggle to enable/disable the event
   - **Title**: Required. The name of the event (e.g., "Yoga Workshop", "Product Launch")
   - **Description**: Optional description of the event
   - **Event Date**: The date when the event occurs
   - **Start Time**: When the event starts
   - **End Time**: When the event ends
   - **Location**: (Optional) Set the location where the event takes place
   - **Capacity**: (Optional) Maximum number of registrations. Leave empty for unlimited capacity

4. Click **"Save"**

### Multi-Site Propagation

On multi-site Craft installations, the event edit page shows a **Propagation Method** dropdown at the top. This controls how the event is shared across your sites:

| Option | Behavior |
|--------|----------|
| **Only save to the site it was created in** | Default. Event exists on one site only. |
| **Save to all sites** | Event is propagated to every site in the installation. |
| **Save to sites in the same site group** | Event is propagated to sites sharing the same site group. |
| **Save to sites with the same language** | Event is propagated to sites with the same language. |

When an event is propagated to multiple sites, a **site selector** appears in the action button area allowing you to switch between site-specific versions of the event (e.g. to translate the title/description).

This works identically to the propagation system on Services and Service Extras.

### Field Explanations

#### Location Field
- **Purpose**: Sets the physical or virtual location where the event takes place
- **Use Case**: When the event has a specific venue (e.g., "Main Studio", "Conference Room A")
- **Benefits**:
  - Displays location information to customers
  - Filters events by location
  - Useful for multi-location businesses

### Example Use Cases

1. **Workshop with Location**: "Yoga Workshop"
   - Title: "Yoga Workshop with Jane Doe"
   - Location: "Main Studio"
   - Capacity: 20

2. **Standalone Event**: "Company Holiday Party"
   - Title: "Company Holiday Party 2026"
   - Description: "Annual celebration at HQ"
   - No location needed

3. **Seminar**: "Product Launch Webinar"
   - Title: "Product Launch Webinar"
   - Description: "Join us for the unveiling of our new product line"
   - Capacity: 100

## Frontend Booking Flow

When customers book through the frontend, they can select from available events:

1. **Step 1**: Customer views available events
2. **Step 2**: Customer selects an event to book
3. **Step 3+**: Continue with the booking flow (customer details, confirmation, etc.)

The event's date and time are automatically used for the booking, so customers don't need to select a date/time manually.

## Managing Event Dates

### Viewing Events

Navigate to **Booked → Event Dates** to see all events in a table view. You can:
- Filter by status (enabled/disabled)
- Sort by title, date, or date created
- See capacity and booked count
- View location associations

### Editing Events

Click on any event in the list to edit it. You can:
- Update event details
- Change capacity
- Enable/disable the event
- View booking count and remaining capacity

### Deleting Events

Events can only be deleted if they have no reservations. This prevents accidental data loss.

## Capacity Management

### Unlimited Capacity

If you leave the **Capacity** field empty, the event has unlimited capacity. Customers can register without restrictions.

### Limited Capacity

When you set a capacity (e.g., 20), the system will:
- Track how many people have registered
- Show remaining capacity in the Control Panel
- Prevent overbooking
- Display "Fully Booked" status when capacity is reached

### Viewing Capacity

In the event edit page, you'll see:
- **Booked**: Number of confirmed registrations
- **Total**: Maximum capacity (if set)
- **Remaining**: Available spots (if capacity is set)

## How Event Booking Differs from Regular Booking

When a booking includes an `eventDateId`, the `BookingService::createBooking()` pipeline behaves differently from regular service bookings:

| Step | Regular Booking | Event Booking |
|------|----------------|---------------|
| **Validation** | Checks `bookingDate` + `startTime` are present | Calls `prepareEventBookingData()` — populates date/time from the EventDate element, then `validateEventCapacity()` checks remaining spots |
| **Soft Lock** | Checks `SoftLockService::isLocked()` | Skipped — capacity is mutex-protected at the transaction level |
| **Employee Assignment** | Auto-assigns an available employee if none specified | Skipped — events are not employee-dependent |
| **Availability Check** | Calls `AvailabilityService::isSlotAvailable()` | Skipped — event capacity is validated in `validateEventCapacity()` via `EventDate::getRemainingCapacity()` |
| **Capacity** | Based on employee/service schedule slots | Based on `EventDate.capacity` minus confirmed reservation quantities |

### Capacity Validation

Event capacity is checked in `prepareEventBookingData()` and `validateEventCapacity()`:
1. `prepareEventBookingData()` loads the `EventDate` element by ID and populates date/time fields
2. Checks the event is enabled and in the future
3. `validateEventCapacity()` calls `EventDate::getRemainingCapacity()` which counts existing reservations (using raw database queries)
4. Compares remaining capacity against the requested `quantity`
5. Throws `BookingValidationException` if insufficient capacity

### Quantity Support

Customers can book **multiple spots** in a single event reservation by specifying a `quantity` parameter. The quantity is validated against remaining capacity and stored on the reservation. This allows group registrations (e.g., booking 3 seats for a workshop).

## Integration with Regular Bookings

Event-based bookings share infrastructure with the regular booking system:

- **Same Reservation System**: Event bookings create regular `Reservation` records
- **Same Notifications**: Event bookings trigger the same email notifications
- **Same Calendar Sync**: Event bookings sync to employee calendars only if an employee is explicitly assigned to the reservation
- **Same Commerce Integration**: Event bookings can be paid through Craft Commerce

## API Usage

### Getting Events

```php
use anvildev\booked\Booked;

$eventDateService = Booked::getInstance()->eventDate;

// Get all events
$events = $eventDateService->getEventDates();

// Get events within a date range
$events = $eventDateService->getEventDates($dateFrom, $dateTo);

// Get available events (not fully booked, in the future)
$availableEvents = $eventDateService->getAvailableEventDates();

// Get a specific event
$event = $eventDateService->getEventDateById($eventId);
```

### Checking Capacity

```php
// Get booked count
$bookedCount = $eventDateService->getBookedCount($eventId);

// Check if fully booked
$isFullyBooked = $event->isFullyBooked();

// Get remaining capacity
$remaining = $event->getRemainingCapacity();
```

### Creating Event Bookings

When creating a booking, include the `eventDateId`:

```php
use anvildev\booked\Booked;

$bookingService = Booked::getInstance()->booking;

$reservation = $bookingService->createBooking([
    'eventDateId' => 123, // The event date ID
    'userName' => 'John Doe',
    'userEmail' => 'john@example.com',
    // ... other booking data
]);
```

## Twig Templates

### Displaying Events

```twig
{# Get all enabled future events #}
{% set events = craft.booked.eventDates().all() %}

{% for event in events %}
    <div class="event">
        <h3>{{ event.title }}</h3>
        <p>{{ event.description }}</p>
        <p>
            <strong>Date:</strong> {{ event.getFormattedDate() }}<br>
            <strong>Time:</strong> {{ event.getFormattedTimeRange() }}
        </p>
        {% if event.getLocation() %}
            <p><strong>Location:</strong> {{ event.getLocation().title }}</p>
        {% endif %}
        {% if event.capacity %}
            <p>
                {% set remaining = event.getRemainingCapacity() %}
                {% if remaining is not same as(null) %}
                    {{ event.capacity - remaining }} / {{ event.capacity }} booked
                    ({{ remaining }} remaining)
                {% endif %}
            </p>
        {% endif %}
        {% if event.isFullyBooked() %}
            <p class="fully-booked">Fully Booked</p>
        {% else %}
            <a href="{{ url('bookings/new', {eventDateId: event.id}) }}">Register</a>
        {% endif %}
    </div>
{% endfor %}
```

### Checking Event Status

```twig
{% if event.enabled %}
    {# Event is active #}
{% endif %}

{% if event.isFullyBooked() %}
    {# Event is fully booked #}
{% endif %}

{% set remaining = event.getRemainingCapacity() %}
{% if remaining is not same as(null) and remaining > 0 %}
    {# Event has available spots #}
{% endif %}
```

## Best Practices

1. **Use Descriptive Titles**: Make event titles clear and descriptive (e.g., "Yoga Workshop - January 2025" instead of "Workshop")

2. **Set Capacity Early**: If you know the capacity limit, set it when creating the event to prevent overbooking

3. **Use Descriptions**: Add descriptions to help customers understand what the event is about

4. **Monitor Capacity**: Regularly check event capacity in the Control Panel to see how many spots are remaining

5. **Disable Past Events**: Disable events that have passed to keep your event list clean

## Troubleshooting

### Event Not Showing in Frontend

- Check that the event is **enabled**
- Verify the event date is in the future
- Check that the event hasn't reached capacity
- On multi-site installations, check the event's **propagation method** — if set to "Only save to the site it was created in", it won't appear on other sites

### Capacity Not Updating

- Capacity is calculated in real-time based on reservations (using raw database queries)
- Pending reservations (Commerce payment flow) may not count toward capacity depending on status
- The `quantity` field on each reservation is summed, so a single reservation for 3 spots counts as 3
- Check the event edit page to see the current booked count

### "Insufficient Capacity" When Capacity Seems Available

- Verify the event's `capacity` field is set correctly
- Check if there are reservations with `quantity > 1` consuming multiple spots
- Event bookings do **not** use normal employee/schedule availability — capacity is solely based on the EventDate's capacity field

### Event Can't Be Deleted

- Events with reservations cannot be deleted
- Cancel or delete all reservations first, then delete the event
- Alternatively, disable the event instead of deleting it

## Related Documentation

- [Availability & Schedule System](AVAILABILITY.md) - How availability works
- [Developer Guide](DEVELOPER_GUIDE.md) - API reference
- [Event System](EVENT_SYSTEM.md) - Event hooks for custom logic
