<?php

/**
 * Booked plugin for Craft CMS 5.x
 *
 * A comprehensive booking system for Craft CMS
 *
 * @link      https://anvildev.xyz
 * @copyright Copyright (c) 2025
 */

namespace anvildev\booked;

use anvildev\booked\elements\Reservation;
use anvildev\booked\gql\interfaces\elements\BlackoutDateInterface;
use anvildev\booked\gql\interfaces\elements\EmployeeInterface;
use anvildev\booked\gql\interfaces\elements\EventDateInterface;
use anvildev\booked\gql\interfaces\elements\LocationInterface;
use anvildev\booked\gql\interfaces\elements\ReservationInterface;
use anvildev\booked\gql\interfaces\elements\ScheduleInterface;
use anvildev\booked\gql\interfaces\elements\ServiceInterface;
use anvildev\booked\gql\mutations\QuantityMutations;
use anvildev\booked\gql\mutations\ReservationMutations;
use anvildev\booked\gql\mutations\WaitlistMutations;
use anvildev\booked\gql\queries\BlackoutDateQuery;
use anvildev\booked\gql\queries\EmployeeQuery;
use anvildev\booked\gql\queries\EventDateQuery;
use anvildev\booked\gql\queries\LocationQuery;
use anvildev\booked\gql\queries\ReportSummaryQuery;
use anvildev\booked\gql\queries\ReservationQuery;
use anvildev\booked\gql\queries\ScheduleQuery;
use anvildev\booked\gql\queries\ServiceExtrasQuery;
use anvildev\booked\gql\queries\ServiceQuery;
use Craft;
use craft\base\Plugin;
use craft\commerce\elements\Order;
use craft\events\RegisterGqlMutationsEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Fields;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use yii\base\Event;

/**
 * Booked plugin for Craft CMS - a comprehensive booking and appointment system.
 *
 * Provides element-based services, employees, locations, schedules, and reservations
 * with subtractive availability, queue-based notifications, calendar sync,
 * and optional Craft Commerce integration.
 *
 * @method static Booked|null getInstance()
 *
 * @property-read \anvildev\booked\services\AvailabilityService $availability
 * @property-read \anvildev\booked\services\BookingService $booking
 * @property-read \anvildev\booked\services\BookingSecurityService $bookingSecurity
 * @property-read \anvildev\booked\services\BookingNotificationService $bookingNotification
 * @property-read \anvildev\booked\services\BookingValidationService $bookingValidation
 * @property-read \anvildev\booked\services\BlackoutDateService $blackoutDate
 * @property-read \anvildev\booked\services\EventDateService $eventDate
 * @property-read \anvildev\booked\services\SoftLockService $softLock
 * @property-read \anvildev\booked\services\CalendarSyncService $calendarSync
 * @property-read \anvildev\booked\services\VirtualMeetingService $virtualMeeting
 * @property-read \anvildev\booked\services\ReminderService $reminder
 * @property-read \anvildev\booked\services\EmailRenderService $emailRender
 * @property-read \anvildev\booked\services\TwilioSmsService $twilioSms
 * @property-read \anvildev\booked\services\WebhookService $webhook
 * @property-read \anvildev\booked\services\WaitlistService $waitlist
 * @property-read \anvildev\booked\services\CommerceService $commerce
 * @property-read \anvildev\booked\services\ServiceExtraService $serviceExtra
 * @property-read \anvildev\booked\services\ServiceLocationService $serviceLocation
 * @property-read \anvildev\booked\services\CaptchaService $captcha
 * @property-read \anvildev\booked\services\ScheduleAssignmentService $scheduleAssignment
 * @property-read \anvildev\booked\services\PermissionService $permission
 * @property-read \anvildev\booked\services\AuditService $audit
 * @property-read \anvildev\booked\services\MaintenanceService $maintenance
 * @property-read \anvildev\booked\services\TimezoneService $timezone
 * @property-read \anvildev\booked\services\TimeWindowService $timeWindow
 * @property-read \anvildev\booked\services\SlotGeneratorService $slotGenerator
 * @property-read \anvildev\booked\services\ScheduleResolverService $scheduleResolver
 * @property-read \anvildev\booked\services\CapacityService $capacity
 * @property-read \anvildev\booked\services\ReportsService $reports
 * @property-read \anvildev\booked\services\DashboardService $dashboard
 * @property-read \anvildev\booked\services\RefundService $refund
 * @property-read \anvildev\booked\services\RefundPolicyService $refundPolicy
 * @property-read \anvildev\booked\services\MutexFactory $mutex
 */
class Booked extends Plugin
{
    /**
     * Edition constant
     */
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '1.2.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_PRO,
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@booked', $this->getBasePath());

