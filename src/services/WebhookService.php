<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\events\WebhookEvent;
use anvildev\booked\queue\jobs\SendWebhookJob;
use anvildev\booked\records\WebhookLogRecord;
use anvildev\booked\records\WebhookRecord;
use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Dispatches booking lifecycle events to configured webhook endpoints.
 * Supports standard (nested) and flat (Zapier-optimized) payload formats,
 * HMAC-SHA256 signatures, per-site scoping, and delivery logging.
 */
class WebhookService extends Component
{
    public const EVENT_BOOKING_CREATED = 'booking.created';
    public const EVENT_BOOKING_CANCELLED = 'booking.cancelled';
    public const EVENT_BOOKING_UPDATED = 'booking.updated';
    public const EVENT_BOOKING_QUANTITY_REDUCED = 'booking.quantity.reduced';
    public const EVENT_BOOKING_QUANTITY_INCREASED = 'booking.quantity.increased';

    /** @event WebhookEvent */
    public const EVENT_BEFORE_DISPATCH = 'beforeWebhookDispatch';
    /** @event WebhookEvent */
    public const EVENT_AFTER_DISPATCH = 'afterWebhookDispatch';

    public static function getEventTypes(): array
    {
        return [
            self::EVENT_BOOKING_CREATED => Craft::t('booked', 'webhook.event.bookingCreated'),
            self::EVENT_BOOKING_CANCELLED => Craft::t('booked', 'webhook.event.bookingCancelled'),
            self::EVENT_BOOKING_UPDATED => Craft::t('booked', 'webhook.event.bookingUpdated'),
            self::EVENT_BOOKING_QUANTITY_REDUCED => Craft::t('booked', 'webhook.event.bookingQuantityReduced'),
            self::EVENT_BOOKING_QUANTITY_INCREASED => Craft::t('booked', 'webhook.event.bookingQuantityIncreased'),
        ];
    }

    public function dispatch(string $event, ReservationInterface $reservation, array $extraData = []): void
    {
        $settings = Booked::getInstance()->getSettings();

        if (!($settings->webhooksEnabled ?? false)) {
            Craft::info("Webhooks disabled - skipping dispatch for {$event}", __METHOD__);
            return;
        }

        $reservationSiteId = $reservation->getSiteId()
            ?? Craft::$app->getSites()->getCurrentSite()->id;

        $webhooks = $this->getWebhooksForEvent($event, $reservationSiteId);

        if (empty($webhooks)) {
            Craft::info("No webhooks configured for event {$event} on site {$reservationSiteId}", __METHOD__);
            return;
        }

        foreach ($webhooks as $webhook) {
            $payload = $this->buildPayload($event, $reservation, $extraData, $webhook->payloadFormat ?? 'standard', $reservationSiteId);
            Craft::$app->getQueue()->push(new SendWebhookJob([
                'webhookId' => $webhook->id,
                'event' => $event,
                'payload' => $payload,
                'reservationId' => $reservation->id,
                'siteId' => $reservationSiteId,
                'maxRetries' => $webhook->retryCount ?? 3,
            ]));
            Craft::info("Queued webhook #{$webhook->id} for event {$event}", __METHOD__);
        }
    }

    /** @return WebhookRecord[] */
    public function getWebhooksForEvent(string $event, ?int $siteId = null): array
    {
        $query = WebhookRecord::find()->where(['enabled' => true]);

        if ($siteId !== null) {
            $query->andWhere(['or', ['siteId' => null], ['siteId' => $siteId]]);
        }

        return array_filter($query->all(), fn($webhook) => $webhook->handlesEvent($event));
    }

    /** @return WebhookRecord[] */
    public function getAllWebhooks(): array
    {
        return WebhookRecord::find()->orderBy(['name' => SORT_ASC])->all();
    }

    public function getWebhookById(int $id): ?WebhookRecord
    {
        return WebhookRecord::findOne($id);
    }

    public function saveWebhook(WebhookRecord $webhook): bool
    {
        if (!$this->validateWebhookUrl($webhook->url)['valid']) {
            $webhook->addError('url', Craft::t('booked', 'webhook.invalidUrl'));
            return false;
        }

        if (empty($webhook->secret)) {
            $webhook->secret = WebhookRecord::generateSecret();
        }
        if (is_array($webhook->events)) {
            $webhook->events = json_encode([...$webhook->events]);
        }
        if (is_array($webhook->headers)) {
            $webhook->headers = json_encode([...$webhook->headers]);
        }
        return $webhook->save();
    }

