# Configuration Guide

All settings are configured via **Settings → Booked** in the Craft control panel.

Store sensitive credentials (API keys, secrets) in `.env` and reference them via environment variable fields in the CP.

---

## General

| Setting | Default | Description |
|---------|---------|-------------|
| Default Currency | Auto-detect | ISO 4217 code, auto-detects from Commerce or falls back to CHF |
| Soft Lock Duration | 5 min | Holds slot while customer completes booking |
| Minimum Advance Booking | 0 hours | 0 = no minimum |
| Maximum Advance Booking | 90 days | |
| Cancellation Policy | 24 hours | 0 = no deadline |
| Default Time Slot Length | Service duration | See below |
| Mutex Driver | `auto` | Lock driver for booking concurrency (`auto`, `file`, `db`, `redis`) |
| Booking Page URL | — | Public URL to the booking page, used in notification links (`null` = auto-detect) |

### Time Slot Interval

The slot interval determines how often available times appear in the booking calendar. This is separate from the service duration.

**Fallback chain**: Service `timeSlotLength` → Global `defaultTimeSlotLength` → Service `duration`

Example with `defaultTimeSlotLength` = 15:
- 30 min massage → slots at 09:00, 09:15, 09:30...
- 60 min facial → slots at 09:00, 09:15, 09:30...
- 90 min package (own `timeSlotLength` = 30) → slots at 09:00, 09:30, 10:00...

---

## Security

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Rate Limiting | Yes | |
| Rate Limit Per Email | 5 | Max bookings per email per day |
| Rate Limit Per IP | 10 | Max bookings per IP per day |
| Enable Honeypot | Yes | |
| Honeypot Field Name | `website` | |
| Enable IP Blocking | No | |
| Blocked IPs | — | JSON-encoded array of IPs |
| Enable Time-Based Limits | Yes | |
| Minimum Submission Time | 3 sec | Seconds between submissions |
| Enable Audit Log | No | Logs to `@storage/logs/booked-audit.log` |

**Important**: The global rate limits are checked **before** per-service customer limits. If you set `Rate Limit Per Email` to 5, a customer is blocked after 5 bookings that day even if the service allows 10 per week. Set the global limit high enough to accommodate your per-service customer limits.

### CAPTCHA

Supports reCAPTCHA v3, hCaptcha, and Cloudflare Turnstile. Select a provider in the CP and enter your site key and secret key. Store keys in `.env` and use environment variable fields in the CP.

| Setting | Default | Description |
|---------|---------|-------------|
| reCAPTCHA Score Threshold | 0.5 | Minimum score (0–1) to accept a reCAPTCHA v3 submission |
| reCAPTCHA Action | `booking` | Action name sent with reCAPTCHA v3 verification requests |

---

## Email Notifications

| Setting | Default | Description |
|---------|---------|-------------|
| Owner Notification Enabled | Yes | |
| Owner Email | — | Falls back to Craft system email |
| Owner Name | — | Falls back to Craft system sender name |
| Owner Notification Subject | — | `null` = translated default |
| Owner Notification Language | — | e.g. `de`, `null` = primary site language |
| Booking Confirmation Subject | — | `null` = translated default |
| Reminder Email Subject | — | `null` = translated default |
| Cancellation Email Subject | — | `null` = translated default |
| Email Reminders Enabled | Yes | |
| Email Reminder Hours Before | 24 | |
| Send Cancellation Email | Yes | |

Reminders require a cron job — see [Console Commands](CONSOLE_COMMANDS.md).

---

## SMS Notifications (Twilio)

See [SMS Notifications](SMS_NOTIFICATIONS.md) for full setup guide.

Configure Twilio credentials and notification toggles in **Settings → Booked → SMS**. Store credentials in `.env` and use environment variable fields in the CP.

| Setting | Default | Description |
|---------|---------|-------------|
| SMS Max Retries | 3 | Maximum delivery retry attempts for failed SMS messages |
| Default Country Code | `US` | ISO country code used to parse phone numbers without a country prefix |

---

## Calendar Sync

### Google Calendar

