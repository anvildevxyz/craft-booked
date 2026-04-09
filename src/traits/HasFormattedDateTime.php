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
        if (empty($this->bookingDate)) {
            return '';
        }

        $locale = Craft::$app->language ?: 'en';
        $timezone = $this->userTimezone ?: Craft::$app->getTimeZone();

        // Multi-day booking: "June 10 – June 12, 2026 (3 days)"
        if ($this->isMultiDay()) {
            $startDate = \DateTime::createFromFormat('Y-m-d', $this->bookingDate);
            $endDate = \DateTime::createFromFormat('Y-m-d', $this->endDate);

            if (!$startDate || !$endDate) {
                return $this->bookingDate . ' – ' . ($this->endDate ?? '');
            }

            $dateFormatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::LONG,
                \IntlDateFormatter::NONE,
                $timezone
            );

            $days = $this->getDurationDays();
            $dayLabel = $days === 1
                ? Craft::t('booked', 'labels.day')
                : Craft::t('booked', 'labels.days');

            return $dateFormatter->format($startDate) . ' – ' .
                $dateFormatter->format($endDate) .
                ' (' . $days . ' ' . $dayLabel . ')';
        }

        // Single-day booking: original behavior
        if (empty($this->startTime) || empty($this->endTime)) {
            return '';
        }

        $date = \DateTime::createFromFormat('Y-m-d', $this->bookingDate);
        $startTime = DateHelper::parseTime($this->startTime);
        $endTime = DateHelper::parseTime($this->endTime);

        if (!$date || !$startTime || !$endTime) {
            return $this->bookingDate . ' ' . $this->startTime . ' - ' . $this->endTime;
        }

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
