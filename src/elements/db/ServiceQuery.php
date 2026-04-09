<?php

namespace anvildev\booked\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @method \anvildev\booked\elements\Service[]|array all($db = null)
 * @method \anvildev\booked\elements\Service|array|null one($db = null)
 * @method \anvildev\booked\elements\Service|array|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class ServiceQuery extends ElementQuery
{
    public ?int $duration = null;
    public ?float $price = null;
    public ?string $durationType = null;
    public ?int $minDays = null;
    public ?int $maxDays = null;
    public ?bool $enabled = null;
    public bool $withTrashed = false;
    public array|int|null $locationId = null;

    public function duration(?int $value): static
    {
        $this->duration = $value;
        return $this;
    }

    public function price(?float $value): static
    {
        $this->price = $value;
        return $this;
    }

    public function durationType(?string $value): static
    {
        $this->durationType = $value;
        return $this;
    }

    public function enabled(?bool $value = true): static
    {
        $this->enabled = $value;
        return $this;
    }

    public function withTrashed(bool $value = true): static
    {
        $this->withTrashed = $value;
        return $this;
    }

    public function locationId(array|int|null $value): static
    {
        $this->locationId = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $t = 'booked_services';
        $this->joinElementTable($t);
        $this->subQuery->andWhere(['is not', "$t.id", null]);

        $this->query->addSelect([
            "$t.propagationMethod", "$t.description", "$t.duration", "$t.bufferBefore", "$t.bufferAfter",
            "$t.price", "$t.allowCancellation", "$t.cancellationPolicyHours", "$t.allowRefund", "$t.refundTiers", "$t.virtualMeetingProvider", "$t.minTimeBeforeBooking",
            "$t.durationType", "$t.pricingMode", "$t.minDays", "$t.maxDays",
            "$t.timeSlotLength", "$t.availabilitySchedule",
            "$t.customerLimitEnabled", "$t.customerLimitCount",
            "$t.customerLimitPeriod", "$t.customerLimitPeriodType", "$t.enableWaitlist",
            "$t.taxCategoryId", "$t.deletedAt",
        ]);

        foreach (['duration', 'price', 'durationType', 'minDays', 'maxDays'] as $param) {
            if ($this->$param !== null) {
                $this->subQuery->andWhere(Db::parseParam("$t.$param", $this->$param));
            }
        }

        if ($this->enabled !== null) {
            $this->subQuery->andWhere(Db::parseParam('elements.enabled', (int)$this->enabled));
        }

        if (!$this->withTrashed) {
            $this->subQuery->andWhere(["$t.deletedAt" => null]);
        }

        if ($this->locationId !== null) {
            $this->subQuery->andWhere([
                'in', 'elements.id',
                (new \craft\db\Query())
                    ->select(['serviceId'])
                    ->from('{{%booked_service_locations}}')
                    ->where(['in', 'locationId', (array) $this->locationId]),
            ]);
        }

        return true;
    }
}
