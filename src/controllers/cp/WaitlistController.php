<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\Booked;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Service;
use anvildev\booked\records\WaitlistRecord;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class WaitlistController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('booked-manageWaitlist');
        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();

        $status = $request->getParam('status');
        $search = $request->getParam('search');
        $serviceId = $request->getParam('serviceId');
        $employeeId = $request->getParam('employeeId');

        $sortRaw = $request->getParam('sort');
        $sort = in_array($sortRaw, ['userName', 'dateCreated', 'status', 'preferredDate'], true) ? $sortRaw : 'dateCreated';
        $dir = $request->getParam('dir') === 'asc' ? 'asc' : 'desc';
        $sortDir = $dir === 'asc' ? SORT_ASC : SORT_DESC;

        $query = WaitlistRecord::find()->orderBy([$sort => $sortDir]);

        if ($status && $status !== '*') {
            $query->andWhere(['status' => $status]);
        }

        if ($serviceId) {
            $query->andWhere(['serviceId' => (int)$serviceId]);
        }

        if ($employeeId) {
            $query->andWhere(['employeeId' => (int)$employeeId]);
        }

        if ($search) {
            $conditions = [
                'or',
                ['like', 'userName', $search],
                ['like', 'userEmail', $search],
                ['like', 'userPhone', $search],
            ];

            if (ctype_digit($search)) {
                $conditions[] = ['id' => (int)$search];
            }

            $query->andWhere($conditions);
        }

        $page = max(1, (int)($request->getParam('page') ?? 1));
        $limitParam = (int)($request->getParam('limit') ?? 50);
        $limit = in_array($limitParam, [10, 20, 50, 100, 200], true) ? $limitParam : 50;
        $offset = ($page - 1) * $limit;
        $totalCount = (int)(clone $query)->count();
        $entries = $query->offset($offset)->limit($limit)->all();

        return $this->renderTemplate('booked/waitlist/_index', [
            'title' => Craft::t('booked', 'waitlist.title'),
            'entries' => $entries,
            'statuses' => WaitlistRecord::getStatuses(),
            'currentStatus' => $status ?: '*',
            'search' => $search,
            'currentServiceId' => $serviceId,
            'currentEmployeeId' => $employeeId,
            'serviceOptions' => Service::find()->orderBy('title')->all(),
            'employeeOptions' => Employee::find()->siteId('*')->orderBy('title')->all(),
            'sort' => $sort,
            'dir' => $dir,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => (int)ceil($totalCount / $limit) ?: 1,
                'totalCount' => $totalCount,
                'limit' => $limit,
                'first' => $totalCount > 0 ? $offset + 1 : 0,
                'last' => min($offset + $limit, $totalCount),
            ],
        ]);
    }

    public function actionEdit(int $id): Response
    {
        $entry = WaitlistRecord::findOne($id)
            ?? throw new NotFoundHttpException('Waitlist entry not found');

        return $this->renderTemplate('booked/waitlist/_edit', [
            'entry' => $entry,
            'service' => $entry->getService(),
            'employee' => $entry->getEmployee(),
            'location' => $entry->getLocation(),
            'eventDate' => $entry->getEventDate(),
        ]);
    }

    public function actionNotify(): Response
    {
        $this->requirePostRequest();

        $success = Booked::getInstance()->waitlist->manualNotify((int)Craft::$app->getRequest()->getRequiredBodyParam('entryId'));

        Craft::$app->getSession()->{$success ? 'setNotice' : 'setError'}(
            Craft::t('booked', $success ? 'waitlist.notificationSent' : 'waitlist.notificationFailed')
        );

        return $this->redirectToPostedUrl();
    }

    public function actionCancel(): Response
    {
        $this->requirePostRequest();

        $success = Booked::getInstance()->waitlist->cancelEntry((int)Craft::$app->getRequest()->getRequiredBodyParam('entryId'));

        Craft::$app->getSession()->{$success ? 'setNotice' : 'setError'}(
            Craft::t('booked', $success ? 'waitlist.entryCancelled' : 'waitlist.cancelFailed')
        );

        return $this->redirectToPostedUrl();
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $entry = WaitlistRecord::findOne(Craft::$app->getRequest()->getRequiredBodyParam('entryId'))
            ?? throw new NotFoundHttpException('Waitlist entry not found');

        if ($entry->delete()) {
            Craft::$app->getSession()->setNotice(Craft::t('booked', 'waitlist.entryDeleted'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('booked', 'waitlist.deleteFailed'));
        }

        return $this->redirectToPostedUrl(null, 'booked/waitlist');
    }

    public function actionCleanup(): Response
    {
        $this->requirePostRequest();

        $count = Booked::getInstance()->waitlist->cleanupExpired();

        Craft::$app->getSession()->setNotice(Craft::t('booked',
            $count > 0 ? 'waitlist.cleanedUpCount' : 'waitlist.noneToCleanup',
            ['count' => $count]
        ));

        return $this->redirectToPostedUrl(null, 'booked/waitlist');
    }
}