        $this->controllerNamespace = Craft::$app instanceof \craft\console\Application
            ? 'anvildev\\booked\\console\\controllers'
            : 'anvildev\\booked\\controllers';

        $this->registerServices();
        $this->registerCpRoutes();
        $this->registerSiteRoutes();
        $this->registerCommerceListeners();
        $this->registerQuantityChangeListeners();
        $this->registerCalendarSyncListeners();
        $this->registerVirtualMeetingListeners();
        $this->registerWebhookListeners();
        $this->registerTemplateRoots();
        $this->registerElementTypes();
        $this->registerPermissions();
        $this->registerTemplateVariable();
        $this->registerGraphQl();
        $this->registerFieldTypes();
        $this->registerWidgetTypes();
    }

    public static function displayName(): string
    {
        return Craft::t('booked', 'plugin.name');
    }

    public static function description(): string
    {
        return Craft::t('booked', 'plugin.description');
    }

    public static function getInstance(): ?self
    {
        return parent::getInstance();
    }

    private function registerTemplateRoots(): void
    {
        $templateRoot = $this->getBasePath() . DIRECTORY_SEPARATOR . 'templates';
        $handler = static fn(RegisterTemplateRootsEvent $event) => $event->roots['booked'] = $templateRoot;

        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, $handler);
        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, $handler);
    }

    private function registerServices(): void
    {
        $this->setComponents([
            'availability' => \anvildev\booked\services\AvailabilityService::class,
            'timeWindow' => \anvildev\booked\services\TimeWindowService::class,
            'slotGenerator' => \anvildev\booked\services\SlotGeneratorService::class,
            'scheduleResolver' => \anvildev\booked\services\ScheduleResolverService::class,
            'capacity' => \anvildev\booked\services\CapacityService::class,
            'booking' => \anvildev\booked\services\BookingService::class,
            'bookingSecurity' => \anvildev\booked\services\BookingSecurityService::class,
            'bookingNotification' => \anvildev\booked\services\BookingNotificationService::class,
            'bookingValidation' => \anvildev\booked\services\BookingValidationService::class,
            'blackoutDate' => \anvildev\booked\services\BlackoutDateService::class,
            'eventDate' => \anvildev\booked\services\EventDateService::class,
            'softLock' => \anvildev\booked\services\SoftLockService::class,
            'commerce' => \anvildev\booked\services\CommerceService::class,
            'calendarSync' => \anvildev\booked\services\CalendarSyncService::class,
            'virtualMeeting' => \anvildev\booked\services\VirtualMeetingService::class,
            'reminder' => \anvildev\booked\services\ReminderService::class,
            'emailRender' => \anvildev\booked\services\EmailRenderService::class,
            'twilioSms' => \anvildev\booked\services\TwilioSmsService::class,
            'webhook' => \anvildev\booked\services\WebhookService::class,
            'waitlist' => \anvildev\booked\services\WaitlistService::class,
            'maintenance' => \anvildev\booked\services\MaintenanceService::class,
            'timezone' => \anvildev\booked\services\TimezoneService::class,
            'serviceExtra' => \anvildev\booked\services\ServiceExtraService::class,
            'serviceLocation' => \anvildev\booked\services\ServiceLocationService::class,
            'captcha' => \anvildev\booked\services\CaptchaService::class,
            'scheduleAssignment' => \anvildev\booked\services\ScheduleAssignmentService::class,
            'audit' => \anvildev\booked\services\AuditService::class,
            'permission' => \anvildev\booked\services\PermissionService::class,
            'reports' => \anvildev\booked\services\ReportsService::class,
            'dashboard' => \anvildev\booked\services\DashboardService::class,
            'refund' => \anvildev\booked\services\RefundService::class,
            'refundPolicy' => \anvildev\booked\services\RefundPolicyService::class,
            'mutex' => \anvildev\booked\services\MutexFactory::class,
        ]);
    }

    public function getReminder(): \anvildev\booked\services\ReminderService
    {
        return $this->get('reminder');
    }

    public function getAvailability(): \anvildev\booked\services\AvailabilityService
    {
        return $this->get('availability');
    }

    public function getBooking(): \anvildev\booked\services\BookingService
    {
        return $this->get('booking');
    }

    public function getBlackoutDate(): \anvildev\booked\services\BlackoutDateService
    {
        return $this->get('blackoutDate');
    }

    public function getEventDate(): \anvildev\booked\services\EventDateService
    {
        return $this->get('eventDate');
    }

    public function getSoftLock(): \anvildev\booked\services\SoftLockService
    {
        return $this->get('softLock');
    }

    public function getCalendarSync(): \anvildev\booked\services\CalendarSyncService
    {
        return $this->get('calendarSync');
    }

    public function getVirtualMeeting(): \anvildev\booked\services\VirtualMeetingService
    {
        return $this->get('virtualMeeting');
    }

    public function getEmailRender(): \anvildev\booked\services\EmailRenderService
    {
        return $this->get('emailRender');
    }

    public function getTimeWindow(): \anvildev\booked\services\TimeWindowService
    {
        return $this->get('timeWindow');
    }

    public function getSlotGenerator(): \anvildev\booked\services\SlotGeneratorService
    {
        return $this->get('slotGenerator');
    }

    public function getScheduleResolver(): \anvildev\booked\services\ScheduleResolverService
    {
        return $this->get('scheduleResolver');
    }

    public function getCapacity(): \anvildev\booked\services\CapacityService
    {
        return $this->get('capacity');
    }

    public function getBookingSecurity(): \anvildev\booked\services\BookingSecurityService
    {
        return $this->get('bookingSecurity');
    }

    public function getBookingNotification(): \anvildev\booked\services\BookingNotificationService
    {
        return $this->get('bookingNotification');
    }

    public function getBookingValidation(): \anvildev\booked\services\BookingValidationService
    {
        return $this->get('bookingValidation');
    }

    public function getTimezone(): \anvildev\booked\services\TimezoneService
    {
        return $this->get('timezone');
    }

    public function isCommerceEnabled(): bool
    {
        return Craft::$app->plugins->isPluginEnabled('commerce') && $this->getSettings()->commerceEnabled;
    }

    private function registerCommerceListeners(): void
    {
        if (!class_exists(Order::class)) {
            return;
        }

        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            function(Event $event) {
                if (!$this->isCommerceEnabled()) {
                    return;
                }

                /** @var Order $order */
                $order = $event->sender;
                $reservation = $this->commerce->getReservationByOrderId($order->id);
                if ($reservation) {
                    $reservation->status = \anvildev\booked\records\ReservationRecord::STATUS_CONFIRMED;
                    Craft::$app->elements->saveElement($reservation);
                    Craft::info("Reservation #{$reservation->id} confirmed via Order #{$order->id}", __METHOD__);

                    $this->getWebhook()->dispatch(
                        \anvildev\booked\services\WebhookService::EVENT_BOOKING_CREATED,
                        $reservation
                    );

                    try {
                        $ns = $this->bookingNotification;
                        $ns->queueBookingEmail($reservation->id, 'confirmation', null, 512);
                        $ns->queueOwnerNotification($reservation->id, 512);
                        $ns->queueCalendarSync($reservation->id);
                        $ns->queueSmsConfirmation($reservation);
                    } catch (\Throwable $e) {
                        Craft::error(
                            "Failed to queue notifications for reservation #{$reservation->id} " .
                            "(Order #{$order->id}): " . $e->getMessage(),
                            __METHOD__
                        );
                    }
                }
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_AFTER_REMOVE_LINE_ITEM,
            function(\craft\commerce\events\LineItemEvent $event) {
                if (!$this->isCommerceEnabled()) {
                    return;
                }

                $lineItem = $event->lineItem;
                if (!$lineItem->purchasableId) {
                    return;
                }

                $reservation = Reservation::findOne($lineItem->purchasableId);
                if (!$reservation) {
                    return;
                }

                if ($reservation->status !== \anvildev\booked\records\ReservationRecord::STATUS_PENDING) {
                    return;
                }

                $reservation->status = \anvildev\booked\records\ReservationRecord::STATUS_CANCELLED;
                Craft::$app->elements->saveElement($reservation);
                Craft::info(
                    "Reservation #{$reservation->id} cancelled — line item removed from cart",
                    __METHOD__
                );
            }
        );

        // Auto-refund on full cancellation
        Event::on(
            \anvildev\booked\services\BookingService::class,
            \anvildev\booked\services\BookingService::EVENT_AFTER_BOOKING_CANCEL,
            function(\anvildev\booked\events\AfterBookingCancelEvent $event) {
                if (!$this->isCommerceEnabled()) {
                    return;
                }

                if ($event->shouldRefund && $this->getSettings()->enableAutoRefund) {
                    $this->refund->processFullRefund($event->reservation);
                }
            }
        );

        // Auto-refund on partial cancellation (quantity reduction)
        Event::on(
            \anvildev\booked\services\BookingService::class,
            \anvildev\booked\services\BookingService::EVENT_AFTER_QUANTITY_CHANGE,
            function(\anvildev\booked\events\AfterQuantityChangeEvent $event) {
                if (!$this->isCommerceEnabled()) {
                    return;
                }

                if ($this->getSettings()->enableAutoRefund) {
                    $this->refund->processPartialRefund(
                        $event->reservation,
                        $event->reduceBy,
                        $event->previousQuantity,
                        $event->originalTotalPrice
                    );
                }

                // Sync Commerce line item quantity and price
                Booked::getInstance()->commerce->syncLineItemQuantity($event->reservation);
            }
        );
    }

    /**
     * Register quantity change integrations that should fire regardless of Commerce status.
     */
    private function registerQuantityChangeListeners(): void
    {
        Event::on(
            \anvildev\booked\services\BookingService::class,
            \anvildev\booked\services\BookingService::EVENT_AFTER_QUANTITY_CHANGE,
            function(\anvildev\booked\events\AfterQuantityChangeEvent $event) {
                $reservationId = $event->reservation->getId();

                try {
                    $webhookEventType = $event->increaseBy > 0
                        ? \anvildev\booked\services\WebhookService::EVENT_BOOKING_QUANTITY_INCREASED
                        : \anvildev\booked\services\WebhookService::EVENT_BOOKING_QUANTITY_REDUCED;

                    $this->getWebhook()->dispatch($webhookEventType, $event->reservation, [
                        'quantityChange' => [
                            'previousQuantity' => $event->previousQuantity,
                            'newQuantity' => $event->newQuantity,
                            'reduceBy' => $event->reduceBy,
                            'increaseBy' => $event->increaseBy,
                            'reason' => $event->reason,
                        ],
                    ]);
                } catch (\Throwable $e) {
                    Craft::error("Failed to dispatch quantity change webhook for reservation #{$reservationId}: " . $e->getMessage(), __METHOD__);
                }

                try {
                    $this->bookingNotification->queueQuantityChangedEmail(
                        $reservationId,
                        $event->previousQuantity,
                        $event->newQuantity,
                    );
                } catch (\Throwable $e) {
                    Craft::error("Failed to queue quantity change email for reservation #{$reservationId}: " . $e->getMessage(), __METHOD__);
                }

                try {
                    $this->calendarSync->queueCalendarUpdate($reservationId);
                } catch (\Throwable $e) {
                    Craft::error("Failed to queue calendar update for reservation #{$reservationId}: " . $e->getMessage(), __METHOD__);
                }
            }
        );
    }

    /**
     * Register element types
     *
     * Reservation is only registered as an element when Commerce is enabled.
     * When Commerce is disabled, ReservationModel (ActiveRecord) is used instead
     * for better long-term performance with high-volume transactional data.
     *
     * @see \anvildev\booked\factories\ReservationFactory for the abstraction that handles both modes
     */
    private function registerElementTypes(): void
    {
        Event::on(
            \craft\services\Elements::class,
            \craft\services\Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(\craft\events\RegisterComponentTypesEvent $event) {
                $event->types[] = \anvildev\booked\elements\Service::class;
                $event->types[] = \anvildev\booked\elements\ServiceExtra::class;
                $event->types[] = \anvildev\booked\elements\Employee::class;
                $event->types[] = \anvildev\booked\elements\Location::class;
                $event->types[] = \anvildev\booked\elements\EventDate::class;
                $event->types[] = \anvildev\booked\elements\Schedule::class;

                // Required for PurchasableInterface support in Craft Commerce
                if ($this->isCommerceEnabled()) {
                    $event->types[] = \anvildev\booked\elements\Reservation::class;
                }
            }
        );
    }

    private function registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(\craft\events\RegisterComponentTypesEvent $event) {
                $event->types[] = \anvildev\booked\fields\BookedServices::class;
                $event->types[] = \anvildev\booked\fields\BookedEventDates::class;
            }
        );
    }

    private function registerWidgetTypes(): void
    {
        Event::on(
            \craft\services\Dashboard::class,
            \craft\services\Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(\craft\events\RegisterComponentTypesEvent $event) {
                $event->types[] = \anvildev\booked\widgets\BookedWidget::class;
            }
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            \craft\web\UrlManager::class,
            \craft\web\UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(\craft\events\RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    // Default redirect to dashboard
                    'booked' => 'booked/cp/dashboard/index',

                    // Dashboard
                    'booked/dashboard' => 'booked/cp/dashboard/index',

                    // Calendar Views
                    'booked/calendar-view/month' => 'booked/cp/calendar-view/month',
                    'booked/calendar-view/week' => 'booked/cp/calendar-view/week',
                    'booked/calendar-view/day' => 'booked/cp/calendar-view/day',
                    'booked/calendar-view/reschedule' => 'booked/cp/calendar-view/reschedule',

                    // Reports
                    'booked/reports' => 'booked/cp/reports/index',
                    'booked/reports/revenue' => 'booked/cp/reports/revenue',
                    'booked/reports/by-service' => 'booked/cp/reports/by-service',
                    'booked/reports/by-employee' => 'booked/cp/reports/by-employee',
                    'booked/reports/cancellations' => 'booked/cp/reports/cancellations',
                    'booked/reports/peak-hours' => 'booked/cp/reports/peak-hours',
                    'booked/reports/utilization' => 'booked/cp/reports/utilization',
                    'booked/reports/by-location' => 'booked/cp/reports/by-location',
                    'booked/reports/day-of-week' => 'booked/cp/reports/day-of-week',
                    'booked/reports/waitlist' => 'booked/cp/reports/waitlist',
                    'booked/reports/event-attendance' => 'booked/cp/reports/event-attendance',

                    // Phase 1.3 - Core element management
                    'booked/services' => 'booked/cp/services/index',
                    'booked/services/new' => 'booked/cp/services/edit',
                    'booked/services/<id:\d+>' => 'booked/cp/services/edit',

                    'booked/employees' => 'booked/cp/employees/index',
                    'booked/employees/new' => 'booked/cp/employees/edit',
                    'booked/employees/<id:\d+>' => 'booked/cp/employees/edit',

                    'booked/schedules' => 'booked/cp/schedules/index',
                    'booked/schedules/new' => 'booked/cp/schedules/edit',
                    'booked/schedules/<id:\d+>' => 'booked/cp/schedules/edit',

                    'booked/locations' => 'booked/cp/locations/index',
                    'booked/locations/new' => 'booked/cp/locations/edit',
                    'booked/locations/<id:\d+>' => 'booked/cp/locations/edit',

                    'booked/blackout-dates' => 'booked/cp/blackout-dates/index',
                    'booked/blackout-dates/new' => 'booked/cp/blackout-dates/new',
                    'booked/blackout-dates/<id:\d+>' => 'booked/cp/blackout-dates/edit',

                    // Event dates
                    'booked/cp/event-dates' => 'booked/cp/event-dates/index',
                    'booked/cp/event-dates/new' => 'booked/cp/event-dates/new',
                    'booked/cp/event-dates/<id:\d+>' => 'booked/cp/event-dates/edit',


                    // Service Extras (Phase 5.4)
                    'booked/service-extras' => 'booked/cp/service-extra/index',
                    'booked/service-extras/new' => 'booked/cp/service-extra/new',
                    'booked/service-extras/<id:\d+>' => 'booked/cp/service-extra/edit',

                    // Bookings
                    'booked/bookings' => 'booked/cp/bookings/index',
                    'booked/bookings/new' => 'booked/cp/bookings/edit',
                    'booked/bookings/<id:\d+>' => 'booked/cp/bookings/edit',
                    'booked/bookings/<id:\d+>/view' => 'booked/cp/bookings/view',
                    'booked/bookings/export' => 'booked/cp/bookings/export',

                    // Settings - with sidebar navigation
                    'booked/settings' => 'booked/cp/settings/booking',
                    'booked/settings/booking' => 'booked/cp/settings/booking',
                    'booked/settings/waitlist' => 'booked/cp/settings/waitlist',
                    'booked/settings/security' => 'booked/cp/settings/security',
                    'booked/settings/notifications' => 'booked/cp/settings/notifications',
                    'booked/settings/sms' => 'booked/cp/settings/sms',
                    'booked/settings/calendar' => 'booked/cp/settings/calendar',
                    'booked/settings/meetings' => 'booked/cp/settings/meetings',
                    'booked/settings/commerce' => 'booked/cp/settings/commerce',
                    'booked/settings/webhooks' => 'booked/cp/settings/webhooks',

                    // Waitlist Management
                    'booked/waitlist' => 'booked/cp/waitlist/index',
                    'booked/waitlist/<id:\d+>' => 'booked/cp/waitlist/edit',

                    // Webhooks Management
                    'booked/webhooks' => 'booked/cp/webhooks/index',
                    'booked/webhooks/new' => 'booked/cp/webhooks/edit',
                    'booked/webhooks/<id:\d+>' => 'booked/cp/webhooks/edit',
                    'booked/webhooks/<id:\d+>/logs' => 'booked/cp/webhooks/logs',
                    'booked/webhooks/<id:\d+>/test' => 'booked/cp/webhooks/test',
                    'booked/webhooks/<id:\d+>/delete' => 'booked/cp/webhooks/delete',

                    // Calendar Sync (OAuth)
                    'booked/calendar/connect' => 'booked/cp/calendar/connect',
                    'booked/calendar/callback' => 'booked/cp/calendar/callback',
                ]);
            }
        );
    }

    private function registerSiteRoutes(): void
    {
        Event::on(
            \craft\web\UrlManager::class,
            \craft\web\UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(\craft\events\RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    'booking/manage/<token:[^\/]+>' => 'booked/booking-management/manage-booking',
                    'booking/cancel/<token:[^\/]+>' => 'booked/booking-management/cancel-booking-by-token',
                    'booking/ics/<token:[^\/]+>' => 'booked/booking-management/download-ics',
                    'account/bookings' => 'booked/booking-management/my-bookings',
                    'employee/schedule' => 'booked/employee-schedule/index',
                    'employee/schedule/<employeeId:\d+>' => 'booked/employee-schedule/index',
                    // Frontend calendar OAuth routes
                    'booked/calendar/connect' => 'booked/calendar-connect/connect',
                    'booked/calendar/frontend-callback' => 'booked/calendar-connect/callback',
                    'booked/calendar/success' => 'booked/calendar-connect/success',
                    'booked/calendar/error' => 'booked/calendar-connect/error',
                    // Customer account portal routes
                    'booked/account' => 'booked/account/index',
                    'booked/account/bookings' => 'booked/account/bookings',
                    'booked/account/upcoming' => 'booked/account/upcoming',
                    'booked/account/past' => 'booked/account/past',
                    'booked/account/<id:\d+>' => 'booked/account/view',
                ]);
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('booked', 'permissions.heading'),
                    'permissions' => [
                        'booked-accessPlugin' => [
                            'label' => Craft::t('booked', 'permissions.accessPlugin'),
                            'nested' => [
                                'booked-viewBookings' => [
                                    'label' => Craft::t('booked', 'permissions.viewBookings'),
                                    'nested' => [
                                        'booked-manageBookings' => [
                                            'label' => Craft::t('booked', 'permissions.manageBookings'),
                                        ],
                                    ],
                                ],
                                'booked-viewCalendar' => [
                                    'label' => Craft::t('booked', 'permissions.viewCalendar'),
                                ],
                                'booked-viewReports' => [
                                    'label' => Craft::t('booked', 'permissions.viewReports'),
                                ],
                                'booked-manageServices' => [
                                    'label' => Craft::t('booked', 'permissions.manageServices'),
                                ],
                                'booked-manageEmployees' => [
                                    'label' => Craft::t('booked', 'permissions.manageEmployees'),
                                ],
                                'booked-manageLocations' => [
                                    'label' => Craft::t('booked', 'permissions.manageLocations'),
                                ],
                                'booked-manageEvents' => [
                                    'label' => Craft::t('booked', 'permissions.manageEvents'),
                                ],
                                'booked-manageBlackoutDates' => [
                                    'label' => Craft::t('booked', 'permissions.manageBlackoutDates'),
                                ],
                                'booked-manageWaitlist' => [
                                    'label' => Craft::t('booked', 'permissions.manageWaitlist'),
                                ],
                                'booked-manageSettings' => [
                                    'label' => Craft::t('booked', 'permissions.manageSettings'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['icon'] = '@booked/nav-icon.svg';
        $item['url'] = 'booked';

        /** @var \craft\elements\User|null $user */
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            $item['subnav'] = [];
            return $item;
        }

        $isAdmin = $user->admin;
        $can = static fn(string ...$perms): bool => $isAdmin || array_any($perms, fn($perm) => $user->can($perm));

        // [key, translationKey, url, ...permissions]
        $navDefs = [
            ['calendar', 'nav.calendar', 'booked/calendar-view/month', 'booked-viewCalendar', 'booked-viewBookings'],
            ['bookings', 'nav.bookings', 'booked/bookings', 'booked-viewBookings'],
            ['services', 'nav.services', 'booked/services', 'booked-manageServices'],
            ['service-extras', 'nav.serviceExtras', 'booked/service-extras', 'booked-manageServices'],
            ['employees', 'nav.employees', 'booked/employees', 'booked-manageEmployees'],
            ['schedules', 'nav.schedules', 'booked/schedules', 'booked-manageEmployees'],
            ['locations', 'nav.locations', 'booked/locations', 'booked-manageLocations'],
            ['blackout-dates', 'nav.blackoutDates', 'booked/blackout-dates', 'booked-manageBlackoutDates'],
            ['event-dates', 'nav.eventDates', 'booked/cp/event-dates', 'booked-manageEvents'],
            ['waitlist', 'nav.waitlist', 'booked/waitlist', 'booked-manageWaitlist'],
            ['reports', 'nav.reports', 'booked/reports', 'booked-viewReports'],
            ['webhooks', 'nav.webhooks', 'booked/webhooks', 'booked-manageSettings'],
            ['settings', 'nav.settings', 'booked/settings', 'booked-manageSettings'],
        ];

        $subnav = [];
        foreach ($navDefs as $def) {
            if ($can(...array_slice($def, 3))) {
                $subnav[$def[0]] = ['label' => Craft::t('booked', $def[1]), 'url' => $def[2]];
            }
        }

        $item['subnav'] = $subnav;
        return $item;
    }

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new \anvildev\booked\models\Settings();
    }

    public function getSettings(): \anvildev\booked\models\Settings
    {
        return \anvildev\booked\models\Settings::loadSettings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            \craft\helpers\UrlHelper::cpUrl('booked/settings')
        );
    }

    private function registerTemplateVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('booked', \anvildev\booked\variables\BookingVariable::class);
            }
        );
    }

    private function registerGraphQl(): void
    {
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            function(RegisterGqlTypesEvent $event) {
                $event->types[] = ServiceInterface::class;
                $event->types[] = ReservationInterface::class;
                $event->types[] = EmployeeInterface::class;
                $event->types[] = ScheduleInterface::class;
                $event->types[] = LocationInterface::class;
                $event->types[] = EventDateInterface::class;
                $event->types[] = BlackoutDateInterface::class;
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            function(RegisterGqlQueriesEvent $event) {
                $event->queries = array_merge(
                    $event->queries,
                    ServiceQuery::getQueries(),
                    ReservationQuery::getQueries(),
                    EmployeeQuery::getQueries(),
                    ServiceExtrasQuery::getQueries(),
                    ScheduleQuery::getQueries(),
                    LocationQuery::getQueries(),
                    EventDateQuery::getQueries(),
                    BlackoutDateQuery::getQueries(),
                    ReportSummaryQuery::getQueries()
                );
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_MUTATIONS,
            function(RegisterGqlMutationsEvent $event) {
                $event->mutations = array_merge(
                    $event->mutations,
                    ReservationMutations::getMutations(),
                    QuantityMutations::getMutations(),
                    WaitlistMutations::getMutations()
                );
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            function(RegisterGqlSchemaComponentsEvent $event) {
                $event->queries['Booked'] = [
                    'bookedServices:read' => ['label' => Craft::t('booked', 'graphql.queryServices')],
                    'bookedReservations:read' => ['label' => Craft::t('booked', 'graphql.queryReservations')],
                    'bookedEmployees:read' => ['label' => Craft::t('booked', 'graphql.queryEmployees')],
                    'bookedServiceExtras:read' => ['label' => Craft::t('booked', 'graphql.queryServiceExtras')],
                    'bookedSchedules:read' => ['label' => Craft::t('booked', 'graphql.querySchedules')],
                    'bookedLocations:read' => ['label' => Craft::t('booked', 'graphql.queryLocations')],
                    'bookedEventDates:read' => ['label' => Craft::t('booked', 'graphql.queryEventDates')],
                    'bookedBlackoutDates:read' => ['label' => Craft::t('booked', 'graphql.queryBlackoutDates')],
                    'bookedReports:read' => ['label' => Craft::t('booked', 'graphql.queryReports')],
                ];

                $event->mutations['Booked'] = [
                    'bookedReservations:create' => ['label' => Craft::t('booked', 'graphql.createReservations')],
                    'bookedReservations:update' => ['label' => Craft::t('booked', 'graphql.updateReservations')],
                    'bookedReservations:cancel' => ['label' => Craft::t('booked', 'graphql.cancelReservations')],
                ];
            }
        );
    }

    public function getServiceExtra(): \anvildev\booked\services\ServiceExtraService
    {
        return $this->get('serviceExtra');
    }

    public function getServiceLocation(): \anvildev\booked\services\ServiceLocationService
    {
        return $this->get('serviceLocation');
    }

    public function getCaptcha(): \anvildev\booked\services\CaptchaService
    {
        return $this->get('captcha');
    }

    public function getScheduleAssignment(): \anvildev\booked\services\ScheduleAssignmentService
    {
        return $this->get('scheduleAssignment');
    }

    public function getAudit(): \anvildev\booked\services\AuditService
    {
        return $this->get('audit');
    }

    public function getPermission(): \anvildev\booked\services\PermissionService
    {
        return $this->get('permission');
    }

    public function getCommerce(): \anvildev\booked\services\CommerceService
    {
        return $this->get('commerce');
    }

    public function getTwilioSms(): \anvildev\booked\services\TwilioSmsService
    {
        return $this->get('twilioSms');
    }

    public function getWebhook(): \anvildev\booked\services\WebhookService
    {
        return $this->get('webhook');
    }

    public function getWaitlist(): \anvildev\booked\services\WaitlistService
    {
        return $this->get('waitlist');
    }

    public function getMaintenance(): \anvildev\booked\services\MaintenanceService
    {
        return $this->get('maintenance');
    }

    public function getReports(): \anvildev\booked\services\ReportsService
    {
        return $this->get('reports');
    }

    public function getDashboard(): \anvildev\booked\services\DashboardService
    {
        return $this->get('dashboard');
    }

    public function getRefund(): \anvildev\booked\services\RefundService
    {
        return $this->get('refund');
    }

    public function getRefundPolicy(): \anvildev\booked\services\RefundPolicyService
    {
        return $this->get('refundPolicy');
    }

    public function getMutex(): \anvildev\booked\services\MutexFactory
    {
        return $this->get('mutex');
    }

    private function registerCalendarSyncListeners(): void
    {
        Event::on(
            \anvildev\booked\services\CalendarSyncService::class,
            \anvildev\booked\services\CalendarSyncService::EVENT_AFTER_CALENDAR_SYNC,
            function(\anvildev\booked\events\AfterCalendarSyncEvent $event) {
                if ($event->success && $event->externalEventId) {
                    $column = match ($event->provider) {
                        'google' => 'googleEventId',
                        'outlook' => 'outlookEventId',
                        default => null,
                    };

                    if ($column === null) {
                        return;
                    }

                    try {
                        \Craft::$app->db->createCommand()->update(
                            '{{%booked_reservations}}',
                            [$column => $event->externalEventId],
                            ['id' => $event->reservation->id],
                        )->execute();
                    } catch (\Throwable $e) {
                        \Craft::error("Failed to save calendar event ID for reservation #{$event->reservation->id}: {$e->getMessage()}", __METHOD__);
                    }
                }
            }
        );
    }

    private function registerVirtualMeetingListeners(): void
    {
        Event::on(
            \anvildev\booked\services\BookingService::class,
            \anvildev\booked\services\BookingService::EVENT_AFTER_BOOKING_CANCEL,
            function(\anvildev\booked\events\AfterBookingCancelEvent $event) {
                if ($event->success) {
                    $this->getVirtualMeeting()->deleteMeeting($event->reservation);
                }
            }
        );

        Event::on(
            \anvildev\booked\services\BookingService::class,
            \anvildev\booked\services\BookingService::EVENT_AFTER_BOOKING_SAVE,
            function(\anvildev\booked\events\AfterBookingSaveEvent $event) {
                if ($event->success && !$event->isNew) {
                    $this->getVirtualMeeting()->updateMeeting($event->reservation);
                }
            }
        );
    }

    private function registerWebhookListeners(): void
    {
        Event::on(
            \anvildev\booked\services\BookingService::class,
            \anvildev\booked\services\BookingService::EVENT_AFTER_BOOKING_SAVE,
            function(\anvildev\booked\events\AfterBookingSaveEvent $event) {
                if ($event->success) {
                    $this->getReports()->invalidateReportCaches();
                    if ($event->isNew) {
                        // Skip webhook for pending Commerce reservations — fire on order completion instead
                        if ($event->reservation->getStatus() !== \anvildev\booked\records\ReservationRecord::STATUS_PENDING) {
                            $this->getWebhook()->dispatch(
                                \anvildev\booked\services\WebhookService::EVENT_BOOKING_CREATED,
                                $event->reservation
                            );
                        }
                    } else {
                        $this->getWebhook()->dispatch(
                            \anvildev\booked\services\WebhookService::EVENT_BOOKING_UPDATED,
                            $event->reservation
                        );
                    }
                }
            }
        );

        Event::on(
            \anvildev\booked\services\BookingService::class,
            \anvildev\booked\services\BookingService::EVENT_AFTER_BOOKING_CANCEL,
            function(\anvildev\booked\events\AfterBookingCancelEvent $event) {
                if ($event->success) {
                    $this->getReports()->invalidateReportCaches();
                    $this->getWebhook()->dispatch(
                        \anvildev\booked\services\WebhookService::EVENT_BOOKING_CANCELLED,
                        $event->reservation,
                        ['cancellation' => ['reason' => $event->reason ?? null]]
                    );
                }
            }
        );

        Event::on(
            \craft\services\Gc::class,
            \craft\services\Gc::EVENT_RUN,
            function() {
                $results = $this->getMaintenance()->runAll();
                $total = array_sum(array_filter($results, 'is_int'));
                if ($total > 0) {
                    Craft::info("Maintenance cleanup completed: " . json_encode($results), __METHOD__);
                }
            }
        );
    }
}
