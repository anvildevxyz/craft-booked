<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use anvildev\booked\helpers\FormFieldHelper;
use anvildev\booked\records\CalendarTokenRecord;
use anvildev\booked\records\EmployeeManagerRecord;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

class EmployeesController extends Controller
{
    use JsonResponseTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('booked-manageEmployees');
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('booked/employees/_index', [
            'title' => Craft::t('booked', 'titles.employees'),
        ]);
    }

    public function actionEdit(?int $id = null): Response
    {
        if ($id) {
            $employee = Employee::find()->siteId('*')->id($id)->status(null)->one()
                ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.employeeNotFound'));
        } else {
            $employee = new Employee();
            $employee->siteId = Craft::$app->request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id;
        }

        $assignedUserIds = array_values(array_filter(array_map('intval',
            Employee::find()->siteId('*')->status(null)
                ->andWhere(['not', ['booked_employees.userId' => null]])
                ->select(['booked_employees.userId'])->column()
        )));

        $managedEmployees = [];
        if ($employee->id) {
            $managedIds = EmployeeManagerRecord::find()
                ->where(['employeeId' => $employee->id])
                ->select(['managedEmployeeId'])->column();
            if ($managedIds) {
                $managedEmployees = Employee::find()->siteId('*')->id($managedIds)->status(null)->all();
            }
        }

        return $this->renderTemplate('booked/employees/edit', [
            'employee' => $employee,
            'locations' => Location::find()->siteId('*')->enabled()->all(),
            'services' => \anvildev\booked\helpers\ElementQueryHelper::forCurrentSite(Service::find()->enabled())->all(),
            'assignedUserIds' => $assignedUserIds,
            'managedEmployees' => $managedEmployees,
            'googleConnected' => $employee->id && (bool)CalendarTokenRecord::findOne(['employeeId' => $employee->id, 'provider' => 'google']),
            'outlookConnected' => $employee->id && (bool)CalendarTokenRecord::findOne(['employeeId' => $employee->id, 'provider' => 'outlook']),
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $id = $request->getBodyParam('elementId') ?? $request->getBodyParam('id');

        $employee = $id
            ? (Employee::find()->siteId('*')->id($id)->status(null)->one() ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.employeeNotFound')))
            : new Employee();

        $employee->title = $request->getBodyParam('title');
        $employee->enabled = (bool)$request->getBodyParam('enabled', true);

        // Handle userId (array from element selector or scalar)
        $userId = $request->getBodyParam('userId');
        $userId = is_array($userId) ? ($userId[0] ?? null) : $userId;
        $employee->userId = ($userId === '' || $userId === null) ? null : (int)$userId;

        $locationId = $request->getBodyParam('locationId');
        $locationId = is_array($locationId) ? ($locationId[0] ?? null) : $locationId;
        $employee->locationId = ($locationId === '' || $locationId === null) ? null : (int)$locationId;

        $email = $request->getBodyParam('email');
        $employee->email = ($email === '' || $email === null) ? null : trim($email);

        $services = $request->getBodyParam('services', []);
        $employee->serviceIds = is_array($services) ? array_map('intval', $services) : [];

        $workingHours = $request->getBodyParam('workingHours', []);
        if (is_array($workingHours)) {
            $employee->workingHours = FormFieldHelper::formatWorkingHoursFromRequest($workingHours);
        }

        if (!Craft::$app->elements->saveElement($employee)) {
            Craft::$app->session->setError(Craft::t('booked', 'messages.employeeNotSaved'));
            Craft::$app->urlManager->setRouteParams(['employee' => $employee]);
            return $this->redirectToPostedUrl();
        }

        // Schedule assignments
        $schedules = $request->getBodyParam('schedules', []);
        \anvildev\booked\Booked::getInstance()->getScheduleAssignment()->setSchedulesForEmployee(
            $employee->id,
            is_array($schedules) ? array_map('intval', array_filter($schedules)) : []
        );

        // Managed employee assignments
        $managed = $request->getBodyParam('managedEmployees', []);
        $this->syncManagedEmployees($employee->id, is_array($managed) ? array_map('intval', array_filter($managed)) : []);

        Craft::$app->session->setNotice(Craft::t('booked', 'messages.employeeSaved'));
        return $this->redirect('booked/employees');
    }

    private function syncManagedEmployees(int $employeeId, array $managedEmployeeIds): void
    {
        $transaction = Craft::$app->db->beginTransaction();
        try {
            EmployeeManagerRecord::deleteAll(['employeeId' => $employeeId]);
            foreach ($managedEmployeeIds as $managedId) {
                $record = new EmployeeManagerRecord();
                $record->employeeId = $employeeId;
                $record->managedEmployeeId = $managedId;
                $record->save(false);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
