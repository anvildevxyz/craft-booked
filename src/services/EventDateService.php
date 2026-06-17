<?php

namespace anvildev\booked\services;

use anvildev\booked\elements\EventDate;
use anvildev\booked\exceptions\BookingConflictException;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\helpers\DateHelper;
use Craft;
use craft\base\Component;

/**
 * Manages one-time bookable events with CRUD operations and capacity tracking.
 */
class EventDateService extends Component
{
    /**
     * @param int|string|null $siteId Site ID, '*' for all sites, or null for all sites (legacy default)
     */
    public function getEventDates(?string $dateFrom = null, ?string $dateTo = null, int|string|null $siteId = '*', bool $enabledOnly = true, ?int $limit = null, ?int $offset = null): array
    {
        $query = EventDate::find()
            ->siteId($siteId)
            ->unique()
            ->orderBy(['eventDate' => SORT_ASC, 'startTime' => SORT_ASC]);

        if ($enabledOnly) {
            $query->enabled(true);
        } else {
            // Include retired events so they can be found and re-enabled.
            $query->status(null);
        }

        if ($dateFrom) {
            $query->andWhere(['>=', 'booked_event_dates.eventDate', $dateFrom]);
        }
        if ($dateTo) {
            $query->andWhere(['<=', 'booked_event_dates.eventDate', $dateTo]);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }
        if ($offset !== null) {
            $query->offset($offset);
        }

        return $query->all();
    }

    /**
     * Get available event dates (not fully booked, in the future).
     *
     * @param int|string|null $siteId Site ID, '*' for all sites, or null for all sites (legacy default)
     */
    public function getAvailableEventDates(?string $dateFrom = null, int|string|null $siteId = '*'): array
    {
        return array_values(array_filter(
            $this->getEventDates($dateFrom ?? DateHelper::today(), null, $siteId),
            fn($event) => $event->isAvailable()
        ));
    }

    public function getEventDateById(int $id, bool $includeDisabled = false): ?EventDate
    {
        $query = EventDate::find()->siteId('*')->id($id);
        if ($includeDisabled) {
            // Admin/MCP management needs to resolve retired events too; booking
            // flows keep the default enabled-only lookup.
            $query->status(null);
        }
        return $query->one();
    }

    /** @throws \Exception */
    public function createEventDate(array $data): EventDate
    {
        $event = new EventDate();
        return $this->saveEventDate($event, $data, 'save');
    }

    /** @throws \Exception */
    public function updateEventDate(int $id, array $data, bool $includeDisabled = false): EventDate
    {
        $event = $this->getEventDateById($id, $includeDisabled)
            ?? throw new \Exception("Event date with ID {$id} not found");

        return $this->saveEventDate($event, $data, 'update');
    }

    /** @throws \Exception */
    public function deleteEventDate(int $id, bool $includeDisabled = false): bool
    {
        $event = $this->getEventDateById($id, $includeDisabled)
            ?? throw new \Exception("Event date with ID {$id} not found");

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Note: There is a small race window between the reservation count check and the
            // element deletion below. A new reservation could theoretically be created in this
            // gap. This is acceptable because: (1) the booking flow uses soft locks which prevent
            // most concurrent bookings, and (2) a database-level foreign key constraint on
            // eventDateId would catch any remaining edge cases at the DB layer.
            $count = ReservationFactory::find()->eventDateId($id)->count();
            if ($count > 0) {
                $transaction->rollBack();
                // Typed (Booked) exception so the message survives the MCP guard()
                // sanitiser — the caller needs the actionable "retire instead" reason,
                // not a generic "internal error".
                throw new BookingConflictException("Cannot delete event date: {$count} reservation(s) exist for this event. Retire it with enabled=false instead.");
            }

            $result = Craft::$app->elements->deleteElement($event);
            $transaction->commit();
            return $result;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    public function getRemainingCapacity(int $eventDateId): ?int
    {
        return $this->getEventDateById($eventDateId)?->getRemainingCapacity();
    }

    public function getBookedCount(int $eventDateId): int
    {
        return (int) (new \yii\db\Query())
            ->from('{{%booked_reservations}}')
            ->where(['eventDateId' => $eventDateId, 'status' => ['confirmed', 'pending']])
            ->sum('[[quantity]]');
    }

    /** @return array<int, int> Map of eventDateId => booked quantity */
    public function getBookedCountBatch(array $eventDateIds): array
    {
        if (empty($eventDateIds)) {
            return [];
        }

        $rows = (new \yii\db\Query())
            ->select(['eventDateId', 'total' => 'SUM([[quantity]])'])
            ->from('{{%booked_reservations}}')
            ->where(['eventDateId' => $eventDateIds, 'status' => ['confirmed', 'pending']])
            ->groupBy('eventDateId')
            ->all();

        $map = array_fill_keys($eventDateIds, 0);
        foreach ($rows as $row) {
            $map[(int) $row['eventDateId']] = (int) $row['total'];
        }

        return $map;
    }

    /** @throws \Exception */
    private function saveEventDate(EventDate $event, array $data, string $action): EventDate
    {
        $event->setAttributes($data);

        if (!$event->validate()) {
            throw new \Exception('Validation failed: ' . implode(', ', $event->getFirstErrors()));
        }
        if (!Craft::$app->elements->saveElement($event)) {
            throw new \Exception("Failed to {$action} event date: " . implode(', ', $event->getFirstErrors()));
        }

        return $event;
    }
}
