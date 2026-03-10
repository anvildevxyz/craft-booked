<?php

namespace anvildev\booked\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class ServiceExtraQuery extends ElementQuery
{
    public ?float $price = null;
    public ?int $duration = null;
    public ?int $maxQuantity = null;
    public ?bool $isRequired = null;

    public function price(?float $value): static
    {
        $this->price = $value;
        return $this;
    }

    public function duration(?int $value): static
    {
        $this->duration = $value;
        return $this;
    }

    public function maxQuantity(?int $value): static
    {
        $this->maxQuantity = $value;
        return $this;
    }

    public function isRequired(?bool $value): static
    {
        $this->isRequired = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $t = 'booked_service_extras';
        $this->joinElementTable($t);

        $this->query->addSelect([
            "$t.propagationMethod", "$t.price", "$t.duration",
            "$t.maxQuantity", "$t.isRequired", "$t.description",
        ]);

        foreach (['price', 'duration', 'maxQuantity', 'isRequired'] as $param) {
            if ($this->$param !== null) {
                $this->subQuery->andWhere(Db::parseParam("$t.$param", $this->$param));
            }
        }

        return true;
    }
}
