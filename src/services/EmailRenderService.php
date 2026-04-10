<?php

namespace anvildev\booked\services;

use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\helpers\DateHelper;
use anvildev\booked\models\Settings;
use anvildev\booked\traits\RendersInLanguage;
use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\View;

/**
 * Renders email templates for booking notifications with multi-site and multi-language support.
 *
 * Template resolution order:
 * 1. templates/booked/emails/[site-handle]/[template].twig (site-specific public)
 * 2. templates/_booked/emails/[site-handle]/[template].twig (site-specific private)
 * 3. templates/_booked/emails/[template].twig (private, underscore prefix)
 * 4. templates/booked/emails/[template].twig (public fallback)
 */
class EmailRenderService extends Component
{
    use RendersInLanguage;

    private function renderEmailTemplate(string $template, array $variables, ?Site $site = null): string
    {
        $view = Craft::$app->view;
        $mode = $view->getTemplateMode();

        if ($site) {
            $siteTemplate = dirname($template) . '/' . $site->handle . '/' . basename($template);
            foreach ([$siteTemplate, '_' . $siteTemplate] as $candidate) {
                if ($view->doesTemplateExist($candidate, $mode)) {
                    return $view->renderTemplate($candidate, $variables);
                }
            }
        }

        $privateTemplate = '_' . $template;
        return $view->renderTemplate(
            $view->doesTemplateExist($privateTemplate, $mode) ? $privateTemplate : $template,
            $variables,
        );
    }

    private function getReservationSite(ReservationInterface $reservation): Site
    {
        $siteId = $reservation->getSiteId();
        return ($siteId ? Craft::$app->getSites()->getSiteById($siteId) : null)
            ?? Craft::$app->getSites()->getPrimarySite();
    }

    private function getCommonVariables(ReservationInterface $reservation, Settings $settings): array
    {
        $service = $reservation->getService();
        $employee = $reservation->getEmployee();
        $location = $reservation->getLocation();
        $quantity = $reservation->quantity ?? 1;

        $employeeName = '';
        if ($employee) {
            $user = $employee->getUser();
            $employeeName = $user ? $user->getName() : ($employee->title ?? '');
        }

        return [
            'reservation' => $reservation,
            'service' => $service,
            'employee' => $employee,
            'location' => $location,
            'settings' => $settings,
            'siteName' => Craft::$app->getSystemName(),
            'ownerName' => $settings->getEffectiveName() ?? '',
            'ownerEmail' => $settings->getEffectiveEmail() ?? '',
            'bookingId' => $reservation->id,
            'userName' => $reservation->userName,
            'userEmail' => $reservation->userEmail,
            'userPhone' => $reservation->userPhone,
            'bookingDate' => $reservation->bookingDate,
            'endDate' => $reservation->getEndDate(),
            'startTime' => $reservation->startTime,
            'endTime' => $reservation->endTime,
            'isMultiDay' => $reservation->isMultiDay(),
            'formattedBookingDate' => $reservation->bookingDate
                ? DateHelper::formatDateLocale($reservation->bookingDate)
                : '',
            'formattedEndDate' => $reservation->isMultiDay() && $reservation->getEndDate()
                ? DateHelper::formatDateLocale($reservation->getEndDate())
                : null,
            'formattedStartTime' => $reservation->startTime
                ? DateHelper::formatTimeLocale(DateHelper::parseTime($reservation->startTime))
                : '',
            'formattedEndTime' => $reservation->endTime
                ? DateHelper::formatTimeLocale(DateHelper::parseTime($reservation->endTime))
                : '',
            // For multi-day bookings, `duration` is the number of days so the
            // shared `{{ duration }} {{ durationUnit }}` template expression
            // renders correctly. `durationMinutes` / `durationDays` are kept
            // available for templates that need the raw values.
            'duration' => $reservation->isMultiDay()
                ? ($reservation->getDurationDays() ?? 0)
                : $reservation->getDurationMinutes(),
            'durationMinutes' => $reservation->getDurationMinutes(),
            'durationDays' => $reservation->getDurationDays(),
            'durationUnit' => $reservation->isMultiDay()
                ? (($reservation->getDurationDays() ?? 0) === 1
                    ? Craft::t('booked', 'labels.day')
                    : Craft::t('booked', 'labels.days'))
                : Craft::t('booked', 'labels.minutes'),
            'durationDisplay' => $reservation->isMultiDay()
                ? $reservation->getDurationDays() . ' ' . Craft::t('booked', 'labels.days')
                : $reservation->getDurationMinutes() . ' ' . Craft::t('booked', 'labels.minutes'),
            'quantity' => $quantity,
            'quantityDisplay' => $quantity > 1,
            'status' => $reservation->getStatusLabel(),
            'notes' => $reservation->notes,
            'confirmationToken' => $reservation->confirmationToken,
            'dateCreated' => $reservation->dateCreated ? Craft::$app->getFormatter()->asDatetime($reservation->dateCreated, 'short') : '',
            'serviceName' => $service?->title ?? '',
            'employeeName' => $employeeName,
            'locationName' => $location?->title ?? '',
            'variationName' => null,
            'sourceName' => $service?->title,
            'formattedDateTime' => $reservation->getFormattedDateTime(),
            'managementUrl' => $reservation->getManagementUrl(),
            'cancelUrl' => $reservation->getCancelUrl(),
            'icsUrl' => $reservation->getIcsUrl(),
            'bookingPageUrl' => $settings->bookingPageUrl ?? '',
            'isVirtual' => !empty($reservation->virtualMeetingUrl),
            'virtualMeetingUrl' => $reservation->virtualMeetingUrl,
            'virtualMeetingProvider' => $reservation->virtualMeetingProvider,
            'virtualMeetingPassword' => null,
        ];
    }

