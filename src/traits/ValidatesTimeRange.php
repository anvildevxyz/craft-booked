<?php

namespace anvildev\booked\traits;

use anvildev\booked\helpers\DateHelper;
use Craft;

trait ValidatesTimeRange
{
    public function validateTimeRange(): void
    {
        if (!$this->startTime || !$this->endTime) {
            return;
        }

        $start = DateHelper::parseTime($this->startTime);
        $end = DateHelper::parseTime($this->endTime);
        if (!$start || !$end) {
            return;
        }

        $effectiveEndDate = method_exists($this, 'getEffectiveEndDate')
            ? $this->getEffectiveEndDate()
            : ($this->eventDate ?? null);
        $startDate = $this->eventDate ?? null;

        if ($effectiveEndDate && $startDate && $effectiveEndDate > $startDate) {
            return;
        }

        if ($end->getTimestamp() <= $start->getTimestamp()) {
            $this->addError('endTime', Craft::t('booked', 'validation.endTimeAfterStart'));
        }
    }
}
