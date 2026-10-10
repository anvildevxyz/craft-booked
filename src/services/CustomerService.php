<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\records\PaymentRecord;
use anvildev\booked\records\ReservationRecord;
use Craft;
use craft\db\Query;
use yii\base\Component;

/**
 * Reads customers back out of the bookings they made.
 *
 * There is no customer table. A booking only ever needed a name, an email and a
 * phone number — no account, no signup — so a "customer" here is every booking
 * that shares an email address, aggregated on read. That keeps the promise that
 * a booking can be taken from a stranger, and it means this view is correct for
 * data that predates it.
 *
 * Email is the identity, compared case-insensitively: someone who books once as
 * `Ann@example.com` and again as `ann@example.com` is one person, and treating
 * them as two would split their history in half.
 */
class CustomerService extends Component
{
    public const SORTABLE = ['name', 'email', 'totalBookings', 'lastBooking'];

    /**
     * One page of customers, newest booking first by default.
     *
     * @return array{customers: list<array<string, mixed>>, total: int}
     */
    public function search(
        string $search = '',
        string $sort = 'lastBooking',
        string $dir = 'desc',
        int $limit = 50,
        int $offset = 0,
    ): array {
        if (!in_array($sort, self::SORTABLE, true)) {
            $sort = 'lastBooking';
        }
        $direction = strtolower($dir) === 'asc' ? SORT_ASC : SORT_DESC;

        $base = $this->baseQuery($search);

        $total = (int)(new Query())
            ->from(['grouped' => $this->summaryQuery($base)])
            ->count('*');

        if ($total === 0) {
            return ['customers' => [], 'total' => 0];
        }

        $rows = $this->summaryQuery($base)
            ->orderBy([$sort => $direction, 'emailKey' => SORT_ASC])
            ->limit(max(1, $limit))
            ->offset(max(0, $offset))
            ->all();

        $rows = $this->attachLatestDetails($rows);
        $rows = $this->attachSpend($rows);

        return ['customers' => $rows, 'total' => $total];
    }

    /**
     * A single customer's summary, or null when no booking carries that email.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $base = $this->baseQuery('')->andWhere($this->emailMatches($email));

        $row = $this->summaryQuery($base)->one();
        if (!$row) {
            return null;
        }

        $rows = $this->attachSpend($this->attachLatestDetails([$row]));

        return $rows[0];
    }

    /**
     * Every booking this customer has made, most recent first.
     *
     * @return list<\anvildev\booked\contracts\ReservationInterface>
     */
    public function bookingsFor(string $email): array
    {
        $query = ReservationFactory::find()
            ->withRelations()
            ->orderBy(['bookingDate' => SORT_DESC, 'startTime' => SORT_DESC]);

        Booked::getInstance()->getPermission()->scopeReservationQuery($query);
        $query->andWhere($this->emailMatches($email, ''));

        return $query->all();
    }

    /**
     * The Craft user this customer's bookings are linked to, if any.
     *
     * Bookings need no account, so most customers have none. A booking made
     * while logged in records the user id, which is what links the two.
     */
    public function linkedUser(?int $userId): ?\craft\elements\User
    {
        return $userId ? Craft::$app->getUsers()->getUserById($userId) : null;
    }

    /**
     * The staff employeeId scoping condition, or null for no restriction.
     *
     * Every query that reads a reservation to build a customer row — the base
     * list, the latest-booking lookup, the spend sum — has to apply this, or a
     * staff member scoped to one employee sees a customer's name, phone,
     * linked account, or spend pulled from a booking made with a *different*
     * employee they have no permission to view.
     */
    private function employeeScopeCondition(string $column): array|string|null
    {
        $employeeIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();
        if ($employeeIds === null) {
            return null;
        }

        return $employeeIds === [] ? '0=1' : [$column => $employeeIds];
    }

