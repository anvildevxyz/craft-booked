<?php

namespace anvildev\booked\models\db;

use anvildev\booked\contracts\ReservationQueryInterface;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use anvildev\booked\models\ReservationModel;
use anvildev\booked\records\ReservationRecord;
use Craft;
use yii\db\ActiveQuery;

class ReservationModelQuery implements ReservationQueryInterface
{
    private ActiveQuery $query;
    private array $eagerLoad = [];

    public function __construct()
    {
        $this->query = ReservationRecord::find();
    }

    public function id($value): static
    {
        if ($value !== null) {
            $this->query->andWhere(['id' => $value]);
        }
        return $this;
    }

    public function siteId($value): static
    {
        return $this;
    }

    public function userName(?string $value): static
    {
        if ($value !== null) {
            $this->query->andWhere(['like', 'userName', $value]);
        }
        return $this;
    }

    public function userEmail(?string $value): static
    {
        if ($value !== null) {
            $this->query->andWhere(['userEmail' => $value]);
        }
        return $this;
    }

    public function userId(?int $value): static
    {
        if ($value !== null) {
            $this->query->andWhere(['userId' => $value]);
        }
        return $this;
    }

    public function bookingDate(array|string|null $value): static
    {
        if ($value === null) {
            return $this;
        }

        if (!is_array($value)) {
            $this->query->andWhere(['bookingDate' => $value]);
            return $this;
        }

        if (count($value) === 2 && is_string($value[0]) && in_array($value[0], ['>', '<', '>=', '<=', '!=', '<>'])) {
            $this->query->andWhere([$value[0], 'bookingDate', $value[1]]);
        } elseif (isset($value[0]) && $value[0] === 'and') {
            $allowedOps = ['>', '<', '>=', '<=', '!=', '<>'];
            $conditions = ['and'];
            foreach (array_slice($value, 1) as $condition) {
                if (preg_match('/^([<>=!]+)\s*(.+)$/', $condition, $matches) && in_array($matches[1], $allowedOps, true)) {
                    $conditions[] = [$matches[1], 'bookingDate', $matches[2]];
                }
            }
            $this->query->andWhere($conditions);
        } else {
            $this->query->andWhere(['bookingDate' => $value]);
        }

        return $this;
    }

    public function startTime(?string $value): static
    {
        if ($value !== null) {
            $this->query->andWhere(['startTime' => $value]);
        }
        return $this;
    }

    public function endTime(?string $value): static
    {
        if ($value !== null) {
            $this->query->andWhere(['endTime' => $value]);
        }
        return $this;
    }

    public function employeeId(?int $value): static
    {
        if ($value !== null) {
            $this->query->andWhere(['employeeId' => $value]);
        }
        return $this;
    }

    public function locationId(?int $value): static
    {
        if ($value !== null) {
            $this->query->andWhere(['locationId' => $value]);
        }
        return $this;
    }

    public function serviceId(?int $value): static
    {
        if ($value !== null) {
            $this->query->andWhere(['serviceId' => $value]);
        }
        return $this;
    }

    public function eventDateId(?int $value): static
    {
        if ($value !== null) {
            $this->query->andWhere(['eventDateId' => $value]);
        }
        return $this;
    }

    public function status(array|string|null $value): static
    {
        if ($value !== null) {
            if (is_array($value) && count($value) === 2 && $value[0] === 'not') {
                $this->query->andWhere(['not', ['status' => $value[1]]]);
            } else {
                $this->query->andWhere(['status' => $value]);
            }
        }
        return $this;
    }

    public function reservationStatus(array|string|null $value): static
    {
        return $this->status($value);
    }

    public function confirmationToken(?string $value): static
    {
        if ($value !== null) {
            $this->query->andWhere(['confirmationToken' => $value]);
        }
        return $this;
    }

    public function forCurrentUser(): static
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser) {
            $this->query->andWhere([
                'or',
                ['userId' => $currentUser->id],
                ['userEmail' => $currentUser->email],
            ]);
        } else {
            $this->query->andWhere('1 = 0');
        }
        return $this;
    }

    public function withEmployee(): static
    {
        $this->eagerLoad[] = 'employee';
        return $this;
    }

    public function withService(): static
    {
        $this->eagerLoad[] = 'service';
        return $this;
    }

    public function withLocation(): static
    {
        $this->eagerLoad[] = 'location';
        return $this;
    }

    public function withRelations(): static
    {
        $this->eagerLoad = array_merge($this->eagerLoad, ['employee', 'service', 'location']);
        return $this;
    }

    public function orderBy($columns)
    {
        $this->query->orderBy($columns);
        return $this;
    }

    public function limit($limit)
    {
        $this->query->limit($limit);
        return $this;
    }

    public function offset($offset)
    {
        $this->query->offset($offset);
        return $this;
    }

    public function one($db = null)
    {
        /** @var ReservationRecord|null $record */
        $record = $this->query->one($db);
        return $record ? ReservationModel::fromRecord($record) : null;
    }

    public function all($db = null)
    {
        $models = array_map(
            fn(ReservationRecord $r) => ReservationModel::fromRecord($r),
            $this->query->all($db)
        );

        if (!empty($this->eagerLoad) && !empty($models)) {
            $this->eagerLoadRelations($models);
        }

        return $models;
    }

    /** @param ReservationModel[] $models */
    private function eagerLoadRelations(array $models): void
    {
        $loadMap = [
            'service' => [
                'idGetter' => fn($m) => $m->serviceId,
                'query' => fn($ids) => Service::find()->siteId('*')->id($ids)->indexBy('id')->all(),
                'setter' => 'setService',
            ],
            'employee' => [
                'idGetter' => fn($m) => $m->employeeId,
                'query' => fn($ids) => Employee::find()->siteId('*')->id($ids)->indexBy('id')->all(),
                'setter' => 'setEmployee',
            ],
            'location' => [
                'idGetter' => fn($m) => $m->locationId,
                'query' => fn($ids) => Location::find()->siteId('*')->id($ids)->indexBy('id')->all(),
                'setter' => 'setLocation',
            ],
        ];

        foreach ($loadMap as $relation => $config) {
            if (!in_array($relation, $this->eagerLoad)) {
                continue;
            }
            $ids = array_unique(array_filter(array_map($config['idGetter'], $models)));
            if (empty($ids)) {
                continue;
            }
            $entities = ($config['query'])($ids);
            $setter = $config['setter'];
            foreach ($models as $model) {
                $id = ($config['idGetter'])($model);
                if ($id && isset($entities[$id])) {
                    $model->$setter($entities[$id]);
                }
            }
        }
    }

    public function each($batchSize = 100, $db = null): \Generator
    {
        foreach ($this->query->each($batchSize, $db) as $record) {
            /** @var \anvildev\booked\records\ReservationRecord $record */
            yield ReservationModel::fromRecord($record);
        }
    }

    public function sum($q, $db = null)
    {
        return $this->query->sum($q, $db);
    }

    public function count($q = '*', $db = null)
    {
        return $this->query->count($q, $db);
    }

    public function exists($db = null)
    {
        return $this->query->exists($db);
    }

    public function ids(?\yii\db\Connection $db = null): array
    {
        return $this->query->select(['id'])->column($db);
    }

    public function where($condition, $params = [])
    {
        $this->query->where($condition, $params);
        return $this;
    }

    public function andWhere($condition, $params = [])
    {
        $this->query->andWhere($condition, $params);
        return $this;
    }

    public function getQuery(): ActiveQuery
    {
        return $this->query;
    }
}
