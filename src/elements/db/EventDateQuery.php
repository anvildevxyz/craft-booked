<?php

namespace anvildev\booked\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @method \anvildev\booked\elements\EventDate[]|array all($db = null)
 * @method \anvildev\booked\elements\EventDate|array|null one($db = null)
 * @method \anvildev\booked\elements\EventDate|array|null nth(int $n, ?Connection $db = null)
 */
class EventDateQuery extends ElementQuery
{
    public ?int $locationId = null;
    public ?string $eventDate = null;
    public ?string $endDate = null;
    public ?string $startTime = null;
    public ?string $endTime = null;
    public ?bool $enabled = null;
    public bool $withTrashed = false;

    public function locationId(?int $value): static
    {
        $this->locationId = $value;
        return $this;
    }

    public function eventDate(?string $value): static
    {
        $this->eventDate = $value;
        return $this;
    }

    public function endDate(?string $value): static
    {
        $this->endDate = $value;
        return $this;
    }

    public function startTime(?string $value): static
    {
        $this->startTime = $value;
        return $this;
    }

    public function endTime(?string $value): static
    {
        $this->endTime = $value;
        return $this;
    }

    public function enabled(?bool $value): static
    {
        $this->enabled = $value;
        return $this;
    }

    public function withTrashed(bool $value = true): static
    {
        $this->withTrashed = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $t = 'booked_event_dates';
        $this->joinElementTable($t);

        $this->query->addSelect([
            "$t.propagationMethod", "$t.locationId", "$t.eventDate", "$t.endDate",
            "$t.startTime", "$t.endTime", "$t.description", "$t.capacity",
            "$t.allowCancellation", "$t.cancellationPolicyHours", "$t.allowRefund", "$t.refundTiers", "$t.price", "$t.enabled", "$t.deletedAt",
            "$t.enableWaitlist",
        ]);

        foreach (['locationId', 'eventDate', 'endDate', 'startTime', 'endTime'] as $param) {
            if ($this->$param !== null) {
                $this->subQuery->andWhere(Db::parseParam("$t.$param", $this->$param));
            }
        }

        if ($this->enabled !== null) {
            $this->subQuery->andWhere(Db::parseParam("$t.enabled", (int) $this->enabled));
        }

        if (!$this->withTrashed) {
            $this->subQuery->andWhere(["$t.deletedAt" => null]);
        }

        return true;
    }
}
