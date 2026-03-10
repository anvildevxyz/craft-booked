<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\EventDate;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\helpers\ElementQueryHelper;
use Craft;
use craft\base\Component;
use yii\caching\TagDependency;
use yii\db\Query;

/**
 * Central reporting and analytics service for the Booked plugin.
 * Aggregates reservation, revenue, utilization, and customer data
 * with staff-scoped permission filtering on all queries.
 */
class ReportsService extends Component
{
    public function getCurrency(): string
    {
        $settings = Booked::getInstance()->getSettings();

        if (!empty($settings->defaultCurrency) && $settings->defaultCurrency !== 'auto') {
            return $settings->defaultCurrency;
        }

        if (Craft::$app->plugins->isPluginEnabled('commerce')) {
            try {
                $pc = \craft\commerce\Plugin::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrency();
                if ($pc) {
                    return $pc->iso;
                }
            } catch (\Exception) {
            }
        }

        return 'USD';
    }

    public function getRevenueData(?string $startDate, ?string $endDate, bool $includePreviousPeriod = false): array
    {
        $startDate ??= date('Y-m-01');
        $endDate ??= date('Y-m-t');
        $prev = $includePreviousPeriod ? '1' : '0';

        return $this->cachedReport("revenue_{$startDate}_{$endDate}_{$prev}", function() use ($startDate, $endDate, $includePreviousPeriod) {
            $total = $this->aggregateRevenueSum($startDate, $endDate);

            $previousTotal = null;
            $changePercent = null;

            if ($includePreviousPeriod) {
                $start = new \DateTime($startDate);
                $end = new \DateTime($endDate);
                $days = $start->diff($end)->days + 1;

                $prevEnd = (clone $start)->modify('-1 day');
                $prevStart = (clone $prevEnd)->modify('-' . ($days - 1) . ' days');

                $previousTotal = $this->aggregateRevenueSum($prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d'));
                $changePercent = $previousTotal > 0 ? (($total - $previousTotal) / $previousTotal) * 100 : 0.0;
            }

            $reservations = $this->buildReservationQuery($startDate, $endDate)->all();

            return compact('total', 'previousTotal', 'changePercent', 'reservations');
        });
    }

    /**
     * Aggregate revenue for a date range.
     *
     * Note: Revenue is calculated from catalog/service prices (service.price,
     * eventDate.price, serviceExtra.price) multiplied by reservation quantities,
     * NOT from actual payment amounts. If discounts, coupons, or partial payments
     * are applied at checkout, those adjustments are not reflected here.
     */
    private function aggregateRevenueSum(string $startDate, string $endDate): float
    {
        $query = (new Query())
            ->from('{{%booked_reservations}} r')
            ->leftJoin('{{%booked_services}} s', 'r.serviceId = s.id')
            ->leftJoin('{{%booked_event_dates}} ed', 'r.eventDateId = ed.id')
            ->leftJoin(
                ['re_sum' => (new Query())
                    ->select(['reservationId', 'extras_total' => 'SUM(se.price * re.quantity)'])
                    ->from('{{%booked_reservation_extras}} re')
                    ->leftJoin('{{%booked_service_extras}} se', 're.serviceExtraId = se.id')
                    ->groupBy('re.reservationId'), ],
                'r.id = re_sum.reservationId'
            )
            ->where(['r.status' => 'confirmed'])
            ->andWhere(['between', 'r.bookingDate', $startDate, $endDate]);

        $staffIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();
        if ($staffIds !== null) {
            $query->andWhere(['r.employeeId' => $staffIds]);
        }

        return (float) $query->sum('COALESCE(s.price, 0) * r.quantity + COALESCE(ed.price, 0) * r.quantity + COALESCE(re_sum.extras_total, 0)');
    }

    public function getByServiceData(?string $startDate = null, ?string $endDate = null): array
    {
        return $this->cachedReport("by_service_{$startDate}_{$endDate}", fn() => $this->computeGroupedData(
            $startDate, $endDate, 'serviceId',
            fn(array $ids) => self::batchLoadServices($ids),
            'service', 'services',
        ));
    }

    public function getByEmployeeData(?string $startDate = null, ?string $endDate = null): array
    {
        return $this->cachedReport("by_employee_{$startDate}_{$endDate}", fn() => $this->computeGroupedData(
            $startDate, $endDate, 'employeeId',
            fn(array $ids) => self::batchLoadEmployees($ids),
            'employee', 'employees',
        ));
    }

    public function getByLocationData(?string $startDate = null, ?string $endDate = null): array
    {
        return $this->cachedReport("by_location_{$startDate}_{$endDate}", fn() => $this->computeGroupedData(
            $startDate, $endDate, 'locationId',
            fn(array $ids) => self::batchLoadLocations($ids),
            'location', 'locations',
        ));
    }

    /** Shared logic for by-service, by-employee, by-location reports. */
    private function computeGroupedData(
        ?string $startDate,
        ?string $endDate,
        string $field,
        callable $batchLoader,
        string $elementKey,
        string $collectionKey,
    ): array {
        $reservations = $this->buildReservationQuery($startDate, $endDate)->all();
        $totalBookings = count($reservations);

        $grouped = [];
        $groupedCount = 0;
        foreach ($reservations as $r) {
            $id = $r->$field;
            if (!$id) {
                continue;
            }
            $grouped[$id] ??= ['count' => 0, 'revenue' => 0.0];
            $grouped[$id]['count']++;
            $grouped[$id]['revenue'] += $r->getTotalPrice();
            $groupedCount++;
        }

        // Batch-load all elements at once instead of one-by-one
        $elements = $batchLoader(array_keys($grouped));

        $items = [];
        foreach ($grouped as $id => $data) {
            $items[$id] = [
                $elementKey => $elements[$id] ?? null,
                'count' => $data['count'],
                'revenue' => $data['revenue'],
                'percentage' => $groupedCount > 0 ? ($data['count'] / $groupedCount) * 100 : 0.0,
            ];
        }

        uasort($items, fn($a, $b) => $b['count'] <=> $a['count']);

        return [$collectionKey => $items, 'totalBookings' => $totalBookings];
    }

    public function getCancellationData(?string $startDate = null, ?string $endDate = null): array
    {
        return $this->cachedReport("cancellations_{$startDate}_{$endDate}", fn() => $this->computeCancellationData($startDate, $endDate));
    }

    private function computeCancellationData(?string $startDate, ?string $endDate): array
    {
        $total = (int) $this->buildReservationQuery($startDate, $endDate, null)->count();
        $cancelled = (int) $this->buildReservationQuery($startDate, $endDate, 'cancelled')->count();

        $buildBreakdown = function(string $field, callable $batchLoader, string $elementKey) use ($startDate, $endDate): array {
            $query = (new Query())
                ->select([$field, 'total' => 'COUNT(*)', 'cancelled_count' => "SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END)"])
                ->from('{{%booked_reservations}}')
                ->andWhere(['not', [$field => null]]);

            if ($startDate && $endDate) {
                $query->andWhere(['between', 'bookingDate', $startDate, $endDate]);
            }

            $staffIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();
            if ($staffIds !== null) {
                $query->andWhere(['employeeId' => $staffIds]);
            }

            $query->groupBy($field);
            $rows = $query->all();

            $ids = array_column($rows, $field);
            $elements = $batchLoader($ids);

            $result = [];
            foreach ($rows as $row) {
                $id = $row[$field];
                $t = (int) $row['total'];
                $c = (int) $row['cancelled_count'];
                $result[] = [
                    $elementKey => $elements[$id] ?? null,
                    'total' => $t,
                    'cancelled' => $c,
                    'rate' => $t > 0 ? ($c / $t) * 100 : 0.0,
                ];
            }
            return $result;
        };

        return [
            'total' => $total,
            'cancelled' => $cancelled,
            'rate' => $total > 0 ? ($cancelled / $total) * 100 : 0.0,
            'byService' => $buildBreakdown(
                'serviceId',
                fn(array $ids) => self::batchLoadServices($ids),
                'service',
            ),
            'byEmployee' => $buildBreakdown(
                'employeeId',
                fn(array $ids) => self::batchLoadEmployees($ids),
                'employee',
            ),
        ];
    }

    public function getPeakHoursData(?string $startDate = null, ?string $endDate = null, bool $includeDayOfWeek = false, ?int $serviceId = null, ?int $employeeId = null): array
    {
        $dow = $includeDayOfWeek ? '1' : '0';
        $svc = $serviceId ?? 'all';
        $emp = $employeeId ?? 'all';
        return $this->cachedReport("peak_hours_{$startDate}_{$endDate}_{$dow}_{$svc}_{$emp}", fn() => $this->computePeakHoursData($startDate, $endDate, $includeDayOfWeek, $serviceId, $employeeId), 900);
    }

    private function computePeakHoursData(?string $startDate, ?string $endDate, bool $includeDayOfWeek, ?int $serviceId = null, ?int $employeeId = null): array
    {
        $query = (new Query())
            ->from('{{%booked_reservations}} r')
            ->where(['r.status' => 'confirmed'])
            ->andWhere(['not', ['r.startTime' => null]]);

        if ($startDate && $endDate) {
            $query->andWhere(['between', 'r.bookingDate', $startDate, $endDate]);
        }

        $staffIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();
        if ($staffIds !== null) {
            $query->andWhere(['r.employeeId' => $staffIds]);
        }
        if ($serviceId) {
            $query->andWhere(['r.serviceId' => $serviceId]);
        }
        if ($employeeId) {
            $query->andWhere(['r.employeeId' => $employeeId]);
        }

        $isPostgres = Craft::$app->getDb()->getDriverName() === 'pgsql';
        $hourExpr = $isPostgres ? 'EXTRACT(HOUR FROM [[r.startTime]])' : 'HOUR([[r.startTime]])';

        if ($includeDayOfWeek) {
            $dowExpr = $isPostgres ? 'EXTRACT(DOW FROM [[r.bookingDate]])' : 'DAYOFWEEK([[r.bookingDate]])';

            $query->select([
                'hour' => $hourExpr,
                'dow' => $dowExpr,
                'cnt' => 'COUNT(*)',
            ])->groupBy([$hourExpr, $dowExpr]);

            $result = [];
            foreach ($query->all() as $row) {
                $hour = (int) $row['hour'];
                $rawDow = (int) $row['dow'];
                // MySQL DAYOFWEEK: 1=Sunday..7=Saturday → 0=Monday..6=Sunday
                // PostgreSQL DOW: 0=Sunday..6=Saturday → 0=Monday..6=Sunday
                $dow = $isPostgres ? (($rawDow + 6) % 7) : (($rawDow + 5) % 7);
                $result[$hour][$dow] = (int) $row['cnt'];
            }

            ksort($result);
            foreach ($result as &$dowCounts) {
                ksort($dowCounts);
            }
            unset($dowCounts);

            return $result;
        }

        $query->select([
            'hour' => $hourExpr,
            'cnt' => 'COUNT(*)',
        ])->groupBy([$hourExpr]);

        $result = [];
        foreach ($query->all() as $row) {
            $result[(int) $row['hour']] = (int) $row['cnt'];
        }

        ksort($result);

        return $result;
    }



    public function getUtilizationData(string $startDate, string $endDate, ?int $serviceId = null, ?int $employeeId = null, ?int $locationId = null): array
    {
        $availability = Booked::getInstance()->getAvailability();

        $reservationQuery = $this->buildReservationQuery($startDate, $endDate);
        if ($serviceId) {
            $reservationQuery->serviceId($serviceId);
        }
        if ($employeeId) {
            $reservationQuery->employeeId($employeeId);
        }
        if ($locationId) {
            $reservationQuery->locationId($locationId);
        }

        $bookedPerDay = [];
        foreach ($reservationQuery->all() as $r) {
            // Skip event-date registrations (no serviceId) — capacity only covers services
            if ($r->bookingDate && $r->serviceId) {
                $bookedPerDay[$r->bookingDate] = ($bookedPerDay[$r->bookingDate] ?? 0) + 1;
            }
        }

        $days = [];
        $totalCapacity = $totalBooked = 0;
        $current = new \DateTime($startDate);
        $end = new \DateTime($endDate);

        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $capacity = $availability->getCapacitySlotCount($dateStr, $serviceId, $employeeId, $locationId);
            $booked = $bookedPerDay[$dateStr] ?? 0;

            $days[$dateStr] = [
                'date' => $dateStr,
                'capacity' => $capacity,
                'booked' => $booked,
                'utilizationPct' => round($capacity > 0 ? min(100.0, max(0.0, ($booked / $capacity) * 100)) : 0.0, 2),
            ];

            $totalCapacity += $capacity;
            $totalBooked += $booked;
            $current->modify('+1 day');
        }

        return [
            'days' => $days,
            'summaryPct' => round($totalCapacity > 0 ? min(100.0, max(0.0, ($totalBooked / $totalCapacity) * 100)) : 0.0, 2),
        ];
    }

