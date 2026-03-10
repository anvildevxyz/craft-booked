<?php

namespace anvildev\booked\controllers;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Schedule;
use anvildev\booked\helpers\FormFieldHelper;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Frontend schedule management (employee self-service + admin mode)
 */
class EmployeeScheduleController extends Controller
{
    use JsonResponseTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireLogin();
        return true;
    }

    public function actionIndex(?int $employeeId = null): Response
    {
        $canManageAll = $this->canManageAllEmployees();
        $employee = null;
        $isAdminMode = false;
        $employees = [];

        if ($employeeId !== null) {
            if (!$canManageAll) {
                throw new ForbiddenHttpException(Craft::t('booked', 'employeeSchedule.noPermissionManage'));
            }
            $employee = Employee::find()->siteId('*')->id($employeeId)->status(null)->one();
            if (!$employee) {
                throw new NotFoundHttpException(Craft::t('booked', 'errors.employeeNotFound'));
            }
            if (!$employee->userId) {
                throw new NotFoundHttpException(Craft::t('booked', 'employeeSchedule.noLinkedAccount'));
            }
            $isAdminMode = true;
        } elseif ($canManageAll) {
            $isAdminMode = true;
            $employees = $this->getAllEmployeesWithUsers();
        } else {
            $employee = $this->getEmployeeForCurrentUser();
            if (!$employee) {
                throw new ForbiddenHttpException(Craft::t('booked', 'employeeSchedule.notLinked'));
            }
        }

        $schedules = $employee
            ? Booked::getInstance()->getScheduleAssignment()->getSchedulesForEmployee($employee->id)
            : [];

        return $this->renderTemplate('booked/frontend/employee-schedule', [
            'employee' => $employee,
            'employees' => $employees,
            'schedules' => $schedules,
            'days' => [
                1 => Craft::t('booked', 'labels.monday'),
                2 => Craft::t('booked', 'labels.tuesday'),
                3 => Craft::t('booked', 'labels.wednesday'),
                4 => Craft::t('booked', 'labels.thursday'),
                5 => Craft::t('booked', 'labels.friday'),
                6 => Craft::t('booked', 'labels.saturday'),
                7 => Craft::t('booked', 'labels.sunday'),
            ],
            'isAdminMode' => $isAdminMode,
            'selectedEmployeeId' => $employee?->id,
        ]);
    }

    public function actionListEmployees(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->canManageAllEmployees()) {
            throw new ForbiddenHttpException(Craft::t('booked', 'employeeSchedule.noPermissionView'));
        }

        return $this->jsonSuccess('', [
            'employees' => array_map(fn(Employee $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'userId' => $e->userId,
                'userName' => $e->getUser()?->getName() ?? $e->title,
            ], $this->getAllEmployeesWithUsers()),
        ]);
    }

    public function actionGetSchedules(): Response
    {
        $this->requireAcceptsJson();

        $employeeId = Craft::$app->request->getParam('employeeId');

        if (!$employeeId) {
            $employee = $this->getEmployeeForCurrentUser();
            if (!$employee) {
                return $this->jsonError(Craft::t('booked', 'employeeSchedule.notLinked'));
            }
            $employeeId = $employee->id;
        } else {
            $employee = Employee::find()->siteId('*')->id($employeeId)->status(null)->one();
            if (!$employee) {
                return $this->jsonError(Craft::t('booked', 'errors.employeeNotFound'));
            }
            if (!$this->canManageEmployee($employee)) {
                throw new ForbiddenHttpException(Craft::t('booked', 'employeeSchedule.noPermissionViewSchedules'));
            }
        }

        return $this->jsonSuccess('', [
            'schedules' => array_map(fn(Schedule $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'workingHours' => $s->workingHours,
                'startDate' => $s->startDate,
                'endDate' => $s->endDate,
                'enabled' => $s->enabled,
            ], Booked::getInstance()->getScheduleAssignment()->getSchedulesForEmployee((int)$employeeId)),
        ]);
    }

    public function actionSaveSchedule(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->request;
        $scheduleId = $request->getBodyParam('id');
        $employeeId = $request->getRequiredBodyParam('employeeId');

        $employee = Employee::find()->siteId('*')->id($employeeId)->status(null)->one();
        if (!$employee) {
            return $this->jsonError(Craft::t('booked', 'errors.employeeNotFound'));
        }
        if (!$this->canManageEmployee($employee)) {
            throw new ForbiddenHttpException(Craft::t('booked', 'employeeSchedule.noPermissionManageSchedules'));
        }

        $scheduleAssignment = Booked::getInstance()->getScheduleAssignment();

        if ($scheduleId) {
            $schedule = Schedule::find()->siteId('*')->id($scheduleId)->status(null)->one();
            if (!$schedule) {
                return $this->jsonError(Craft::t('booked', 'employeeSchedule.scheduleNotFound'));
            }
            $canEdit = false;
            foreach ($schedule->getAssignedEmployees() as $assigned) {
                if ($this->canManageEmployee($assigned)) {
                    $canEdit = true;
                    break;
                }
            }
            if (!$canEdit) {
                throw new ForbiddenHttpException(Craft::t('booked', 'employeeSchedule.noPermissionEdit'));
            }
        } else {
            $schedule = new Schedule();
        }

        $schedule->title = $request->getBodyParam('title');

        $workingHours = $request->getBodyParam('workingHours', []);
        if (is_string($workingHours)) {
            $workingHours = json_decode($workingHours, true) ?? [];
        }
        $schedule->workingHours = FormFieldHelper::formatWorkingHoursFromRequest($workingHours);
        $schedule->startDate = FormFieldHelper::extractDateValue($request->getBodyParam('startDate'));
        $schedule->endDate = FormFieldHelper::extractDateValue($request->getBodyParam('endDate'));

        $enabled = $request->getBodyParam('enabled');
        $schedule->enabled = ($enabled === null || $enabled === '')
            ? true
            : in_array($enabled, [true, '1', 1, 'true'], true);

        if (!Craft::$app->elements->saveElement($schedule)) {
            return $this->jsonError(Craft::t('booked', 'employeeSchedule.saveFailed'), null, $schedule->getErrors());
        }

        if (!$scheduleId) {
            $currentScheduleIds = $scheduleAssignment->getScheduleIdsForEmployee((int)$employeeId);
            $currentScheduleIds[] = $schedule->id;
            $scheduleAssignment->setSchedulesForEmployee((int)$employeeId, $currentScheduleIds);
        }

        return $this->jsonSuccess('', [
            'schedule' => [
                'id' => $schedule->id,
                'title' => $schedule->title,
                'enabled' => $schedule->enabled,
            ],
        ]);
    }

    public function actionDeleteSchedule(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->request;
        $scheduleId = $request->getRequiredBodyParam('id');
        $employeeId = $request->getBodyParam('employeeId');

        $schedule = Schedule::find()->siteId('*')->id($scheduleId)->status(null)->one();
        if (!$schedule) {
            return $this->jsonError(Craft::t('booked', 'employeeSchedule.scheduleNotFound'));
        }

        if (!$employeeId) {
            $employee = $this->getEmployeeForCurrentUser();
            if (!$employee) {
                return $this->jsonError(Craft::t('booked', 'employeeSchedule.notLinked'));
            }
            $employeeId = $employee->id;
        } else {
            $employee = Employee::find()->siteId('*')->id($employeeId)->status(null)->one();
            if (!$employee) {
                return $this->jsonError(Craft::t('booked', 'errors.employeeNotFound'));
            }
        }

        if (!$this->canManageEmployee($employee)) {
            throw new ForbiddenHttpException(Craft::t('booked', 'employeeSchedule.noPermissionDelete'));
        }

        $scheduleAssignment = Booked::getInstance()->getScheduleAssignment();

        if (count($schedule->getAssignedEmployees()) > 1) {
            $scheduleAssignment->unassignScheduleFromEmployee($scheduleId, $employeeId);
            return $this->jsonSuccess(Craft::t('booked', 'employeeSchedule.unassigned'));
        }

        return Craft::$app->elements->deleteElement($schedule)
            ? $this->jsonSuccess(Craft::t('booked', 'employeeSchedule.deleted'))
            : $this->jsonError(Craft::t('booked', 'employeeSchedule.deleteFailed'));
    }

    public function actionAssignSchedule(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->request;
        $scheduleId = $request->getRequiredBodyParam('scheduleId');
        $employeeId = $request->getRequiredBodyParam('employeeId');

        $employee = Employee::find()->siteId('*')->id($employeeId)->status(null)->one();
        if (!$employee) {
            return $this->jsonError(Craft::t('booked', 'errors.employeeNotFound'));
        }
        if (!$this->canManageEmployee($employee)) {
            throw new ForbiddenHttpException(Craft::t('booked', 'employeeSchedule.noPermissionManageSchedules'));
        }

        if (!Schedule::find()->siteId('*')->id($scheduleId)->status(null)->one()) {
            return $this->jsonError(Craft::t('booked', 'employeeSchedule.scheduleNotFound'));
        }

        $scheduleAssignment = Booked::getInstance()->getScheduleAssignment();
        $currentScheduleIds = $scheduleAssignment->getScheduleIdsForEmployee((int)$employeeId);
        if (!in_array($scheduleId, $currentScheduleIds)) {
            $currentScheduleIds[] = $scheduleId;
            $scheduleAssignment->setSchedulesForEmployee((int)$employeeId, $currentScheduleIds);
        }

        return $this->jsonSuccess(Craft::t('booked', 'employeeSchedule.assigned'));
    }

    private function getEmployeeForCurrentUser(): ?Employee
    {
        $user = Craft::$app->user->identity;
        return $user ? Employee::find()->siteId('*')->userId($user->id)->status(null)->one() : null;
    }

    private function canManageAllEmployees(): bool
    {
        return Craft::$app->user->checkPermission('booked-manageEmployees');
    }

    private function canManageEmployee(?Employee $employee): bool
    {
        if (!$employee) {
            return false;
        }
        if ($this->canManageAllEmployees()) {
            return true;
        }
        $user = Craft::$app->user->identity;
        return $user && $employee->userId === $user->id;
    }

    private function getAllEmployeesWithUsers(): array
    {
        return Employee::find()
            ->siteId('*')
            ->where(['not', ['userId' => null]])
            ->status('enabled')
            ->orderBy('title')
            ->all();
    }
}
