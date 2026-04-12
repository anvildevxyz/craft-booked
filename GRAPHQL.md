# GraphQL API

The Booked plugin integrates with Craft CMS's native GraphQL system.

## Setup

1. Go to **Settings → GraphQL**, create or edit a schema
2. Enable the Booked queries under the "Booked" section:
   - Query services (`bookedServices:read`)
   - Query reservations (`bookedReservations:read`)
   - Query employees (`bookedEmployees:read`)
   - Query service extras (`bookedServiceExtras:read`)
   - Query locations (`bookedLocations:read`)
   - Query event dates (`bookedEventDates:read`)
   - Query blackout dates (`bookedBlackoutDates:read`)
   - Query schedules (`bookedSchedules:read`)
   - Query reports (`bookedReports:read`)
3. Optionally create a token at **Settings → GraphQL → Tokens** for API access

Schedule queries require the `bookedSchedules:read` permission. Report queries require `bookedReports:read`.

Endpoint: `POST /api` — or use the GraphiQL IDE at `/admin/graphql`.

### Pagination

All queries support standard Craft element arguments for pagination and ordering:

```graphql
query {
  bookedReservations(limit: 10, offset: 20, orderBy: "bookingDate DESC") {
    id
    bookingDate
    userName
  }
}

# Count total for building pagination UI
query { bookedReservationCount(status: ["confirmed"]) }
```

## Queries

### Services

```graphql
query {
  bookedServices {
    id
    title
    slug
    description
    duration
    durationType
    pricingMode
    price
    bufferBefore
    bufferAfter
    virtualMeetingProvider
    minTimeBeforeBooking
    timeSlotLength
    locationIds
  }
}

# Single service
query { bookedService(id: 5) { id title description duration price locationIds } }

# Filter by location
query { bookedServices(locationId: 5) { id title duration price } }

# Count
query { bookedServiceCount }
```

**Filter arguments:** `duration` (Int), `price` (Float), `locationId` (Int), plus standard Craft element arguments (`limit`, `offset`, `orderBy`, `id`, `uid`, `status`, etc.).

`durationType` is `minutes`, `days`, or `flexible_days`. For day-based types, `duration` is the fixed day count (`days`); `minDays` / `maxDays` are not yet exposed on the GraphQL service type—use the REST `booking-data/get-services` payload or element APIs if you need them in headless builds.

### Reservations

```graphql
query {
  bookedReservations {
    id
    bookingDate
    endDate
    isMultiDay
    durationDays
    startTime
    endTime
    userName
    userEmail
    userPhone
    userTimezone
    status
    quantity
    notes
    serviceId
    employeeId
    locationId
    eventDateId
    virtualMeetingUrl
    virtualMeetingProvider
  }
}

# Single reservation
query { bookedReservation(id: 123) { id userName status } }

# Count
query { bookedReservationCount }
```

**Filter arguments:** `status` ([String]), `bookingDate` ([String]), `endDate` ([String]), `serviceId` ([Int]), `employeeId` ([Int]), `locationId` ([Int]), `userId` ([Int]), plus standard Craft element arguments.

### Employees

```graphql
query {
  bookedEmployees {
    id
    title
    userId
    locationId
  }
}

# Single / Count
query { bookedEmployee(id: 3) { id title } }
query { bookedEmployeeCount }
```

**Filter arguments:** `userId` (Int), `locationId` (Int), `serviceId` (Int), plus standard Craft element arguments.

### Service Extras

```graphql
query {
  bookedServiceExtras {
    id
    title
    description
    price
    duration
    maxQuantity
    isRequired
    sortOrder
    enabled
    dateCreated
    dateUpdated
  }
}

# Filter by service
query { bookedServiceExtras(serviceId: 5, enabled: true) { id title price } }

# Single extra
query { bookedServiceExtra(id: 12) { id title price } }
```

**Filter arguments:** `serviceId` (Int), `enabled` (Boolean).

### Schedules

```graphql
query {
  bookedSchedules {
    id
    title
    startDate
    endDate
    workingHours
  }
}

# Filter by employee or active date
query { bookedSchedules(employeeId: 123) { id title workingHours } }
query { bookedSchedules(activeOn: "2025-03-15") { id title } }

# Single / Count
query { bookedSchedule(id: 456) { id title workingHours } }
query { bookedScheduleCount(employeeId: 123) }
```

