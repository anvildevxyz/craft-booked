<?php

namespace anvildev\booked\traits;

use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\helpers\DateHelper;

trait HasMultiDaySupport
{
    public function isMultiDay(): bool
    {
        return $this->endDate !== null && $this->endDate !== '';
    }

    public function getDurationDays(): ?int
    {
        if (!$this->isMultiDay()) {
            return null;
        }

        $start = \DateTime::createFromFormat('Y-m-d', $this->bookingDate);
        $end = \DateTime::createFromFormat('Y-m-d', $this->endDate);

        if (!$start || !$end) {
            return null;
        }

        return (int)$start->diff($end)->days + 1;
    }

    public function getDurationMinutes(): int
    {
        if ($this->isMultiDay()) {
            return 0;
        }

        $start = DateHelper::parseTime($this->startTime);
        $end = DateHelper::parseTime($this->endTime);

        if (!$start || !$end) {
            return 0;
        }

        $diff = $start->diff($end);
        return (int) ($diff->h * 60 + $diff->i);
    }

    public function conflictsWith(ReservationInterface $other): bool
    {
        $thisStart = $this->bookingDate;
        $thisEnd = $this->isMultiDay() ? $this->endDate : $this->bookingDate;
        $otherStart = $other->getBookingDate();
        $otherEnd = $other->getEndDate() ?? $other->getBookingDate();

        // Date range overlap check
        if ($thisStart > $otherEnd || $thisEnd < $otherStart) {
            return false;
        }

        // If both are on the same single day and neither is multi-day, check time overlap
        if (!$this->isMultiDay() && !$other->isMultiDay() && $thisStart === $otherStart) {
            $thisStartTime = DateHelper::parseTime($this->getStartTime());
            $thisEndTime = DateHelper::parseTime($this->getEndTime());
            $otherStartTime = DateHelper::parseTime($other->getStartTime());
            $otherEndTime = DateHelper::parseTime($other->getEndTime());

            if (!$thisStartTime || !$thisEndTime || !$otherStartTime || !$otherEndTime) {
                return false;
            }

            return !($thisEndTime->getTimestamp() <= $otherStartTime->getTimestamp() || $thisStartTime->getTimestamp() >= $otherEndTime->getTimestamp());
        }

        // Multi-day overlapping with any date = conflict
        return true;
    }

    public function getTotalPrice(): float
    {
        $service = $this->getService();
        $servicePrice = 0.0;

        if ($service && isset($service->price)) {
            $basePrice = (float)$service->price;

            if ($service->isPerUnitPricing() && $service->isDayService() && $this->isMultiDay()) {
                $servicePrice = $basePrice * $this->getDurationDays() * $this->quantity;
            } else {
                $servicePrice = $basePrice * $this->quantity;
            }
        }

        $eventDate = $this->getEventDate();
        $eventPrice = ($eventDate && $eventDate->price) ? (float)$eventDate->price * $this->quantity : 0.0;
        return $servicePrice + $eventPrice + $this->getExtrasPrice();
    }
}
