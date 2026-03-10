<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\elements\Schedule;
use anvildev\booked\helpers\FormFieldHelper;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

class SchedulesController extends Controller
{
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
        return $this->renderTemplate('booked/schedules/_index', [
            'title' => Craft::t('booked', 'titles.schedules'),
        ]);
    }

    public function actionEdit(?int $id = null): Response
    {
        if ($id) {
            $schedule = Schedule::find()->siteId('*')->id($id)->status(null)->one()
                ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.scheduleNotFound'));
        } else {
            $schedule = new Schedule();
            $schedule->siteId = Craft::$app->request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id;
        }

        return $this->renderTemplate('booked/schedules/edit', [
            'schedule' => $schedule,
            'isNew' => $id === null,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $id = $request->getBodyParam('elementId') ?? $request->getBodyParam('id');

        $schedule = $id
            ? (Schedule::find()->siteId('*')->id($id)->status(null)->one() ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.scheduleNotFound')))
            : new Schedule();

        $schedule->title = $request->getBodyParam('title');
        $schedule->enabled = (bool)$request->getBodyParam('enabled', true);
        $schedule->startDate = FormFieldHelper::extractDateValue($request->getBodyParam('startDate'));
        $schedule->endDate = FormFieldHelper::extractDateValue($request->getBodyParam('endDate'));

        $workingHours = $request->getBodyParam('workingHours', []);
        if (is_string($workingHours)) {
            $workingHours = json_decode($workingHours, true) ?? [];
        }
        if (is_array($workingHours)) {
            $schedule->workingHours = FormFieldHelper::formatWorkingHoursFromRequest($workingHours, true);
        }

        if (!Craft::$app->elements->saveElement($schedule)) {
            Craft::$app->session->setError(Craft::t('booked', 'messages.scheduleNotSaved'));
            Craft::$app->urlManager->setRouteParams(['schedule' => $schedule]);
            return $this->redirectToPostedUrl();
        }

        Craft::$app->session->setNotice(Craft::t('booked', 'messages.scheduleSaved'));
        return $this->redirect('booked/schedules');
    }
}
