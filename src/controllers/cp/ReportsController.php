<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\Booked;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use anvildev\booked\helpers\CsvHelper;
use Craft;
use craft\web\Controller;
use craft\web\Response;

class ReportsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('booked-viewReports');
        return true;
    }

    public function actionIndex(): mixed
    {
        return $this->renderTemplate('booked/reports/index');
    }

    public function actionRevenue(): mixed
    {
        $request = Craft::$app->request;
        $startDate = self::normalizeDateParam($request->getParam('startDate'), date('Y-m-01'));
        $endDate = self::normalizeDateParam($request->getParam('endDate'), date('Y-m-t'));

        $reports = Booked::getInstance()->getReports();
        $data = $reports->getRevenueData($startDate, $endDate, true);

        if ($request->getParam('format') === 'csv') {
            return $this->sendCsvResponse(
                ['Date', 'Customer', 'Service / Event', 'Time', 'Revenue', 'Status'],
                array_map(fn($r) => [
                    $r->bookingDate,
                    CsvHelper::sanitizeValue($r->userEmail ?? ''),
                    CsvHelper::sanitizeValue($r->getService()?->title ?? $r->getEventDate()?->title ?? ''),
                    ($r->startTime ?? '') . ' - ' . ($r->endTime ?? ''),
                    number_format($r->getTotalPrice(), 2),
                    $r->status,
                ], $data['reservations']),
                'revenue-report', $startDate, $endDate,
            );
        }

        return $this->renderTemplate('booked/reports/revenue', [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'totalRevenue' => $data['total'],
            'previousTotal' => $data['previousTotal'],
            'changePercent' => $data['changePercent'],
            'reservations' => $data['reservations'],
            'currency' => $reports->getCurrency(),
        ]);
    }

    public function actionByService(): mixed
    {
        $request = Craft::$app->request;
        $startDate = self::normalizeDateParam($request->getParam('startDate'));
        $endDate = self::normalizeDateParam($request->getParam('endDate'));

        $reports = Booked::getInstance()->getReports();
        $data = $reports->getByServiceData($startDate, $endDate);

        if ($request->getParam('format') === 'csv') {
            return $this->sendCsvResponse(
                ['Service', 'Bookings', 'Revenue', 'Percentage'],
                array_map(fn($d) => [
                    CsvHelper::sanitizeValue($d['service']?->title ?? 'Unknown'),
                    $d['count'],
                    number_format($d['revenue'], 2),
                    number_format($d['percentage'], 1) . '%',
                ], $data['services']),
                'by-service-report', $startDate, $endDate,
            );
        }

        return $this->renderTemplate('booked/reports/by-service', [
            'serviceBookings' => $data['services'],
            'totalBookings' => $data['totalBookings'],
            'startDate' => $startDate,
            'endDate' => $endDate,
            'currency' => $reports->getCurrency(),
        ]);
    }

    public function actionByEmployee(): mixed
    {
        $request = Craft::$app->request;
        $startDate = self::normalizeDateParam($request->getParam('startDate'));
        $endDate = self::normalizeDateParam($request->getParam('endDate'));

        $reports = Booked::getInstance()->getReports();
        $data = $reports->getByEmployeeData($startDate, $endDate);

        if ($request->getParam('format') === 'csv') {
            return $this->sendCsvResponse(
                ['Employee', 'Bookings', 'Revenue', 'Percentage'],
                array_map(fn($d) => [
                    CsvHelper::sanitizeValue($d['employee']?->title ?? 'Unknown'),
                    $d['count'],
                    number_format($d['revenue'], 2),
                    number_format($d['percentage'], 1) . '%',
                ], $data['employees']),
                'by-employee-report', $startDate, $endDate,
            );
        }

        return $this->renderTemplate('booked/reports/by-employee', [
            'employeeBookings' => $data['employees'],
            'totalBookings' => $data['totalBookings'],
            'startDate' => $startDate,
            'endDate' => $endDate,
            'currency' => $reports->getCurrency(),
        ]);
    }

    public function actionCancellations(): mixed
    {
        $request = Craft::$app->request;
        $startDate = self::normalizeDateParam($request->getParam('startDate'));
        $endDate = self::normalizeDateParam($request->getParam('endDate'));

        $data = Booked::getInstance()->getReports()->getCancellationData($startDate, $endDate);

        if ($request->getParam('format') === 'csv') {
            $rows = [];
            foreach ($data['byService'] as $entry) {
                $rows[] = ['Service', CsvHelper::sanitizeValue($entry['service']?->title ?? 'Unknown'), $entry['total'], $entry['cancelled'], number_format($entry['rate'], 1) . '%'];
            }
            foreach ($data['byEmployee'] as $entry) {
                $rows[] = ['Employee', CsvHelper::sanitizeValue($entry['employee']?->title ?? 'Unknown'), $entry['total'], $entry['cancelled'], number_format($entry['rate'], 1) . '%'];
            }
            return $this->sendCsvResponse(['Type', 'Name', 'Total', 'Cancelled', 'Rate'], $rows, 'cancellations-report', $startDate, $endDate);
        }

        return $this->renderTemplate('booked/reports/cancellations', [
            'totalBookings' => $data['total'],
            'cancelledBookings' => $data['cancelled'],
            'cancellationRate' => $data['rate'],
            'byService' => $data['byService'],
            'byEmployee' => $data['byEmployee'],
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    public function actionPeakHours(): mixed
    {
        $request = Craft::$app->request;
        $startDate = self::normalizeDateParam($request->getParam('startDate'));
        $endDate = self::normalizeDateParam($request->getParam('endDate'));
        $serviceId = $request->getParam('serviceId') ? (int) $request->getParam('serviceId') : null;
        $employeeId = $request->getParam('employeeId') ? (int) $request->getParam('employeeId') : null;

        $hourlyBookings = Booked::getInstance()->getReports()->getPeakHoursData($startDate, $endDate, false, $serviceId, $employeeId);

        if ($request->getParam('format') === 'csv') {
            return $this->sendCsvResponse(
                ['Hour', 'Bookings'],
                array_map(fn($hour, $count) => [sprintf('%02d:00', $hour), $count], array_keys($hourlyBookings), $hourlyBookings),
                'peak-hours-report', $startDate, $endDate,
            );
        }

        return $this->renderTemplate('booked/reports/peak-hours', [
            'hourlyBookings' => $hourlyBookings,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'serviceId' => $serviceId,
            'employeeId' => $employeeId,
            'services' => $this->getScopedServices(),
            'employees' => $this->getScopedEmployees(),
        ]);
    }

    public function actionUtilization(): mixed
    {
        $request = Craft::$app->request;
        $startDate = self::normalizeDateParam($request->getParam('startDate'), date('Y-m-01'));
        $endDate = self::normalizeDateParam($request->getParam('endDate'), date('Y-m-t'));
        $serviceId = $request->getParam('serviceId') ? (int) $request->getParam('serviceId') : null;
        $employeeId = $request->getParam('employeeId') ? (int) $request->getParam('employeeId') : null;
        $locationId = $request->getParam('locationId') ? (int) $request->getParam('locationId') : null;

        $data = Booked::getInstance()->getReports()->getUtilizationData($startDate, $endDate, $serviceId, $employeeId, $locationId);

        if ($request->getParam('format') === 'csv') {
            return $this->sendCsvResponse(
                ['Date', 'Capacity', 'Booked', 'Utilization %'],
                array_map(fn($d) => [$d['date'], $d['capacity'], $d['booked'], $d['utilizationPct'] . '%'], $data['days']),
                'utilization-report', $startDate, $endDate,
            );
        }

        return $this->renderTemplate('booked/reports/utilization', [
            'days' => $data['days'],
            'summaryPct' => $data['summaryPct'],
            'startDate' => $startDate,
            'endDate' => $endDate,
            'serviceId' => $serviceId,
            'employeeId' => $employeeId,
            'locationId' => $locationId,
            'services' => $this->getScopedServices(),
            'employees' => $this->getScopedEmployees(),
            'locations' => $this->getScopedLocations(),
        ]);
    }

    public function actionByLocation(): mixed
    {
        $request = Craft::$app->request;
        $startDate = self::normalizeDateParam($request->getParam('startDate'));
        $endDate = self::normalizeDateParam($request->getParam('endDate'));

        $reports = Booked::getInstance()->getReports();
        $data = $reports->getByLocationData($startDate, $endDate);

        if ($request->getParam('format') === 'csv') {
            return $this->sendCsvResponse(
                ['Location', 'Bookings', 'Revenue', 'Percentage'],
                array_map(fn($d) => [
                    CsvHelper::sanitizeValue($d['location']?->title ?? 'Unknown'),
                    $d['count'],
                    number_format($d['revenue'], 2),
                    number_format($d['percentage'], 1) . '%',
                ], $data['locations']),
                'by-location-report', $startDate, $endDate,
            );
        }

        return $this->renderTemplate('booked/reports/by-location', [
            'locationBookings' => $data['locations'],
            'totalBookings' => $data['totalBookings'],
            'startDate' => $startDate,
            'endDate' => $endDate,
            'currency' => $reports->getCurrency(),
        ]);
    }



    public function actionDayOfWeek(): mixed
    {
        $request = Craft::$app->request;
        $startDate = self::normalizeDateParam($request->getParam('startDate'));
        $endDate = self::normalizeDateParam($request->getParam('endDate'));
        $serviceId = $request->getParam('serviceId') ? (int) $request->getParam('serviceId') : null;
        $employeeId = $request->getParam('employeeId') ? (int) $request->getParam('employeeId') : null;

        $heatmapData = Booked::getInstance()->getReports()->getPeakHoursData($startDate, $endDate, true, $serviceId, $employeeId);

        if ($request->getParam('format') === 'csv') {
            $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            $rows = [];
            foreach ($heatmapData as $hour => $dowCounts) {
                $row = [sprintf('%02d:00', $hour)];
                for ($d = 0; $d < 7; $d++) {
                    $row[] = $dowCounts[$d] ?? 0;
                }
                $rows[] = $row;
            }
            return $this->sendCsvResponse(array_merge(['Hour'], $days), $rows, 'day-of-week-report', $startDate, $endDate);
        }

        return $this->renderTemplate('booked/reports/day-of-week', [
            'heatmapData' => $heatmapData,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'serviceId' => $serviceId,
            'employeeId' => $employeeId,
            'services' => $this->getScopedServices(),
            'employees' => $this->getScopedEmployees(),
        ]);
    }

    public function actionWaitlist(): mixed
    {
        $request = Craft::$app->request;
        $startDate = self::normalizeDateParam($request->getParam('startDate'));
        $endDate = self::normalizeDateParam($request->getParam('endDate'));

        $data = Booked::getInstance()->getReports()->getWaitlistConversionData($startDate, $endDate);

        if ($request->getParam('format') === 'csv') {
            return $this->sendCsvResponse(
                ['Service', 'Total', 'Converted', 'Conversion Rate'],
                array_map(fn($d) => [
                    CsvHelper::sanitizeValue($d['service']?->title ?? 'Unknown'),
                    $d['total'],
                    $d['converted'],
                    number_format($d['conversionRate'], 1) . '%',
                ], $data['byService']),
                'waitlist-report', $startDate, $endDate,
            );
        }

        return $this->renderTemplate('booked/reports/waitlist', [
            'total' => $data['total'],
            'active' => $data['active'],
            'notified' => $data['notified'],
            'converted' => $data['converted'],
            'expired' => $data['expired'],
            'cancelled' => $data['cancelled'],
            'conversionRate' => $data['conversionRate'],
            'byService' => $data['byService'],
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    public function actionEventAttendance(): mixed
    {
        $request = Craft::$app->request;
        $startDate = self::normalizeDateParam($request->getParam('startDate'));
        $endDate = self::normalizeDateParam($request->getParam('endDate'));

        $data = Booked::getInstance()->getReports()->getEventDateAttendanceData($startDate, $endDate);

        if ($request->getParam('format') === 'csv') {
            return $this->sendCsvResponse(
                ['Event', 'Date', 'Time', 'Capacity', 'Booked', 'Remaining', 'Utilization %'],
                array_map(fn($d) => [
                    CsvHelper::sanitizeValue($d['eventDate']->title ?? ''),
                    $d['eventDate']->eventDate ?? '',
                    ($d['eventDate']->startTime ?? '') . ' - ' . ($d['eventDate']->endTime ?? ''),
                    $d['capacity'] ?? 'Unlimited',
                    $d['booked'],
                    $d['remaining'] ?? 'N/A',
                    $d['utilizationPct'] !== null ? $d['utilizationPct'] . '%' : 'N/A',
                ], $data['events']),
                'event-attendance-report', $startDate, $endDate,
            );
        }

        return $this->renderTemplate('booked/reports/event-attendance', [
            'events' => $data['events'],
            'totalEvents' => $data['totalEvents'],
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    /** @return Employee[] */
    private function getScopedEmployees(): array
    {
        $query = Employee::find()->siteId('*')->orderBy('title');
        $staffIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();
        if ($staffIds !== null) {
            $query->id($staffIds);
        }
        return $query->all();
    }

    /** @return Service[] */
    private function getScopedServices(): array
    {
        $query = Service::find()->siteId('*')->orderBy('title');
        $staffIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();
        if ($staffIds !== null) {
            $employees = Employee::find()->siteId('*')->id($staffIds)->all();
            $serviceIds = [];
            foreach ($employees as $emp) {
                $serviceIds = array_merge($serviceIds, $emp->getServiceIds());
            }
            $serviceIds = array_unique($serviceIds);
            if (!empty($serviceIds)) {
                $query->id($serviceIds);
            } else {
                $query->id(0);
            }
        }
        return $query->all();
    }

    /** @return Location[] */
    private function getScopedLocations(): array
    {
        $query = Location::find()->siteId('*')->orderBy('title');
        $staffIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();
        if ($staffIds !== null) {
            $employees = Employee::find()->siteId('*')->id($staffIds)->all();
            $locationIds = array_unique(array_filter(array_map(fn($e) => $e->locationId, $employees)));
            if (!empty($locationIds)) {
                $query->id($locationIds);
            } else {
                $query->id(0);
            }
        }
        return $query->all();
    }

    private function sendCsvResponse(array $headers, array $rows, string $name, ?string $startDate = null, ?string $endDate = null): Response
    {
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF");
        if ($startDate && $endDate) {
            fputcsv($output, ['Date Range', $startDate . ' to ' . $endDate]);
            fputcsv($output, []);
        }
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $this->response->sendContentAsFile($csv, $name . '-' . date('Y-m-d') . '.csv', [
            'mimeType' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Normalize a date parameter from Craft's dateField (which sends {date, locale, timezone})
     * into a Y-m-d string.
     */
    private static function normalizeDateParam(mixed $value, ?string $default = null): ?string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_array($value)) {
            $value = $value['date'] ?? null;
            if ($value === null || $value === '') {
                return $default;
            }
        }

        $parsed = date_create((string) $value);
        return $parsed ? $parsed->format('Y-m-d') : $default;
    }
}
