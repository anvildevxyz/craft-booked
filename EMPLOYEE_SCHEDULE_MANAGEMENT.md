# Schedule Management - Developer Guide

## Overview

Schedules in Booked are now full Craft CMS Elements, enabling:
- **Reusable schedules** - Define once, assign to multiple employees
- **Many-to-many relationships** - Employees can have multiple schedules, schedules can be shared
- **Proper Craft infrastructure** - Element queries, CP management, GraphQL support
- **Priority-based resolution** - When an employee has multiple schedules, the system resolves the active one based on date ranges and sort order

## Architecture

### Database Schema

**`booked_schedules`** - Element content table
- `id` - Links to `elements.id`
- `workingHours` - JSON with day-by-day schedule data
- `startDate` - Optional validity period start
- `endDate` - Optional validity period end

**`booked_employee_schedule_assignments`** - Employee pivot table
- `employeeId` - FK to `elements.id` (Employee)
- `scheduleId` - FK to `booked_schedules.id`
- `sortOrder` - Priority order for schedule resolution

**`booked_service_schedule_assignments`** - Service pivot table
- `serviceId` - FK to `booked_services.id`
- `scheduleId` - FK to `booked_schedules.id`
- `sortOrder` - Priority order for schedule resolution

### Element Class

**Location**: `src/elements/Schedule.php`

The Schedule element includes:
- `workingHours` - Array of working hours per day (1-7, Monday-Sunday)
- `startDate` - Optional start date for the schedule's validity
- `endDate` - Optional end date for the schedule's validity
- `getAssignedEmployees()` - Returns employees using this schedule
- `getWorkingHoursForDay($dayOfWeek)` - Get hours for a specific day
- `isActiveOn($date)` - Check if schedule is active on a given date

## Working Hours Format

Working hours are stored as a JSON object with day numbers (1-7, Monday-Sunday):

```json
{
    "1": {
        "enabled": true,
        "start": "09:00",
        "end": "17:00",
        "breakStart": "12:00",
        "breakEnd": "13:00"
    },
    "2": {
        "enabled": true,
        "start": "09:00",
        "end": "17:00",
        "breakStart": null,
        "breakEnd": null
    }
}
```

## Querying Schedules

### Basic Element Query

```php
use anvildev\booked\elements\Schedule;

// Get all schedules
$schedules = Schedule::find()->all();

// Get enabled schedules only
$schedules = Schedule::find()->enabled(true)->all();

// Get schedules assigned to a specific employee
$schedules = Schedule::find()->employeeId($employeeId)->all();

// Get schedules active on a specific date
$schedules = Schedule::find()->activeOn('2025-03-15')->all();
```

### Twig Examples

```twig
{# Get all schedules #}
{% set schedules = craft.booked.schedules().all() %}

{# Get schedules for an employee #}
{% set employeeSchedules = craft.booked.schedules().employeeId(employee.id).all() %}

{# Display schedule working hours #}
{% for schedule in schedules %}
    <h3>{{ schedule.title }}</h3>
    {% for day in 1..7 %}
        {% set hours = schedule.getWorkingHoursForDay(day) %}
        {% if hours %}
            <p>Day {{ day }}: {{ hours.start }} - {{ hours.end }}</p>
        {% endif %}
    {% endfor %}
{% endfor %}
```

## Assigning Schedules to Employees

### Using the Assignment Service

```php
use anvildev\booked\Booked;

$assignmentService = Booked::getInstance()->scheduleAssignment;

// Assign a schedule to an employee
$assignmentService->assignScheduleToEmployee($scheduleId, $employeeId, $sortOrder);

// Unassign a schedule
$assignmentService->unassignScheduleFromEmployee($scheduleId, $employeeId);

// Get all schedules for an employee (ordered by sortOrder)
$schedules = $assignmentService->getSchedulesForEmployee($employeeId);

// Get all employees using a schedule
$schedule = Schedule::find()->id($scheduleId)->one();
$employees = $schedule->getAssignedEmployees();
```

