<?php

namespace anvildev\booked\helpers;

class DateHelper
{
    public static function parseTime(string $time, ?string $timezone = null): ?\DateTime
    {
        if (empty($time)) {
            return null;
        }

        $dt = \DateTime::createFromFormat('H:i:s', $time)
            ?: \DateTime::createFromFormat('H:i', $time);

        return $dt ? self::applyTimezone($dt, $timezone) : null;
    }

    public static function parseDate(string $date, ?string $timezone = null): ?\DateTime
    {
        if (empty($date)) {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $date);

        return $dt ? self::applyTimezone($dt, $timezone) : null;
    }

    public static function parseDateTime(string $date, string $time, ?string $timezone = null): ?\DateTime
    {
        if (empty($date) || empty($time)) {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', "$date $time")
            ?: \DateTime::createFromFormat('Y-m-d H:i', "$date $time");

        return $dt ? self::applyTimezone($dt, $timezone) : null;
    }

    public static function today(): string
    {
        return (new \DateTime())->format('Y-m-d');
    }

    public static function relativeDate(string $modify): string
    {
        return (new \DateTime())->modify($modify)->format('Y-m-d');
    }

    /**
     * Convert an IANA timezone to Windows timezone name for Outlook API.
     * Falls back to UTC if mapping not found.
     */
    public static function toWindowsTimezone(string $iana): string
    {
        $map = [
            'Europe/Zurich' => 'W. Europe Standard Time',
            'Europe/Berlin' => 'W. Europe Standard Time',
            'Europe/Vienna' => 'W. Europe Standard Time',
            'Europe/Amsterdam' => 'W. Europe Standard Time',
            'Europe/Brussels' => 'Romance Standard Time',
            'Europe/Paris' => 'Romance Standard Time',
            'Europe/Rome' => 'W. Europe Standard Time',
            'Europe/Madrid' => 'Romance Standard Time',
            'Europe/London' => 'GMT Standard Time',
            'Europe/Dublin' => 'GMT Standard Time',
            'Europe/Lisbon' => 'GMT Standard Time',
            'Europe/Stockholm' => 'W. Europe Standard Time',
            'Europe/Oslo' => 'W. Europe Standard Time',
            'Europe/Copenhagen' => 'Romance Standard Time',
            'Europe/Helsinki' => 'FLE Standard Time',
            'Europe/Warsaw' => 'Central European Standard Time',
            'Europe/Prague' => 'Central Europe Standard Time',
            'Europe/Budapest' => 'Central Europe Standard Time',
            'Europe/Bucharest' => 'GTB Standard Time',
            'Europe/Athens' => 'GTB Standard Time',
            'Europe/Moscow' => 'Russian Standard Time',
            'Europe/Istanbul' => 'Turkey Standard Time',
            'America/New_York' => 'Eastern Standard Time',
            'America/Chicago' => 'Central Standard Time',
            'America/Denver' => 'Mountain Standard Time',
            'America/Los_Angeles' => 'Pacific Standard Time',
            'America/Anchorage' => 'Alaskan Standard Time',
            'Pacific/Honolulu' => 'Hawaiian Standard Time',
            'America/Toronto' => 'Eastern Standard Time',
            'America/Vancouver' => 'Pacific Standard Time',
            'America/Sao_Paulo' => 'E. South America Standard Time',
            'America/Argentina/Buenos_Aires' => 'Argentina Standard Time',
            'America/Mexico_City' => 'Central Standard Time (Mexico)',
            'Asia/Tokyo' => 'Tokyo Standard Time',
            'Asia/Shanghai' => 'China Standard Time',
            'Asia/Hong_Kong' => 'China Standard Time',
            'Asia/Singapore' => 'Singapore Standard Time',
            'Asia/Seoul' => 'Korea Standard Time',
            'Asia/Kolkata' => 'India Standard Time',
            'Asia/Dubai' => 'Arabian Standard Time',
            'Asia/Jerusalem' => 'Israel Standard Time',
            'Australia/Sydney' => 'AUS Eastern Standard Time',
            'Australia/Melbourne' => 'AUS Eastern Standard Time',
            'Australia/Perth' => 'W. Australia Standard Time',
            'Pacific/Auckland' => 'New Zealand Standard Time',
            'Africa/Cairo' => 'Egypt Standard Time',
            'Africa/Johannesburg' => 'South Africa Standard Time',
            'UTC' => 'UTC',
        ];

        if (!isset($map[$iana])) {
            \Craft::warning("No Windows timezone mapping for '{$iana}', falling back to UTC", __METHOD__);
            return 'UTC';
        }

        return $map[$iana];
    }

    /**
     * Ensure a time or datetime string includes seconds.
     * Converts 'HH:MM' to 'HH:MM:SS' and leaves 'HH:MM:SS' unchanged.
     */
    public static function ensureSeconds(string $time): string
    {
        // If it looks like a datetime with T separator, handle the time part
        if (str_contains($time, 'T')) {
            $parts = explode('T', $time, 2);
            return $parts[0] . 'T' . self::ensureSeconds($parts[1]);
        }

        // Count colons to determine format
        if (substr_count($time, ':') === 1) {
            return $time . ':00';
        }

        return $time;
    }

    /**
     * Format a time value using locale-aware formatting (e.g. "2:00 PM" for en-US, "14:00" for de).
     */
    public static function formatTimeLocale(\DateTimeInterface $time, ?string $locale = null, ?string $timezone = null): string
    {
        $locale = $locale ?: (\Craft::$app->language ?: 'en');
        $timezone = $timezone ?: \Craft::$app->getTimeZone();

        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::SHORT,
            $timezone
        );

        return $formatter->format($time);
    }

    /**
     * Format a date string (Y-m-d) using locale-aware formatting.
     */
    public static function formatDateLocale(string $dateStr, ?string $locale = null, ?string $timezone = null, int $dateType = \IntlDateFormatter::LONG): string
    {
        $date = \DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$date) {
            return $dateStr;
        }

        $locale = $locale ?: (\Craft::$app->language ?: 'en');
        $timezone = $timezone ?: \Craft::$app->getTimeZone();

        $formatter = new \IntlDateFormatter(
            $locale,
            $dateType,
            \IntlDateFormatter::NONE,
            $timezone
        );

        return $formatter->format($date);
    }

    private static function applyTimezone(\DateTime $dt, ?string $timezone): \DateTime
    {
        if ($timezone) {
            try {
                $dt->setTimezone(new \DateTimeZone($timezone));
            } catch (\Exception) {
            }
        }

        return $dt;
    }
}
