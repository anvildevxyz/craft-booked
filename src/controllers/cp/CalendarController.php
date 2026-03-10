<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\Employee;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

class CalendarController extends Controller
{
    use JsonResponseTrait;

    public function beforeAction($action): bool
    {
        if ($action->id === 'callback') {
            $this->enableCsrfValidation = false;
        }
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('booked-manageEmployees');
        return true;
    }

    public function actionConnect(int $employeeId, string $provider): Response
    {
        $employee = Employee::find()->siteId('*')->id($employeeId)->one()
            ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.employeeNotFound'));

        return $this->redirect(Booked::getInstance()->getCalendarSync()->getAuthUrl($employee, $provider));
    }

    public function actionCallback(): Response
    {
        $request = Craft::$app->request;
        $stateToken = $request->getParam('state');
        $code = $request->getParam('code');

        if (!$stateToken) {
            Craft::error('OAuth callback missing state parameter', __METHOD__);
            Craft::$app->getSession()->setError(Craft::t('booked', 'calendar.invalidState'));
            return $this->redirect('booked/employees');
        }

        if (!$code) {
            Craft::error('OAuth callback missing code parameter', __METHOD__);
            Craft::$app->getSession()->setError(Craft::t('booked', 'calendar.noCode'));
            return $this->redirect('booked/employees');
        }

        $stateData = \anvildev\booked\records\OAuthStateTokenRecord::peek($stateToken);
        if (!$stateData) {
            Craft::error('Invalid or expired OAuth state token', __METHOD__);
            Craft::$app->getSession()->setError(Craft::t('booked', 'calendar.invalidState'));
            return $this->redirect('booked/employees');
        }

        $employeeId = $stateData['employeeId'];
        $provider = $stateData['provider'];
        $success = Booked::getInstance()->getCalendarSync()->handleCallback($stateToken, $code);

        $session = Craft::$app->getSession();
        $success
            ? $session->setNotice(Craft::t('booked', 'calendar.connected', ['provider' => ucfirst($provider)]))
            : $session->setError(Craft::t('booked', 'calendar.connectFailed', ['provider' => ucfirst($provider)]));

        return $this->redirect('booked/employees/' . $employeeId);
    }

    public function actionSendInvite(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $employeeId = $request->getRequiredBodyParam('employeeId');
        $provider = $request->getRequiredBodyParam('provider');

        $employee = Employee::find()->siteId('*')->id($employeeId)->one()
            ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.employeeNotFound'));

        if (!$employee->email) {
            return $this->respondWithMessage(Craft::t('booked', 'calendar.noEmployeeEmail'), false, $employeeId);
        }

        $success = Booked::getInstance()->getCalendarSync()->sendConnectionInvite($employee, $provider);
        $message = $success
            ? Craft::t('booked', 'calendar.inviteSent', ['email' => $employee->email])
            : Craft::t('booked', 'calendar.inviteFailed');

        return $this->respondWithMessage($message, $success, $employeeId);
    }

    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $employeeId = $request->getRequiredBodyParam('employeeId');
        $provider = $request->getRequiredBodyParam('provider');

        $employee = Employee::find()->siteId('*')->id($employeeId)->one()
            ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.employeeNotFound'));

        $success = Booked::getInstance()->getCalendarSync()->disconnect($employee, $provider);
        $message = Craft::t('booked', $success ? 'calendar.disconnected' : 'calendar.disconnectFailed', ['provider' => ucfirst($provider)]);

        return $this->respondWithMessage($message, $success, $employeeId);
    }

    private function respondWithMessage(string $message, bool $success, int $employeeId): Response
    {
        if (Craft::$app->request->getAcceptsJson()) {
            return $success ? $this->jsonSuccess($message) : $this->jsonError($message);
        }
        $success
            ? Craft::$app->getSession()->setNotice($message)
            : Craft::$app->getSession()->setError($message);
        return $this->redirect("booked/employees/{$employeeId}");
    }
}
