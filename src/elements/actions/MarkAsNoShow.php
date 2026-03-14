<?php

namespace anvildev\booked\elements\actions;

use anvildev\booked\records\ReservationRecord;
use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;

class MarkAsNoShow extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('booked', 'action.markAsNoShow');
    }

    public function getConfirmationMessage(): ?string
    {
        return Craft::t('booked', 'action.markAsNoShowConfirm');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $count = 0;
        foreach ($query->all() as $reservation) {
            if ($reservation->status === ReservationRecord::STATUS_CANCELLED
                || $reservation->status === ReservationRecord::STATUS_NO_SHOW) {
                continue;
            }
            $reservation->status = ReservationRecord::STATUS_NO_SHOW;
            if (Craft::$app->elements->saveElement($reservation)) {
                $count++;
            }
        }

        $this->setMessage(Craft::t('booked', 'action.markedAsNoShow', ['count' => $count]));
        return true;
    }
}
