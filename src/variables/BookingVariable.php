<?php

namespace anvildev\booked\variables;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationQueryInterface;
use anvildev\booked\elements\BlackoutDate;
use anvildev\booked\elements\db\BlackoutDateQuery;
use anvildev\booked\elements\db\EmployeeQuery;
use anvildev\booked\elements\db\EventDateQuery;
use anvildev\booked\elements\db\LocationQuery;
use anvildev\booked\elements\db\ScheduleQuery;
use anvildev\booked\elements\db\ServiceExtraQuery;
use anvildev\booked\elements\db\ServiceQuery;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\EventDate;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Schedule;
use anvildev\booked\elements\Service;
use anvildev\booked\elements\ServiceExtra;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\helpers\ElementQueryHelper;
use anvildev\booked\models\Settings;
use anvildev\booked\services\EventDateService;
use anvildev\booked\services\ServiceExtraService;
use Craft;
use craft\helpers\Template;
use Twig\Markup;

class BookingVariable
{
    public function getForm(array $options = []): Markup
    {
        $viewMode = $options['viewMode'] ?? Booked::getInstance()->getSettings()->defaultViewMode ?? 'wizard';
        $title = $options['title'] ?? '';
        $text = $options['text'] ?? '';
        $entry = $options['entry'] ?? null;

        $entryId = null;
        if ($entry) {
            $entryId = is_object($entry) && isset($entry->id) ? $entry->id : (is_numeric($entry) ? (int)$entry : null);
        }

        $template = 'booked/frontend/' . $viewMode;
        if ($viewMode === 'legacy' || !Craft::$app->view->doesTemplateExist($template)) {
            return Template::raw(Craft::$app->view->renderTemplate('booked/booking-form', compact('title', 'text', 'entryId')));
        }

        return Template::raw(Craft::$app->view->renderTemplate($template, compact('title', 'text', 'entryId', 'options')));
    }

    public function getWizard(array $options = []): Markup
    {
        $settings = Booked::getInstance()->getSettings();

        return Template::raw(Craft::$app->view->renderTemplate('booked/frontend/wizard', [
            'options' => $options,
            'honeypotFieldName' => $settings->enableHoneypot ? $settings->honeypotFieldName : null,
            'captchaEnabled' => $settings->enableCaptcha ?? false,
            'captchaProvider' => $settings->captchaProvider ?? null,
            'captchaSiteKey' => $settings->enableCaptcha ? $this->getCaptchaSiteKey($settings) : null,
            'captchaAction' => $settings->recaptchaAction ?? 'booking',
        ]));
    }

    protected function getCaptchaSiteKey($settings): ?string
    {
        return match ($settings->captchaProvider) {
            'recaptcha' => $settings->recaptchaSiteKey ?? null,
            'hcaptcha' => $settings->hcaptchaSiteKey ?? null,
            'turnstile' => $settings->turnstileSiteKey ?? null,
            default => null,
        };
    }

    public function services(): ServiceQuery
    {
        return ElementQueryHelper::forCurrentSite(Service::find());
    }

    public function employees(): EmployeeQuery
    {
        return Employee::find()->siteId('*');
    }

    public function locations(): LocationQuery
    {
        return Location::find()->siteId('*');
    }

    public function reservations(): ReservationQueryInterface
    {
        return ReservationFactory::find();
    }

    public function myBookings(): ReservationQueryInterface
    {
        return ReservationFactory::find()->forCurrentUser();
    }

    public function myUpcomingBookings(int $limit = 10): array
    {
        return ReservationFactory::find()
            ->forCurrentUser()
            ->status(['confirmed', 'pending'])
            ->andWhere(['>=', 'booked_reservations.bookingDate', date('Y-m-d')])
            ->orderBy('booked_reservations.bookingDate ASC, booked_reservations.startTime ASC')
            ->limit($limit)
            ->all();
    }

    public function myPastBookings(int $limit = 10): array
    {
        return ReservationFactory::find()
            ->forCurrentUser()
            ->andWhere(['<', 'booked_reservations.bookingDate', date('Y-m-d')])
            ->orderBy('booked_reservations.bookingDate DESC, booked_reservations.startTime DESC')
            ->limit($limit)
            ->all();
    }

    public function myBookingCount(): int
    {
        return ReservationFactory::find()->forCurrentUser()->count();
    }

