# Console Commands

All commands are run via `php craft <command>`.

## Doctor (Health Check)

```bash
php craft booked/doctor         # Run all health checks
php craft booked/doctor --ping  # Include live API calls to Zoom, Twilio, and Microsoft Teams
```

Validates database tables (22 of 26 core tables), settings, email config, data presence (services/employees/locations/schedules), Google Calendar, Outlook Calendar, Zoom, Microsoft Teams, Twilio SMS, CAPTCHA, webhooks, and queue status. Returns exit code `0` on success, `1` on errors.

## Configuration Validation

```bash
php craft booked/config/validate
```

Deeper settings analysis complementing `booked/doctor`: runs Yii model validators, checks booking window, cancellation policy, soft lock duration, notification config, integration status, security settings (CSRF, rate limiting, CAPTCHA, honeypot), and shows effective runtime values. Returns exit code `0`/`1`.

## Availability

Debug the subtractive availability model step by step — shows working hours, existing bookings with buffers, blackouts, soft locks, remaining windows, and generated slots.

```bash
php craft booked/availability/check --service=5 --date=2026-03-01
php craft booked/availability/check --service=5 --date=2026-03-01 --employee=3 --location=2
```

| Option | Default | Description |
|--------|---------|-------------|
| `--service` | *(required)* | Service ID |
| `--date` | Today | Date (Y-m-d) |
| `--employee` | *(none)* | Employee ID |
| `--location` | *(none)* | Location ID |

## Bookings

### `booked/bookings/list`

```bash
php craft booked/bookings/list
php craft booked/bookings/list --date=2026-03-01 --status=confirmed --limit=50
```

| Option | Default | Description |
|--------|---------|-------------|
| `--date` | *(none)* | Filter by date (Y-m-d) |
| `--status` | *(none)* | `pending`, `confirmed`, `cancelled` |
| `--limit` | `20` | Max results |

### `booked/bookings/validate`

Runs a data integrity check across all bookings. Scans every reservation and reports errors and warnings:

- **Errors:** missing customer email, missing booking date, missing start/end time, references to deleted services or event dates, invalid status values
- **Warnings:** orphaned employee or location references, start time >= end time, bookings not linked to any service or event

```bash
php craft booked/bookings/validate
```

Returns exit code `0` if no errors are found (warnings are allowed), `1` if any errors are detected.

### `booked/bookings/info <id>`

Shows full booking details: customer data, service, employee, location, extras, virtual meeting links, calendar sync status, notification tracking, and timestamps.

```bash
php craft booked/bookings/info 42
```

### `booked/bookings/cancel <id>`

Cancels a booking after a confirmation prompt.

```bash
php craft booked/bookings/cancel 42 --reason="Customer requested reschedule"
```

### `booked/bookings/export`

Export bookings to stdout in CSV or JSON format.

```bash
php craft booked/bookings/export > bookings.csv
php craft booked/bookings/export --format=json --from=2026-03-01 --to=2026-03-31 --status=confirmed > march.json
```

| Option | Default | Description |
|--------|---------|-------------|
| `--format` | `csv` | `csv` or `json` |
| `--from` | *(none)* | Start date (Y-m-d) |
| `--to` | *(none)* | End date (Y-m-d) |
| `--status` | *(none)* | Filter by status |

**CSV columns:** `id`, `status`, `bookingDate`, `startTime`, `endTime`, `duration`, `quantity`, `customerName`, `customerEmail`, `customerPhone`, `service`, `serviceId`, `employee`, `employeeId`, `location`, `locationId`, `notes`, `confirmationToken`, `createdAt`

## Email

### `booked/email/list`

Lists all available email template types.

### `booked/email/preview`

Renders and sends preview emails using the most recent real reservation (or synthetic data). Subjects are prefixed with `[Preview]`.

```bash
php craft booked/email/preview --to=test@example.com
php craft booked/email/preview --type=confirmation --to=test@example.com
php craft booked/email/preview --site=de --to=test@example.com
```

| Option | Default | Description |
|--------|---------|-------------|
| `--type` | `all` | `all`, `confirmation`, `status-change`, `cancellation`, `reminder`, `owner-notification`, `waitlist-notification` |
| `--to` | Owner email | Recipient email |
| `--site` | Primary site | Site handle for language context |

### `booked/email/publish`

Copies email templates to `templates/_booked/emails/` for customization. Each published template includes a comment header listing every available variable.

```bash
php craft booked/email/publish
php craft booked/email/publish --site=de
php craft booked/email/publish --force
```

| Option | Default | Description |
|--------|---------|-------------|
| `--site` | *(none)* | Site handle for site-specific overrides |
| `--force` | `false` | Overwrite existing files |

**Template resolution order** (first match wins):

1. `templates/booked/emails/{site-handle}/{template}.twig`
2. `templates/_booked/emails/{site-handle}/{template}.twig`
3. `templates/_booked/emails/{template}.twig`
4. `templates/booked/emails/{template}.twig`
5. Plugin default (built-in)

## Reminders

```bash
php craft booked/reminders/send   # Send pending reminders synchronously
php craft booked/reminders/queue  # Queue reminders for async processing (recommended for cron)
```

## Waitlist

```bash
php craft booked/waitlist/cleanup                          # Remove expired entries
php craft booked/waitlist/stats                            # Show statistics by status
php craft booked/waitlist/list 20                           # List active entries (default: 10)
php craft booked/waitlist/notify-all 5 2025-12-26 14:00 15:00  # Notify entries for a slot
```

## SMS

```bash
php craft booked/sms/test +41791234567   # Send a test SMS to verify Twilio config
```

## Webhooks

### `booked/webhooks/test <url>`

Sends a signed sample webhook with realistic payload, HMAC-SHA256 signature, and reports the response.

```bash
php craft booked/webhooks/test https://example.com/webhook
php craft booked/webhooks/test https://example.com/webhook --event=booking.cancelled --format=flat
```

| Option | Default | Description |
|--------|---------|-------------|
| `--event` | `booking.created` | Event type to simulate |
| `--format` | `standard` | `standard` (nested) or `flat` (Zapier-optimized) |

### `booked/webhooks/cleanup-logs`

```bash
php craft booked/webhooks/cleanup-logs          # Use configured retention period
php craft booked/webhooks/cleanup-logs --days=7 # Override retention
```

### `booked/webhooks/retry-failed <logId>`

```bash
php craft booked/webhooks/retry-failed 42
```

## Users

```bash
php craft booked/users/link-bookings            # Link unlinked reservations to Craft users by email
php craft booked/users/link-bookings --dry-run  # Preview without saving
php craft booked/users/stats                    # Show user-linking statistics
```

## Testing

Commands for seeding test data and validating booking integrity. All seeded data is prefixed with `[TEST]` for safe cleanup.

```bash
php craft booked/test/seed 5000          # Seed test reservations (default: 10000)
php craft booked/test/clear              # Delete all [TEST]-prefixed elements
php craft booked/test/verify-no-doubles  # Check for overlapping confirmed reservations
php craft booked/test/benchmark 10       # Benchmark availability queries (default: 5 iterations)
php craft booked/test/security           # Run automated security and validation tests
```

The `security` command tests IDOR prevention, confirmation token strength, input sanitization (XSS/SQL injection), CSRF protection, booking model validation, advance booking limits, cancel policy enforcement, rate limiting, and email template rendering.
