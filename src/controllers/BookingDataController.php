<?php

namespace anvildev\booked\controllers;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\BookingHelpersTrait;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use anvildev\booked\helpers\ElementQueryHelper;
use anvildev\booked\helpers\SiteHelper;
use Craft;
use craft\web\Controller;
use craft\web\Response;

/**
 * AJAX endpoints for booking-related reference data (services, extras, employees, Commerce settings).
 */
class BookingDataController extends Controller
{
    use JsonResponseTrait;
    use BookingHelpersTrait;

    protected array|bool|int $allowAnonymous = [
        'get-services',
        'get-service-extras',
        'get-employees',
        'get-commerce-settings',
    ];

    public $enableCsrfValidation = true;

    public function init(): void
    {
        parent::init();
        $this->closeSession();
    }

    public function actionGetServices(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_data_throttle', 60)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $site = SiteHelper::getSiteForRequest(Craft::$app->getRequest());

        $services = ElementQueryHelper::forSite(
            Service::find()->enabled()->unique(),
            $site->id
        )->all();

        if (empty($services)) {
            return $this->jsonSuccess('', ['services' => []]);
        }

        $serviceIds = array_map(fn($s) => $s->id, $services);

        // Batch query: services with enabled extras
        $servicesWithExtrasSet = [];
        try {
            $servicesWithExtrasSet = array_flip(
                (new \craft\db\Query())
                    ->select(['booked_service_extras_services.serviceId'])
                    ->distinct()
                    ->from('{{%booked_service_extras_services}}')
                    ->innerJoin(
                        '{{%booked_service_extras}}',
                        '[[booked_service_extras.id]] = [[booked_service_extras_services.extraId]]'
                    )
                    ->where(['booked_service_extras_services.serviceId' => $serviceIds])
                    ->andWhere(['booked_service_extras.enabled' => true])
                    ->column()
            );
        } catch (\Throwable $e) {
            Craft::warning("Could not query service extras: " . $e->getMessage(), __METHOD__);
        }

        // Batch query: service → location IDs
        $serviceLocationMap = Booked::getInstance()->serviceLocation->getLocationIdMapForServices($serviceIds);

        return $this->jsonSuccess('', [
            'services' => array_map(fn($service) => [
                'id' => $service->id,
                'title' => $service->title,
                'description' => $service->description ?? '',
                'duration' => $service->duration,
                'price' => $service->price,
                'bufferBefore' => $service->bufferBefore,
                'bufferAfter' => $service->bufferAfter,
                'virtualMeetingProvider' => $service->virtualMeetingProvider,
                'hasExtras' => isset($servicesWithExtrasSet[$service->id]),
                'locationIds' => $serviceLocationMap[$service->id] ?? [],
            ], $services),
        ]);
    }

    public function actionGetServiceExtras(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_data_throttle', 60)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $serviceId = Craft::$app->request->getParam('serviceId');
        if (!$serviceId) {
            return $this->jsonError(Craft::t('booked', 'booking.serviceIdRequired'), null, ['extras' => []]);
        }

        try {
            $extras = Booked::getInstance()->serviceExtra->getExtrasForService((int)$serviceId, true);
            return $this->jsonSuccess('', [
                'extras' => array_map(fn($extra) => [
                    'id' => $extra->id,
                    'title' => $extra->title,
                    'description' => $extra->description ?? '',
                    'price' => $extra->price,
                    'duration' => $extra->duration ?? 0,
                    'maxQuantity' => $extra->maxQuantity ?? 1,
                    'isRequired' => $extra->isRequired ?? false,
                ], $extras),
            ]);
        } catch (\Throwable $e) {
            Craft::error("Failed to load service extras: " . $e->getMessage(), __METHOD__);
            return $this->jsonSuccess('', ['extras' => []]);
        }
    }

    public function actionGetEmployees(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_data_throttle', 60)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $locationId = Craft::$app->request->getParam('locationId');
        $serviceId = Craft::$app->request->getParam('serviceId');

        // Check if service has its own availability schedule (employee-less booking)
        $serviceHasSchedule = false;
        if ($serviceId) {
            $service = ElementQueryHelper::forAllSites(
                Service::find()->id((int)$serviceId)->status(null)
            )->one();
            $serviceHasSchedule = $service?->hasAvailabilitySchedule() ?? false;
        }

        $query = Employee::find()->siteId('*')->enabled(true);
        if ($locationId) {
            $query->locationId((int)$locationId);
        }
        if ($serviceId) {
            $query->serviceId((int)$serviceId);
        }
        $employees = $query->all();

        // Extract unique locations from matching employees + direct service-location assignments
        $locationIds = array_unique(array_filter(array_map(fn($e) => $e->locationId, $employees)));

        // Merge in direct service-location assignments (for employee-less services)
        if ($serviceId) {
            $directLocationIds = Booked::getInstance()->serviceLocation
                ->getLocationIdMapForServices([(int) $serviceId])[(int) $serviceId] ?? [];
            $locationIds = array_unique(array_merge($locationIds, $directLocationIds));
        }

        $locations = [];
        if (!empty($locationIds)) {
            foreach (Location::find()->siteId('*')->id($locationIds)->all() as $location) {
                $locations[] = [
                    'id' => $location->id,
                    'name' => $location->title,
                    'address' => implode(', ', array_filter([
                        $location->addressLine1, $location->addressLine2,
                        $location->locality, $location->administrativeArea,
                        $location->postalCode, $location->countryCode,
                    ])),
                    'timezone' => $location->timezone,
                ];
            }
        }

        // Check if any employee has schedules (single batch query)
        $hasSchedules = $serviceHasSchedule;
        if (!$hasSchedules && !empty($employees)) {
            $hasSchedules = !empty(
                (new \craft\db\Query())
                    ->select(['employeeId'])
                    ->distinct()
                    ->from('{{%booked_employee_schedule_assignments}}')
                    ->where(['employeeId' => array_map(fn($e) => $e->id, $employees)])
                    ->column()
            );
        }

        return $this->jsonSuccess('', [
            'employees' => array_map(fn($e) => ['id' => $e->id, 'name' => $e->title], $employees),
            'employeeRequired' => count($employees) === 1 && !$serviceHasSchedule,
            'hasSchedules' => $hasSchedules,
            'serviceHasSchedule' => $serviceHasSchedule,
            'locations' => $locations,
        ]);
    }

    public function actionGetCommerceSettings(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_data_throttle', 60)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $settings = Booked::getInstance()->getSettings();
        $commerceEnabled = $settings->canUseCommerce();
        $currency = Booked::getInstance()->reports->getCurrency();

        return $this->jsonSuccess('', [
            'commerceEnabled' => $commerceEnabled,
            'currency' => $currency,
            'currencySymbol' => $currency,
            'cartUrl' => \craft\helpers\UrlHelper::siteUrl($settings->commerceCartUrl),
            'checkoutUrl' => \craft\helpers\UrlHelper::siteUrl($settings->commerceCheckoutUrl),
        ]);
    }
}
