<?php

namespace anvildev\booked\elements\conditions;

use anvildev\booked\elements\Reservation;
use anvildev\booked\elements\Service;
use Craft;
use craft\base\conditions\BaseElementSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class ReservationServiceConditionRule extends BaseElementSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('booked', 'labels.service');
    }

    protected function elementType(): string
    {
        return Service::class;
    }

    public function getExclusiveQueryParams(): array
    {
        return ['serviceId'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $query->serviceId($this->getElementIds());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Reservation $element */
        return $this->matchValue($element->serviceId);
    }
}
