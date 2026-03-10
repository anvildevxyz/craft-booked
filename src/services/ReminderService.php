<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\helpers\DateHelper;
use anvildev\booked\models\Settings;
use anvildev\booked\records\ReservationRecord;
use Craft;
use craft\base\Component;
use DateTime;

/**
 * Sends scheduled email and SMS reminders for upcoming reservations.
 */
class ReminderService extends Component
{
    private const ALLOWED_REMINDER_FLAGS = [
        'emailReminder24hSent',
        'smsReminder24hSent',
    ];

    public function sendReminders(): int
    {
        $settings = Booked::getInstance()->getSettings();
        if (!$settings->emailRemindersEnabled && !$settings->smsRemindersEnabled) {
            return 0;
        }

        $sentCount = 0;
        foreach ($this->getPendingReminders() as $reservation) {
            if ($this->processReservationReminders($reservation, $settings)) {
                $sentCount++;
            }
        }

        return $sentCount;
    }

    protected function processReservationReminders(ReservationInterface $reservation, Settings $settings): bool
    {
        $tz = new \DateTimeZone(Craft::$app->getTimeZone());
        $now = new DateTime('now', $tz);
        $bookingTime = new DateTime($reservation->bookingDate . ' ' . $reservation->startTime, $tz);

        if ($bookingTime < $now) {
            return false;
        }

        $diff = $now->diff($bookingTime);
        $hoursRemaining = ($diff->days * 24) + $diff->h + ($diff->i / 60);
        $sent = false;

        if ($settings->emailRemindersEnabled
            && !$reservation->emailReminder24hSent
            && $hoursRemaining <= $settings->emailReminderHoursBefore
            && $hoursRemaining > 0
            && $this->claimReminderFlag($reservation->id, 'emailReminder24hSent')) {
            if ($this->sendEmailReminder($reservation, '24h')) {
                $sent = true;
            } else {
                $this->revertReminderFlag($reservation->id, 'emailReminder24hSent');
            }
        }

        if ($settings->smsRemindersEnabled
            && !$reservation->smsReminder24hSent
            && $hoursRemaining <= $settings->smsReminderHoursBefore
            && $hoursRemaining > 0
            && $this->claimReminderFlag($reservation->id, 'smsReminder24hSent')) {
            if ($this->sendSmsReminder($reservation, '24h')) {
                $sent = true;
            } else {
                $this->revertReminderFlag($reservation->id, 'smsReminder24hSent');
            }
        }

        return $sent;
    }

    /**
     * Atomically claim a reminder flag using UPDATE ... WHERE to prevent duplicate sends.
     */
    protected function claimReminderFlag(int $reservationId, string $flagColumn): bool
    {
        if (!in_array($flagColumn, self::ALLOWED_REMINDER_FLAGS, true)) {
            throw new \InvalidArgumentException("Invalid reminder flag column: {$flagColumn}");
        }

        $affectedRows = Craft::$app->getDb()->createCommand()
            ->update(
                '{{%booked_reservations}}',
                [$flagColumn => true],
                ['and', ['id' => $reservationId], [$flagColumn => false]],
            )
            ->execute();

        return $affectedRows > 0;
    }

    /**
     * Revert a claimed reminder flag so the reminder can be retried on the next run.
     */
    protected function revertReminderFlag(int $reservationId, string $flagColumn): void
    {
        if (!in_array($flagColumn, self::ALLOWED_REMINDER_FLAGS, true)) {
            throw new \InvalidArgumentException("Invalid reminder flag column: {$flagColumn}");
        }

        Craft::warning("Reverting reminder flag '{$flagColumn}' for reservation #{$reservationId} after send failure", __METHOD__);

        Craft::$app->getDb()->createCommand()
            ->update(
                '{{%booked_reservations}}',
                [$flagColumn => false],
                ['id' => $reservationId],
            )
            ->execute();
    }

    /** @return ReservationInterface[] */
    public function getPendingReminders(): array
    {
        $settings = Booked::getInstance()->getSettings();

        // Upper bound: only fetch reservations within the maximum reminder window
        $maxHours = max(
            $settings->emailRemindersEnabled ? ($settings->emailReminderHoursBefore ?? 24) : 0,
            $settings->smsRemindersEnabled ? ($settings->smsReminderHoursBefore ?? 24) : 0,
        );
        $upperBound = (new DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone())))
            ->modify("+{$maxHours} hours")
            ->format('Y-m-d');

        return ReservationFactory::find()
            ->siteId('*')
            ->status(ReservationRecord::STATUS_CONFIRMED)
            ->andWhere(['>=', 'bookingDate', DateHelper::today()])
            ->andWhere(['<=', 'bookingDate', $upperBound])
            // Only fetch reservations that still have at least one unsent reminder flag
            ->andWhere(['or',
                ['emailReminder24hSent' => false],
                ['smsReminder24hSent' => false],
            ])
            ->all();
    }

    protected function sendEmailReminder(ReservationInterface $reservation, string $type): bool
    {
        Booked::getInstance()->bookingNotification->queueBookingEmail($reservation->id, 'reminder_' . $type);
        return true;
    }

    protected function sendSmsReminder(ReservationInterface $reservation, string $type): bool
    {
        if (empty($reservation->userPhone)) {
            Craft::info("No phone number for reservation #{$reservation->id} - skipping SMS reminder", __METHOD__);
            return false;
        }

        if (!Booked::getInstance()->getSettings()->isSmsConfigured()) {
            Craft::info("SMS not configured or not Pro edition - skipping SMS reminder for reservation #{$reservation->id}", __METHOD__);
            return false;
        }

        return Booked::getInstance()->getTwilioSms()->sendReminder($reservation, $type);
    }
}
