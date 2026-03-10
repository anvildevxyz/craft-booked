# SMS Notifications

Send SMS notifications to customers for booking confirmations, reminders, and cancellations using Twilio.

## Overview

The plugin sends automated text messages at key points in the booking lifecycle:

- **Confirmation** — when a booking is created
- **Reminder** — before the appointment (configurable hours)
- **Cancellation** — when a booking is cancelled

All SMS messages are sent asynchronously via Craft's queue system.

## Requirements

- Twilio account ([twilio.com](https://www.twilio.com))
- Twilio phone number with SMS capability
- Customer phone numbers collected in booking forms

## Plugin Configuration

Navigate to **Booked → Settings → SMS Notifications**.

### Twilio Credentials

| Field | Description | Example |
|-------|-------------|---------|
| Twilio Account SID | Account identifier (starts with `AC`) | `ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx` |
| Twilio Auth Token | Secret auth token | `xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx` |
| Twilio Phone Number | Your Twilio number (E.164) | `+1234567890` |
| Default Country Code | For normalizing local phone numbers | `US`, `DE`, `GB` |

Store credentials securely in `.env` and reference them via environment variable fields in **Settings → Booked → SMS**:

```bash
# .env
TWILIO_ACCOUNT_SID=your_sid
TWILIO_AUTH_TOKEN=your_token
TWILIO_PHONE_NUMBER=+15551234567
```

### Notification Toggles

- **Enable SMS Confirmations** — sent when booking is created
- **Enable SMS Reminders** — sent before appointments
- **Enable SMS Cancellations** — sent when booking is cancelled

### Reminder Timing

| Field | Description | Default |
|-------|-------------|---------|
| SMS Reminder Hours Before | Hours before appointment | 24 |

SMS reminders are queued alongside email reminders when you run `php craft booked/reminders/send` via cron.

## Message Templates

### Available Variables

These placeholders are replaced with reservation data in `TwilioSmsService::getReservationVariables()`:

| Variable | Description | Example |
|----------|-------------|---------|
| `{{service}}` | Service name | "Haircut" |
| `{{date}}` | Date (short, locale-aware) | "1/20/26" |
| `{{dateMedium}}` | Date (medium) | "Jan 20, 2026" |
| `{{dateLong}}` | Date (long) | "January 20, 2026" |
| `{{dateFull}}` | Date (full) | "Monday, January 20, 2026" |
| `{{time}}` | Start time | "14:00" |
| `{{endTime}}` | End time | "15:00" |
| `{{employee}}` | Employee name | "Sarah" |
| `{{location}}` | Location name | "Downtown Salon" |
| `{{customerName}}` | Customer's name | "John Doe" |
| `{{status}}` | Reservation status | "confirmed" |
| `{{confirmationCode}}` | Confirmation code (from the reservation's `confirmationCode` property) | "ABC123" |

### Default Templates

```
Confirmation: Your booking is confirmed! {{service}} on {{date}} at {{time}}. {{location}}
Reminder:     Reminder: {{service}} tomorrow at {{time}}. {{location}}
Cancellation: Your booking for {{service}} on {{date}} has been cancelled.
```

> **Note:** Default SMS templates are loaded from translation files (keys: `sms.confirmation`, `sms.reminder`, `sms.cancellation`, etc.) and may vary by language. Custom templates set in plugin settings override translation files for all languages.

### Multi-Language Support

Date variables use Craft's formatter, which respects the site's locale automatically.

Default templates (when no custom template is set) are translatable via Craft's static translation system:

```php
// translations/de/booked.php
return [
    'Your booking is confirmed! {{service}} on {{date}} at {{time}}. {{location}}'
        => 'Buchung bestätigt! {{service}} am {{date}} um {{time}}. {{location}}',
];
```

If you set a custom template in plugin settings, it is used for **all** languages (translation files are bypassed). For multi-language setups, leave template fields empty and use translation files instead.

## Phone Number Handling

All numbers are normalized to E.164 format (`+[country code][number]`) using the **Default Country Code** setting:

| Input | Country Code | Output |
|-------|--------------|--------|
| `555-123-4567` | US | `+15551234567` |
| `07700 900123` | GB | `+447700900123` |
| `0170 1234567` | DE | `+491701234567` |
| `+49 170 1234567` | (any) | `+491701234567` |

Numbers starting with `+` are used as-is. If a number cannot be normalized, the booking still succeeds but no SMS is sent (an error is logged).

## Delivery Status Tracking

The `smsDeliveryStatus` field on reservations tracks delivery:

| Status | Description |
|--------|-------------|
| `queued` | Queued at Twilio |
| `sent` | Sent to carrier |
| `delivered` | Delivered to phone |
| `failed` | Delivery failed |
| `undelivered` | Could not be delivered |

### Reservation Database Fields

| Field | Type | Description |
|-------|------|-------------|
| `smsConfirmationSent` | boolean | Confirmation SMS was sent |
| `smsConfirmationSentAt` | datetime | When confirmation was sent |
| `smsCancellationSent` | boolean | Cancellation SMS was sent |
| `smsDeliveryStatus` | string | Last known delivery status |

## Events

### `EVENT_BEFORE_SEND_SMS`

Fired before each SMS is sent. Cancelable (`$event->handled = true`).

```php
use yii\base\Event;
use anvildev\booked\services\TwilioSmsService;
use anvildev\booked\events\SmsEvent;

Event::on(
    TwilioSmsService::class,
    TwilioSmsService::EVENT_BEFORE_SEND_SMS,
    function(SmsEvent $event) {
        // Modify message
        $event->message .= "\nReply STOP to unsubscribe.";

        // Or prevent delivery
        // $event->handled = true;
    }
);
```

### `EVENT_AFTER_SEND_SMS`

Fired after a delivery attempt (success or failure).

```php
Event::on(
    TwilioSmsService::class,
    TwilioSmsService::EVENT_AFTER_SEND_SMS,
    function(SmsEvent $event) {
        if (!$event->success) {
            \Craft::error("SMS to {$event->to} failed: {$event->errorMessage}", 'my-plugin');
        }
    }
);
```

### SmsEvent Properties

| Property | Type | Description |
|----------|------|-------------|
| `$to` | string | Recipient phone (E.164) |
| `$message` | string | SMS body |
| `$messageType` | string | `confirmation`, `reminder_24h`, `cancellation`, `test`, `general` |
| `$reservationId` | int\|null | Related reservation ID |
| `$success` | bool | Whether delivery succeeded (after event only) |
| `$errorMessage` | string\|null | Error message if failed (after event only) |

## Troubleshooting

### SMS Not Sending

1. Verify Account SID and Auth Token are correct
2. Check your Twilio number is SMS-capable
3. Trial accounts can only send to verified numbers
4. Run `php craft queue/run` to process queued jobs
5. Check `storage/logs/` for errors

### Delivery Failures

Check Twilio Console → **Monitor → Logs → Messaging** for detailed errors:

| Error | Cause | Solution |
|-------|-------|----------|
| 21211 | Invalid phone number | Validate input format |
| 21608 | Unverified number (trial) | Add to Verified Caller IDs |
| 21610 | Recipient blocked SMS | Customer opted out |
| 30003 | Unreachable phone | Carrier issue, retry later |
| 30007 | Carrier filtering | May need A2P registration |

## Related Documentation

- [Configuration](CONFIGURATION.md) - General plugin settings
- [Webhooks](WEBHOOKS.md) - Outgoing webhook integrations
- [Developer Guide](DEVELOPER_GUIDE.md) - Custom integrations