    /**
     * Reservations the current user is allowed to see, before grouping.
     */
    private function baseQuery(string $search): Query
    {
        $query = (new Query())->from(['r' => ReservationRecord::tableName()]);

        // Staff see only the employees they manage, matching the bookings index.
        if (($scope = $this->employeeScopeCondition('r.[[employeeId]]')) !== null) {
            $query->andWhere($scope);
        }

        $search = trim($search);
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['like', 'r.[[userName]]', $search],
                ['like', 'r.[[userEmail]]', $search],
                ['like', 'r.[[userPhone]]', $search],
            ]);
        }

        return $query;
    }

    /**
     * Groups bookings into one row per customer.
     *
     * The counts use SUM(CASE …) rather than a boolean sum or FILTER, both of
     * which are dialect-specific, and every camelCase column is bracket-quoted
     * so Postgres does not fold it to lowercase.
     */
    private function summaryQuery(Query $base): Query
    {
        $query = clone $base;

        return $query
            ->select([
                'emailKey' => 'LOWER(r.[[userEmail]])',
                'email' => 'MIN(r.[[userEmail]])',
                'totalBookings' => 'COUNT(*)',
                'cancelledBookings' => new \yii\db\Expression(
                    'SUM(CASE WHEN r.[[status]] = :cancelled THEN 1 ELSE 0 END)',
                    [':cancelled' => ReservationRecord::STATUS_CANCELLED],
                ),
                'noShowBookings' => new \yii\db\Expression(
                    'SUM(CASE WHEN r.[[status]] = :noShow THEN 1 ELSE 0 END)',
                    [':noShow' => ReservationRecord::STATUS_NO_SHOW],
                ),
                'upcomingBookings' => new \yii\db\Expression(
                    'SUM(CASE WHEN r.[[bookingDate]] >= :today AND r.[[status]] <> :cancelledToo THEN 1 ELSE 0 END)',
                    [
                        ':today' => (new \DateTime('today', new \DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d'),
                        ':cancelledToo' => ReservationRecord::STATUS_CANCELLED,
                    ],
                ),
                'firstBooking' => 'MIN(r.[[bookingDate]])',
                'lastBooking' => 'MAX(r.[[bookingDate]])',
                'userId' => 'MAX(r.[[userId]])',
                // Sorting by name has to sort by something in the GROUP BY, and
                // the display name is resolved separately below.
                'name' => 'MIN(r.[[userName]])',
            ])
            ->groupBy(['LOWER(r.[[userEmail]])']);
    }

    /**
     * Fills in the name and phone from each customer's most recently entered
     * booking.
     *
     * An aggregate cannot answer "the name they used last" — MIN() gives the
     * alphabetically first, which is how a customer ends up displayed under a
     * name they used once, years ago. "Last" is ordered by when the booking
     * was made (dateCreated), not by the appointment date it books — a
     * correction entered today for a date next month is more current than an
     * untouched booking made last week for a date next year.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachLatestDetails(array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $keys = array_column($rows, 'emailKey');

        $latest = (new Query())
            ->select(['[[userEmail]]', '[[userName]]', '[[userPhone]]', '[[userId]]', '[[dateCreated]]'])
            ->from(ReservationRecord::tableName())
            ->where(['IN', new \yii\db\Expression('LOWER([[userEmail]])'), $keys]);

        if (($scope = $this->employeeScopeCondition('employeeId')) !== null) {
            $latest->andWhere($scope);
        }

        // Ordered ascending, so the last write per key wins — the newest booking.
        // The id tiebreaker makes ties (e.g. a bulk import sharing one
        // dateCreated) deterministic instead of left to the database.
        $latest = $latest->orderBy(['[[dateCreated]]' => SORT_ASC, '[[id]]' => SORT_ASC])->all();

        $byKey = [];
        foreach ($latest as $row) {
            $byKey[mb_strtolower((string)$row['userEmail'])] = $row;
        }

        foreach ($rows as $i => $row) {
            $match = $byKey[$row['emailKey']] ?? null;
            $rows[$i]['name'] = $match['userName'] ?? $row['name'];
            $rows[$i]['phone'] = $match['userPhone'] ?? null;
            $rows[$i]['email'] = $match['userEmail'] ?? $row['email'];
            $rows[$i]['userId'] = $match['userId'] ?? $row['userId'];
            $rows[$i]['totalBookings'] = (int)$row['totalBookings'];
            $rows[$i]['cancelledBookings'] = (int)$row['cancelledBookings'];
            $rows[$i]['noShowBookings'] = (int)$row['noShowBookings'];
            $rows[$i]['upcomingBookings'] = (int)$row['upcomingBookings'];
        }

        return $rows;
    }

    /**
     * Adds what each customer has actually paid, net of refunds.
     *
     * Only settled payments count — a pending intent is not money received.
     * Amounts are minor units, as stored. Currency is install-wide, like
     * {@see ReportsService::aggregateDirectPaymentsSum()} — summing payments
     * across currencies into one minor-units total would be meaningless, so a
     * stray legacy row in a different currency is excluded rather than
     * silently summed under whichever currency happens to sort first.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachSpend(array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $keys = array_column($rows, 'emailKey');
        $currency = Booked::getInstance()->reports->getCurrency();

        $spend = (new Query())
            ->select([
                'emailKey' => 'LOWER(r.[[userEmail]])',
                'paid' => 'SUM(p.[[amount]] - p.[[refundedAmount]])',
            ])
            ->from(['p' => PaymentRecord::tableName()])
            ->innerJoin(['r' => ReservationRecord::tableName()], 'r.[[id]] = p.[[reservationId]]')
            ->where(['p.[[status]]' => [PaymentRecord::STATUS_PAID, PaymentRecord::STATUS_PARTIALLY_REFUNDED]])
            ->andWhere(['p.[[currency]]' => $currency])
            ->andWhere(['IN', new \yii\db\Expression('LOWER(r.[[userEmail]])'), $keys]);

        if (($scope = $this->employeeScopeCondition('r.[[employeeId]]')) !== null) {
            $spend->andWhere($scope);
        }

        $spend = $spend->groupBy(['LOWER(r.[[userEmail]])'])->all();

        $byKey = [];
        foreach ($spend as $row) {
            $byKey[$row['emailKey']] = $row;
        }

        foreach ($rows as $i => $row) {
            $match = $byKey[$row['emailKey']] ?? null;
            $paidMinorUnits = (int)($match['paid'] ?? 0);
            $rows[$i]['paidMinorUnits'] = $paidMinorUnits;
            // Converted here, not in the template: a template-side `/ 100` is
            // wrong for zero-decimal currencies (JPY, KRW, …), where the minor
            // unit already is the major unit — see fromMinorUnits().
            $rows[$i]['paidAmount'] = PaymentService::fromMinorUnits($paidMinorUnits, $currency);
            $rows[$i]['currency'] = $currency;
        }

        return $rows;
    }

    /**
     * Case-insensitive email match, for a table alias or, with an empty one,
     * the bare column — ReservationModelQuery wraps a raw, alias-less
     * ActiveQuery, so `bookingsFor()` cannot reference an `r.` that isn't there.
     */
    private function emailMatches(string $email, string $table = 'r'): array
    {
        $column = $table === '' ? '[[userEmail]]' : $table . '.[[userEmail]]';

        return ['=', new \yii\db\Expression('LOWER(' . $column . ')'), mb_strtolower(trim($email))];
    }
}
