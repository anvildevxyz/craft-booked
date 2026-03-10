<?php

namespace anvildev\booked\queue\jobs;

use anvildev\booked\helpers\PiiRedactor;
use anvildev\booked\models\Settings;
use anvildev\booked\records\WaitlistRecord;
use Craft;
use craft\mail\Message;
use craft\queue\BaseJob;

class SendWaitlistNotificationJob extends BaseJob
{
    public int $waitlistId;
    public ?string $date = null;
    public ?string $startTime = null;
    public ?string $endTime = null;
    public ?string $conversionToken = null;
    public int $attempt = 1;

    public function execute($queue): void
    {
        $entry = WaitlistRecord::findOne($this->waitlistId);
        if (!$entry) {
            Craft::error("Waitlist entry #{$this->waitlistId} not found", __METHOD__);
            return;
        }

        // Idempotency guard: skip if converted, expired, or cancelled
        if (in_array($entry->status, [WaitlistRecord::STATUS_CONVERTED, WaitlistRecord::STATUS_EXPIRED, WaitlistRecord::STATUS_CANCELLED], true)) {
            Craft::info("Waitlist entry #{$this->waitlistId} has status '{$entry->status}' — skipping notification", __METHOD__);
            return;
        }

        $this->setProgress($queue, 0.1, 'Preparing waitlist notification for ' . PiiRedactor::redactEmail($entry->userEmail));

        try {
            $settings = Settings::loadSettings();
            $service = $entry->getService();
            $serviceName = $service?->title ?? Craft::t('booked', 'queue.sendWaitlistNotification.appointment');
            $formatter = Craft::$app->getFormatter();

            $body = Craft::$app->view->renderTemplate('booked/emails/waitlist-notification', [
                'entry' => $entry,
                'service' => $service,
                'serviceName' => $serviceName,
                'date' => $this->date,
                'startTime' => $this->startTime,
                'endTime' => $this->endTime,
                'formattedDate' => $this->date ? $formatter->asDate($this->date, 'medium') : null,
                'formattedTime' => $this->startTime ? $formatter->asTime($this->startTime, 'short') : null,
                'settings' => $settings,
                'conversionToken' => $this->conversionToken,
            ]);

            $this->setProgress($queue, 0.5, 'Sending notification email');

            $message = (new Message())
                ->setTo($entry->userEmail)
                ->setFrom([$settings->getEffectiveEmail() => $settings->getEffectiveName()])
                ->setSubject(Craft::t('booked', 'queue.sendWaitlistNotification.slotAvailable', ['service' => $serviceName]))
                ->setHtmlBody($body);

            if (!Craft::$app->mailer->send($message)) {
                throw new \Exception('Failed to send waitlist notification email');
            }

            $entry->status = WaitlistRecord::STATUS_NOTIFIED;
            $entry->notifiedAt = (new \DateTime())->format('Y-m-d H:i:s');
            $entry->save(false);

            $this->setProgress($queue, 1.0, 'Notification sent successfully');
            Craft::info('Waitlist notification sent to ' . PiiRedactor::redactEmail($entry->userEmail) . " for entry #{$this->waitlistId}", __METHOD__);
        } catch (\Throwable $e) {
            Craft::error("Failed to send waitlist notification for entry #{$this->waitlistId}: " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('booked', 'queue.sendWaitlistNotification.description', ['id' => $this->waitlistId]);
    }

    public function getTtr(): int
    {
        return 120;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3;
    }
}