    public function getWaitlistConversionData(?string $startDate = null, ?string $endDate = null): array
    {
        $query = (new Query())->from('{{%booked_waitlist}}');
        if ($startDate && $endDate) {
            $query->andWhere(['and', ['>=', 'dateCreated', $startDate], ['<=', 'dateCreated', $endDate]]);
        }

        $staffEmployeeIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();
        if ($staffEmployeeIds !== null) {
            $query->andWhere(['employeeId' => $staffEmployeeIds]);
        }

        $rows = $query->all();
        $statusCounts = ['active' => 0, 'notified' => 0, 'converted' => 0, 'expired' => 0, 'cancelled' => 0];
        $byServiceGrouped = [];

        foreach ($rows as $row) {
            $status = $row['status'] ?? '';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
            if ($sid = ($row['serviceId'] ?? null)) {
                $byServiceGrouped[$sid] ??= ['total' => 0, 'converted' => 0];
                $byServiceGrouped[$sid]['total']++;
                if ($status === 'converted') {
                    $byServiceGrouped[$sid]['converted']++;
                }
            }
        }

        $total = count($rows);
        $byService = [];
        foreach ($byServiceGrouped as $serviceId => $data) {
            $byService[$serviceId] = [
                'service' => ElementQueryHelper::forAllSites(Service::find()->id($serviceId))->one(),
                'total' => $data['total'],
                'converted' => $data['converted'],
                // @phpstan-ignore greater.alwaysTrue, ternary.elseUnreachable
                'conversionRate' => $data['total'] > 0 ? ($data['converted'] / $data['total']) * 100 : 0.0,
            ];
        }

        return [
            'total' => $total,
            ...$statusCounts,
            'conversionRate' => round($total > 0 ? ($statusCounts['converted'] / $total) * 100 : 0.0, 2),
            'byService' => $byService,
        ];
    }

