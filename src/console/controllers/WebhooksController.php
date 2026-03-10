<?php

namespace anvildev\booked\console\controllers;

use anvildev\booked\Booked;
use anvildev\booked\records\WebhookRecord;
use craft\console\Controller;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use yii\console\ExitCode;
use yii\helpers\Console;

class WebhooksController extends Controller
{
    public ?int $days = null;
    public string $event = 'booking.created';
    public string $format = 'standard';

    public function options($actionID): array
    {
        return match ($actionID) {
            'cleanup-logs' => [...parent::options($actionID), 'days'],
            'test' => [...parent::options($actionID), 'event', 'format'],
            default => parent::options($actionID),
        };
    }

    public function actionCleanupLogs(): int
    {
        $this->stdout("Cleaning up old webhook logs...\n");

        try {
            $deleted = Booked::getInstance()->getWebhook()->cleanupOldLogs($this->days);
            $this->stdout("Deleted {$deleted} old log(s)\n", Console::FG_GREEN);
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("Failed: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    public function actionRetryFailed(int $logId): int
    {
        $this->stdout("Retrying webhook from log #{$logId}...\n");

        try {
            if (Booked::getInstance()->getWebhook()->retryFromLog($logId)) {
                $this->stdout("Webhook retry queued successfully\n", Console::FG_GREEN);
                return ExitCode::OK;
            }

            $this->stderr("Could not retry - log not found, webhook disabled, or payload missing\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        } catch (\Throwable $e) {
            $this->stderr("Failed: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    public function actionTest(string $url): int
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->stderr("Invalid URL: {$url}\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!in_array($this->format, ['standard', 'flat'], true)) {
            $this->stderr("Invalid format '{$this->format}'. Use: standard, flat\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $this->stdout("\nWebhook Test\n", Console::BOLD);
        $this->stdout("═══════════════════════════════════\n\n");
        $this->stdout("URL:     {$url}\nEvent:   {$this->event}\nFormat:  {$this->format}\n\n");

        $payload = $this->buildSamplePayload();
        $jsonPayload = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $compactJson = json_encode($payload);
        $secret = WebhookRecord::generateSecret();
        $timestamp = time();

        $webhookService = Booked::getInstance()->getWebhook();
        $signature = $webhookService->generateSignature($compactJson, $secret, $timestamp);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Booked-Webhook/1.0',
            'X-Booked-Event' => $this->event,
            'X-Booked-Timestamp' => (string)$timestamp,
            'X-Booked-Webhook-Id' => 'test-' . bin2hex(random_bytes(8)),
            'X-Booked-Signature' => $signature,
        ];

        $this->stdout("Payload:\n{$jsonPayload}\n\n");
        $this->stdout("Secret:    {$secret}\nSignature: {$signature}\nTimestamp: {$timestamp}\n\n");
        $this->stdout("Sending...\n");

        $timeout = Booked::getInstance()->getSettings()->webhookTimeout ?? 30;
        $client = new Client(['timeout' => $timeout]);
        $startTime = microtime(true);

        try {
            $response = $client->post($url, [
                'headers' => $headers,
                'body' => $compactJson,
                'http_errors' => false,
            ]);

            $duration = round((microtime(true) - $startTime) * 1000);
            $statusCode = $response->getStatusCode();
            $success = $statusCode >= 200 && $statusCode < 300;
            $body = (string)$response->getBody();

            $this->stdout("\n");
            $this->stdout($success ? "  ✓ HTTP {$statusCode} — {$duration}ms\n" : "  ✗ HTTP {$statusCode} — {$duration}ms\n", $success ? Console::FG_GREEN : Console::FG_RED);

            if ($body !== '') {
                $this->stdout("\nResponse body:\n{$body}\n");
            }

            $this->stdout("\n");
            return $success ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $this->stdout("\n");
            $this->stderr("  ✗ Request failed — {$duration}ms\n  {$e->getMessage()}\n", Console::FG_RED);

            if ($e->hasResponse()) {
                $body = (string)$e->getResponse()->getBody();
                if ($body !== '') {
                    $this->stdout("\nResponse body:\n{$body}\n");
                }
            }

            $this->stdout("\n");
            return ExitCode::UNSPECIFIED_ERROR;
        } catch (\Throwable $e) {
            $this->stderr("\n  ✗ Connection failed: {$e->getMessage()}\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    private function buildSamplePayload(): array
    {
        $now = new \DateTime();

        if ($this->format === 'flat') {
            return [
                'event' => $this->event,
                'timestamp' => $now->format('c'),
                'booking_id' => 999,
                'booking_uid' => 'test-' . bin2hex(random_bytes(16)),
                'booking_confirmation_code' => strtoupper(bin2hex(random_bytes(8))),
                'booking_status' => 'confirmed',
                'booking_date' => $now->modify('+7 days')->format('Y-m-d'),
                'booking_start_time' => '10:00',
                'booking_end_time' => '11:00',
                'booking_quantity' => 1,
                'booking_notes' => null,
                'customer_name' => 'Test Customer',
                'customer_email' => 'test@example.com',
                'customer_phone' => '+1234567890',
                'service_id' => 1,
                'service_title' => 'Sample Service',
                'service_duration' => 60,
                'service_price' => 50.00,
                'employee_id' => 1,
                'employee_name' => 'Sample Employee',
                'location_id' => 1,
                'location_name' => 'Main Office',
                'location_timezone' => 'UTC',
            ];
        }

        return [
            'event' => $this->event,
            'timestamp' => $now->format('c'),
            'data' => [
                'booking' => [
                    'id' => 999,
                    'uid' => 'test-' . bin2hex(random_bytes(16)),
                    'confirmationCode' => strtoupper(bin2hex(random_bytes(8))),
                    'status' => 'confirmed',
                    'bookingDate' => $now->modify('+7 days')->format('Y-m-d'),
                    'startTime' => '10:00',
                    'endTime' => '11:00',
                    'quantity' => 1,
                    'notes' => null,
                    'createdAt' => $now->format('c'),
                    'updatedAt' => $now->format('c'),
                ],
                'customer' => [
                    'name' => 'Test Customer',
                    'email' => 'test@example.com',
                    'phone' => '+1234567890',
                ],
                'service' => [
                    'id' => 1,
                    'title' => 'Sample Service',
                    'duration' => 60,
                    'price' => 50.00,
                ],
                'employee' => [
                    'id' => 1,
                    'name' => 'Sample Employee',
                ],
                'location' => [
                    'id' => 1,
                    'name' => 'Main Office',
                    'timezone' => 'UTC',
                ],
            ],
            'meta' => [
                'siteId' => 1,
                'siteName' => 'Test Site',
                'siteUrl' => 'https://example.com',
            ],
        ];
    }
}
