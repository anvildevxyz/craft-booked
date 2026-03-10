<?php

namespace anvildev\booked\queue\jobs;

use anvildev\booked\Booked;
use Craft;
use craft\queue\BaseJob;

class SyncToCalendarJob extends BaseJob
{
    public int $reservationId;

    public bool $isUpdate = false;

    public function execute($queue): void
    {
        try {
            $reservation = Booked::getInstance()->getBooking()->getReservationById($this->reservationId);
            if (!$reservation) {
                Craft::warning("SyncToCalendarJob: reservation #{$this->reservationId} not found, skipping", __METHOD__);
                return;
            }

            if (!$this->isUpdate && (!empty($reservation->googleEventId) || !empty($reservation->outlookEventId))) {
                Craft::info(
                    "Reservation #{$this->reservationId} already synced to external calendar — skipping",
                    __METHOD__
                );
                return;
            }

            Booked::getInstance()->getCalendarSync()->syncToExternal($reservation);
        } catch (\Throwable $e) {
            Craft::error("Failed to sync reservation #{$this->reservationId} to calendar: " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('booked', 'queue.syncToCalendar.description');
    }

    public function getTtr(): int
    {
        return 300; // 5 minutes - external API calls can be slow
    }

    public function canRetry($attempt, $error): bool
    {
        // Don't retry on authentication/authorization errors (permanent failures)
        if ($error instanceof \Exception) {
            $message = $error->getMessage();
            if (str_contains($message, '401') || str_contains($message, '403')) {
                return false;
            }
        }
        return $attempt < 3;
    }
}
