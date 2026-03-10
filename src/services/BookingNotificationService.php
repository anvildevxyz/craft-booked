<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\helpers\PiiRedactor;
use anvildev\booked\queue\jobs\SendBookingEmailJob;
use anvildev\booked\queue\jobs\SendSmsJob;
use anvildev\booked\queue\jobs\SyncToCalendarJob;
use Craft;
use craft\base\Component;

/**
 * Queues booking-related notifications asynchronously: confirmation/cancellation emails,
 * owner notifications, calendar sync jobs, and SMS messages via Twilio.
 */
class BookingNotificationService extends Component
{
    /**
     * @param string $emailType 'confirmation', 'status_change', 'cancellation', 'owner_notification'
     * @param string|null $oldStatus For status change emails
     */
    public function queueBookingEmail(
        int $reservationId,
        string $emailType,
        ?string $oldStatus = null,
        int $priority = 1024,
    ): void {
        Craft::$app->getQueue()->priority($priority)->push(new SendBookingEmailJob([
            'reservationId' => $reservationId,
            'emailType' => $emailType,
            'oldStatus' => $oldStatus,
        ]));
        Craft::info("Queued {$emailType} email for reservation #{$reservationId}", __METHOD__);
    }

    public function queueCalendarSync(int $reservationId, int $priority = 1024): void
    {
        Craft::$app->getQueue()->priority($priority)->push(new SyncToCalendarJob([
            'reservationId' => $reservationId,
        ]));
        Craft::info("Queued calendar sync for reservation #{$reservationId}", __METHOD__);
    }

    /** Only queues if owner notification is enabled and an email is configured. */
    public function queueOwnerNotification(int $reservationId, int $priority = 1024): void
    {
        $settings = Booked::getInstance()->getSettings();

        if (!$settings->ownerNotificationEnabled) {
            Craft::info("Owner notification disabled - skipping for reservation #{$reservationId}", __METHOD__);
            return;
        }

        if (empty($settings->getEffectiveEmail())) {
            Craft::warning("No owner email configured - skipping notification for reservation #{$reservationId}", __METHOD__);
            return;
        }

        Craft::$app->getQueue()->priority($priority)->push(new SendBookingEmailJob([
            'reservationId' => $reservationId,
            'emailType' => 'owner_notification',
        ]));
        Craft::info("Queued owner notification email for reservation #{$reservationId}", __METHOD__);
    }

    public function queueCancellationNotification(int $reservationId, int $priority = 1024): void
    {
        if (!Booked::getInstance()->getSettings()->sendCancellationEmail) {
            Craft::info("Cancellation email disabled - skipping for reservation #{$reservationId}", __METHOD__);
            return;
        }

        $this->queueBookingEmail($reservationId, 'cancellation', null, $priority);
    }

    public function queueQuantityChangedEmail(
        int $reservationId,
        int $previousQuantity,
        int $newQuantity,
        float $refundAmount = 0.0,
        int $priority = 1024,
    ): void {
        Craft::$app->getQueue()->priority($priority)->push(new SendBookingEmailJob([
            'reservationId' => $reservationId,
            'emailType' => 'quantity_changed',
            'previousQuantity' => $previousQuantity,
            'newQuantity' => $newQuantity,
            'refundAmount' => $refundAmount,
        ]));
        Craft::info(
            "Queued quantity_changed email for reservation #{$reservationId} ({$previousQuantity} → {$newQuantity})",
            __METHOD__,
        );
    }

    public function queueSmsConfirmation(ReservationInterface $reservation): void
    {
        Craft::info("Attempting to queue SMS confirmation for reservation #{$reservation->id}", __METHOD__);

        if (!$this->canSendSms($reservation, 'smsConfirmationEnabled', 'confirmation')) {
            return;
        }

        $this->pushSmsJob($reservation, 'confirmation');
        Craft::info("Queued SMS confirmation for reservation #{$reservation->id}", __METHOD__);
    }

    public function queueSmsCancellation(ReservationInterface $reservation): void
    {
        if (!$this->canSendSms($reservation, 'smsCancellationEnabled', 'cancellation')) {
            return;
        }

        $this->pushSmsJob($reservation, 'cancellation');
        Craft::info("Queued SMS cancellation for reservation #{$reservation->id}", __METHOD__);
    }

    private function pushSmsJob(ReservationInterface $reservation, string $messageType): void
    {
        $twilioSms = Booked::getInstance()->getTwilioSms();
        $normalizedPhone = $twilioSms->normalizePhoneNumber(
            $reservation->userPhone,
            Booked::getInstance()->getSettings()->defaultCountryCode ?? 'US',
        );

        if (!$normalizedPhone) {
            Craft::warning("Invalid phone number for reservation #{$reservation->id}: " . PiiRedactor::redactPhone($reservation->userPhone), __METHOD__);
            return;
        }

        // Template rendering is deferred to SendSmsJob::execute() to avoid
        // blocking the main request with template compilation and variable resolution.
        Craft::$app->getQueue()->push(new SendSmsJob([
            'to' => $normalizedPhone,
            'reservationId' => $reservation->id,
            'messageType' => $messageType,
        ]));
    }

    private function canSendSms(ReservationInterface $reservation, string $settingKey, string $type): bool
    {
        $settings = Booked::getInstance()->getSettings();

        if (!$settings->isSmsConfigured()) {
            return false;
        }

        if (!($settings->$settingKey ?? false)) {
            Craft::info("SMS {$type} disabled - skipping for reservation #{$reservation->id}", __METHOD__);
            return false;
        }

        if (empty($reservation->userPhone)) {
            Craft::info("No phone number - skipping SMS {$type} for reservation #{$reservation->id}", __METHOD__);
            return false;
        }

        return true;
    }
}