**Filter arguments:** `employeeId` (Int), `activeOn` (String), plus standard Craft element arguments.

### Locations

```graphql
query {
  bookedLocations {
    id
    title
    timezone
    addressLine1
    addressLine2
    locality
    administrativeArea
    postalCode
    countryCode
  }
}

# Single location
query { bookedLocation(id: 10) { id title timezone } }

# Count
query { bookedLocationCount }
```

**Filter arguments:** `timezone` (String), plus standard Craft element arguments.

### Event Dates

```graphql
query {
  bookedEventDates {
    id
    title
    locationId
    eventDate
    endDate
    startTime
    endTime
    description
    capacity
    price
    enabled
  }
}

# Filter by location
query { bookedEventDates(locationId: 5) { id title eventDate startTime endTime } }

# Single / Count
query { bookedEventDate(id: 20) { id title capacity price } }
query { bookedEventDateCount(enabled: true) }
```

**Filter arguments:** `locationId` (Int), `eventDate` (String), `endDate` (String), `startTime` (String), `endTime` (String), `enabled` (Boolean), plus standard Craft element arguments.

### Blackout Dates

```graphql
query {
  bookedBlackoutDates {
    id
    title
    startDate
    endDate
    isActive
    locationIds
    employeeIds
  }
}

# Filter by active status
query { bookedBlackoutDates(isActive: true) { id startDate endDate } }

# Filter by location or employee
query { bookedBlackoutDates(locationId: [5, 10]) { id startDate endDate } }
query { bookedBlackoutDates(employeeId: [3]) { id startDate endDate } }

# Single / Count
query { bookedBlackoutDate(id: 15) { id startDate endDate isActive } }
query { bookedBlackoutDateCount(isActive: true) }
```

**Filter arguments:** `startDate` (String), `endDate` (String), `isActive` (Boolean), `locationId` ([Int]), `employeeId` ([Int]), plus standard Craft element arguments.

### Report Summary

Requires `bookedReports:read` permission.

```graphql
query {
  bookedReportSummary(startDate: "2026-01-01", endDate: "2026-01-31") {
    totalBookings
    confirmedBookings
    cancelledBookings
    cancellationRate
    totalRevenue
    averageBookingValue
    newCustomers
    returningCustomers
    startDate
    endDate
  }
}
```

**Arguments:** `startDate` (String, defaults to first of current month), `endDate` (String, defaults to last of current month).

## Types

### BookedService

| Field | Type | Description |
|-------|------|-------------|
| id | Int! | Service ID |
| title | String | Service title |
| slug | String | Service slug |
| description | String | Service description |
| duration | Int | Minutes when `durationType` is `minutes`; fixed day count when `durationType` is `days` |
| durationType | String | `minutes`, `days`, or `flexible_days` |
| pricingMode | String | `flat` or `per_unit` (per-unit day services multiply price by stay length) |
| price | Float | Service price |
| bufferBefore | Int | Buffer before booking (minutes for `minutes`; **days** for day-based services—same field, different unit) |
| bufferAfter | Int | Buffer after booking (same unit rule as `bufferBefore`) |
| virtualMeetingProvider | String | Virtual meeting provider (`zoom`, `google_meet`) |
| minTimeBeforeBooking | Int | Minimum time before booking (minutes) |
| timeSlotLength | Int | Time slot length (minutes) |
| locationIds | [Int] | IDs of directly assigned locations |

### BookedReservation

| Field | Type | Description |
|-------|------|-------------|
| id | Int! | Reservation ID |
| userName | String | Customer name |
| userEmail | String | Customer email |
| userId | Int | Linked Craft user ID |
| userPhone | String | Customer phone |
| userTimezone | String | Customer timezone |
| bookingDate | String | Booking date (YYYY-MM-DD); for multi-day, first day of stay |
| endDate | String | Inclusive last day for multi-day bookings; null for single-day |
| isMultiDay | Boolean! | True when `endDate` is set |
| durationDays | Int | Inclusive day count for multi-day; null for single-day |
| startTime | String | Start time (HH:MM); null for multi-day |
| endTime | String | End time (HH:MM); null for multi-day |
| status | String | `pending`, `confirmed`, `cancelled`, `completed` |
| notes | String | Customer notes |
| quantity | Int | Number of spots booked |
| serviceId | Int | Service ID |
| employeeId | Int | Employee ID |
| locationId | Int | Location ID |
| eventDateId | Int | Event date ID (for event bookings) |
| virtualMeetingUrl | String | Virtual meeting URL |
| virtualMeetingProvider | String | Virtual meeting provider |