    /** @return array{valid: bool, ip: string|null} */
    public function validateWebhookUrl(?string $url): array
    {
        if (empty($url)) {
            return ['valid' => false, 'ip' => null];
        }

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return ['valid' => false, 'ip' => null];
        }

        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            return ['valid' => false, 'ip' => null];
        }

        $host = $parsed['host'];
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);

        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            Craft::warning("Webhook URL rejected: DNS resolution failed for {$host}", __METHOD__);
            return ['valid' => false, 'ip' => null];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                Craft::warning("Webhook URL rejected: resolves to private/reserved IP {$ip}", __METHOD__);
                return ['valid' => false, 'ip' => null];
            }
        }

        // Check AAAA (IPv6) DNS records for private addresses
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if ($aaaa) {
            foreach ($aaaa as $record) {
                $ipv6 = $record['ipv6'] ?? '';
                if ($ipv6 !== '' && $this->isPrivateIpv6($ipv6)) {
                    Craft::warning("Webhook URL resolves to private IPv6 address: {$ipv6}", __METHOD__);
                    return ['valid' => false, 'ip' => null];
                }
            }
        }

        return ['valid' => true, 'ip' => $ip];
    }

    private function isPrivateIpv6(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        // ::1 loopback
        if ($packed === inet_pton('::1')) {
            return true;
        }

        $firstByte = ord($packed[0]);
        $secondByte = ord($packed[1]);

        // fc00::/7 - unique local addresses (fc00::/8 and fd00::/8)
        if (($firstByte & 0xFE) === 0xFC) {
            return true;
        }

        // fe80::/10 - link-local (fe80-febf prefix)
        if ($firstByte === 0xFE && ($secondByte & 0xC0) === 0x80) {
            return true;
        }

        // 100::/64 - discard prefix (RFC 6666)
        if ($firstByte === 0x01 && $secondByte === 0x00 && substr($packed, 2, 6) === str_repeat("\0", 6)) {
            return true;
        }

        // 2001:db8::/32 - documentation prefix (RFC 3849)
        if ($firstByte === 0x20 && $secondByte === 0x01 && ord($packed[2]) === 0x0D && ord($packed[3]) === 0xB8) {
            return true;
        }

        // ::ffff:0:0/96 - IPv4-mapped IPv6 addresses, check for private IPv4 ranges
        if (substr($packed, 0, 10) === str_repeat("\0", 10) && ord($packed[10]) === 0xFF && ord($packed[11]) === 0xFF) {
            $ipv4First = ord($packed[12]);
            $ipv4Second = ord($packed[13]);

            // 10.0.0.0/8
            if ($ipv4First === 10) {
                return true;
            }
            // 172.16.0.0/12
            if ($ipv4First === 172 && ($ipv4Second >= 16 && $ipv4Second <= 31)) {
                return true;
            }
            // 192.168.0.0/16
            if ($ipv4First === 192 && $ipv4Second === 168) {
                return true;
            }
            // 127.0.0.0/8 loopback
            if ($ipv4First === 127) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redact PII from a webhook payload JSON string for log storage.
     */
    public function redactPayloadForLog(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }

        $redact = static function(string $value, int $showStart = 1, int $showEnd = 1): string {
            $len = mb_strlen($value);
            if ($len <= $showStart + $showEnd + 1) {
                return str_repeat('*', $len);
            }

            return mb_substr($value, 0, $showStart) . '***' . mb_substr($value, -$showEnd);
        };

        $redactEmail = static function(string $email) use ($redact): string {
            $parts = explode('@', $email, 2);
            if (count($parts) !== 2) {
                return $redact($email);
            }

            return $redact($parts[0], 1, 0) . '@' . $parts[1];
        };

        // Standard payload format
        if (isset($data['customer']['name'])) {
            $data['customer']['name'] = $redact($data['customer']['name']);
        }
        if (isset($data['customer']['email'])) {
            $data['customer']['email'] = $redactEmail($data['customer']['email']);
        }
        if (isset($data['customer']['phone'])) {
            $data['customer']['phone'] = $redact($data['customer']['phone'], 2, 2);
        }
        if (isset($data['booking']['confirmationCode'])) {
            $data['booking']['confirmationCode'] = $redact($data['booking']['confirmationCode'], 4, 0);
        }

        // Flat payload format
        $flatFields = [
            'customer_name' => fn($v) => $redact($v),
            'customer_email' => fn($v) => $redactEmail($v),
            'customer_phone' => fn($v) => $redact($v, 2, 2),
            'booking_confirmation_code' => fn($v) => $redact($v, 4, 0),
        ];
        foreach ($flatFields as $key => $fn) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = $fn($data[$key]);
            }
        }

        return json_encode($data) ?: '{"redaction_error":true}';
    }

    private function redactSensitiveHeaders(array $headers): array
    {
        $sensitiveKeys = ['authorization', 'x-webhook-secret', 'x-api-key', 'cookie', 'x-booked-signature'];
        $redacted = [];

        foreach ($headers as $key => $value) {
            $isRedacted = false;

            foreach ($sensitiveKeys as $sensitiveKey) {
                if (strcasecmp($key, $sensitiveKey) === 0) {
                    $redacted[$key] = '[REDACTED]';
                    $isRedacted = true;
                    break;
                }
            }

            if (!$isRedacted) {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    public function deleteWebhook(int $id): bool
    {
        return (bool) WebhookRecord::findOne($id)?->delete();
    }

    public function buildPayload(
        string $event,
        ReservationInterface $reservation,
        array $extraData = [],
        string $format = 'standard',
        ?int $siteId = null,
    ): array {
        $service = $reservation->getService();
        $employee = $reservation->getEmployee();
        $location = $reservation->getLocation();

        $effectiveSiteId = $siteId ?? $reservation->getSiteId();
        $site = ($effectiveSiteId ? Craft::$app->getSites()->getSiteById($effectiveSiteId) : null)
            ?? Craft::$app->getSites()->getCurrentSite();

        return $format === 'flat'
            ? $this->buildFlatPayload($event, $reservation, $extraData, $service, $employee, $location, $site)
            : $this->buildStandardPayload($event, $reservation, $extraData, $service, $employee, $location, $site);
    }

    protected function buildStandardPayload(string $event, ReservationInterface $reservation, array $extraData, $service, $employee, $location, $site): array
    {
        $data = [
            'booking' => [
                'id' => $reservation->id,
                'uid' => $reservation->uid,
                // Intentionally truncated to first 8 chars of confirmationToken for display purposes (not a security token)
                'confirmationCode' => $reservation->confirmationToken ? substr($reservation->confirmationToken, 0, 8) : null,
                'status' => $reservation->status,
                'bookingDate' => $reservation->bookingDate,
                'startTime' => $reservation->startTime,
                'endTime' => $reservation->endTime,
                'quantity' => $reservation->quantity,
                'notes' => $reservation->notes,
                'createdAt' => $reservation->dateCreated?->format('c'),
                'updatedAt' => $reservation->dateUpdated?->format('c'),
            ],
            'customer' => [
                'name' => $reservation->userName,
                'email' => $reservation->userEmail,
                'phone' => $reservation->userPhone,
            ],
            'service' => $service ? [
                'id' => $service->id, 'title' => $service->title,
                'duration' => $service->duration, 'price' => $service->price,
            ] : null,
            'employee' => $employee ? ['id' => $employee->id, 'name' => $employee->title] : null,
            'location' => $location ? [
                'id' => $location->id, 'name' => $location->title, 'timezone' => $location->timezone ?? null,
            ] : null,
            ...$extraData,
        ];

        return [
            'event' => $event,
            'timestamp' => (new \DateTime())->format('c'),
            'data' => $data,
            'meta' => ['siteId' => $site->id, 'siteName' => $site->name, 'siteUrl' => $site->getBaseUrl()],
        ];
    }

    protected function buildFlatPayload(string $event, ReservationInterface $reservation, array $extraData, $service, $employee, $location, $site): array
    {
        $payload = [
            'event' => $event,
            'timestamp' => (new \DateTime())->format('c'),
            'booking_id' => $reservation->id,
            'booking_uid' => $reservation->uid,
            // Intentionally truncated to first 8 chars of confirmationToken for display purposes (not a security token)
            'booking_confirmation_code' => $reservation->confirmationToken ? substr($reservation->confirmationToken, 0, 8) : null,
            'booking_status' => $reservation->status,
            'booking_date' => $reservation->bookingDate,
            'booking_start_time' => $reservation->startTime,
            'booking_end_time' => $reservation->endTime,
            'booking_quantity' => $reservation->quantity,
            'booking_notes' => $reservation->notes,
            'customer_name' => $reservation->userName,
            'customer_email' => $reservation->userEmail,
            'customer_phone' => $reservation->userPhone,
        ];

        if ($service) {
            $payload += ['service_id' => $service->id, 'service_title' => $service->title, 'service_duration' => $service->duration, 'service_price' => $service->price];
        }
        if ($employee) {
            $payload += ['employee_id' => $employee->id, 'employee_name' => $employee->title];
        }
        if ($location) {
            $payload += ['location_id' => $location->id, 'location_name' => $location->title, 'location_timezone' => $location->timezone ?? null];
        }

        $payload += ['site_id' => $site->id, 'site_name' => $site->name, 'site_url' => $site->getBaseUrl()];

        foreach ($extraData as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (!is_array($subValue)) {
                        $payload["{$key}_{$subKey}"] = $subValue;
                    }
                }
            } else {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    public function generateSignature(string $payload, string $secret, int $timestamp): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    public function verifySignature(string $payload, string $signature, string $secret, int $timestamp): bool
    {
        return abs(time() - $timestamp) <= 300
            && hash_equals($this->generateSignature($payload, $secret, $timestamp), $signature);
    }

    public function sendImmediate(WebhookRecord $webhook, array $payload): array
    {
        $validation = $this->validateWebhookUrl($webhook->url);
        if (!$validation['valid']) {
            return ['success' => false, 'errorMessage' => 'Webhook URL targets a private or reserved address'];
        }

        $beforeEvent = new WebhookEvent([
            'webhook' => $webhook,
            'payload' => $payload,
            'event' => $payload['event'] ?? 'unknown',
        ]);
        $this->trigger(self::EVENT_BEFORE_DISPATCH, $beforeEvent);

        if ($beforeEvent->handled) {
            return ['success' => false, 'errorMessage' => 'Dispatch prevented by event handler'];
        }

        $payload = $beforeEvent->payload;
        $timestamp = time();
        try {
            $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Craft::error("Failed to encode webhook payload: {$e->getMessage()}", __METHOD__);
            return ['success' => false, 'errorMessage' => 'Failed to encode webhook payload as JSON'];
        }
        $eventName = $payload['event'] ?? 'unknown';

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Booked-Webhook/1.0',
            'X-Booked-Event' => $eventName,
            'X-Booked-Timestamp' => (string)$timestamp,
            'X-Booked-Webhook-Id' => $webhook->uid ?? ($webhook->id ? (string)$webhook->id : StringHelper::UUID()),
        ];

        if (!empty($webhook->secret)) {
            $headers['X-Booked-Signature'] = $this->generateSignature($jsonPayload, $webhook->secret, $timestamp);
        }

        $headers = array_merge($headers, $webhook->getHeadersArray() ?: []);

        $clientOptions = ['timeout' => Booked::getInstance()->getSettings()->webhookTimeout ?? 30];
        $requestOptions = [
            'headers' => $headers,
            'body' => $jsonPayload,
            'http_errors' => false,
        ];

        // Pin resolved IP to prevent DNS rebinding
        if ($validation['ip']) {
            $parsed = parse_url($webhook->url);
            if ($parsed && isset($parsed['host']) && $validation['ip'] !== $parsed['host']) {
                $port = $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80);
                $requestOptions['curl'] = [
                    CURLOPT_RESOLVE => [$parsed['host'] . ':' . $port . ':' . $validation['ip']],
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ];
            }
        }

        $client = new Client($clientOptions);
        $startTime = microtime(true);

        try {
            $response = $client->post($webhook->url, $requestOptions);

            $statusCode = $response->getStatusCode();
            $success = $statusCode >= 200 && $statusCode < 300;

            $result = [
                'success' => $success,
                'responseCode' => $statusCode,
                'responseBody' => (string) $response->getBody(),
                'requestHeaders' => $headers,
                'requestBody' => $jsonPayload,
                'errorMessage' => $success ? null : "HTTP {$statusCode} response from {$webhook->url}",
                'duration' => round((microtime(true) - $startTime) * 1000),
            ];
        } catch (RequestException $e) {
            $result = [
                'success' => false,
                'responseCode' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                'responseBody' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : null,
                'requestHeaders' => $headers,
                'requestBody' => $jsonPayload,
                'errorMessage' => $e->getMessage(),
                'duration' => round((microtime(true) - $startTime) * 1000),
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'requestHeaders' => $headers,
                'requestBody' => $jsonPayload,
                'errorMessage' => $e->getMessage(),
                'duration' => round((microtime(true) - $startTime) * 1000),
            ];
        }

        $this->trigger(self::EVENT_AFTER_DISPATCH, new WebhookEvent([
            'webhook' => $webhook,
            'payload' => $payload,
            'event' => $eventName,
            'success' => $result['success'],
            'responseCode' => $result['responseCode'] ?? null,
            'errorMessage' => $result['errorMessage'] ?? null,
        ]));

        return $result;
    }

    public function logDelivery(
        int $webhookId,
        string $event,
        ?int $reservationId,
        string $url,
        array $result,
        int $attempt = 1,
    ): ?WebhookLogRecord {
        if (!Booked::getInstance()->getSettings()->webhookLogEnabled) {
            return null;
        }

        $log = new WebhookLogRecord();
        $log->webhookId = $webhookId;
        $log->event = $event;
        $log->reservationId = $reservationId;
        $log->url = $url;
        $log->requestHeaders = isset($result['requestHeaders']) ? json_encode($this->redactSensitiveHeaders($result['requestHeaders'])) : null;
        $log->requestBody = isset($result['requestBody'])
            ? substr($this->redactPayloadForLog($result['requestBody']), 0, 65535)
            : null;
        $log->responseCode = $result['responseCode'] ?? null;
        $log->responseBody = isset($result['responseBody']) ? substr($result['responseBody'], 0, 65535) : null;
        $log->success = $result['success'] ?? false;
        $log->errorMessage = $result['errorMessage'] ?? null;
        $log->duration = $result['duration'] ?? null;
        $log->attempt = $attempt;
        if (!$log->save()) {
            Craft::error("Failed to save webhook delivery log: " . json_encode($log->getErrors()), __METHOD__);
        }

        return $log;
    }

    public function test(WebhookRecord $webhook): array
    {
        $site = ($webhook->siteId ? Craft::$app->getSites()->getSiteById($webhook->siteId) : null)
            ?? Craft::$app->getSites()->getCurrentSite()
            ?? Craft::$app->getSites()->getPrimarySite();

        $format = $webhook->payloadFormat ?? 'standard';

        if ($format === 'flat') {
            $payload = [
                'event' => 'test',
                'timestamp' => (new \DateTime())->format('c'),
                'message' => 'This is a test webhook from the Booked plugin.',
                'booking_id' => 0,
                'booking_uid' => 'test-uid-' . bin2hex(random_bytes(8)),
                'booking_confirmation_code' => 'TEST-' . strtoupper(bin2hex(random_bytes(4))),
                'booking_status' => 'test',
                'booking_date' => date('Y-m-d'),
                'booking_start_time' => '10:00',
                'booking_end_time' => '11:00',
                'booking_quantity' => 1,
                'booking_notes' => 'This is a test booking',
                'customer_name' => 'Test Customer',
                'customer_email' => 'test@example.com',
                'customer_phone' => '+15551234567',
                'service_id' => 0,
                'service_title' => 'Test Service',
                'service_duration' => 60,
                'service_price' => 50.00,
                'site_id' => $site->id,
                'site_name' => $site->name,
                'site_url' => $site->getBaseUrl(),
            ];
        } else {
            $payload = [
                'event' => 'test',
                'timestamp' => (new \DateTime())->format('c'),
                'message' => 'This is a test webhook from the Booked plugin.',
                'data' => [
                    'booking' => [
                        'id' => 0, 'uid' => 'test-uid-' . bin2hex(random_bytes(8)),
                        'confirmationCode' => 'TEST-' . strtoupper(bin2hex(random_bytes(4))),
                        'status' => 'test', 'bookingDate' => date('Y-m-d'),
                        'startTime' => '10:00', 'endTime' => '11:00',
                        'quantity' => 1, 'notes' => 'This is a test booking',
                    ],
                    'customer' => ['name' => 'Test Customer', 'email' => 'test@example.com', 'phone' => '+15551234567'],
                    'service' => ['id' => 0, 'title' => 'Test Service', 'duration' => 60, 'price' => 50.00],
                ],
                'meta' => ['siteId' => $site->id, 'siteName' => $site->name, 'siteUrl' => $site->getBaseUrl()],
            ];
        }

        return $this->sendImmediate($webhook, $payload);
    }

    /** @return WebhookLogRecord[] */
    public function getLogs(int $webhookId, int $limit = 50): array
    {
        return WebhookLogRecord::find()->where(['webhookId' => $webhookId])->orderBy(['dateCreated' => SORT_DESC])->limit($limit)->all();
    }

    public function cleanupOldLogs(?int $days = null): int
    {
        $retentionDays = (int) ($days ?? (Booked::getInstance()->getSettings()->webhookLogRetentionDays ?? 30));
        $cutoffDate = (new \DateTime())->modify("-{$retentionDays} days")->format('Y-m-d H:i:s');
        return Craft::$app->getDb()->createCommand()->delete('{{%booked_webhook_logs}}', ['<', 'dateCreated', $cutoffDate])->execute();
    }

    public function retryFromLog(int $logId): bool
    {
        $log = WebhookLogRecord::findOne($logId);
        if (!$log) {
            return false;
        }

        $webhook = $log->getWebhook();
        if (!$webhook?->enabled) {
            return false;
        }

        // Reconstruct payload fresh from reservation data to avoid replaying redacted PII
        $payload = null;
        if ($log->reservationId) {
            $reservation = \anvildev\booked\factories\ReservationFactory::findById($log->reservationId);

            if ($reservation) {
                $payload = $this->buildPayload(
                    $log->event,
                    $reservation,
                    [],
                    $webhook->payloadFormat ?? 'standard',
                    $reservation->getSiteId(),
                );
                Craft::info("Rebuilt fresh payload for webhook retry from reservation #{$log->reservationId}", __METHOD__);
            }
        }

        // Cannot retry if reservation no longer exists — logged payload may contain redacted PII
        if ($payload === null) {
            Craft::warning("Cannot retry webhook log #{$logId}: reservation #{$log->reservationId} no longer exists and logged payload may be redacted", __METHOD__);
            return false;
        }

        $payload['timestamp'] = (new \DateTime())->format('c');
        Craft::$app->getQueue()->push(new SendWebhookJob([
            'webhookId' => $webhook->id,
            'event' => $log->event,
            'payload' => $payload,
            'reservationId' => $log->reservationId,
            'attempt' => $log->attempt + 1,
            'maxRetries' => $webhook->retryCount ?? 3,
        ]));
        return true;
    }

    /** @return array{total: int, success: int, failed: int, successRate: float, avgDuration: int} */
    public function getWebhookStats(int $webhookId, int $days = 7): array
    {
        $cutoffDate = (new \DateTime())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $baseQuery = WebhookLogRecord::find()->where(['webhookId' => $webhookId])->andWhere(['>=', 'dateCreated', $cutoffDate]);

        $total = (int) (clone $baseQuery)->count();
        $success = (int) (clone $baseQuery)->andWhere(['success' => true])->count();

        $avgDuration = (int) round((float) Craft::$app->getDb()->createCommand(
            'SELECT AVG([[duration]]) FROM {{%booked_webhook_logs}} WHERE [[webhookId]] = :webhookId AND [[dateCreated]] >= :cutoff AND [[duration]] IS NOT NULL',
            [':webhookId' => $webhookId, ':cutoff' => $cutoffDate]
        )->queryScalar() ?: 0);

        return [
            'total' => $total,
            'success' => $success,
            'failed' => $total - $success,
            'successRate' => $total > 0 ? round(($success / $total) * 100, 1) : 0,
            'avgDuration' => $avgDuration,
        ];
    }
}
