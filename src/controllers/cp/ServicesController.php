<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\Service;
use anvildev\booked\elements\ServiceExtra;
use anvildev\booked\helpers\ElementQueryHelper;
use anvildev\booked\helpers\FormFieldHelper;
use anvildev\booked\helpers\RefundTierHelper;
use anvildev\booked\helpers\SiteHelper;
use Craft;
use craft\enums\PropagationMethod;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

class ServicesController extends Controller
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
        return $this->renderTemplate('booked/services/_index', [
            'title' => Craft::t('booked', 'titles.services'),
        ]);
    }

    public function actionEdit(?int $id = null, ?Service $service = null): Response
    {
        $currentSite = SiteHelper::getSiteForRequest(Craft::$app->getRequest());

        if ($service === null) {
            if ($id !== null) {
                $service = ElementQueryHelper::forSite(
                    Service::find()->id($id)->status(null),
                    $currentSite->id
                )->one();

                if (!$service) {
                    $service = ElementQueryHelper::forAllSites(
                        Service::find()->id($id)->status(null)
                    )->one() ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.serviceNotFound'));

                    $existingSite = Craft::$app->getSites()->getSiteById($service->siteId);
                    if ($existingSite) {
                        Craft::$app->getSession()->setNotice(Craft::t('booked', 'multiSite.redirectNotice', [
                            'site' => $currentSite->name,
                            'existingSite' => $existingSite->name,
                        ]));
                        return $this->redirect("booked/services/{$id}?site={$existingSite->handle}");
                    }
                }
            } else {
                $service = new Service();
                $service->siteId = $currentSite->id;
            }
        }

        $isNew = !$service->id;
        $assignedExtras = $isNew ? [] : array_map(
            fn($extra) => $extra->id,
            Booked::getInstance()->serviceExtra->getExtrasForService($service->id)
        );
        $assignedLocations = $isNew ? [] : array_map(
            fn($location) => $location->id,
            Booked::getInstance()->serviceLocation->getLocationsForService($service->id)
        );

        return $this->renderTemplate('booked/services/_edit', [
            'service' => $service,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('booked', 'titles.newService') : $service->title,
            'serviceExtras' => ServiceExtra::find()->status('enabled')->orderBy('title')->all(),
            'assignedExtras' => $assignedExtras,
            'assignedLocations' => $assignedLocations,
            'currentSite' => $currentSite,
        ]);
    }

    public function actionSave(): ?\yii\web\Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $id = $request->getBodyParam('id');
        $currentSite = SiteHelper::getSiteFromPost($request);

        $service = $id
            ? (ElementQueryHelper::forSite(Service::find()->id($id)->status(null), $currentSite->id)->one()
                ?? throw new NotFoundHttpException('Service not found'))
            : (function() use ($currentSite) {
                $s = new Service();
                $s->siteId = $currentSite->id;
                return $s;
            })();

        $service->title = $request->getBodyParam('title');
        $service->description = $request->getBodyParam('description') ?: null;
        $service->enabled = (bool)$request->getBodyParam('enabled', true);
        $service->duration = $request->getBodyParam('duration') ?: null;
        $service->bufferBefore = $request->getBodyParam('bufferBefore') ?: null;
        $service->bufferAfter = $request->getBodyParam('bufferAfter') ?: null;
        $service->price = $request->getBodyParam('price') ?: null;
        $service->virtualMeetingProvider = $request->getBodyParam('virtualMeetingProvider') ?: null;
        $service->minTimeBeforeBooking = $request->getBodyParam('minTimeBeforeBooking') ?: null;
        $service->propagationMethod = PropagationMethod::tryFrom($request->getBodyParam('propagationMethod', 'none')) ?? PropagationMethod::None;
        $service->timeSlotLength = $request->getBodyParam('timeSlotLength') ?: null;
        $service->customerLimitEnabled = (bool)$request->getBodyParam('customerLimitEnabled', false);
        $service->customerLimitCount = $request->getBodyParam('customerLimitCount') ?: null;
        $service->customerLimitPeriod = $request->getBodyParam('customerLimitPeriod') ?: null;
        $service->customerLimitPeriodType = $request->getBodyParam('customerLimitPeriodType') ?: null;

        $service->allowCancellation = (bool)$request->getBodyParam('allowCancellation', false);
        $service->allowRefund = (bool)$request->getBodyParam('allowRefund', false);

        $cancellationPolicyHours = $request->getBodyParam('cancellationPolicyHours');
        $service->cancellationPolicyHours = ($cancellationPolicyHours !== '' && $cancellationPolicyHours !== null) ? (int)$cancellationPolicyHours : null;

        $refundTiersParam = $request->getBodyParam('refundTiers');
        $service->refundTiers = $this->normalizeRefundTiers($refundTiersParam);

        $enableWaitlist = $request->getBodyParam('enableWaitlist');
        $service->enableWaitlist = ($enableWaitlist === '' || $enableWaitlist === null) ? null : (bool)$enableWaitlist;

        $taxCategoryId = $request->getBodyParam('taxCategoryId');
        $service->taxCategoryId = ($taxCategoryId !== '' && $taxCategoryId !== null) ? (int)$taxCategoryId : null;

        $service->availabilitySchedule = FormFieldHelper::formatWorkingHoursFromRequest(
            $request->getBodyParam('availabilitySchedule', [])
        );

        if (!Craft::$app->getElements()->saveElement($service)) {
            Craft::$app->getSession()->setError(Craft::t('booked', 'messages.serviceNotSaved'));
            Craft::$app->getUrlManager()->setRouteParams(['service' => $service]);
            return null;
        }

        // Save service extras
        $selectedExtras = $request->getBodyParam('extras', []);
        $selectedExtras = is_array($selectedExtras) ? array_map('intval', array_filter($selectedExtras)) : [];
        Booked::getInstance()->serviceExtra->setExtrasForService($service->id, $selectedExtras);

        // Save service locations
        $selectedLocations = $request->getBodyParam('locations', []);
        $selectedLocations = is_array($selectedLocations) ? array_map('intval', array_filter($selectedLocations)) : [];
        Booked::getInstance()->serviceLocation->setLocationsForService($service->id, $selectedLocations);

        // Save schedule assignments
        $schedules = $request->getBodyParam('schedules', []);
        Booked::getInstance()->getScheduleAssignment()->setSchedulesForService(
            $service->id,
            is_array($schedules) ? array_map('intval', array_filter($schedules)) : []
        );

        Craft::$app->getSession()->setNotice(Craft::t('booked', 'messages.serviceSaved'));
        return $this->redirectToPostedUrl($service);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $service = ElementQueryHelper::forAllSites(
            Service::find()->id(Craft::$app->getRequest()->getRequiredBodyParam('id'))->status(null)
        )->one() ?? throw new NotFoundHttpException('Service not found');

        if (!Craft::$app->getElements()->deleteElement($service)) {
            return $this->jsonError(Craft::t('booked', 'messages.serviceDeleteFailed'));
        }

        return $this->jsonSuccess(Craft::t('booked', 'messages.serviceDeleted'));
    }

    private function normalizeRefundTiers(mixed $param): ?array
    {
        return RefundTierHelper::normalize($param);
    }
}
