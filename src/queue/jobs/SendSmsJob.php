<?php

namespace anvildev\booked\queue\jobs;

use anvildev\booked\Booked;
use anvildev\booked\helpers\PiiRedactor;
use Craft;
use craft\queue\BaseJob;

/**
 * Queue job for sending SMS notifications.
 *
 * Security note: This job stores the phone number ($to) and message body ($body)
 * directly in the serialized queue payload. For reservation-based SMS, these could
 * theoretically be resolved at execution time from $reservationId + $messageType
 * to avoid persisting PII in the queue table. However, this would require duplicating
 * the template rendering and phone normalization logic from TwilioSmsService, and
 * would not work for non-reservation SMS (test messages, general notifications).
 * This is an accepted tradeoff — the queue table should be treated as sensitive data
 * with appropriate access controls and retention policies.
 *
 * @see TwilioSmsService::send() where this job is queued
 */
class SendSmsJob extends BaseJob
{
    public string $to;
    public string $body = '';
    public ?int $reservationId = null;
    public string $messageType = 'general';
    public int $attempt = 1;

    public function execute($queue): void
    {
        // Deferred rendering: when queued from BookingNotificationService,
        // body is empty and must be rendered from reservationId + messageType.
        if ($this->body === '' && $this->reservationId !== null) {
            $twilioSms = Booked::getInstance()->getTwilioSms();
            $reservation = Booked::getInstance()->getBooking()->getReservationById($this->reservationId);
            if (!$reservation) {
                Craft::warning("SendSmsJob: reservation #{$this->reservationId} not found, skipping", __METHOD__);
                return;
            }

            $type = match ($this->messageType) {
                'confirmation' => 'confirmation',
                'cancellation' => 'cancellation',
                'reminder_24h' => 'reminder',
                default => 'confirmation',
            };

            $this->body = $twilioSms->renderSmsBody($reservation, $type);
        }

        $this->setProgress($queue, 0.1, "Preparing SMS to " . PiiRedactor::redactPhone($this->to));
        $this->setProgress($queue, 0.3, 'Sending SMS');

        try {
            Booked::getInstance()->getTwilioSms()->sendImmediate($this->to, $this->body, [
                'reservationId' => $this->reservationId,
                'messageType' => $this->messageType,
                'throwOnError' => true,
            ]);

            $this->setProgress($queue, 0.9, 'SMS sent successfully');

            if ($this->reservationId) {
                $this->updateReservationTracking();
            }

            Craft::info("SMS sent successfully: {$this->messageType} to " . PiiRedactor::redactPhone($this->to) . " (Attempt {$this->attempt})", __METHOD__);
            $this->setProgress($queue, 1, 'Complete');
        } catch (\Throwable $e) {
            Craft::error("Failed to send {$this->messageType} SMS to " . PiiRedactor::redactPhone($this->to) . " (Attempt {$this->attempt}): " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    protected function updateReservationTracking(): void
    {
        $db = \Craft::$app->db;
        $table = '{{%booked_reservations}}';

        [$column, $extraColumns] = match ($this->messageType) {
            'confirmation' => ['smsConfirmationSent', ['smsConfirmationSentAt' => (new \DateTime())->format('Y-m-d H:i:s')]],
            'reminder_24h' => ['smsReminder24hSent', []],
            'cancellation' => ['smsCancellationSent', []],
            default => [null, []],
        };

        if ($column === null) {
            return;
        }

        $affected = $db->createCommand()->update(
            $table,
            array_merge([$column => true], $extraColumns),
            ['id' => $this->reservationId, $column => false],
        )->execute();

        if ($affected === 0) {
            Craft::info("SMS tracking already set for reservation #{$this->reservationId}: {$this->messageType} — skipping", __METHOD__);
            return;
        }

        Craft::info("Updated SMS tracking for reservation #{$this->reservationId}: {$this->messageType}", __METHOD__);
    }

    protected function defaultDescription(): string
    {
        return Craft::t('booked', 'queue.sendSms.description', ['type' => $this->messageType, 'to' => PiiRedactor::redactPhone($this->to)]);
    }

    public function getTtr(): int
    {
        return 30;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < (Booked::getInstance()->getSettings()->smsMaxRetries ?? 3);
    }
}
