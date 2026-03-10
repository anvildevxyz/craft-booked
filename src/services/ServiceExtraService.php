<?php

namespace anvildev\booked\services;

use anvildev\booked\elements\ServiceExtra;
use anvildev\booked\records\ReservationExtraRecord;
use anvildev\booked\records\ServiceExtraServiceRecord;
use Craft;
use craft\base\Component;

/**
 * Manages optional add-ons (extras) that customers can select during booking.
 *
 * Handles CRUD for ServiceExtra elements, their assignment to services via a
 * pivot table, per-reservation extra selections with quantity validation, and
 * price/duration calculations for the booking flow.
 */
class ServiceExtraService extends Component
{
    /**
     * Return all extras for the current site.
     *
     * Current-site scoping is intentional: ServiceExtra is a localized element,
     * so querying without an explicit siteId returns the correct localized
     * versions for the active site. Use `->siteId('*')` only when you need
     * cross-site results (e.g. for CP index screens).
     *
     * @return ServiceExtra[]
     */
    public function getAllExtras(bool $enabledOnly = false): array
    {
        $query = ServiceExtra::find()->orderBy(['title' => SORT_ASC]);
        if ($enabledOnly) {
            $query->status('enabled');
        }
        return $query->all();
    }

    /** @return ServiceExtra[] */
    public function getExtrasForService(int $serviceId, bool $enabledOnly = true): array
    {
        $extraIds = ServiceExtraServiceRecord::find()
            ->select('extraId')
            ->where(['serviceId' => $serviceId])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->column();

        if (empty($extraIds)) {
            return [];
        }

        $query = ServiceExtra::find()->id($extraIds)->fixedOrder();
        if ($enabledOnly) {
            $query->status('enabled');
        }
        return $query->all();
    }

    /**
     * Batch-load multiple extras by ID in a single query, indexed by ID.
     *
     * @param int[] $ids
     * @return ServiceExtra[]
     */
    protected function getExtrasByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        /** @var ServiceExtra[] */
        return ServiceExtra::find()->id($ids)->status(null)->indexBy('id')->all();
    }

    public function getExtraById(int $id): ?ServiceExtra
    {
        /** @var ServiceExtra|null */
        return ServiceExtra::find()->id($id)->status(null)->one();
    }

    public function saveExtra(ServiceExtra $extra): bool
    {
        return Craft::$app->elements->saveElement($extra);
    }

    /** @param int[] $extraIds */
    public function setExtrasForService(int $serviceId, array $extraIds): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            ServiceExtraServiceRecord::deleteAll(['serviceId' => $serviceId]);

            foreach ($extraIds as $index => $extraId) {
                $record = new ServiceExtraServiceRecord();
                $record->extraId = $extraId;
                $record->serviceId = $serviceId;
                $record->sortOrder = $index;
                if (!$record->save()) {
                    $transaction->rollBack();
                    return false;
                }
            }

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error("Failed to set extras for service {$serviceId}: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /** @return array Array with extra info, quantity, and price */
    public function getExtrasForReservation(int $reservationId): array
    {
        $records = ReservationExtraRecord::find()
            ->where(['reservationId' => $reservationId])
            ->all();

        if (empty($records)) {
            return [];
        }

        // Batch-load all extras in a single query to avoid N+1
        $extraIds = array_map(fn($r) => $r->serviceExtraId, $records);
        $extras = $this->getExtrasByIds($extraIds);

        $results = [];
        foreach ($records as $record) {
            $extra = $extras[$record->serviceExtraId] ?? null;
            if ($extra) {
                $results[] = [
                    'id' => $record->id,
                    'extra' => $extra,
                    'quantity' => $record->quantity,
                    'unitPrice' => $extra->price,
                    'totalPrice' => $record->getTotalPrice(),
                ];
            }
        }
        return $results;
    }

    /** @param array $extras ['extraId' => quantity] */
    public function saveExtrasForReservation(int $reservationId, array $extras): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            ReservationExtraRecord::deleteAll(['reservationId' => $reservationId]);

            $extrasById = $this->getExtrasByIds(array_keys($extras));

            foreach ($extras as $extraId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                $extra = $extrasById[$extraId] ?? null;
                if (!$extra || !$extra->enabled) {
                    continue;
                }

                if (!$extra->isValidQuantity($quantity)) {
                    Craft::warning("Invalid quantity {$quantity} for extra {$extraId}, max is {$extra->maxQuantity}", __METHOD__);
                    continue;
                }

                $record = new ReservationExtraRecord();
                $record->reservationId = $reservationId;
                $record->serviceExtraId = $extraId;
                $record->quantity = $quantity;

                if (!$record->save()) {
                    Craft::error("Failed to save extra {$extraId} for reservation {$reservationId}", __METHOD__);
                    $transaction->rollBack();
                    return false;
                }
            }

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /** @param array $extras ['extraId' => quantity] */
    public function calculateExtrasDuration(array $extras): int
    {
        $validExtras = array_filter($extras, fn($qty) => $qty > 0);
        if (empty($validExtras)) {
            return 0;
        }

        // Batch-load all extras in a single query to avoid N+1
        $extraIds = array_keys($validExtras);
        $loadedExtras = $this->getExtrasByIds($extraIds);

        $total = 0;
        foreach ($validExtras as $extraId => $quantity) {
            $extra = $loadedExtras[$extraId] ?? null;
            if ($extra?->enabled) {
                $total += $extra->getTotalDuration($quantity);
            }
        }
        return $total;
    }

    /** @return string[] Missing required extra names */
    public function validateRequiredExtras(int $serviceId, array $selectedExtras): array
    {
        return array_values(array_filter(array_map(
            fn(ServiceExtra $extra) => $extra->isRequired && ($selectedExtras[$extra->id] ?? 0) <= 0
                ? $extra->title
                : null,
            $this->getExtrasForService($serviceId),
        )));
    }

    public function getExtrasSummary(int $reservationId): string
    {
        $extras = $this->getExtrasForReservation($reservationId);
        if (empty($extras)) {
            return '';
        }

        return implode("\n", array_map(function(array $item) {
            $line = $item['extra']->title;
            if ($item['quantity'] > 1) {
                $line .= " x{$item['quantity']}";
            }
            return $line . ' - ' . Craft::$app->formatter->asCurrency($item['totalPrice']);
        }, $extras));
    }

    public function getTotalExtrasPrice(int $reservationId): float
    {
        return array_sum(array_map(
            fn($r) => $r->getTotalPrice(),
            ReservationExtraRecord::find()->where(['reservationId' => $reservationId])->all(),
        ));
    }
}
