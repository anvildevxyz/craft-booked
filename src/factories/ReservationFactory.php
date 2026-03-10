<?php

namespace anvildev\booked\factories;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\contracts\ReservationQueryInterface;
use anvildev\booked\elements\Reservation;
use anvildev\booked\models\ReservationModel;

class ReservationFactory
{
    private const ALLOWED_CREATE_ATTRIBUTES = ['siteId'];

    public static function create(array $attributes = []): ReservationInterface
    {
        $reservation = self::isElementMode() ? new Reservation() : new ReservationModel();

        foreach (array_intersect_key($attributes, array_flip(self::ALLOWED_CREATE_ATTRIBUTES)) as $key => $value) {
            if (property_exists($reservation, $key)) {
                $reservation->$key = $value;
            }
        }

        return $reservation;
    }

    public static function find(): ReservationQueryInterface
    {
        return ReservationModel::find()->siteId('*');
    }

    public static function findById(int $id): ?ReservationInterface
    {
        return self::find()->id($id)->one();
    }

    public static function findByToken(string $token): ?ReservationInterface
    {
        return $token === '' ? null : self::find()->confirmationToken($token)->one();
    }

    public static function isElementMode(): bool
    {
        $plugin = Booked::getInstance();
        return $plugin !== null && $plugin->isCommerceEnabled();
    }
}
