# Webhooks

Send booking events to external services like Zapier, n8n, Make, or custom API endpoints.

## Overview

Webhooks notify external services when booking events occur:

- Multiple webhook endpoints per event
- Two payload formats (Standard/Flat)
- HMAC-SHA256 request signing with timestamp validation
- Custom HTTP headers
- Automatic retries via Craft's queue system
- Delivery logging with request/response details

## Configuration

### Global Settings

Navigate to **Booked → Settings → Webhooks**:

| Setting | Description | Default |
|---------|-------------|---------|
| Enable Webhooks | Master toggle | Off |
| Webhook Timeout | Seconds to wait for response | 30 |
| Enable Delivery Logging | Log all webhook attempts | On |
| Log Retention (days) | Days to keep logs | 30 |

### Webhook Endpoint Settings

| Setting | Description |
|---------|-------------|
| Name | Descriptive name |
| Enabled | Toggle on/off |
| URL | Endpoint URL to call |
| Events | Which events trigger this webhook |
| Payload Format | Standard (nested) or Flat |
| Signing Secret | For HMAC signature verification |
| Custom Headers | Additional HTTP headers |
| Retry Attempts | Override global retry count |

## Events

| Event | Constant | Trigger |
|-------|----------|---------|
| `booking.created` | `WebhookService::EVENT_BOOKING_CREATED` | New booking created |
| `booking.updated` | `WebhookService::EVENT_BOOKING_UPDATED` | Booking modified or rescheduled |
| `booking.cancelled` | `WebhookService::EVENT_BOOKING_CANCELLED` | Booking cancelled |
| `booking.quantity.reduced` | `WebhookService::EVENT_BOOKING_QUANTITY_REDUCED` | Booking quantity decreased |
| `booking.quantity.increased` | `WebhookService::EVENT_BOOKING_QUANTITY_INCREASED` | Booking quantity increased |

## Payload Formats

### Standard Format (Nested)

Best for n8n, Make, custom integrations:

```json
{
  "event": "booking.created",
  "timestamp": "2026-01-20T10:00:00Z",
  "data": {
    "booking": {
      "id": 456,
      "uid": "abc123-def456",
      "confirmationCode": "BK-ABC123",
      "status": "confirmed",
      "bookingDate": "2026-01-21",
      "startTime": "14:00",
      "endTime": "15:00",
      "quantity": 1,
      "notes": "First time customer",
      "createdAt": "2026-01-20T10:00:00Z",
      "updatedAt": "2026-01-20T10:00:00Z"
    },
    "customer": {
      "name": "John Doe",
      "email": "john@example.com",
      "phone": "+1234567890"
    },
    "service": {
      "id": 1,
      "title": "Haircut",
      "duration": 60,
      "price": 50.00
    },
    "employee": {
      "id": 2,
      "name": "Sarah"
    },
    "location": {
      "id": 3,
      "name": "Downtown Salon",
      "timezone": "America/New_York"
    }
  },
  "meta": {
    "siteId": 1,
    "siteName": "My Site",
    "siteUrl": "https://example.com"
  }
}
```

### Flat Format (Zapier-Optimized)

Best for Zapier (easier field mapping). Same data, flattened with underscores:

```json
{
  "event": "booking.created",
  "timestamp": "2026-01-20T10:00:00+00:00",
  "booking_id": 456,
  "booking_uid": "abc123-def456",
  "booking_confirmation_code": "BK-ABC12",
  "booking_status": "confirmed",
  "booking_date": "2026-01-21",
  "booking_start_time": "14:00",
  "booking_end_time": "15:00",
  "booking_quantity": 1,
  "booking_notes": "First time customer",
  "customer_name": "John Doe",
  "customer_email": "john@example.com",
  "customer_phone": "+1234567890",
  "service_id": 1,
  "service_title": "Haircut",
  "service_duration": 60,
  "service_price": 50,
  "employee_id": 2,
  "employee_name": "Sarah",
  "location_id": 3,
  "location_name": "Downtown Salon",
  "location_timezone": "America/New_York",
  "site_id": 1,
  "site_name": "My Site",
  "site_url": "https://example.com"
}
```

## Security

### HMAC-SHA256 Signing

Every webhook request includes these headers:

| Header | Description |
|--------|-------------|
| `X-Booked-Signature` | `sha256=<hex_digest>` |
| `X-Booked-Timestamp` | Unix timestamp |
| `X-Booked-Event` | Event type |
| `X-Booked-Webhook-Id` | Webhook identifier (may be a UUID, a numeric ID, or a generated UUID depending on context) |

The signature is computed over `{timestamp}.{request_body}`:

