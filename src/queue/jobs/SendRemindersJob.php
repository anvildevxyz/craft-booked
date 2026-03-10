<?php

namespace anvildev\booked\queue\jobs;

use anvildev\booked\Booked;
use Craft;
use craft\queue\BaseJob;

class SendRemindersJob extends BaseJob
{
    public function execute($queue): void
    {
        $this->setProgress($queue, 0.1, 'Checking for pending reminders...');

        try {
            $sentCount = Booked::getInstance()->getReminder()->sendReminders();
            $this->setProgress($queue, 1, "Sent {$sentCount} reminders.");

            if ($sentCount > 0) {
                Craft::info("Reminder job completed: Sent {$sentCount} reminders.", __METHOD__);
            }
        } catch (\Throwable $e) {
            Craft::error('Failed to process reminders: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    protected function defaultDescription(): string
    {
        return Craft::t('booked', 'queue.sendReminders.description');
    }

    public function getTtr(): int
    {
        return 300; // Processes multiple reminders
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 2;
    }
}