    public function getAvailableSlots(string|array $dateOrParams): array
    {
        $availabilityService = Booked::getInstance()->getAvailability();

        if (is_array($dateOrParams)) {
            return $availabilityService->getAvailableSlots(
                $dateOrParams['date'] ?? '',
                $dateOrParams['employeeId'] ?? null,
                $dateOrParams['locationId'] ?? null,
                $dateOrParams['serviceId'] ?? null,
                $dateOrParams['requestedQuantity'] ?? 1,
                $dateOrParams['userTimezone'] ?? null
            );
        }

        return $availabilityService->getAvailableSlots($dateOrParams);
    }

    public function getNextAvailableDate(): ?string
    {
        return Booked::getInstance()->getAvailability()->getNextAvailableDate();
    }

    public function getAvailabilityCalendar(string $startDate, string $endDate): array
    {
        return Booked::getInstance()->getAvailability()->getAvailabilitySummary($startDate, $endDate);
    }

    public function getUpcomingReservations(int $limit = 10): array
    {
        return Booked::getInstance()->getBooking()->getUpcomingReservations($limit);
    }

    public function getSettings(): Settings
    {
        return Booked::getInstance()->getSettings();
    }

    public function isSlotAvailable(
        string $date,
        string $startTime,
        string $endTime,
        ?int $employeeId = null,
        ?int $locationId = null,
        ?int $serviceId = null,
        int $requestedQuantity = 1,
    ): bool {
        return Booked::getInstance()->getAvailability()->isSlotAvailable(
            $date, $startTime, $endTime, $employeeId, $locationId, $serviceId, $requestedQuantity
        );
    }

    public function getEmployeeSchedules(int $employeeId): array
    {
        return Booked::getInstance()->getScheduleAssignment()->getSchedulesForEmployee($employeeId);
    }

    public function getServiceEmployees(int $serviceId): array
    {
        return Employee::find()->siteId('*')->serviceId($serviceId)->all();
    }

    public function getLocationEmployees(int $locationId): array
    {
        return Employee::find()->siteId('*')->locationId($locationId)->all();
    }

    public function isEmployeeAvailable(int $employeeId, string $date): bool
    {
        return Booked::getInstance()->getScheduleAssignment()->getActiveScheduleForDate($employeeId, $date) !== null;
    }

    public function isServiceBookable(Service|int $service): bool
    {
        if (is_int($service)) {
            $service = Service::find()->id($service)->one();
        }

        if (!$service || !$service->enabled) {
            return false;
        }

        return Employee::find()->siteId('*')->serviceId($service->id)->count() > 0
            || $service->hasAvailabilitySchedule();
    }

    public function getStats(): array
    {
        return Booked::getInstance()->getBooking()->getBookingStats();
    }

    public function getServiceExtra(): ServiceExtraService
    {
        return Booked::getInstance()->serviceExtra;
    }

    public function getEventDate(): EventDateService
    {
        return Booked::getInstance()->eventDate;
    }

    public function __get($name)
    {
        return match ($name) {
            'serviceExtra' => $this->getServiceExtra(),
            'eventDate' => $this->getEventDate(),
            default => null,
        };
    }

    public function schedules(): ScheduleQuery
    {
        return Schedule::find()->siteId('*');
    }

    public function eventDates(): EventDateQuery
    {
        return EventDate::find()->siteId('*')->unique();
    }

    public function serviceExtras(): ServiceExtraQuery
    {
        return ServiceExtra::find();
    }

    public function blackoutDates(): BlackoutDateQuery
    {
        return BlackoutDate::find()->siteId('*');
    }

    public function getExtrasForService(int $serviceId, bool $enabledOnly = true): array
    {
        return Booked::getInstance()->serviceExtra->getExtrasForService($serviceId, $enabledOnly);
    }

    public function getCurrency(): string
    {
        $settings = Booked::getInstance()->getSettings();

        if (!empty($settings->defaultCurrency) && $settings->defaultCurrency !== 'auto') {
            return $settings->defaultCurrency;
        }

        if (Craft::$app->plugins->isPluginEnabled('commerce')) {
            try {
                $paymentCurrency = \craft\commerce\Plugin::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrency();
                if ($paymentCurrency) {
                    return $paymentCurrency->iso;
                }
            } catch (\Exception) {
            }
        }

        return 'USD';
    }
}
