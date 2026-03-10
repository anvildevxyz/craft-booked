<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\elements\Location;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

class LocationsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('booked-manageLocations');
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('booked/locations/_index', [
            'title' => Craft::t('booked', 'titles.locations'),
        ]);
    }

    public function actionEdit(?int $id = null): Response
    {
        if ($id) {
            $location = Location::find()->siteId('*')->id($id)->one()
                ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.locationNotFound'));
        } else {
            $location = new Location();
            $location->siteId = Craft::$app->request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id;
        }

        return $this->renderTemplate('booked/locations/edit', [
            'location' => $location,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $id = $request->getBodyParam('elementId') ?? $request->getBodyParam('id');

        $location = $id
            ? (Location::find()->siteId('*')->id($id)->one() ?? throw new NotFoundHttpException(Craft::t('booked', 'errors.locationNotFound')))
            : new Location();

        $location->title = $request->getBodyParam('title');
        $location->enabled = (bool)$request->getBodyParam('enabled', true);
        $location->timezone = $request->getBodyParam('timezone');

        $addressData = $request->getBodyParam('address');
        if (is_array($addressData)) {
            $location->countryCode = $addressData['countryCode'] ?? null;
            $location->administrativeArea = $addressData['administrativeArea'] ?? null;
            $location->locality = $addressData['locality'] ?? null;
            $location->postalCode = $addressData['postalCode'] ?? null;
            $location->addressLine1 = $addressData['addressLine1'] ?? null;
            $location->addressLine2 = $addressData['addressLine2'] ?? null;
        }

        if (!Craft::$app->elements->saveElement($location)) {
            Craft::$app->session->setError(Craft::t('booked', 'messages.locationNotSaved'));
            Craft::$app->urlManager->setRouteParams(['location' => $location]);
            return $this->redirectToPostedUrl();
        }

        Craft::$app->session->setNotice(Craft::t('booked', 'messages.locationSaved'));
        return $this->redirect('booked/locations');
    }
}
