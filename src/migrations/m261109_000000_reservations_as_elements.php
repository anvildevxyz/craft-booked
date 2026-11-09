<?php

namespace anvildev\booked\migrations;

use anvildev\booked\elements\Reservation;
use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\StringHelper;

/**
 * Gives every reservation a row in `elements`, so a booking is a Craft element
 * on installs that never had Commerce.
 *
 * Rows that are already reservation elements (Commerce installs) keep their
 * ids. Purchasable and order links point at those ids and must not move.
 *
 * The rest were plain ActiveRecord rows with their own auto-increment key, so
 * their ids have to be remapped onto element ids. That is done in two passes
 * so no id collides with one still in use:
 *
 *  1. every orphan id is shifted above the highest id the new element rows
 *     can possibly be assigned
 *  2. each row is then lowered onto its real element id, which is now free
 *
 * Child tables that store the reservation id are shifted with it. The foreign
 * keys on those columns are dropped for the duration and rebuilt afterwards,
 * and `booked_reservations.id` itself becomes a cascading key onto `elements.id`.
 */
class m261109_000000_reservations_as_elements extends Migration
{
    private const RESERVATIONS = '{{%booked_reservations}}';

    /**
     * Tables whose reservation id column has to follow a remapped booking.
     *
     * @var array<string, string> table => column
     */
    private const ID_COLUMNS = [
        '{{%booked_reservation_extras}}' => 'reservationId',
        '{{%booked_payments}}' => 'reservationId',
        '{{%booked_order_reservations}}' => 'reservationId',
        '{{%booked_webhook_logs}}' => 'reservationId',
    ];

    public function safeUp(): bool
    {
        if ($this->db->getTableSchema(self::RESERVATIONS, true) === null) {
            return true;
        }

        $orphans = (new Query())
            ->select(['r.id', 'r.siteId', 'r.dateCreated', 'r.dateUpdated', 'r.uid'])
            ->from(['r' => self::RESERVATIONS])
            ->leftJoin(
                ['e' => '{{%elements}}'],
                '[[e.id]] = [[r.id]] AND [[e.type]] = :type',
                [':type' => Reservation::class],
            )
            ->where(['e.id' => null])
            ->orderBy(['r.id' => SORT_ASC])
            ->all();

        if ($orphans !== []) {
            $this->remap($orphans);
        }

        $this->addElementForeignKey();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261109_000000_reservations_as_elements cannot be reverted: the original reservation ids are not recoverable.\n";
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $orphans
     */
    private function remap(array $orphans): void
    {
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $orphans);

        $dropped = $this->dropChildForeignKeys();

        $maxElementId = (int)(new Query())->from('{{%elements}}')->max('[[id]]');
        $maxReservationId = (int)(new Query())->from(self::RESERVATIONS)->max('[[id]]');
        $offset = $maxElementId + $maxReservationId + count($orphans) + 1;

        $this->shiftIds(self::RESERVATIONS, 'id', $ids, $offset);
        foreach (self::ID_COLUMNS as $table => $column) {
            $this->shiftIds($table, $column, $ids, $offset);
        }

        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        foreach ($orphans as $reservation) {
            $parkedId = (int)$reservation['id'] + $offset;
            $siteId = (int)($reservation['siteId'] ?: 0);
            if ($siteId === 0 || Craft::$app->getSites()->getSiteById($siteId) === null) {
                $siteId = $primarySiteId;
            }

            $uid = (string)($reservation['uid'] ?: StringHelper::UUID());
            if ((new Query())->from('{{%elements}}')->where(['uid' => $uid])->exists()) {
                $uid = StringHelper::UUID();
            }

            $this->insert('{{%elements}}', [
                'type' => Reservation::class,
                'enabled' => true,
                'archived' => false,
                'dateCreated' => $reservation['dateCreated'],
                'dateUpdated' => $reservation['dateUpdated'],
                'uid' => $uid,
            ]);
            $elementId = (int)$this->db->getLastInsertID('{{%elements}}');

            $this->insert('{{%elements_sites}}', [
                'elementId' => $elementId,
                'siteId' => $siteId,
                'enabled' => true,
                'dateCreated' => $reservation['dateCreated'],
                'dateUpdated' => $reservation['dateUpdated'],
                'uid' => StringHelper::UUID(),
            ]);

            $this->update(self::RESERVATIONS, ['id' => $elementId], ['id' => $parkedId]);
            foreach (self::ID_COLUMNS as $table => $column) {
                if ($this->db->tableExists($table)) {
                    $this->update($table, [$column => $elementId], [$column => $parkedId]);
                }
            }
        }

        $this->restoreChildForeignKeys($dropped);
    }

    /**
     * @return array<int, array{table: string, column: string, refTable: string, refColumn: string, onDelete: string, onUpdate: ?string}>
     */
    private function dropChildForeignKeys(): array
    {
        $dropped = [];

        foreach (self::ID_COLUMNS as $table => $column) {
            $name = $this->foreignKeyName($table, $column);
            if ($name === null) {
                continue;
            }

            $dropped[] = $this->foreignKeyDefinition($table, $column);
            $this->dropForeignKey($name, $table);
        }

        return $dropped;
    }

    /**
     * @param array<int, array{table: string, column: string, refTable: string, refColumn: string, onDelete: string, onUpdate: ?string}> $dropped
     */
    private function restoreChildForeignKeys(array $dropped): void
    {
        foreach ($dropped as $fk) {
            $this->addForeignKey(
                null,
                $fk['table'],
                $fk['column'],
                $fk['refTable'],
                $fk['refColumn'],
                $fk['onDelete'],
                $fk['onUpdate'],
            );
        }
    }

    /**
     * @return array{table: string, column: string, refTable: string, refColumn: string, onDelete: string, onUpdate: ?string}
     */
    private function foreignKeyDefinition(string $table, string $column): array
    {
        // The schema no longer carries ON DELETE once the key is dropped, and
        // these two keys are the only ones we drop. Extras cascade both ways;
        // payments cascade the delete and restrict the update.
        $onUpdate = $table === '{{%booked_reservation_extras}}' ? 'CASCADE' : null;

        return [
            'table' => $table,
            'column' => $column,
            'refTable' => self::RESERVATIONS,
            'refColumn' => 'id',
            'onDelete' => 'CASCADE',
            'onUpdate' => $onUpdate,
        ];
    }

    /**
     * @param int[] $ids
     */
    private function shiftIds(string $table, string $column, array $ids, int $offset): void
    {
        if (!$this->db->tableExists($table)) {
            return;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            $list = implode(',', $chunk);
            $this->execute("UPDATE {$table} SET [[$column]] = [[$column]] + {$offset} WHERE [[$column]] IN ({$list})");
        }
    }

    private function addElementForeignKey(): void
    {
        if ($this->foreignKeyName(self::RESERVATIONS, 'id') === null) {
            $this->addForeignKey(null, self::RESERVATIONS, 'id', '{{%elements}}', 'id', 'CASCADE', null);
        }
    }

    /**
     * The generated constraint name, or null when the column carries no foreign key.
     */
    private function foreignKeyName(string $table, string $column): ?string
    {
        $rawTable = $this->db->getSchema()->getRawTableName($table);
        $schema = $this->db->getTableSchema($rawTable, true);

        if ($schema === null) {
            return null;
        }

        foreach ($schema->foreignKeys as $name => $definition) {
            if (array_key_exists($column, array_slice($definition, 1, null, true))) {
                return (string)$name;
            }
        }

        return null;
    }
}
