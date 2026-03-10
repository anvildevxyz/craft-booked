<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\Booked;
use craft\web\Controller;

class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('booked-accessPlugin');
        return true;
    }

    public function actionIndex(): mixed
    {
        $staffEmployeeIds = Booked::getInstance()->getPermission()->getStaffEmployeeIds();
        $data = Booked::getInstance()->getDashboard()->getDashboardData($staffEmployeeIds);
        return $this->renderTemplate('booked/dashboard/index', $data);
    }
}
