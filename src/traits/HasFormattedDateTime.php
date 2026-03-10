<?php

namespace anvildev\booked\traits;

use anvildev\booked\helpers\DateHelper;
use Craft;

/**
 * Shared formatted date/time display for Reservation element, model, and record.
 *
 * Expects the using class to have: $bookingDate, $startTime, $endTime, $userTimezone properties.
 */
trait HasFormattedDateTime
{
    public function getFormattedDateTime(): string
    {
        if (empty($this->bookingDate) || empty($this->startTime) || empty($this->endTime)) {
            return '';
        }

        $date = \DateTime::createFromFormat('Y-m-d', $this->bookingDate);
        $startTime = DateHelper::parseTime($this->startTime);
        $endTime = DateHelper::parseTime($this->endTime);

        if (!$date || !$startTime || !$endTime) {
            return $this->bookingDate . ' ' . $this->startTime . ' - ' . $this->endTime;
        }

        $locale = Craft::$app->language ?: 'en';
        $timezone = $this->userTimezone ?: Craft::$app->getTimeZone();

        $dateFormatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            $timezone
        );

        return $dateFormatter->format($date) . ' ' .
            Craft::t('booked', 'dateTime.fromTime') . ' ' .
            DateHelper::formatTimeLocale($startTime, $locale, $timezone) . ' ' .
            Craft::t('booked', 'dateTime.toTime') . ' ' .
            DateHelper::formatTimeLocale($endTime, $locale, $timezone);
    }
}
