<?php

namespace anvildev\booked\elements\actions;

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
            if ($reservation->markAsNoShow()) {
                $count++;
            }
        }

        $this->setMessage(Craft::t('booked', 'action.markedAsNoShow', ['count' => $count]));
        return $count > 0;
    }
}
