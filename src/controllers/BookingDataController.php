<?php

namespace anvildev\booked\controllers;

use anvildev\booked\Booked;
use anvildev\booked\controllers\traits\BookingHelpersTrait;
use anvildev\booked\controllers\traits\JsonResponseTrait;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use anvildev\booked\elements\ServiceExtra;
use anvildev\booked\helpers\ElementQueryHelper;
use anvildev\booked\helpers\SiteHelper;
use Craft;
use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\web\Controller;
use craft\web\Response;
use craft\web\UploadedFile;

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
        'get-service-questions',
        'upload-question-file',
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

        /** @var Service[] $services */
        $services = ElementQueryHelper::forSite(
            Service::find()->enabled()->unique(),
            $site->id
        )->all();

        if (empty($services)) {
            return $this->jsonSuccess('', ['services' => []]);
        }

        $serviceIds = array_map(fn($s) => $s->id, $services);

        // Batch query: services with enabled extras.
        //
        // An extra's enabled state lives on `elements`, not on the extra's own
        // table — ServiceExtra is an element. Reading a non-existent
        // `booked_service_extras.enabled` column threw on every request, was
        // swallowed into a warning, and left every service reporting no add-ons.
        $servicesWithExtrasSet = [];
        try {
            $enabledExtraIds = ElementQueryHelper::forSite(
                ServiceExtra::find()->status('enabled')->unique(),
                $site->id
            )->ids();

            if (!empty($enabledExtraIds)) {
                $servicesWithExtrasSet = array_flip(
                    (new \craft\db\Query())
                        ->select(['serviceId'])
                        ->distinct()
                        ->from('{{%booked_service_extras_services}}')
                        ->where([
                            'serviceId' => $serviceIds,
                            'extraId' => $enabledExtraIds,
                        ])
                        ->column()
                );
            }
        } catch (\Throwable $e) {
            Craft::error("Could not query service extras: " . $e->getMessage(), __METHOD__);
        }

        // Batch query: service → location IDs
        $serviceLocationMap = Booked::getInstance()->serviceLocation->getLocationIdMapForServices($serviceIds);

        return $this->jsonSuccess('', [
            'services' => array_map(fn(Service $service) => [
                'id' => $service->id,
                'title' => $service->title,
                'description' => $service->description ?? '',
                'duration' => $service->duration,
                'durationType' => $service->durationType,
                'pricingMode' => $service->pricingMode,
                'isFlexibleDayService' => $service->isFlexibleDayService(),
                'minDays' => $service->minDays,
                'maxDays' => $service->maxDays,
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

    /**
     * The service's custom booking questions (#146), in the order its field
     * layout declares them. Only field types the wizard step actually knows
     * how to render are included — anything else is silently skipped rather
     * than sent as something the front end can't draw.
     */
    public function actionGetServiceQuestions(): Response
    {
        $this->requireAcceptsJson();

        if (!$this->checkRateLimit('booked_data_throttle', 60)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $serviceId = Craft::$app->request->getParam('serviceId');
        if (!$serviceId) {
            return $this->jsonError(Craft::t('booked', 'booking.serviceIdRequired'), null, ['questions' => []]);
        }

        try {
            $service = ElementQueryHelper::forAllSites(Service::find()->id((int)$serviceId)->status(null))->one();
            if (!$service) {
                return $this->jsonSuccess('', ['questions' => []]);
            }

            $questions = [];
            foreach ($service->getReservationFieldLayout()->getCustomFieldElements() as $layoutElement) {
                $field = $layoutElement->getField();
                $type = $this->wizardFieldType($field);
                if ($type === null) {
                    continue;
                }

                $question = [
                    'handle' => $field->handle,
                    'label' => $field->name,
                    'instructions' => $field->instructions ?: '',
                    'required' => $layoutElement->required,
                    'type' => $type,
                ];
                if ($field instanceof \craft\fields\BaseOptionsField) {
                    $question['options'] = array_map(
                        static fn(array $option): array => ['label' => $option['label'], 'value' => $option['value']],
                        $field->options,
                    );
                }

                $questions[] = $question;
            }

            return $this->jsonSuccess('', ['questions' => $questions]);
        } catch (\Throwable $e) {
            Craft::error("Failed to load service questions: " . $e->getMessage(), __METHOD__);
            return $this->jsonSuccess('', ['questions' => []]);
        }
    }

    /**
     * The wizard's question step only knows how to render these field
     * types; anything else (a custom third-party field type, Matrix, …)
     * returns null so the caller can skip it.
     */
    private function wizardFieldType(\craft\base\FieldInterface $field): ?string
    {
        return match (true) {
            $field instanceof \craft\fields\PlainText => $field->multiline ? 'textarea' : 'text',
            $field instanceof \craft\fields\Dropdown => 'dropdown',
            $field instanceof \craft\fields\RadioButtons => 'radio',
            $field instanceof \craft\fields\Checkboxes => 'checkboxes',
            $field instanceof \craft\fields\Lightswitch => 'lightswitch',
            $field instanceof \craft\fields\Assets => 'assets',
            default => null,
        };
    }

    /**
     * Pre-upload endpoint for an 'assets' question (#146): the wizard uploads
     * the file here first and gets back an asset id, which it then submits as
     * that question's answer in the normal form-encoded booking POST — the
     * booking submission itself never goes multipart.
     */
    public function actionUploadQuestionFile(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // Uploads are heavier than a plain data read, so they get a tighter
        // window than this controller's other anonymous endpoints.
        if (!$this->checkRateLimit('booked_upload_throttle', 10)) {
            return $this->jsonError(Craft::t('booked', 'booking.rateLimitIP'), statusCode: 429);
        }

        $serviceId = Craft::$app->request->getBodyParam('serviceId');
        $handle = Craft::$app->request->getBodyParam('handle');
        if (!$serviceId || !$handle) {
            return $this->jsonError(Craft::t('booked', 'booking.serviceIdRequired'));
        }

        $service = ElementQueryHelper::forAllSites(Service::find()->id((int)$serviceId)->status(null))->one();
        if (!$service) {
            return $this->jsonError(Craft::t('booked', 'booking.serviceIdRequired'));
        }

        // Only a field actually present in this service's reservation
        // questions can be uploaded to — this is what stops the endpoint
        // being used to drop files into an unrelated field/volume.
        $field = null;
        foreach ($service->getReservationFieldLayout()->getCustomFieldElements() as $layoutElement) {
            if ($layoutElement->getField()->handle === $handle) {
                $field = $layoutElement->getField();
                break;
            }
        }
        if (!$field instanceof AssetsField || !$field->allowUploads) {
            return $this->jsonError(Craft::t('booked', 'validation.invalidFieldHandle'));
        }

        $uploadedFile = UploadedFile::getInstanceByName('file');
        if (!$uploadedFile || $uploadedFile->getHasError()) {
            return $this->jsonError(Craft::t('booked', 'booking.uploadFailed'));
        }

        // Same extension allow-list Craft's own upload action enforces
        // (GeneralConfig::$allowedFileExtensions) — independent of whatever
        // this specific field's `allowedKinds` restricts below.
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $extension = strtolower(pathinfo($uploadedFile->name, PATHINFO_EXTENSION));
        $allowedExtensions = $generalConfig->allowedFileExtensions;
        if (is_array($allowedExtensions) && !in_array($extension, $allowedExtensions, true)) {
            return $this->jsonError(Craft::t('booked', 'booking.uploadExtensionNotAllowed'));
        }

        if ($uploadedFile->size > $generalConfig->maxUploadFileSize) {
            return $this->jsonError(Craft::t('booked', 'booking.uploadTooLarge'));
        }

        if ($field->restrictFiles && !empty($field->allowedKinds)) {
            $kind = AssetsHelper::getFileKindByExtension($uploadedFile->name);
            if (!in_array($kind, $field->allowedKinds, true)) {
                return $this->jsonError(Craft::t('booked', 'booking.uploadKindNotAllowed'));
            }
        }

        // Where the file lands is a plugin setting (Settings::$questionUploadVolumeUid),
        // not the Assets field's own upload-location config: the field's dynamic
        // path can depend on the element it's attached to, and no reservation
        // exists yet at upload time to resolve that against.
        $volumeUid = Booked::getInstance()->getSettings()->questionUploadVolumeUid;
        $volume = $volumeUid ? Craft::$app->getVolumes()->getVolumeByUid($volumeUid) : null;
        if (!$volume) {
            return $this->jsonError(Craft::t('booked', 'booking.uploadVolumeNotConfigured'));
        }

        // The field's own "Sources" setting still scopes which volume(s) it
        // will resolve a relation from. Saving into the configured volume
        // when the field doesn't list it as a source doesn't fail loudly —
        // Craft just can't resolve the relation back, so the answer silently
        // disappears. Catch that here rather than losing the customer's upload.
        if ($field->sources !== '*' && is_array($field->sources) && !in_array("volume:{$volume->uid}", $field->sources, true)) {
            return $this->jsonError(Craft::t('booked', 'booking.uploadVolumeNotInFieldSources'));
        }

        try {
            $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
            if (!$folder) {
                return $this->jsonError(Craft::t('booked', 'booking.uploadFailed'));
            }

            $tempPath = $uploadedFile->saveAsTempFile();
            if ($tempPath === false) {
                return $this->jsonError(Craft::t('booked', 'booking.uploadFailed'));
            }

            $asset = new Asset();
            $asset->tempFilePath = $tempPath;
            $asset->setFilename(AssetsHelper::prepareAssetName($uploadedFile->name));
            $asset->setMimeType(FileHelper::getMimeType($tempPath, checkExtension: false) ?? $uploadedFile->type);
            $asset->newFolderId = $folder->id;
            $asset->setVolumeId($folder->volumeId);
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (!Craft::$app->getElements()->saveElement($asset)) {
                return $this->jsonError(Craft::t('booked', 'booking.uploadFailed'), 'validation', $asset->getErrors());
            }

            return $this->jsonSuccess('', ['assetId' => $asset->id, 'filename' => $asset->filename]);
        } catch (\Throwable $e) {
            Craft::error("Failed to upload question file: " . $e->getMessage(), __METHOD__);
            return $this->jsonError(Craft::t('booked', 'booking.uploadFailed'));
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