```
HMAC-SHA256(signing_secret, "{timestamp}.{json_body}")
```

Verification rejects timestamps older than 5 minutes to prevent replay attacks.

### Verifying Signatures

**PHP:**
```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_BOOKED_SIGNATURE'] ?? '';
$timestamp = (int)($_SERVER['HTTP_X_BOOKED_TIMESTAMP'] ?? 0);
$secret = 'your-signing-secret';

// Check timestamp freshness (5 min window)
if (abs(time() - $timestamp) > 300) {
    http_response_code(401);
    exit('Stale timestamp');
}

$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}
```

**Node.js:**
```javascript
const crypto = require('crypto');

function verifySignature(payload, signature, timestamp, secret) {
    if (Math.abs(Date.now() / 1000 - timestamp) > 300) return false;
    const expected = 'sha256=' + crypto
        .createHmac('sha256', secret)
        .update(timestamp + '.' + payload)
        .digest('hex');
    return crypto.timingSafeEqual(
        Buffer.from(expected),
        Buffer.from(signature)
    );
}
```

## Delivery & Retries

A delivery succeeds when the endpoint returns HTTP 2xx within the timeout period. Failed deliveries are retried automatically by Craft's queue system (up to 3 attempts by default, configurable per webhook). Retry timing depends on your queue runner configuration.

Your endpoint should respond quickly (< 5 seconds), return 200 immediately, and be idempotent (use `booking.id` or `booking.uid` to deduplicate).

## Logging

View delivery logs at **Booked → Webhooks → (webhook) → View Delivery Logs**. Each entry includes timestamp, event type, HTTP status, response time, request/response bodies, and error messages. Logs are automatically cleaned up based on the retention setting.

## Plugin Events

### `EVENT_BEFORE_DISPATCH`

Fired before each webhook is dispatched. Cancelable (`$event->handled = true`).

```php
use yii\base\Event;
use anvildev\booked\services\WebhookService;
use anvildev\booked\events\WebhookEvent;

Event::on(
    WebhookService::class,
    WebhookService::EVENT_BEFORE_DISPATCH,
    function(WebhookEvent $event) {
        $event->payload['custom_field'] = 'my_value';
        // Or prevent: $event->handled = true;
    }
);
```

### `EVENT_AFTER_DISPATCH`

Fired after a delivery attempt (success or failure).

```php
Event::on(
    WebhookService::class,
    WebhookService::EVENT_AFTER_DISPATCH,
    function(WebhookEvent $event) {
        if (!$event->success) {
            \Craft::error("Webhook failed: {$event->errorMessage}", 'my-plugin');
        }
    }
);
```

### WebhookEvent Properties

| Property | Type | Description |
|----------|------|-------------|
| `$webhook` | WebhookRecord | The webhook endpoint |
| `$payload` | array | The payload (modifiable in before event) |
| `$event` | string | Event type (e.g. `booking.created`) |
| `$reservationId` | int\|null | Related reservation ID |
| `$success` | bool | Delivery succeeded (after event only) |
| `$responseCode` | int\|null | HTTP response code (after event only) |
| `$errorMessage` | string\|null | Error message (after event only) |

## Console Commands

```bash
# Clean up old delivery logs (uses configured retention)
php craft booked/webhooks/cleanup-logs

# Override retention period
php craft booked/webhooks/cleanup-logs --days=7

# Retry a failed delivery by log ID
php craft booked/webhooks/retry-failed 42
```

## Troubleshooting

### Webhook Not Firing

1. Is the webhook enabled?
2. Is the event selected on the webhook?
3. Is Webhooks enabled in global settings?
4. Run `php craft queue/run` to process jobs

### Delivery Failures

Check delivery logs for error details:

| Error | Cause | Solution |
|-------|-------|----------|
| Connection refused | Endpoint unreachable | Check URL |
| SSL certificate problem | Invalid cert | Fix certificate |
| Timeout | Endpoint too slow | Increase timeout or optimize endpoint |
| 401 Unauthorized | Auth failed | Check custom headers |
| 500 Server Error | Endpoint error | Debug endpoint code |

### Signature Verification Failing

- Use the raw request body (not parsed JSON)
- Include the `X-Booked-Timestamp` header value in the signature computation
- Use constant-time comparison (`hash_equals`)

## Related Documentation

- [SMS Notifications](SMS_NOTIFICATIONS.md) - Twilio SMS integration
- [Configuration](CONFIGURATION.md) - General plugin settings
- [Developer Guide](DEVELOPER_GUIDE.md) - Custom integrations
- [Event System](EVENT_SYSTEM.md) - Plugin events