    public function getEventDateAttendanceData(?string $startDate = null, ?string $endDate = null): array
    {
        $query = EventDate::find()->siteId('*')->unique();
        if ($startDate && $endDate) {
            $query->andWhere(['and', ['>=', 'booked_event_dates.eventDate', $startDate], ['<=', 'booked_event_dates.eventDate', $endDate]]);
        }

        $eventDates = $query->all();
        $events = [];

        $staffEmployeeIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();

        // Batch-load attendance counts in a single grouped query
        $eventDateIds = array_map(fn($ed) => $ed->id, $eventDates);
        $attendanceMap = [];
        if (!empty($eventDateIds)) {
            $attendanceQuery = (new Query())
                ->select(['eventDateId', 'total' => 'SUM([[quantity]])'])
                ->from('{{%booked_reservations}}')
                ->where(['eventDateId' => $eventDateIds, 'status' => 'confirmed'])
                ->groupBy('eventDateId');

            if ($staffEmployeeIds !== null) {
                $attendanceQuery->andWhere(['employeeId' => $staffEmployeeIds]);
            }

            foreach ($attendanceQuery->all() as $row) {
                $attendanceMap[(int)$row['eventDateId']] = (int)$row['total'];
            }
        }

        foreach ($eventDates as $ed) {
            $booked = $attendanceMap[$ed->id] ?? 0;

            $capacity = $ed->capacity;
            $utilizationPct = ($capacity !== null && $capacity > 0) ? min(100.0, ($booked / $capacity) * 100) : null;

            $events[$ed->id] = [
                'eventDate' => $ed,
                'capacity' => $capacity,
                'booked' => $booked,
                'remaining' => $capacity !== null ? max(0, $capacity - $booked) : null,
                'utilizationPct' => $utilizationPct !== null ? round($utilizationPct, 2) : null,
            ];
        }

        return ['events' => $events, 'totalEvents' => count($eventDates)];
    }

