<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\ServiceExtra;
use anvildev\booked\helpers\SiteHelper;
use Craft;
use craft\enums\PropagationMethod;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ServiceExtraController extends Controller
{
    use JsonResponseTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('booked-manageServices');
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('booked/service-extras/_index', [
            'title' => Craft::t('booked', 'titles.addOns'),
        ]);
    }

    public function actionNew(): Response
    {
        $extra = new ServiceExtra();
        $currentSite = SiteHelper::getSiteForRequest(Craft::$app->getRequest());
        $extra->siteId = $currentSite->id;

        return $this->renderTemplate('booked/service-extras/_edit', [
            'extra' => $extra,
            'isNew' => true,
            'currentSite' => $currentSite,
        ]);
    }

    public function actionEdit(int $id): Response
    {
        $currentSite = SiteHelper::getSiteForRequest(Craft::$app->getRequest());
        $extra = ServiceExtra::find()->id($id)->siteId($currentSite->id)->one()
            ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.addOnNotFound'));

        return $this->renderTemplate('booked/service-extras/_edit', [
            'extra' => $extra,
            'isNew' => false,
            'currentSite' => $currentSite,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $id = $request->getBodyParam('elementId') ?? $request->getBodyParam('id');
        $currentSite = SiteHelper::getSiteFromPost($request);

        if ($id) {
            $extra = ServiceExtra::find()->id($id)->siteId($currentSite->id)->one()
                ?? throw new NotFoundHttpException('Add-on not found');
        } else {
            $extra = new ServiceExtra();
            $extra->siteId = $currentSite->id;
        }

        $extra->title = $request->getBodyParam('title');
        $extra->enabled = (bool)$request->getBodyParam('enabled', true);
        $extra->propagationMethod = PropagationMethod::tryFrom($request->getBodyParam('propagationMethod', 'none')) ?? PropagationMethod::None;
        $extra->description = $request->getBodyParam('description');
        $extra->price = (float)$request->getBodyParam('price', 0);
        $extra->duration = (int)$request->getBodyParam('duration', 0);
        $maxQuantity = $request->getBodyParam('maxQuantity');
        $extra->maxQuantity = ($maxQuantity !== null && $maxQuantity !== '') ? (int)$maxQuantity : 0;
        $extra->isRequired = (bool)$request->getBodyParam('isRequired', false);

        if (!Craft::$app->elements->saveElement($extra)) {
            Craft::$app->getSession()->setError(Craft::t('booked', 'messages.addOnNotSaved'));
            Craft::$app->urlManager->setRouteParams(['extra' => $extra, 'currentSite' => $currentSite]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('booked', 'messages.addOnSaved'));
        return $this->redirect('booked/service-extras');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $extra = ServiceExtra::find()
            ->id(Craft::$app->getRequest()->getRequiredBodyParam('id'))
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one() ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.addOnNotFound'));

        if (Craft::$app->elements->deleteElement($extra)) {
            Craft::$app->getSession()->setNotice(Craft::t('booked', 'messages.addOnDeleted'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('booked', 'messages.addOnDeleteFailed'));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionGetForService(): Response
    {
        $this->requireAcceptsJson();

        $serviceId = Craft::$app->getRequest()->getQueryParam('serviceId');
        if (!$serviceId) {
            return $this->jsonError('Service ID is required', 'validation');
        }

        $extras = Booked::getInstance()->serviceExtra->getExtrasForService((int)$serviceId);

        return $this->jsonSuccess('', [
            'extras' => array_map(fn($extra) => [
                'id' => $extra->id,
                'title' => $extra->title,
                'description' => $extra->description,
                'price' => $extra->price,
                'duration' => $extra->duration,
                'maxQuantity' => $extra->maxQuantity,
                'isRequired' => $extra->isRequired,
                'enabled' => $extra->enabled,
            ], $extras),
        ]);
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        /** @var array<int, int> $ids */
        $ids = Craft::$app->getRequest()->getRequiredBodyParam('ids');
        foreach ($ids as $order => $id) {
            $extra = Booked::getInstance()->serviceExtra->getExtraById($id);
            if ($extra) {
                $extra->sortOrder = $order;
                Booked::getInstance()->serviceExtra->saveExtra($extra);
            }
        }

        return $this->jsonSuccess();
    }
}
