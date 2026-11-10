<?php

namespace anvildev\booked\factories;

use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\contracts\ReservationQueryInterface;
use anvildev\booked\elements\Reservation;

/**
 * Constructs reservations and reservation queries.
 *
 * A booking is always a Craft element, whether or not Commerce is installed.
 * Call sites name `Reservation` only through these two interfaces, so that
 * decision stays in this file.
 */
class ReservationFactory
{
    private const ALLOWED_CREATE_ATTRIBUTES = ['siteId'];

    public static function create(array $attributes = []): ReservationInterface
    {
        $reservation = new Reservation();

        foreach (array_intersect_key($attributes, array_flip(self::ALLOWED_CREATE_ATTRIBUTES)) as $key => $value) {
            if (property_exists($reservation, $key)) {
                $reservation->$key = $value;
            }
        }

        return $reservation;
    }

    /**
     * Reservations are not localized, so every query spans all sites. Without
     * this, a query made from a non-primary site returns nothing.
     */
    public static function find(): ReservationQueryInterface
    {
        return Reservation::find()->siteId('*');
    }

    public static function findById(int $id): ?ReservationInterface
    {
        return self::find()->id($id)->one();
    }

    public static function findByToken(string $token): ?ReservationInterface
    {
        return $token === '' ? null : self::find()->confirmationToken($token)->one();
    }

    /**
     * Reservations are always elements.
     *
     * Kept so callers written while the element existed only with Commerce
     * still compile. New code should not branch on this.
     */
    public static function isElementMode(): bool
    {
        return true;
    }
}
