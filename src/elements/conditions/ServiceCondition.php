<?php

namespace anvildev\booked\elements\conditions;

use craft\elements\conditions\ElementCondition;

class ServiceCondition extends ElementCondition
{
    protected function selectableConditionRules(): array
    {
        return array_merge(parent::selectableConditionRules(), [
            ServiceDurationConditionRule::class,
            ServicePriceConditionRule::class,
        ]);
    }
}
