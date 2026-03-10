# Email Templates

Customize the email notifications sent for booking confirmations, reminders, cancellations, and more.

## Overview

The plugin sends these email types automatically:

| Email Type | Trigger | Recipient |
|------------|---------|-----------|
| **Confirmation** | Booking created | Customer |
| **Reminder** | Cron job (24h / 1h before) | Customer |
| **Cancellation** | Booking cancelled | Customer |
| **Status Change** | Status updated (e.g., confirmed → completed) | Customer |
| **Quantity Changed** | Booking quantity adjusted | Customer |
| **Owner Notification** | New booking created | Business owner |
| **Waitlist Notification** | Slot becomes available | Waitlisted customer |

All emails are sent asynchronously via Craft's queue system. Confirmation emails include an `.ics` calendar attachment.

---

## Customizing Templates

### Step 1: Publish Templates

Run the console command to copy the default templates into your project:

```bash
php craft booked/email/publish
```

This copies templates to `templates/_booked/emails/`. Each file includes a comment header listing every available variable.

For site-specific overrides (multi-site):

```bash
php craft booked/email/publish --site=de
```

Use `--force` to overwrite existing customizations.

### Step 2: Edit Templates

The published templates are standard Twig files. Modify them to match your brand — change colors, layout, copy, or add custom content.

### Template Resolution Order

The plugin checks these paths in order (first match wins):

1. `templates/booked/emails/{site-handle}/{template}.twig`
2. `templates/_booked/emails/{site-handle}/{template}.twig`
3. `templates/_booked/emails/{template}.twig`
4. `templates/booked/emails/{template}.twig`
5. Plugin default (built-in)

This means you can:
- Override all emails globally in `templates/_booked/emails/`
- Override per-site in `templates/_booked/emails/de/` for the German site
- Fall back to plugin defaults for templates you don't need to customize

### Template Files

| File | Email Type |
|------|-----------|
| `confirmation.twig` | Booking confirmation |
| `reminder.twig` | Booking reminder (24h and 1h) |
| `cancellation.twig` | Booking cancellation |
| `status-change.twig` | Status change notification |
| `quantity-changed.twig` | Quantity adjustment notification |
| `owner-notification.twig` | New booking alert to business |
| `waitlist-notification.twig` | Slot available notification |
| `calendar-invite.twig` | Calendar connection invitation email sent to employees |
| `_base.twig` | Base layout (all templates extend this) |

---

## Available Variables

### Common Variables (All Templates)

These variables are available in every email template:

#### Reservation / Booking

| Variable | Type | Description | Example |
|----------|------|-------------|---------|
| `reservation` | object | Full reservation object | |
| `bookingId` | int | Reservation ID | `123` |
| `bookingDate` | string | Raw booking date (`Y-m-d` format) | `2026-01-15` |
| `formattedBookingDate` | string | Display-friendly formatted date | `Monday, Jan 15` |
| `startTime` | string | Raw start time | `14:00` |
| `formattedStartTime` | string | Display-friendly formatted start time | `2:00 PM` |
| `endTime` | string | Raw end time | `15:00` |
| `formattedEndTime` | string | Display-friendly formatted end time | `3:00 PM` |
| `duration` | int | Duration in minutes | `60` |
| `quantity` | int | Number of spots booked | `1` |
| `quantityDisplay` | bool | Whether to show quantity (true when > 1) | |
| `status` | string | Localized status label | `Confirmed` |
| `notes` | string | Customer notes | `First visit` |
| `formattedDateTime` | string | Full formatted date/time | `Monday, Jan 15 at 2:00 PM` |
| `dateCreated` | string | Booking creation timestamp | `15.01.2026 14:30` |

#### Customer

| Variable | Type | Description |
|----------|------|-------------|
| `userName` | string | Customer name |
| `userEmail` | string | Customer email |
| `userPhone` | string | Customer phone |
| `confirmationToken` | string | Token for management links |

#### Service / Employee / Location

| Variable | Type | Description |
|----------|------|-------------|
| `service` | object | Service element (access `.title`, `.duration`, `.price`) |
| `serviceName` | string | Service title |
| `employee` | object | Employee element |
| `employeeName` | string | Employee name |
| `location` | object | Location element |
| `locationName` | string | Location title |
| `sourceName` | string | Source name (service title or event title) |

#### Virtual Meeting

| Variable | Type | Description |
|----------|------|-------------|
| `isVirtual` | bool | Whether booking has a virtual meeting |
| `virtualMeetingUrl` | string | Meeting join link |
| `virtualMeetingProvider` | string | Provider name (Zoom, Teams, Google Meet) |

#### Action URLs

| Variable | Type | Description |
|----------|------|-------------|
| `managementUrl` | string | Token-based booking management link |
| `cancelUrl` | string | Token-based cancellation link |
| `icsUrl` | string | ICS calendar file download link |
| `bookingPageUrl` | string | Link to booking page (from settings) |

#### System

| Variable | Type | Description |
|----------|------|-------------|
| `siteName` | string | Craft system name |
| `ownerName` | string | Business name from plugin settings |
| `ownerEmail` | string | Business email from plugin settings |
| `settings` | object | Full plugin Settings object |

