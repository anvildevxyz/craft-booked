<?php

/**
 * Booked plugin for Craft CMS 5.x
 *
 * @link      https://anvildev.xyz
 * @copyright Copyright (c) 2025
 */

namespace anvildev\booked\widgets;

use anvildev\booked\Booked;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\records\ReservationRecord;
use Craft;
use craft\base\Widget;

class BookedWidget extends Widget
{
    public int $lookaheadDays = 1;

    public static function displayName(): string
    {
        return Craft::t('booked', 'widget.todaysBookings');
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@booked/icon.svg');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['lookaheadDays'], 'required'];
        $rules[] = [['lookaheadDays'], 'in', 'range' => [1, 3, 7]];

        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('booked/widgets/_settings', [
            'widget' => $this,
        ]);
    }

    public function getBodyHtml(): ?string
    {
        $permissionService = Booked::getInstance()->getPermission();

        $timezone = new \DateTimeZone(Craft::$app->getTimeZone());
        $today = new \DateTime('now', $timezone);
        $today->setTime(0, 0, 0);

        $endDate = (clone $today)->modify('+' . ($this->lookaheadDays - 1) . ' days');

        $baseQuery = $permissionService->scopeReservationQuery(
            ReservationFactory::find()
                ->bookingDate(['and', '>= ' . $today->format('Y-m-d'), '<= ' . $endDate->format('Y-m-d')])
        );

        // Stats
        $confirmedCount = (clone $baseQuery)->reservationStatus(ReservationRecord::STATUS_CONFIRMED)->count();
        $pendingCount = (clone $baseQuery)->reservationStatus(ReservationRecord::STATUS_PENDING)->count();
        $totalCount = (clone $baseQuery)->reservationStatus([
            ReservationRecord::STATUS_CONFIRMED,
            ReservationRecord::STATUS_PENDING,
        ])->count();

        // Upcoming reservations (next 10, ordered by date+time)
        $upcoming = (clone $baseQuery)
            ->reservationStatus([ReservationRecord::STATUS_CONFIRMED, ReservationRecord::STATUS_PENDING])
            ->orderBy(['booked_reservations.bookingDate' => SORT_ASC, 'booked_reservations.startTime' => SORT_ASC])
            ->limit(10)
            ->all();

        return Craft::$app->getView()->renderTemplate('booked/widgets/booked', [
            'totalCount' => $totalCount,
            'confirmedCount' => $confirmedCount,
            'pendingCount' => $pendingCount,
            'upcoming' => $upcoming,
            'lookaheadDays' => $this->lookaheadDays,
        ]);
    }
}