### BookedEmployee

| Field | Type | Description |
|-------|------|-------------|
| id | Int! | Employee ID |
| title | String | Employee name |
| userId | Int | Linked Craft user ID |
| locationId | Int | Assigned location ID |

### ServiceExtra

| Field | Type | Description |
|-------|------|-------------|
| id | Int! | Extra ID |
| title | String! | Display name |
| description | String | Description |
| price | Float! | Additional cost |
| duration | Int! | Additional duration (minutes) |
| maxQuantity | Int! | Maximum quantity per booking |
| isRequired | Boolean! | Whether required |
| sortOrder | Int! | Display order |
| enabled | Boolean! | Whether enabled |
| dateCreated | String | Creation date |
| dateUpdated | String | Last update date |

### BookedSchedule

| Field | Type | Description |
|-------|------|-------------|
| id | Int! | Schedule ID |
| title | String | Schedule name |
| startDate | String | Validity start date (YYYY-MM-DD) |
| endDate | String | Validity end date (YYYY-MM-DD) |
| workingHours | String | Working hours by day (JSON-encoded string — parse with `JSON.parse()` on the client) |

### BookedLocation

| Field | Type | Description |
|-------|------|-------------|
| id | Int! | Location ID |
| title | String | Location name |
| timezone | String | Timezone identifier (e.g. Europe/Zurich) |
| addressLine1 | String | Address line 1 |
| addressLine2 | String | Address line 2 |
| locality | String | City |
| administrativeArea | String | State/province |
| postalCode | String | Postal code |
| countryCode | String | Country code |

### BookedEventDate

| Field | Type | Description |
|-------|------|-------------|
| id | Int! | Event date ID |
| title | String | Event title |
| locationId | Int | Associated location ID |
| eventDate | String | Event date (YYYY-MM-DD) |
| endDate | String | End date for multi-day events |
| startTime | String | Start time (HH:MM) |
| endTime | String | End time (HH:MM) |
| description | String | Event description |
| capacity | Int | Maximum capacity |
| price | Float | Event price |
| enabled | Boolean | Whether the event is enabled |

### BookedBlackoutDate

| Field | Type | Description |
|-------|------|-------------|
| id | Int! | Blackout date ID |
| title | String | Blackout date title |
| startDate | String | Start date (YYYY-MM-DD) |
| endDate | String | End date (YYYY-MM-DD) |
| isActive | Boolean | Whether the blackout is active |
| locationIds | [Int] | Associated location IDs |
| employeeIds | [Int] | Associated employee IDs |

### BookedReportSummary

| Field | Type | Description |
|-------|------|-------------|
| totalBookings | Int | Total number of bookings in the period |
| confirmedBookings | Int | Number of confirmed bookings |
| cancelledBookings | Int | Number of cancelled bookings |
| cancellationRate | Float | Cancellation rate as a decimal (e.g. 0.15) |
| totalRevenue | Float | Total revenue in the period |
| averageBookingValue | Float | Average booking value |
| newCustomers | Int | Number of new customers |
| returningCustomers | Int | Number of returning customers |
| startDate | String | Period start date (YYYY-MM-DD) |
| endDate | String | Period end date (YYYY-MM-DD) |

## Mutations

All mutations return `{ success, reservation { ... }, errors { field, message, code } }`.

The `token` parameter is the reservation's confirmation token, used for IDOR protection. It is returned in the `createBookedReservation` response.

### createBookedReservation