    private static function batchLoadServices(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $elements = ElementQueryHelper::forAllSites(Service::find()->id($ids))->all();
        $map = [];
        foreach ($elements as $el) {
            $map[$el->id] = $el;
        }
        return $map;
    }

    private static function batchLoadEmployees(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $elements = Employee::find()->siteId('*')->id($ids)->all();
        $map = [];
        foreach ($elements as $el) {
            $map[$el->id] = $el;
        }
        return $map;
    }

    private static function batchLoadLocations(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $elements = Location::find()->siteId('*')->id($ids)->all();
        $map = [];
        foreach ($elements as $el) {
            $map[$el->id] = $el;
        }
        return $map;
    }

    public function invalidateReportCaches(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), 'booked_reports');
    }

    private function cachedReport(string $key, callable $builder, int $ttl = 300): mixed
    {
        $cache = Craft::$app->getCache();
        $fullKey = 'booked_report_' . $key . '_' . md5(serialize(
            Booked::getInstance()->getPermission()->getStaffEmployeeIds()
        ));

        $result = $cache->get($fullKey);
        if ($result !== false) {
            return $result;
        }

        $result = $builder();
        $cache->set($fullKey, $result, $ttl, new TagDependency(['tags' => ['booked_reports']]));

        return $result;
    }

    /** @return \anvildev\booked\contracts\ReservationQueryInterface */
    private function buildReservationQuery(?string $startDate, ?string $endDate, ?string $status = 'confirmed'): \anvildev\booked\contracts\ReservationQueryInterface
    {
        $query = ReservationFactory::find();
        if ($status !== null) {
            $query->status($status);
        }
        if ($startDate && $endDate) {
            $query->bookingDate(['and', '>= ' . $startDate, '<= ' . $endDate]);
        }
        return Booked::getInstance()->getPermission()->scopeReservationQuery($query);
    }
}
