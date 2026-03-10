<?php

namespace anvildev\booked\elements\conditions;

use anvildev\booked\elements\EventDate;
use Craft;
use craft\base\conditions\BaseDateRangeConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class EventDateDateConditionRule extends BaseDateRangeConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('booked', 'labels.eventDate');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['eventDate'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $query->eventDate($this->queryParamValue());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var EventDate $element */
        $date = $element->eventDate ? new \DateTime($element->eventDate) : null;

        return $this->matchValue($date);
    }
}
