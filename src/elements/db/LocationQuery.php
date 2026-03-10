<?php

namespace anvildev\booked\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @method \anvildev\booked\elements\Location[]|array all($db = null)
 * @method \anvildev\booked\elements\Location|array|null one($db = null)
 * @method \anvildev\booked\elements\Location|array|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class LocationQuery extends ElementQuery
{
    public ?string $timezone = null;
    public ?bool $enabled = null;

    public function timezone(?string $value): static
    {
        $this->timezone = $value;
        return $this;
    }

    public function enabled(?bool $value = true): static
    {
        $this->enabled = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $t = 'booked_locations';
        $this->joinElementTable($t);

        $this->query->addSelect([
            "$t.timezone", "$t.addressLine1", "$t.addressLine2",
            "$t.locality", "$t.administrativeArea", "$t.postalCode", "$t.countryCode",
        ]);

        if ($this->enabled !== null) {
            $this->subQuery->andWhere(Db::parseParam('elements.enabled', (int)$this->enabled));
        }

        if ($this->timezone !== null) {
            $this->subQuery->andWhere(Db::parseParam("$t.timezone", $this->timezone));
        }

        return true;
    }
}