```graphql
mutation {
  createBookedReservation(input: {
    serviceId: "5"
    bookingDate: "2026-06-15"
    startTime: "14:00"
    userName: "John Doe"
    userEmail: "john@example.com"
    userPhone: "+41791234567"
    quantity: 1
    notes: "First visit"
  }) {
    success
    reservation { id bookingDate startTime status }
    errors { field message code }
  }
}

# Multi-day day-based service: omit startTime; set inclusive endDate
mutation {
  createBookedReservation(input: {
    serviceId: "12"
    bookingDate: "2026-06-10"
    endDate: "2026-06-12"
    userName: "Jane Doe"
    userEmail: "jane@example.com"
  }) {
    success
    reservation { id bookingDate endDate isMultiDay durationDays status }
    errors { field message code }
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| serviceId | ID | Yes | The service to book |
| bookingDate | String | Yes | Date (YYYY-MM-DD); start date for multi-day |
| startTime | String | Yes* | Time (HH:MM); omit for multi-day (`endDate` set) |
| endDate | String | No | Inclusive end date for multi-day services (YYYY-MM-DD) |
| userName | String | Yes | Customer name |
| userEmail | String | Yes | Customer email |
| employeeId | ID | No | Specific employee |
| locationId | ID | No | Specific location |
| eventDateId | ID | No | Event date ID (for event bookings) |
| userPhone | String | No | Customer phone |
| userTimezone | String | No | Customer timezone |
| notes | String | No | Customer notes |
| quantity | Int | No | Number of spots (default: 1) |
| extraIds | [ID] | No | Service extra IDs |
| extraQuantities | [Int] | No | Quantities for each extra |

### updateBookedReservation

Requires `id` (ID!) and `token` (String!) for authorization.

```graphql
mutation {
  updateBookedReservation(id: "123", token: "confirmation-token", input: {
    userName: "John Smith"
    notes: "Updated notes"
  }) {
    success
    reservation { id userName notes }
    errors { message }
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| userName | String | Customer name |
| userEmail | String | Customer email |
| userPhone | String | Customer phone |
| notes | String | Customer notes |

### cancelBookedReservation

Requires `id` (ID!) and `token` (String!) for authorization. Optional `reason` (String).

```graphql
mutation {
  cancelBookedReservation(id: "123", token: "confirmation-token", reason: "Customer requested") {
    success
    reservation { id status }
    errors { message code }
  }
}
```

### reduceBookedReservationQuantity

Reduce the quantity of an existing booking. Requires `id` (ID!) and `token` (String!) for authorization.

```graphql
mutation {
  reduceBookedReservationQuantity(
    id: "123"
    token: "confirmation-token"
    reduceBy: 2
    reason: "Two guests cancelled"
  ) {
    success
    reservation { id quantity }
    errors { field message code }
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| id | ID! | Yes | The reservation ID |
| token | String! | Yes | The reservation confirmation token |
| reduceBy | Int! | Yes | The number of guests to remove |
| reason | String | No | Reason for the reduction |

### increaseBookedReservationQuantity

Increase the quantity of an existing booking. Requires `id` (ID!) and `token` (String!) for authorization.

```graphql
mutation {
  increaseBookedReservationQuantity(
    id: "123"
    token: "confirmation-token"
    increaseBy: 3
  ) {
    success
    reservation { id quantity }
    errors { field message code }
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| id | ID! | Yes | The reservation ID |
| token | String! | Yes | The reservation confirmation token |
| increaseBy | Int! | Yes | The number of additional guests |

### convertBookedWaitlistEntry

Validate a waitlist conversion token. Returns success if the token is valid and the entry can be converted to a booking.

```graphql
mutation {
  convertBookedWaitlistEntry(token: "conversion-token") {
    success
    waitlistEntryId
    errors { field message code }
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| token | String! | Yes | The waitlist conversion token |

Returns `{ success, waitlistEntryId, errors { field, message, code } }`.

### Mutation Permissions

Control mutation access in the Craft GraphQL schema editor:

- **Create reservations** (`bookedReservations:create`) — also required for `convertBookedWaitlistEntry`
- **Update reservations** (`bookedReservations:update`) — also required for `reduceBookedReservationQuantity` and `increaseBookedReservationQuantity`
- **Cancel reservations** (`bookedReservations:cancel`)

## Troubleshooting

### Queries not appearing in schema

1. Check that the queries are enabled in your GraphQL schema
2. Clear caches: `php craft clear-caches/all`

### Permission denied errors

Ensure your GraphQL schema has the Booked queries enabled under "Booked" in the schema editor.

## Related Documentation

- [Developer Guide](DEVELOPER_GUIDE.md) — Service API reference
- [Event System](EVENT_SYSTEM.md) — Plugin events
