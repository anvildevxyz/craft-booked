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

    /**
     * Unlike core actions such as Delete, a custom ElementAction gets no
     * canSave()/canDelete() check from Craft itself — Reservation::canSave()
     * (admin or booked-manageBookings) already states who may mutate a
     * booking, so it's checked here rather than left unenforced.
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        $elementsService = Craft::$app->getElements();

        $count = 0;
        foreach ($query->all() as $reservation) {
            if ($elementsService->canSave($reservation) && $reservation->markAsNoShow()) {
                $count++;
            }
        }

        $this->setMessage(Craft::t('booked', 'action.markedAsNoShow', ['count' => $count]));
        return $count > 0;
    }
}
