<?php

namespace anvildev\booked\elements\conditions;

use craft\elements\conditions\ElementCondition;

class EventDateCondition extends ElementCondition
{
    protected function selectableConditionRules(): array
    {
        return array_merge(parent::selectableConditionRules(), [
            EventDateDateConditionRule::class,
            EventDateLocationConditionRule::class,
        ]);
    }
}