### Template-Specific Variables

#### Cancellation Email

| Variable | Type | Description |
|----------|------|-------------|
| `cancelledAt` | DateTime | When the booking was cancelled |
| `cancellationReason` | string | Reason provided |

#### Reminder Email

| Variable | Type | Description |
|----------|------|-------------|
| `hoursBefore` | int | Hours until the appointment (24 or 1) |

#### Status Change Email

| Variable | Type | Description |
|----------|------|-------------|
| `oldStatus` | string | Previous status |
| `newStatus` | string | New status |

#### Quantity Changed Email

| Variable | Type | Description |
|----------|------|-------------|
| `previousQuantity` | int | Old quantity |
| `newQuantity` | int | New quantity |
| `refundAmount` | float | Refund amount (if applicable) |

#### Owner Notification Email

| Variable | Type | Description |
|----------|------|-------------|
| `cpEditUrl` | string | Control Panel edit URL for the booking |

#### Waitlist Notification Email

| Variable | Type | Description |
|----------|------|-------------|
| `entry` | WaitlistRecord | The waitlist entry |
| `customerName` | string | Waitlisted customer's name |
| `customerEmail` | string | Waitlisted customer's email |
| `preferredDate` | string | Preferred date |
| `preferredTimeStart` | string | Preferred start time |
| `preferredTimeEnd` | string | Preferred end time |
| `formattedDate` | string | Formatted preferred date |
| `formattedTime` | string | Formatted preferred time |
| `bookingUrl` | string | Pre-populated booking link |

---

## Base Layout

All email templates extend `_base.twig`, which provides:

- Responsive HTML email structure with mobile media queries
- Header with title, subtitle, and optional icon
- Body content area with reusable components
- Footer with contact information
- Preheader text support (preview text in email clients)

### CSS Classes

| Class | Purpose |
|-------|---------|
| `.email-header` | Top section with title |
| `.email-body` | Main content area |
| `.email-footer` | Bottom section |
| `.section` | Content section with optional title |
| `.info-grid` / `.info-row` | Structured key-value display |
| `.highlight-box` | Highlighted content area |
| `.btn-container` / `.btn` | Call-to-action buttons |
| `.notice-box` | Important notices with bullet points |
| `.badge` | Status/label badges |
| `.customer-box` | Customer information card |

### Customizing the Base Layout

To customize the base layout itself, publish templates and edit `_base.twig`. You can change:

- Colors via the `headerColor` variable (default: `#000000`)
- Typography, spacing, and overall structure
- Header/footer content
- Add your logo or branding

---

## Multi-Language Support

Emails are rendered in the language of the reservation's site. The plugin:

1. Temporarily switches Craft's language context to the reservation's site language
2. Renders the template (all `{{ 'text'|t('booked') }}` calls use the correct language)
3. Restores the original language

**Owner notifications** use the language configured in the `ownerNotificationLanguage` setting (defaults to the primary site language).

For multi-language custom templates, you have two options:

### Option A: Per-Site Template Overrides

Create site-specific templates:

```
templates/_booked/emails/en/confirmation.twig
templates/_booked/emails/de/confirmation.twig
templates/_booked/emails/fr/confirmation.twig
```

### Option B: Use Craft's Translation System

Keep a single template and use Craft's `|t` filter:

```twig
<h1>{{ 'Your booking is confirmed!'|t('booked') }}</h1>
<p>{{ 'Thank you for booking {service}.'|t('booked', { service: serviceName }) }}</p>
```

Then add translations in `translations/{locale}/booked.php`.

---

## Email Subject Lines

Subject lines are configurable in **Settings → Booked → Notifications**:

| Setting | Default |
|---------|---------|
| Booking Confirmation Subject | `null` (uses translated default) |
| Reminder Email Subject | `null` (uses translated default) |
| Cancellation Email Subject | `null` (uses translated default) |
| Owner Notification Subject | `null` (uses translated default) |

When set to `null`, the plugin uses translatable default subjects from the `booked` translation category. Set a custom value to override for all languages.

---

## Testing Templates

Preview and test your email templates without creating real bookings:

```bash
# Send all email types to a test address
php craft booked/email/preview --to=test@example.com

# Send a specific type
php craft booked/email/preview --type=confirmation --to=test@example.com

# Preview in a specific site's language
php craft booked/email/preview --site=de --to=test@example.com
```

Available types: `all`, `confirmation`, `status-change`, `cancellation`, `reminder`, `owner-notification`, `waitlist-notification`

> **Note**: The `quantity-changed` email template exists and is sent automatically, but is not yet available for preview via this command.

Preview emails use the most recent real reservation for realistic data. If no reservations exist, synthetic data is generated. Subject lines are prefixed with `[Preview]`.

```bash
# List all available email template types
php craft booked/email/list
```

---

## Related Documentation

- [Configuration Guide](CONFIGURATION.md) - Notification settings
- [SMS Notifications](SMS_NOTIFICATIONS.md) - Twilio SMS setup
- [Console Commands](CONSOLE_COMMANDS.md) - Email preview and publish commands
- [Event System](EVENT_SYSTEM.md) - Hook into notification lifecycle