1. Create OAuth 2.0 credentials in [Google Cloud Console](https://console.cloud.google.com/) (Web application type)
2. Enable the Google Calendar API
3. Add redirect URIs:
   - `https://your-domain.com/{cpTrigger}/booked/calendar/callback` (admin connect)
   - `https://your-domain.com/booked/calendar/frontend-callback` (employee email invite)
   - `https://your-domain.com/booked/calendar/frontend-callback/` (with trailing slash)
4. Add test users in the OAuth consent screen (while in testing mode)

Enable and enter credentials in **Settings → Booked → Calendar**.

**Connecting employees**: Edit an employee → click **Connect** (admin) or **Send Invite** (employee connects on their own device, link valid 72 hours).

### Microsoft Outlook

1. Create an App Registration in [Azure Portal](https://portal.azure.com/)
2. Add redirect URI (Web platform) — shown in **Settings → Booked → Calendar** when Outlook is enabled
3. Check **Access tokens** and **ID tokens** under Authentication
4. Add API permissions: `Calendars.ReadWrite`, `offline_access`, `User.Read`

Enable and enter credentials in **Settings → Booked → Calendar**.

### Additional Calendar Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Google Calendar Webhook URL | — | URL for receiving Google Calendar push notifications (`null` = disabled) |
| Outlook Calendar Webhook URL | — | URL for receiving Outlook Calendar change notifications (`null` = disabled) |

### Troubleshooting Calendar Sync

| Error | Fix |
|-------|-----|
| `redirect_uri_mismatch` / `AADSTS50011` | Redirect URI doesn't match exactly (protocol, domain, path, trailing slash) |
| `403: access_denied` | User not added as test user in OAuth consent screen (Google) |
| `invalid_client` / `AADSTS7000215` | Wrong or expired client secret |
| `Consent required` | Grant admin consent in Azure Portal → API permissions |

---

## Virtual Meetings

Enable globally first, then set the provider per-service in the service editor.

Enable the global toggle and individual providers in **Settings → Booked → Meetings**. Store API credentials in `.env` and use environment variable fields in the CP.

**Zoom setup**: Create a Server-to-Server OAuth app at [Zoom Marketplace](https://marketplace.zoom.us/develop/create) with scopes `meeting:write:admin` and `user:read:admin`.

**Google Meet**: Requires Google Calendar integration to be configured. The employee's Google account must have Meet enabled.

**Microsoft Teams setup**:
1. Register an app in [Azure Portal](https://portal.azure.com/) → Azure Active Directory → App registrations
2. Add the **OnlineMeetings.ReadWrite.All** application permission (not delegated)
3. Grant admin consent for the permission
4. Create a client secret under Certificates & secrets
5. Copy the Tenant ID, Application (Client) ID, and Client Secret into the plugin settings
6. Employees must have a Microsoft email address configured — meetings are created under their account

---

## Webhooks

See [Webhooks](WEBHOOKS.md) for event types, payload format, and HMAC signing.

Enable and configure in **Settings → Booked → Webhooks**. Webhook endpoints are managed under **Booked → Webhooks** in the main nav.

| Setting | Default | Description |
|---------|---------|-------------|
| Webhook Timeout | 30 | Seconds before a webhook delivery times out |
| Enable Webhook Logging | Yes | Log webhook deliveries and responses |
| Webhook Log Retention Days | 30 | Days to keep webhook log entries before cleanup |

---

## Waitlist

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Waitlist | Yes | |
| Waitlist Expiration Days | 30 | 0 = never expires |
| Waitlist Notification Limit | 10 | Max customers notified per available slot |
| Waitlist Conversion Minutes | 30 | Minutes a waitlisted customer has to confirm after being notified |

Configure in **Settings → Booked → Booking**.

---

## Cancellation

Cancellation can be controlled at two levels:

### Per-Service / Per-Event Toggle

Both **Service** and **Event Date** elements have an **Allow Cancellation** toggle (enabled by default). When disabled, customers cannot cancel bookings for that service or event — the cancel button is hidden and the cancellation endpoint rejects requests.

### Global Cancellation Policy

The **Cancellation Policy** setting under **Settings → Booked → Booking** sets the minimum hours before an appointment that cancellation is allowed (default: 24 hours, 0 = no deadline).

---

## Refunds

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Auto Refund | No | Automatically process refunds when bookings are cancelled |
| Default Refund Tiers | — | JSON-encoded array of refund percentage tiers based on time before appointment |

Configure in **Settings → Booked → Commerce**. Requires Craft Commerce.

**Refund tiers** define what percentage of the booking price is refunded based on how far in advance the cancellation occurs. Example:

```json
[
  { "hoursBeforeStart": 48, "refundPercentage": 100 },
  { "hoursBeforeStart": 24, "refundPercentage": 50 },
  { "hoursBeforeStart": 0, "refundPercentage": 0 }
]
```

This means: full refund if cancelled 48+ hours before, 50% if 24-48 hours, no refund within 24 hours.

---

## Commerce Integration

Requires [Craft Commerce](https://craftcms.com/commerce) installed and enabled.

Enable in **Settings → Booked → Commerce**.

**How it works**: Bookings with a total price > 0 (service + extras) go through Commerce checkout. Free bookings are confirmed immediately. Status flow: Pending → Confirmed (after payment).

| Setting | Default | Description |
|---------|---------|-------------|
| Commerce Enabled | No | Route paid bookings through Commerce checkout |
| Cart URL | `shop/cart` | Where to redirect after adding booking to cart |
| Checkout URL | `shop/checkout` | Where to redirect for payment |
| Pending Cart Expiration Hours | 48 | Hours before an unpaid pending cart is expired (1–168) |
| Commerce Tax Category | — | Tax category ID to apply to booking line items (`null` = default) |

---

## Staff Access & Managed Employees

Staff access is configured per-employee, not via settings:

1. **Link employee to Craft user**: Employee edit page → User field
2. **Assign permissions**: Give the user `booked-viewBookings`
3. **Managed employees** (optional): Assign other employees via the Managed Employees field — the staff user sees their bookings too

| Role | Permission | Sees |
|------|-----------|------|
| Staff | `booked-viewBookings` + linked Employee | Own + managed employees' bookings |
| Supervisor | `booked-manageBookings` | All bookings |
| Admin | Admin account | Everything |

---

## Public Booking URLs

These token-based URLs are included in confirmation emails and require no authentication. The `{token}` is the reservation's unique confirmation token.

| URL | Purpose |
|-----|---------|
| `/booking/manage/{token}` | View booking details, reschedule, or cancel |
| `/booking/cancel/{token}` | Direct cancellation page |
| `/booking/ics/{token}` | Download `.ics` calendar file |

The ICS endpoint returns a `text/calendar` response with a `Content-Disposition: attachment` header, triggering a download in all browsers and email clients.

---

## Booking Wizard Behavior

The wizard adapts its flow automatically:

- **Extras step** is skipped when: the service has no enabled extras
- **Location step** is skipped when: the service has its own schedule, only one location exists, or no locations are configured
- **Employee step** is skipped for schedule-based services (tours, classes)

| Service Type | Extras Step | Location Step | Employee Step |
|-------------|------------|--------------|--------------|
| Employee-based (massage, consultation) | Shows if extras exist | Shows if multiple locations | Shows available employees |
| Schedule-based (tour, class) | Shows if extras exist | Skipped | Skipped |

---

## Environment Variables

Store sensitive credentials in your `.env` file and reference them via environment variable fields in the Craft CP:

```bash
# .env
GOOGLE_CALENDAR_CLIENT_ID=your_client_id
GOOGLE_CALENDAR_CLIENT_SECRET=your_client_secret
ZOOM_ACCOUNT_ID=your_account_id
ZOOM_CLIENT_ID=your_client_id
ZOOM_CLIENT_SECRET=your_client_secret
TWILIO_ACCOUNT_SID=your_sid
TWILIO_AUTH_TOKEN=your_token
TWILIO_PHONE_NUMBER=+15551234567
```

In the CP settings fields, reference these as `$GOOGLE_CALENDAR_CLIENT_ID` etc.

---

## Next Steps

- [Email Templates](EMAIL_TEMPLATES.md) - Customize email notifications
- [Developer Guide](DEVELOPER_GUIDE.md) - API reference and extension guide
- [Event System](EVENT_SYSTEM.md) - Hook into the booking lifecycle
- [Console Commands](CONSOLE_COMMANDS.md) - CLI commands for reminders, cleanup, and diagnostics
