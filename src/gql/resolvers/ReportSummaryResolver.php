<?php

namespace anvildev\booked\gql\resolvers;

use anvildev\booked\Booked;
use Craft;
use craft\gql\base\Resolver;
use GraphQL\Type\Definition\ResolveInfo;
use yii\db\Query;

class ReportSummaryResolver extends Resolver
{
    private static function emptyResult(string $startDate, string $endDate): array
    {
        return [
            'totalBookings' => 0,
            'confirmedBookings' => 0,
            'cancelledBookings' => 0,
            'cancellationRate' => 0.0,
            'totalRevenue' => 0.0,
            'averageBookingValue' => 0.0,
            'newCustomers' => 0,
            'returningCustomers' => 0,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
    }

    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        $startDate = $arguments['startDate'] ?? date('Y-m-01');
        $endDate = $arguments['endDate'] ?? date('Y-m-t');

        // Validate date formats
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new \GraphQL\Error\Error('Invalid date format. Expected Y-m-d.');
        }

        // Strict calendar validation
        $startDt = \DateTime::createFromFormat('Y-m-d', $startDate);
        $endDt = \DateTime::createFromFormat('Y-m-d', $endDate);
        if (!$startDt || $startDt->format('Y-m-d') !== $startDate || !$endDt || $endDt->format('Y-m-d') !== $endDate) {
            throw new \GraphQL\Error\Error('Invalid date. Dates must be valid calendar dates in Y-m-d format.');
        }

        // Cap date range to 365 days
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $maxEnd = (clone $start)->add(new \DateInterval('P365D'));
        if ($end > $maxEnd) {
            $endDate = $maxEnd->format('Y-m-d');
        }

        // Staff scoping: unauthenticated users see nothing, non-admin staff see only managed employees
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return self::emptyResult($startDate, $endDate);
        }

        $staffEmployeeIds = null;
        if (!$user->admin) {
            $staffEmployeeIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();
        }

        $reports = Booked::getInstance()->getReports();

        $revenueData = $reports->getRevenueData($startDate, $endDate);
        $cancellationData = $reports->getCancellationData($startDate, $endDate);
        $confirmedCount = count($revenueData['reservations']);

        $subQuery = (new Query())
            ->select(['userEmail', 'cnt' => 'COUNT(*)'])
            ->from('{{%booked_reservations}}')
            ->where(['status' => 'confirmed'])
            ->andWhere(['not', ['userEmail' => null]])
            ->andWhere(['!=', 'userEmail', ''])
            ->andWhere(['between', 'bookingDate', $startDate, $endDate])
            ->groupBy('userEmail');

        if ($staffEmployeeIds !== null) {
            $subQuery->andWhere(['employeeId' => $staffEmployeeIds]);
        }

        $customerCounts = (new Query())
            ->select([
                'total' => 'COUNT(DISTINCT userEmail)',
                'returning' => 'SUM(CASE WHEN cnt >= 2 THEN 1 ELSE 0 END)',
            ])
            ->from(['sub' => $subQuery])
            ->one();

        $totalCustomers = (int) ($customerCounts['total'] ?? 0);
        $returningCustomers = (int) ($customerCounts['returning'] ?? 0);

        return [
            'totalBookings' => $cancellationData['total'],
            'confirmedBookings' => $confirmedCount,
            'cancelledBookings' => $cancellationData['cancelled'],
            'cancellationRate' => round($cancellationData['rate'], 2),
            'totalRevenue' => round($revenueData['total'], 2),
            'averageBookingValue' => $confirmedCount > 0 ? round($revenueData['total'] / $confirmedCount, 2) : 0,
            'newCustomers' => $totalCustomers - $returningCustomers,
            'returningCustomers' => $returningCustomers,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
    }
}
