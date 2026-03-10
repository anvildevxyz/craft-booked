<?php

namespace anvildev\booked\console\controllers;

use anvildev\booked\Booked;
use anvildev\booked\elements\Employee;
use anvildev\booked\elements\Location;
use anvildev\booked\elements\Service;
use anvildev\booked\factories\ReservationFactory;
use anvildev\booked\models\ReservationModel;
use anvildev\booked\models\Settings;
use anvildev\booked\records\WaitlistRecord;
use Craft;
use craft\console\Controller;
use craft\mail\Message;
use yii\console\ExitCode;
use yii\helpers\Console;

class EmailController extends Controller
{
    private const TEMPLATE_TYPES = [
        'confirmation',
        'status-change',
        'cancellation',
        'reminder',
        'owner-notification',
        'waitlist-notification',
    ];

    public string $type = 'all';
    public ?string $to = null;
    public ?string $site = null;
    public bool $force = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'preview' => [...parent::options($actionID), 'type', 'to', 'site'],
            'publish' => [...parent::options($actionID), 'site', 'force'],
            default => parent::options($actionID),
        };
    }

    public function actionList(): int
    {
        $this->stdout("Available email template types:\n\n");

        $descriptions = [
            'confirmation' => 'Booking confirmation sent to customer',
            'status-change' => 'Status change notification sent to customer',
            'cancellation' => 'Cancellation notification sent to customer',
            'reminder' => 'Appointment reminder sent to customer',
            'owner-notification' => 'New booking notification sent to site owner',
            'waitlist-notification' => 'Slot availability notification sent to waitlisted customer',
        ];

        foreach (self::TEMPLATE_TYPES as $type) {
            $this->stdout("  {$type}", Console::FG_CYAN);
            $this->stdout(" - {$descriptions[$type]}\n");
        }

        $this->stdout("\nUsage: php craft booked/email/preview --type=confirmation --to=test@example.com\n");
        $this->stdout("       php craft booked/email/preview --type=all --to=test@example.com\n");

        return ExitCode::OK;
    }

    public function actionPublish(): int
    {
        $sourceDir = Booked::getInstance()->getBasePath() . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'emails';
        $templatesPath = Craft::$app->getPath()->getSiteTemplatesPath();

        if ($this->site) {
            $site = Craft::$app->getSites()->getSiteByHandle($this->site);
            if (!$site) {
                $this->stderr("Site handle '{$this->site}' not found.\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $destDir = $templatesPath . DIRECTORY_SEPARATOR . '_booked' . DIRECTORY_SEPARATOR . 'emails' . DIRECTORY_SEPARATOR . $site->handle;
            $this->stdout("Publishing site-specific templates for '{$site->handle}' ({$site->language})\n\n");
        } else {
            $destDir = $templatesPath . DIRECTORY_SEPARATOR . '_booked' . DIRECTORY_SEPARATOR . 'emails';
            $this->stdout("Publishing email templates for customization\n\n");
        }

        $templates = $this->site
            ? array_map(fn($t) => "{$t}.twig", self::TEMPLATE_TYPES)
            : ['_base.twig', ...array_map(fn($t) => "{$t}.twig", self::TEMPLATE_TYPES)];

        $created = 0;
        $skipped = 0;

        foreach ($templates as $template) {
            $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $template;
            $destPath = $destDir . DIRECTORY_SEPARATOR . $template;

            if (!file_exists($sourcePath)) {
                $this->stdout("  ! {$template} — source not found\n", Console::FG_YELLOW);
                $skipped++;
                continue;
            }

            if (file_exists($destPath) && !$this->force) {
                $this->stdout("  · {$template} — already exists (use --force to overwrite)\n", Console::FG_YELLOW);
                $skipped++;
                continue;
            }

            $dir = dirname($destPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($destPath, $this->getVariableHeader($template) . file_get_contents($sourcePath));
            $this->stdout("  ✓ {$template}\n", Console::FG_GREEN);
            $created++;
        }

        $this->stdout("\nCreated: {$created}", Console::FG_GREEN);
        if ($skipped > 0) {
            $this->stdout(" | Skipped: {$skipped}", Console::FG_YELLOW);
        }
        $this->stdout("\nLocation: templates/" . str_replace($templatesPath . DIRECTORY_SEPARATOR, '', $destDir) . "/\n");

        if (!$this->site) {
            $this->stdout("\nTip: Use --site=<handle> to create site-specific overrides for multi-language emails.\n");
        }

        return ExitCode::OK;
    }

    private function getVariableHeader(string $template): string
    {
        $name = basename($template, '.twig');

        $vars = match ($name) {
            '_base' => [
                'Base Email Layout — shared by all email templates.',
                'Override this to change the global email design.',
                '',
                'Blocks:',
                '  {% block header %}   — Email header (icon, title, subtitle)',
                '  {% block content %}  — Main email body',
                '  {% block footer %}   — Footer (owner info, contact)',
                '  {% block footerMeta %} — Footer metadata (booking ID, date)',
                '',
                'Variables:',
                '  title             (string)   Email title',
                '  preheader         (string)   Preview text for email clients',
                '  headerIcon        (string)   Emoji or icon character',
                '  headerColor       (string)   Accent color (default: #000000)',
                '  siteName          (string)   Craft site name',
                '  ownerName         (string)   Business/owner name',
                '  ownerEmail        (string)   Business/owner email',
                '  bookingId         (int)      Reservation ID',
                '  dateCreated       (string)   Formatted creation date',
            ],
            'confirmation' => [
                'Booking Confirmation — sent to customer after booking.',
                '',
                'Common variables (available in all booking templates):',
                '  reservation       (ReservationInterface)  Full reservation object',
                '  service           (Service|null)          Service element',
                '  employee          (Employee|null)         Employee element',
                '  location          (Location|null)         Location element',
                '  settings          (Settings)              Plugin settings',
                '  siteName          (string)   Craft site name',
                '  ownerName         (string)   Business/owner name',
                '  ownerEmail        (string)   Business/owner email',
                '  bookingId         (int)      Reservation ID',
                '  userName          (string)   Customer name',
                '  userEmail         (string)   Customer email',
                '  userPhone         (string)   Customer phone',
                '  bookingDate       (string)   Date (Y-m-d)',
                '  startTime         (string)   Start time (H:i:s)',
                '  endTime           (string)   End time (H:i:s)',
                '  duration          (int)      Duration in minutes',
                '  quantity          (int)      Number of people',
                '  quantityDisplay   (bool)     True if quantity > 1',
                '  status            (string)   Human-readable status label',
                '  notes             (string)   Customer notes',
                '  confirmationToken (string)   Unique booking token',
                '  dateCreated       (string)   Formatted creation date',
                '  serviceName       (string)   Service title',
                '  employeeName      (string)   Employee/staff name',
                '  locationName      (string)   Location title',
                '  formattedDateTime (string)   Formatted date + time',
                '  managementUrl     (string)   Booking management link',
                '  cancelUrl         (string)   Cancellation link',
                '  icsUrl            (string)   Calendar (.ics) download link',
                '  bookingPageUrl    (string)   Booking page URL',
                '  isVirtual         (bool)     Has virtual meeting',
                '  virtualMeetingUrl (string)   Meeting join URL',
                '  virtualMeetingProvider (string) Provider name (zoom/google)',
                '  virtualMeetingPassword (string|null) Meeting password',
                '',
                'Service extras (via reservation object):',
                '  reservation.getExtras()      Array of {extra, quantity}',
                '  reservation.getExtrasPrice() Extras subtotal',
                '  reservation.getTotalPrice()  Grand total',
            ],
            'status-change' => [
                'Status Change — sent to customer when booking status changes.',
                '',
                'All common variables (see confirmation.twig) plus:',
                '  oldStatus         (string)   Previous status label',
                '  newStatus         (string)   New status label',
            ],
            'cancellation' => [
                'Cancellation — sent to customer when booking is cancelled.',
                '',
                'All common variables (see confirmation.twig) plus:',
                '  cancelledAt       (DateTime) When the cancellation occurred',
                '  cancellationReason (string)  Reason for cancellation',
            ],
            'reminder' => [
                'Reminder — sent to customer before their appointment.',
                '',
                'All common variables (see confirmation.twig) plus:',
                '  hoursBefore       (int)      Hours until appointment (default: 24)',
            ],
            'owner-notification' => [
                'Owner Notification — sent to site owner on new bookings.',
                'Rendered in primary site language (not booking site).',
                '',
                'All common variables (see confirmation.twig) plus:',
                '  cpEditUrl         (string)   Craft CP edit URL for this booking',
            ],
            'waitlist-notification' => [
                'Waitlist Notification — sent when a slot opens for a waitlisted customer.',
                'Uses different variables than booking templates.',
                '',
                'Variables:',
                '  entry             (WaitlistRecord) Waitlist record',
                '  service           (Service|null)  Service element',
                '  settings          (Settings)      Plugin settings',
                '  siteName          (string)   Craft site name',
                '  ownerName         (string)   Business/owner name',
                '  ownerEmail        (string)   Business/owner email',
                '  customerName      (string)   Waitlisted customer name',
                '  customerEmail     (string)   Waitlisted customer email',
                '  serviceName       (string)   Service title',
                '  preferredDate     (string)   Preferred date',
                '  preferredTimeStart (string)  Preferred start time',
                '  preferredTimeEnd  (string)   Preferred end time',
                '  bookingPageUrl    (string)   Link to booking page',
                '',
                'Via entry object:',
                '  entry.userName      Customer name',
                '  entry.getEmployee() Employee element',
                '  entry.getLocation() Location element',
                '  entry.getService()  Service element',
            ],
            default => ['Email template for: ' . $name],
        };

        return "{#\n" . implode("\n", array_map(fn($line) => $line === '' ? ' #' : " # {$line}", $vars)) . "\n #}\n";
    }

    public function actionPreview(): int
    {
        $settings = Settings::loadSettings();

        $recipient = $this->to ?? $settings->getEffectiveEmail();
        if (empty($recipient)) {
            $this->stderr("No recipient email. Use --to=email or configure owner email in settings.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $site = $this->site
            ? Craft::$app->getSites()->getSiteByHandle($this->site)
            : Craft::$app->getSites()->getPrimarySite();

        if ($this->site && !$site) {
            $this->stderr("Site handle '{$this->site}' not found.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $types = $this->type === 'all' ? self::TEMPLATE_TYPES : [$this->type];

        foreach ($types as $type) {
            if (!in_array($type, self::TEMPLATE_TYPES, true)) {
                $this->stderr("Unknown template type: {$type}\n", Console::FG_RED);
                $this->stderr("Run 'php craft booked/email/list' to see available types.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        // Switch language context for the preview so dates/subjects render in the target locale
        $originalLanguage = Craft::$app->language;
        $formatter = Craft::$app->getFormatter();
        $originalLocale = $formatter->locale;
        Craft::$app->language = $site->language;
        $formatter->locale = $site->language;

        $reservation = $this->getOrCreateReservation($site->id);
        $waitlistEntry = $this->getOrCreateWaitlistEntry($site->id);

        $this->stdout("Sending preview emails to {$recipient}");
        if ($this->site) {
            $this->stdout(" (site: {$site->handle}, language: {$site->language})");
        }
        $this->stdout("\n\n");

        $sent = 0;
        $failed = 0;
        $emailRender = Booked::getInstance()->emailRender;

        foreach ($types as $type) {
            $this->stdout("  {$type} ... ");

            try {
                [$subject, $body] = match ($type) {
                    'confirmation' => [
                        $settings->getEffectiveBookingConfirmationSubject(),
                        $emailRender->renderConfirmationEmail($reservation, $settings),
                    ],
                    'status-change' => [
                        Craft::t('booked', 'emails.subject.statusChange'),
                        $emailRender->renderStatusChangeEmail($reservation, 'pending', $settings),
                    ],
                    'cancellation' => [
                        $settings->getEffectiveCancellationEmailSubject(),
                        $emailRender->renderCancellationEmail($reservation, $settings),
                    ],
                    'reminder' => [
                        $settings->getEffectiveReminderEmailSubject(),
                        $emailRender->renderReminderEmail($reservation, $settings, 24),
                    ],
                    'owner-notification' => [
                        $settings->getEffectiveOwnerNotificationSubject(),
                        $emailRender->renderOwnerNotificationEmail($reservation, $settings),
                    ],
                    'waitlist-notification' => [
                        Craft::t('booked', 'queue.sendWaitlistNotification.slotAvailable', [
                            'service' => $waitlistEntry->getService()?->title ?? 'Service',
                        ]),
                        $emailRender->renderWaitlistNotificationEmail($waitlistEntry, $settings),
                    ],
                };

                $message = new Message();
                $message->setTo($recipient)->setSubject('[Preview] ' . $subject)->setHtmlBody($body);

                $fromEmail = $settings->getEffectiveEmail();
                if ($fromEmail) {
                    $message->setFrom([$fromEmail => $settings->getEffectiveName() ?? '']);
                }

                if (Craft::$app->mailer->send($message)) {
                    $this->stdout("OK\n", Console::FG_GREEN);
                    $sent++;
                } else {
                    $this->stdout("SEND FAILED\n", Console::FG_RED);
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->stdout("ERROR\n", Console::FG_RED);
                $this->stderr("    {$e->getMessage()}\n", Console::FG_YELLOW);
                $failed++;
            }
        }

        // Restore original language context
        Craft::$app->language = $originalLanguage;
        $formatter->locale = $originalLocale;

        $this->stdout("\nSent: {$sent}", Console::FG_GREEN);
        $this->stdout(" | ");
        $this->stdout("Failed: {$failed}", $failed > 0 ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout(" | Recipient: {$recipient}\n");

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function getOrCreateReservation(int $siteId): ReservationModel
    {
        $real = ReservationFactory::find()->siteId('*')->orderBy('dateCreated DESC')->one();

        if ($real instanceof ReservationModel) {
            $real->siteId = $siteId;
            return $real;
        }

        if ($real !== null) {
            $model = new ReservationModel();
            $model->id = $real->getId();
            $model->userName = $real->getUserName();
            $model->userEmail = $real->getUserEmail();
            $model->userPhone = $real->getUserPhone();
            $model->bookingDate = $real->getBookingDate();
            $model->startTime = $real->getStartTime();
            $model->endTime = $real->getEndTime();
            $model->status = $real->getStatus() ?? 'confirmed';
            $model->notes = $real->getNotes();
            $model->confirmationToken = $real->getConfirmationToken();
            $model->siteId = $siteId;
            $model->quantity = $real->getQuantity();
            $model->dateCreated = $real->getDateCreated();
            $model->serviceId = $real->getServiceId();
            $model->employeeId = $real->getEmployeeId();
            $model->locationId = $real->getLocationId();
            return $model;
        }

        // Synthetic reservation
        $model = new ReservationModel();
        $model->id = 99999;
        $model->userName = 'Jane Doe';
        $model->userEmail = 'jane@example.com';
        $model->userPhone = '+41 79 123 45 67';
        $model->bookingDate = (new \DateTime('+1 day'))->format('Y-m-d');
        $model->startTime = '10:00:00';
        $model->endTime = '11:00:00';
        $model->status = 'confirmed';
        $model->notes = 'Preview booking — this is not a real reservation.';
        $model->confirmationToken = 'preview-token-' . bin2hex(random_bytes(8));
        $model->siteId = $siteId;
        $model->quantity = 1;
        $model->dateCreated = new \DateTime();

        if ($service = Service::find()->siteId('*')->one()) {
            $model->serviceId = $service->id;
            $model->setService($service);
        }
        if ($employee = Employee::find()->siteId('*')->one()) {
            $model->employeeId = $employee->id;
            $model->setEmployee($employee);
        }
        if ($location = Location::find()->siteId('*')->one()) {
            $model->locationId = $location->id;
            $model->setLocation($location);
        }

        return $model;
    }

    private function getOrCreateWaitlistEntry(int $siteId): WaitlistRecord
    {
        /** @var WaitlistRecord|null $real */
        $real = WaitlistRecord::find()->orderBy(['dateCreated' => SORT_DESC])->one();
        if ($real) {
            return $real;
        }

        $entry = new WaitlistRecord();
        $entry->userName = 'Jane Doe';
        $entry->userEmail = 'jane@example.com';
        $entry->userPhone = '+41 79 123 45 67';
        $entry->preferredDate = (new \DateTime('+1 day'))->format('Y-m-d');
        $entry->preferredTimeStart = '10:00';
        $entry->preferredTimeEnd = '11:00';
        $entry->status = 'active';

        if ($service = Service::find()->siteId('*')->one()) {
            $entry->serviceId = $service->id;
        }

        return $entry;
    }
}