    /**
     * Render a reservation email with site-aware language and template mode.
     */
    /**
     * @param array|callable(): array $extraVars Static array or callable that returns extra vars (called inside language context)
     */
    private function renderReservationEmail(
        string $templatePath,
        ReservationInterface $reservation,
        Settings $settings,
        array|callable $extraVars = [],
    ): string {
        $site = $this->getReservationSite($reservation);
        $language = $this->getReservationLanguage($reservation);

        $oldMode = Craft::$app->view->getTemplateMode();
        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            return $this->renderWithLanguage(
                function() use ($templatePath, $reservation, $settings, $extraVars, $site) {
                    $resolved = is_callable($extraVars) ? $extraVars() : $extraVars;
                    $variables = array_merge($this->getCommonVariables($reservation, $settings), $resolved);
                    return $this->renderEmailTemplate($templatePath, $variables, $site);
                },
                $language
            );
        } finally {
            Craft::$app->view->setTemplateMode($oldMode);
        }
    }

    /**
     * Wraps template mode switch + language context for non-reservation emails.
     */
    private function renderInSiteMode(callable $render, string $language): string
    {
        $oldMode = Craft::$app->view->getTemplateMode();
        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_SITE);
        try {
            return $this->renderWithLanguage($render, $language);
        } finally {
            Craft::$app->view->setTemplateMode($oldMode);
        }
    }

    public function renderConfirmationEmail(ReservationInterface $reservation, Settings $settings): string
    {
        return $this->renderReservationEmail('booked/emails/confirmation', $reservation, $settings);
    }

    public function renderStatusChangeEmail(ReservationInterface $reservation, string $oldStatus, Settings $settings): string
    {
        return $this->renderReservationEmail('booked/emails/status-change', $reservation, $settings, [
            'oldStatus' => $oldStatus,
            'newStatus' => $reservation->getStatusLabel(),
        ]);
    }

    public function renderCancellationEmail(ReservationInterface $reservation, Settings $settings): string
    {
        $cancelledAt = new \DateTime();

        return $this->renderReservationEmail('booked/emails/cancellation', $reservation, $settings, fn() => [
            'cancelledAt' => $cancelledAt,
            'formattedCancelledAt' => Craft::$app->getFormatter()->asDatetime($cancelledAt, 'medium'),
            'cancellationReason' => $reservation->cancellationReason ?? '',
        ]);
    }

    public function renderReminderEmail(ReservationInterface $reservation, Settings $settings, int $hoursBefore = 24): string
    {
        return $this->renderReservationEmail('booked/emails/reminder', $reservation, $settings, [
            'hoursBefore' => $hoursBefore,
        ]);
    }

    /**
     * Uses the owner's preferred language, falling back to the primary site's language.
     */
    public function renderOwnerNotificationEmail(ReservationInterface $reservation, Settings $settings): string
    {
        return $this->renderInSiteMode(
            function() use ($reservation, $settings) {
                $variables = $this->getCommonVariables($reservation, $settings);
                $variables['cpEditUrl'] = UrlHelper::cpUrl('booked/bookings/' . $reservation->id);
                return $this->renderEmailTemplate('booked/emails/owner-notification', $variables);
            },
            $settings->getOwnerNotificationLanguageCode(),
        );
    }

    public function renderQuantityChangedEmail(
        ReservationInterface $reservation,
        Settings $settings,
        int $previousQuantity,
        int $newQuantity,
        float $refundAmount = 0.0,
    ): string {
        return $this->renderReservationEmail('booked/emails/quantity-changed', $reservation, $settings, [
            'previousQuantity' => $previousQuantity,
            'newQuantity' => $newQuantity,
            'refundAmount' => $refundAmount,
        ]);
    }

    public function renderWaitlistNotificationEmail(\anvildev\booked\records\WaitlistRecord $entry, Settings $settings): string
    {
        $service = $entry->getService();
        $siteId = $service?->siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
        $language = $this->getLanguageForSiteId($siteId);

        return $this->renderInSiteMode(
            function() use ($entry, $service, $settings) {
                $formatter = Craft::$app->getFormatter();
                $date = $entry->preferredDate ?? '';
                $startTime = $entry->preferredTimeStart ?? '';
                $endTime = $entry->preferredTimeEnd ?? '';

                $bookingUrl = $settings->bookingPageUrl ?? '';
                if ($bookingUrl && $entry->serviceId) {
                    $separator = str_contains($bookingUrl, '?') ? '&' : '?';
                    $bookingUrl .= $separator . http_build_query(array_filter([
                        'serviceId' => $entry->serviceId,
                        'date' => $date,
                        'time' => $startTime,
                    ]));
                }

                $variables = [
                    'entry' => $entry,
                    'service' => $service,
                    'settings' => $settings,
                    'siteName' => Craft::$app->getSystemName(),
                    'ownerName' => $settings->getEffectiveName() ?? '',
                    'ownerEmail' => $settings->getEffectiveEmail() ?? '',
                    'customerName' => $entry->userName,
                    'customerEmail' => $entry->userEmail,
                    'serviceName' => $service?->title ?? '',
                    'preferredDate' => $date,
                    'preferredTimeStart' => $startTime,
                    'preferredTimeEnd' => $endTime,
                    'formattedDate' => $date ? $formatter->asDate($date, 'medium') : '',
                    'formattedTime' => $startTime ? $formatter->asTime($startTime, 'short') : '',
                    'startTime' => $startTime,
                    'endTime' => $endTime,
                    'bookingUrl' => $bookingUrl,
                    'bookingPageUrl' => $settings->bookingPageUrl ?? '',
                ];

                return $this->renderEmailTemplate('booked/emails/waitlist-notification', $variables);
            },
            $language,
        );
    }
}