### Via Employee Element

```php
// Get schedules for an employee
$employee = Employee::find()->id($employeeId)->one();
$schedules = $employee->getSchedules();
```

## Schedule Resolution

When calculating availability, the `ScheduleAssignmentService` determines which schedule applies using a **date specificity tier** system:

### Priority Tiers

| Tier | Condition | Example |
|------|-----------|---------|
| 1 (highest) | Both `startDate` AND `endDate` defined | "Summer Hours (Jun 1 – Aug 31)" |
| 2 | Only `startDate` OR `endDate` defined | "From March onwards" |
| 3 (lowest) | Neither date defined | "Default Hours" (forever) |

Within the same tier, lower `sortOrder` wins.

### For Employees

```php
use anvildev\booked\Booked;

$assignmentService = Booked::getInstance()->scheduleAssignment;

// Get the active schedule for an employee on a specific date
$schedule = $assignmentService->getActiveScheduleForDate($employeeId, '2025-03-15');

if ($schedule) {
    $hours = $schedule->getWorkingHoursForDay(6); // Saturday
}
```

### For Services

Services can also have their own schedules, independent of employees:

```php
// Get the active schedule for a service on a specific date
$schedule = $assignmentService->getActiveScheduleForServiceOnDate($serviceId, '2025-03-15');
```

### Assigning Schedules to Services

```php
// Set schedules for a service (replaces all existing assignments)
$assignmentService->setSchedulesForService($serviceId, [$scheduleId1, $scheduleId2]);

// Get all schedules for a service
$schedules = $assignmentService->getSchedulesForService($serviceId);
```

## GraphQL API

### Queries

```graphql
# Get all schedules
query {
    bookedSchedules {
        id
        title
        startDate
        endDate
        workingHours
    }
}

# Get schedules for a specific employee
query {
    bookedSchedules(employeeId: 123) {
        id
        title
        workingHours
    }
}

# Get schedules active on a date
query {
    bookedSchedules(activeOn: "2025-03-15") {
        id
        title
    }
}

# Get a single schedule
query {
    bookedSchedule(id: 456) {
        id
        title
        startDate
        endDate
        workingHours
    }
}

# Count schedules
query {
    bookedScheduleCount(employeeId: 123)
}
```

### Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | Int | Element ID |
| `title` | String | Schedule name |
| `startDate` | String | Validity start date (YYYY-MM-DD) |
| `endDate` | String | Validity end date (YYYY-MM-DD) |
| `workingHours` | JSON | Working hours configuration |
| `enabled` | Boolean | Whether the schedule is enabled |

## Control Panel Management

### Routes

- `/admin/booked/schedules` - Schedule listing
- `/admin/booked/schedules/new` - Create new schedule
- `/admin/booked/schedules/{id}` - Edit schedule

### Permissions

Schedule management uses the existing `booked-manageEmployees` permission.

For staff booking access, see the [Staff Access & Managed Employees](CONFIGURATION.md#staff-access--managed-employees) section. Staff users (linked to an Employee via `userId`) can view bookings for their own employee and any employees assigned via the **Managed Employees** field.

## Best Practices

1. **Create reusable schedules** - Instead of duplicating schedules, create named schedules like "Standard Hours", "Summer Hours", "Part-Time" and assign them to multiple employees

2. **Use date ranges** - For seasonal or temporary schedules, set start/end dates so they automatically apply during the correct period

3. **Order by priority** - When an employee has multiple schedules, order them so more specific schedules (with date ranges) come before general schedules

4. **Clear naming** - Use descriptive titles like "Winter Hours (Nov-Feb)" rather than generic names

## Related Documentation

- [Availability System](AVAILABILITY.md) - How schedules affect availability calculation
- [GraphQL API](GRAPHQL.md) - Full GraphQL schema reference
- [Developer Guide](DEVELOPER_GUIDE.md) - API and service usage
