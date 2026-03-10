<?php

namespace anvildev\booked\services;

use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\elements\Reservation;
use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\db\Query;

/**
 * Integrates with Craft Commerce for paid bookings.
 *
 * Links reservations to Commerce orders, manages cart line items for
 * bookable services, and provides lookup between orders and reservations.
 */
class CommerceService extends Component
{
    public function linkOrderToReservation(int $orderId, int $reservationId): bool
    {
        $now = Db::prepareDateForDb(new \DateTime());

        try {
            return Craft::$app->db->createCommand()
                ->insert('{{%booked_order_reservations}}', [
                    'orderId' => $orderId,
                    'reservationId' => $reservationId,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ])
                ->execute() > 0;
        } catch (\yii\db\IntegrityException) {
            return true;
        }
    }

    public function getReservationByOrderId(int $orderId): ?Reservation
    {
        $reservationId = (new Query())
            ->select(['reservationId'])
            ->from(['{{%booked_order_reservations}}'])
            ->where(['orderId' => $orderId])
            ->scalar();

        return $reservationId ? Reservation::findOne($reservationId) : null;
    }

    public function getOrderByReservationId(int $reservationId): ?Order
    {
        $orderId = (new Query())
            ->select(['orderId'])
            ->from(['{{%booked_order_reservations}}'])
            ->where(['reservationId' => $reservationId])
            ->scalar();

        return $orderId ? Order::findOne($orderId) : null;
    }

    public function addReservationToCart(Reservation $reservation): bool
    {
        $cart = Commerce::getInstance()->getCarts()->getCart();
        if (!$cart) {
            return false;
        }

        // Check if the cart already contains a line item for this purchasable
        foreach ($cart->getLineItems() as $existingLineItem) {
            if ($existingLineItem->purchasableId === $reservation->id) {
                Craft::info("Cart already contains line item for reservation #{$reservation->id}, skipping duplicate", __METHOD__);
                return true;
            }
        }

        $quantity = $reservation->quantity ?? 1;
        if ($quantity <= 0) {
            Craft::error("Cannot create line item with quantity {$quantity}", __METHOD__);
            return false;
        }

        $lineItem = Commerce::getInstance()->getLineItems()->create($cart, [
            'purchasableId' => $reservation->id,
            'qty' => $quantity,
        ]);
        $cart->addLineItem($lineItem);

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            if (!Craft::$app->getElements()->saveElement($cart)) {
                $transaction->rollBack();
                return false;
            }

            $linked = $this->linkOrderToReservation($cart->id, $reservation->id);
            if (!$linked) {
                $transaction->rollBack();
                return false;
            }

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Syncs a Commerce order's line item quantity and price after a reservation quantity change.
     */
    public function syncLineItemQuantity(ReservationInterface $reservation): bool
    {
        $order = $this->getOrderByReservationId($reservation->getId());
        if (!$order) {
            Craft::warning("No order found for reservation #{$reservation->getId()} during line item sync", __METHOD__);
            return false;
        }

        $lineItems = $order->getLineItems();
        $found = false;
        $updated = false;
        foreach ($lineItems as $lineItem) {
            if ($lineItem->purchasableId !== $reservation->getId()) {
                continue;
            }

            $found = true;
            if ($lineItem->qty !== $reservation->getQuantity()) {
                $lineItem->qty = $reservation->getQuantity();
                $lineItem->salePrice = $reservation->getTotalPrice() / max($reservation->getQuantity(), 1);
                $updated = true;
            }
        }

        if (!$found) {
            Craft::warning("No matching line item found for reservation #{$reservation->getId()} in order #{$order->id}", __METHOD__);
            return false;
        }

        if (!$updated) {
            return true;
        }

        $order->setLineItems($lineItems);

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            if (!Commerce::getInstance()->getOrders()->saveOrder($order)) {
                $transaction->rollBack();
                Craft::error("Failed to save order #{$order->id} for reservation #{$reservation->getId()}: " . json_encode($order->getErrors()), __METHOD__);
                return false;
            }
            $transaction->commit();
            Craft::info("Synced line item for reservation #{$reservation->getId()} to qty {$reservation->getQuantity()}", __METHOD__);
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error("Failed to sync line item for reservation #{$reservation->getId()}: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
