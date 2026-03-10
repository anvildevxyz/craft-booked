<?php

namespace anvildev\booked\queue\jobs;

use anvildev\booked\Booked;
use anvildev\booked\records\WebhookRecord;
use Craft;
use craft\queue\BaseJob;

class SendWebhookJob extends BaseJob
{
    public int $webhookId;
    public string $event;
    public array $payload;
    public ?int $reservationId = null;
    public ?int $siteId = null;
    public int $attempt = 1;
    public int $maxRetries = 3;

    public function execute($queue): void
    {
        $service = Booked::getInstance()->getWebhook();
        $webhook = WebhookRecord::findOne($this->webhookId);

        if (!$webhook) {
            Craft::warning("Webhook #{$this->webhookId} not found - skipping delivery", __METHOD__);
            $service->logDelivery($this->webhookId, $this->event, $this->reservationId, 'unknown', [
                'success' => false,
                'errorMessage' => 'Webhook was deleted before delivery could complete',
            ]);
            return;
        }

        if (!$webhook->enabled) {
            Craft::info("Webhook #{$this->webhookId} is disabled - skipping delivery", __METHOD__);
            $service->logDelivery($this->webhookId, $this->event, $this->reservationId, $webhook->url, [
                'success' => false,
                'errorMessage' => 'Webhook was disabled before delivery could complete',
            ]);
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('booked', 'queue.sendWebhook.sendingTo', [
            'url' => strlen($webhook->url) > 50 ? substr($webhook->url, 0, 47) . '...' : $webhook->url,
        ]));

        $result = $service->sendImmediate($webhook, $this->payload);

        $this->setProgress($queue, 0.8, Craft::t('booked', 'queue.sendWebhook.loggingDelivery'));

        $service->logDelivery($this->webhookId, $this->event, $this->reservationId, $webhook->url, $result, $this->attempt);

        if (!$result['success']) {
            $errorMessage = $result['errorMessage'] ?? 'Unknown error';
            Craft::error("Webhook delivery failed to {$webhook->url}: {$errorMessage} (HTTP " . ($result['responseCode'] ?? 'N/A') . ')', __METHOD__);

            throw new \Exception("Webhook delivery failed: {$errorMessage}");
        } else {
            Craft::info("Webhook delivered successfully to {$webhook->url} (HTTP {$result['responseCode']})", __METHOD__);
        }

        $this->setProgress($queue, 1, Craft::t('booked', 'queue.sendWebhook.complete'));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('booked', 'queue.sendWebhook.description', ['event' => $this->event]);
    }

    public function getTtr(): int
    {
        return 120;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < $this->maxRetries;
    }
}
