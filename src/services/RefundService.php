<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\events\RefundFailedEvent;
use Craft;
use craft\base\Component;

class RefundService extends Component
{
    public const EVENT_REFUND_FAILED = 'refundFailed';

    public function processFullRefund(ReservationInterface $reservation): bool
    {
        if (!$this->isCommerceAvailable()) {
            Craft::warning("Commerce not available for refund on reservation #{$reservation->getId()}", __METHOD__);
            return false;
        }

        $order = Booked::getInstance()->commerce->getOrderByReservationId($reservation->getId());
        if (!$order) {
            Craft::warning("No order found for reservation #{$reservation->getId()}", __METHOD__);
            return false;
        }

        $transactions = $order->getTransactions();
        $totalPaid = 0.0;
        foreach ($transactions as $transaction) {
            if ($transaction->status === 'success' && $transaction->type !== 'refund') {
                $totalPaid += $transaction->paymentAmount;
            }
        }

        $percentage = Booked::getInstance()->refundPolicy->calculateRefundPercentage($reservation);
        $refundAmount = $totalPaid * ($percentage / 100);
        if ($refundAmount <= 0) {
            return true;
        }

        return $this->executeRefund($reservation, $order, $refundAmount);
    }

    public function processPartialRefund(ReservationInterface $reservation, int $reducedQuantity, int $originalQuantity, float $originalTotalPrice): bool
    {
        if (!$this->isCommerceAvailable()) {
            Craft::warning("Commerce not available for partial refund on reservation #{$reservation->getId()}", __METHOD__);
            return false;
        }

        $order = Booked::getInstance()->commerce->getOrderByReservationId($reservation->getId());
        if (!$order) {
            Craft::warning("No order found for reservation #{$reservation->getId()}", __METHOD__);
            return false;
        }

        $percentage = Booked::getInstance()->refundPolicy->calculateRefundPercentage($reservation);
        $refundAmount = $originalQuantity > 0
            ? round(($originalTotalPrice * $reducedQuantity / $originalQuantity) * ($percentage / 100), 2)
            : 0.0;

        if ($refundAmount <= 0) {
            return true;
        }

        return $this->executeRefund($reservation, $order, $refundAmount);
    }

    private function executeRefund(ReservationInterface $reservation, $order, float $refundAmount): bool
    {
        try {
            $transactions = $order->getTransactions();
            $successfulTransactions = [];

            foreach ($transactions as $transaction) {
                if ($transaction->status === 'success' && $transaction->type !== 'refund') {
                    $successfulTransactions[] = $transaction;
                }
            }

            if (empty($successfulTransactions)) {
                Craft::warning("No successful transaction found for order #{$order->id}", __METHOD__);
                return false;
            }

            // Sum all successful non-refund transactions for the refund cap.
            $totalPaid = array_sum(array_map(fn($t) => $t->paymentAmount, $successfulTransactions));

            if ($refundAmount > $totalPaid) {
                Craft::warning("Refund amount ({$refundAmount}) exceeds total paid ({$totalPaid}) for order #{$order->id} — clamping to total paid", __METHOD__);
                $refundAmount = $totalPaid;
            }

            // Use the most recent successful transaction for the gateway call,
            // as it is most likely to have a valid payment reference.
            $latestTransaction = end($successfulTransactions);

            $commerce = \craft\commerce\Plugin::getInstance();
            $refundTransaction = $commerce->getPayments()->refundTransaction($latestTransaction, $refundAmount);

            if ($refundTransaction->status !== 'success') {
                throw new \RuntimeException("Refund transaction status: {$refundTransaction->status}");
            }

            Craft::info("Refunded {$refundAmount} for reservation #{$reservation->getId()} (order #{$order->id})", __METHOD__);
            return true;
        } catch (\Throwable $e) {
            Craft::error("Refund failed for reservation #{$reservation->getId()}: " . $e->getMessage(), __METHOD__);

            $this->trigger(self::EVENT_REFUND_FAILED, new RefundFailedEvent([
                'reservation' => $reservation,
                'refundAmount' => $refundAmount,
                'error' => $e->getMessage(),
            ]));

            $this->notifyAdminOfFailure($reservation, $refundAmount, $e->getMessage());

            return false;
        }
    }

    private function notifyAdminOfFailure(ReservationInterface $reservation, float $refundAmount, string $error): void
    {
        try {
            $settings = Booked::getInstance()->getSettings();
            if ($settings->ownerEmail) {
                Craft::$app->mailer->compose()
                    ->setTo($settings->ownerEmail)
                    ->setSubject(Craft::t('booked', 'refund.failedSubject'))
                    ->setTextBody(Craft::t('booked', 'refund.failedBody', [
                        'reservationId' => $reservation->getId(),
                        'amount' => $refundAmount,
                        'error' => $error,
                    ]))
                    ->send();
            }
        } catch (\Throwable $mailError) {
            Craft::error("Failed to send refund failure notification: " . $mailError->getMessage(), __METHOD__);
        }
    }

    private function isCommerceAvailable(): bool
    {
        return Craft::$app->plugins->isPluginEnabled('commerce')
            && class_exists(\craft\commerce\Plugin::class);
    }
}
