<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\elements\Employee;
use anvildev\booked\events\AfterCalendarSyncEvent;
use anvildev\booked\events\BeforeCalendarSyncEvent;
use anvildev\booked\helpers\DateHelper;
use anvildev\booked\records\CalendarTokenRecord;
use anvildev\booked\records\OAuthStateTokenRecord;
use Craft;
use craft\base\Component;
use craft\helpers\Html;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use yii\base\InvalidConfigException;

/**
 * Manages two-way calendar synchronization with Google Calendar and Microsoft Outlook.
 *
 * Handles OAuth authentication flows (authorization, token storage, refresh),
 * and pushes reservation data to external calendars via their respective APIs.
 * Requires the Pro edition.
 */
class CalendarSyncService extends Component
{
    public const EVENT_BEFORE_CALENDAR_SYNC = 'beforeCalendarSync';
    public const EVENT_AFTER_CALENDAR_SYNC = 'afterCalendarSync';

    /**
     * Queue a calendar update job for an existing reservation.
     *
     * Used when reservation data changes (e.g. quantity update) and the
     * external calendar event needs to be re-synced.
     */
    public function queueCalendarUpdate(int $reservationId): void
    {
        Craft::$app->getQueue()->push(
            new \anvildev\booked\queue\jobs\SyncToCalendarJob([
                'reservationId' => $reservationId,
                'isUpdate' => true,
            ])
        );
        Craft::info("Queued calendar update for reservation #{$reservationId}", __METHOD__);
    }

    public function getAuthUrl(Employee $employee, string $provider): string
    {
        $stateRecord = OAuthStateTokenRecord::createToken($employee->id, $provider);

        return match ($provider) {
            'google' => $this->getGoogleAuthUrl($stateRecord->token),
            'outlook' => $this->getOutlookClient()->getAuthorizationUrl([
                'state' => $stateRecord->token,
                'scope' => ['openid', 'offline_access', 'https://graph.microsoft.com/Calendars.ReadWrite'],
            ]),
            default => throw new \InvalidArgumentException("Unsupported calendar provider: {$provider}"),
        };
    }

    private function getGoogleAuthUrl(string $state): string
    {
        $client = $this->getGoogleClient();
        $client->setState($state);
        return $client->createAuthUrl();
    }

    public function handleCallback(string $stateToken, string $code, ?string $redirectUri = null): bool
    {
        $stateData = OAuthStateTokenRecord::verifyAndConsume($stateToken);
        if (!$stateData) {
            Craft::error('Invalid or expired OAuth state token', __METHOD__);
            return false;
        }

        $employeeId = $stateData['employeeId'];
        $provider = $stateData['provider'];

        if ($provider === 'google') {
            return $this->handleGoogleCallback($employeeId, $code, $redirectUri);
        }
        if ($provider === 'outlook') {
            return $this->handleOutlookCallback($employeeId, $code, $redirectUri);
        }

        return false;
    }

    private function handleGoogleCallback(int $employeeId, string $code, ?string $redirectUri): bool
    {
        $client = $this->getGoogleClient();
        if ($redirectUri) {
            $client->setRedirectUri($redirectUri);
            Craft::debug("Google OAuth using custom redirect URI: {$redirectUri}", __METHOD__);
        }

        try {
            $token = $client->fetchAccessTokenWithAuthCode($code);
        } catch (\Exception $e) {
            Craft::error('Google OAuth exception: ' . $e->getMessage(), __METHOD__);
            return false;
        }

        if (isset($token['error'])) {
            Craft::error("Google OAuth error: " . ($token['error'] ?? '') . ' - ' . ($token['error_description'] ?? ''), __METHOD__);
            return false;
        }

        return $this->saveToken($employeeId, 'google', [
            'accessToken' => $token['access_token'],
            'refreshToken' => $token['refresh_token'] ?? null,
            'expiresAt' => (new \DateTime())->modify('+' . (int) $token['expires_in'] . ' seconds')->format('Y-m-d H:i:s'),
        ]);
    }

    private function handleOutlookCallback(int $employeeId, string $code, ?string $redirectUri): bool
    {
        $client = $this->getOutlookClient($redirectUri);

        try {
            $token = $client->getAccessToken('authorization_code', ['code' => $code]);
            return $this->saveToken($employeeId, 'outlook', [
                'accessToken' => $token->getToken(),
                'refreshToken' => $token->getRefreshToken(),
                'expiresAt' => (new \DateTime())->setTimestamp($token->getExpires())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            Craft::error('Outlook OAuth error: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    public function getAccessToken(Employee $employee, string $provider): ?string
    {
        $tokenData = $this->getToken($employee->id, $provider);
        if (!$tokenData) {
            return null;
        }

        if (new \DateTime($tokenData['expiresAt']) <= (new \DateTime())->modify('+60 seconds')) {
            $refreshed = $this->refreshToken($employee, $provider, $tokenData['refreshToken']);
            if ($refreshed === null) {
                Craft::warning("Failed to refresh {$provider} access token for employee {$employee->id}: refresh returned null", __METHOD__);
            }
            return $refreshed;
        }

        return $tokenData['accessToken'];
    }

    public function sendConnectionInvite(Employee $employee, string $provider): bool
    {
        $email = $employee->email;
        if (!$email) {
            Craft::error("Cannot send calendar invite: Employee {$employee->id} has no email", __METHOD__);
            return false;
        }

        if (!in_array($provider, ['google', 'outlook'])) {
            Craft::error("Invalid calendar provider: {$provider}", __METHOD__);
            return false;
        }

        $invite = \anvildev\booked\records\CalendarInviteRecord::createInvite(
            $employee->id, $provider, $email, Craft::$app->user->id ?? 0
        );

        $connectUrl = \craft\helpers\UrlHelper::siteUrl('booked/calendar/connect', ['token' => $invite->token]);
        $siteName = Craft::$app->sites->currentSite->name ?? Craft::$app->getSystemName();
        $providerName = ucfirst($provider);

        $sent = Craft::$app->mailer->compose()
            ->setTo($email)
            ->setSubject(Craft::t('booked', 'Connect your {provider} calendar to {site}', [
                'provider' => $providerName, 'site' => $siteName,
            ]))
            ->setHtmlBody($this->renderInviteEmailHtml($employee, $provider, $connectUrl, $siteName))
            ->setTextBody($this->renderInviteEmailText($employee, $provider, $connectUrl, $siteName))
            ->send();

        if ($sent) {
            Craft::info("Calendar invite sent to {$email} for {$provider}", __METHOD__);
        } else {
            Craft::error("Failed to send calendar invite to {$email}", __METHOD__);
        }

        return $sent;
    }

    public function getPendingInvite(int $employeeId, string $provider): ?\anvildev\booked\records\CalendarInviteRecord
    {
        return \anvildev\booked\records\CalendarInviteRecord::findPending($employeeId, $provider);
    }

    protected function renderInviteEmailHtml(Employee $employee, string $provider, string $connectUrl, string $siteName): string
    {
        return Craft::$app->getView()->renderTemplate(
            'booked/emails/calendar-invite',
            [
                'providerName' => ucfirst($provider),
                'employeeName' => $employee->title ?? 'there',
                'siteName' => $siteName,
                'connectUrl' => $connectUrl,
                'expiresIn' => '72 hours',
            ]
        );
    }

    protected function renderInviteEmailText(Employee $employee, string $provider, string $connectUrl, string $siteName): string
    {
        $providerName = ucfirst($provider);
        $employeeName = $employee->title ?? 'there';

        return <<<TEXT
Connect Your {$providerName} Calendar

Hi {$employeeName},

You've been invited to connect your {$providerName} calendar to {$siteName}.
Once connected, your bookings will automatically sync with your calendar.

Click here to connect: {$connectUrl}

This link expires in 72 hours. If you didn't expect this email, you can safely ignore it.

---
You're receiving this because you're registered as a team member at {$siteName}.
TEXT;
    }

    public function syncToExternal(ReservationInterface $reservation): bool
    {
        $employee = $reservation->getEmployee();
        if (!$employee) {
            return false;
        }

        $success = true;
        foreach (['google', 'outlook'] as $provider) {
            $token = $this->getAccessToken($employee, $provider);
            if ($token) {
                $result = match ($provider) {
                    'google' => $this->syncToGoogle($reservation, $token),
                    'outlook' => $this->syncToOutlook($reservation, $token),
                };
                if (!$result) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    protected function syncToGoogle(ReservationInterface $reservation, string $token): bool
    {
        $startTime = microtime(true);

        $client = $this->getGoogleClient();
        $client->setAccessToken($token);

        $eventData = [
            'summary' => $reservation->getService()?->title ?? Craft::t('booked', 'element.booking'),
            'description' => Craft::t('booked', 'calendar.customer', ['name' => Html::encode($reservation->userName)]) . "\n" .
                             Craft::t('booked', 'ics.email', ['email' => Html::encode($reservation->userEmail)]) . "\n" .
                             Craft::t('booked', 'ics.notes', ['notes' => Html::encode($reservation->notes ?? '-')]),
        ];

        if ($reservation->isMultiDay() && $reservation->getEndDate()) {
            $exclusiveEnd = (new \DateTime($reservation->getEndDate()))->modify('+1 day')->format('Y-m-d');
            $eventData['start'] = ['date' => $reservation->bookingDate];
            $eventData['end'] = ['date' => $exclusiveEnd];
        } else {
            $tz = $reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone();
            $eventData['start'] = ['dateTime' => $reservation->bookingDate . 'T' . $reservation->startTime, 'timeZone' => $tz];
            $eventData['end'] = ['dateTime' => $reservation->bookingDate . 'T' . $reservation->endTime, 'timeZone' => $tz];
        }

        $isUpdate = !empty($reservation->googleEventId);
        $action = $isUpdate ? 'update' : 'create';

        $beforeSyncEvent = new BeforeCalendarSyncEvent([
            'reservation' => $reservation, 'provider' => 'google', 'action' => $action,
            'eventData' => $eventData, 'employeeId' => $reservation->employeeId,
        ]);
        $this->trigger(self::EVENT_BEFORE_CALENDAR_SYNC, $beforeSyncEvent);

        if (!$beforeSyncEvent->isValid) {
            $errorMessage = $beforeSyncEvent->errorMessage ?? 'Calendar sync was cancelled by event handler';
            Craft::warning("Calendar sync cancelled by event handler: {$errorMessage}", __METHOD__);
            $this->fireAfterSync($reservation, 'google', false, microtime(true) - $startTime, errorMessage: $errorMessage);
            return false;
        }

        try {
            $calendarService = new GoogleCalendar($client);
            $googleEvent = new \Google\Service\Calendar\Event($beforeSyncEvent->eventData);

            if ($isUpdate) {
                $updatedEvent = $calendarService->events->update('primary', $reservation->googleEventId, $googleEvent);
                $eventId = $updatedEvent->getId();
                $htmlLink = $updatedEvent->getHtmlLink();
            } else {
                $createdEvent = $calendarService->events->insert('primary', $googleEvent);
                $eventId = $createdEvent->getId();
                $htmlLink = $createdEvent->getHtmlLink();
            }

            $this->fireAfterSync($reservation, 'google', true, microtime(true) - $startTime,
                externalEventId: $eventId,
                response: ['id' => $eventId, 'htmlLink' => $htmlLink],
            );
            return true;
        } catch (\Exception $e) {
            Craft::error('Failed to sync booking to Google Calendar: ' . $e->getMessage(), __METHOD__);
            $this->fireAfterSync($reservation, 'google', false, microtime(true) - $startTime, errorMessage: $e->getMessage());
            return false;
        }
    }

    protected function syncToOutlook(ReservationInterface $reservation, string $token): bool
    {
        $startTime = microtime(true);

        $graph = new \Microsoft\Graph\Graph();
        $graph->setAccessToken($token);

        $eventData = [
            'subject' => $reservation->getService()?->title ?? Craft::t('booked', 'element.booking'),
            'body' => [
                'contentType' => 'HTML',
                'content' => Craft::t('booked', 'calendar.customer', ['name' => \craft\helpers\Html::encode($reservation->userName)]) . '<br>' .
                             Craft::t('booked', 'ics.email', ['email' => \craft\helpers\Html::encode($reservation->userEmail)]) . '<br>' .
                             Craft::t('booked', 'ics.notes', ['notes' => \craft\helpers\Html::encode($reservation->notes ?? '-')]),
            ],
        ];

        if ($reservation->isMultiDay() && $reservation->getEndDate()) {
            $tz = DateHelper::toWindowsTimezone($reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone());
            $eventData['isAllDay'] = true;
            $eventData['start'] = ['dateTime' => $reservation->bookingDate . 'T00:00:00', 'timeZone' => $tz];
            $endDatePlusOne = (new \DateTime($reservation->getEndDate()))->modify('+1 day')->format('Y-m-d');
            $eventData['end'] = ['dateTime' => $endDatePlusOne . 'T00:00:00', 'timeZone' => $tz];
        } else {
            $tz = DateHelper::toWindowsTimezone($reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone());
            $eventData['start'] = ['dateTime' => $reservation->bookingDate . 'T' . $reservation->startTime, 'timeZone' => $tz];
            $eventData['end'] = ['dateTime' => $reservation->bookingDate . 'T' . $reservation->endTime, 'timeZone' => $tz];
        }

        $isUpdate = !empty($reservation->outlookEventId);
        $action = $isUpdate ? 'update' : 'create';

        $beforeSyncEvent = new BeforeCalendarSyncEvent([
            'reservation' => $reservation, 'provider' => 'outlook', 'action' => $action,
            'eventData' => $eventData, 'employeeId' => $reservation->employeeId,
        ]);
        $this->trigger(self::EVENT_BEFORE_CALENDAR_SYNC, $beforeSyncEvent);

        if (!$beforeSyncEvent->isValid) {
            $errorMessage = $beforeSyncEvent->errorMessage ?? 'Calendar sync was cancelled by event handler';
            Craft::warning("Calendar sync cancelled by event handler: {$errorMessage}", __METHOD__);
            $this->fireAfterSync($reservation, 'outlook', false, microtime(true) - $startTime, errorMessage: $errorMessage);
            return false;
        }

        try {
            if ($isUpdate) {
                $responseBody = $graph->createRequest('PATCH', '/me/events/' . $reservation->outlookEventId)
                    ->attachBody($beforeSyncEvent->eventData)
                    ->execute()
                    ->getBody();
            } else {
                $responseBody = $graph->createRequest('POST', '/me/events')
                    ->attachBody($beforeSyncEvent->eventData)
                    ->execute()
                    ->getBody();
            }

            $this->fireAfterSync($reservation, 'outlook', true, microtime(true) - $startTime,
                externalEventId: $responseBody['id'] ?? null,
                response: ['id' => $responseBody['id'] ?? null, 'webLink' => $responseBody['webLink'] ?? null],
            );
            return true;
        } catch (\Exception $e) {
            Craft::error('Failed to sync booking to Outlook Calendar: ' . $e->getMessage(), __METHOD__);
            $this->fireAfterSync($reservation, 'outlook', false, microtime(true) - $startTime, errorMessage: $e->getMessage());
            return false;
        }
    }

    /** Fire the after-sync event with common fields */
    private function fireAfterSync(
        ReservationInterface $reservation,
        string $provider,
        bool $success,
        float $duration,
        ?string $externalEventId = null,
        ?array $response = null,
        ?string $errorMessage = null,
    ): void {
        $data = [
            'reservation' => $reservation, 'provider' => $provider, 'action' => 'create',
            'success' => $success, 'duration' => $duration,
        ];
        if ($externalEventId !== null) {
            $data['externalEventId'] = $externalEventId;
        }
        if ($response !== null) {
            $data['response'] = $response;
        }
        if ($errorMessage !== null) {
            $data['errorMessage'] = $errorMessage;
        }
        $this->trigger(self::EVENT_AFTER_CALENDAR_SYNC, new AfterCalendarSyncEvent($data));
    }

    protected function getToken(int $employeeId, string $provider): ?array
    {
        $record = CalendarTokenRecord::findOne(['employeeId' => $employeeId, 'provider' => $provider]);
        return $record ? [
            'accessToken' => Craft::$app->getSecurity()->decryptByKey($record->accessToken, 'booked_calendar_tokens'),
            'refreshToken' => $record->refreshToken ? Craft::$app->getSecurity()->decryptByKey($record->refreshToken, 'booked_calendar_tokens') : null,
            'expiresAt' => $record->expiresAt,
        ] : null;
    }

    public function disconnect(Employee $employee, string $provider): bool
    {
        $deleted = CalendarTokenRecord::deleteAll(['employeeId' => $employee->id, 'provider' => $provider]);

        \anvildev\booked\records\CalendarSyncStatusRecord::deleteAll([
            'employeeId' => $employee->id, 'provider' => $provider,
        ]);

        if ($deleted) {
            Craft::info("Disconnected {$provider} calendar for employee {$employee->id}", __METHOD__);
        }

        return $deleted > 0;
    }

    protected function saveToken(int $employeeId, string $provider, array $data): bool
    {
        $record = CalendarTokenRecord::findOne(['employeeId' => $employeeId, 'provider' => $provider])
            ?? new CalendarTokenRecord();

        $record->employeeId = $employeeId;
        $record->provider = $provider;
        $record->accessToken = Craft::$app->getSecurity()->encryptByKey($data['accessToken'], 'booked_calendar_tokens');
        $record->refreshToken = $data['refreshToken']
            ? Craft::$app->getSecurity()->encryptByKey($data['refreshToken'], 'booked_calendar_tokens')
            : $record->refreshToken;
        $record->expiresAt = $data['expiresAt'];

        if ($record->getIsNewRecord() && empty($data['refreshToken'])) {
            Craft::warning("Saving new calendar token for employee {$employeeId}/{$provider} without a refresh token — token renewal will fail", __METHOD__);
        }

        return $record->save();
    }

    protected function refreshToken(Employee $employee, string $provider, ?string $refreshToken): ?string
    {
        if (!$refreshToken) {
            return null;
        }

        $mutexKey = "booked-token-refresh-{$employee->id}-{$provider}";
        if (!Craft::$app->getMutex()->acquire($mutexKey, 5)) {
            Craft::warning("Could not acquire token refresh lock for employee {$employee->id}", __METHOD__);
            return null;
        }

        try {
            if ($provider === 'google') {
                try {
                    $client = $this->getGoogleClient();
                } catch (\yii\base\InvalidConfigException $e) {
                    Craft::warning('Google token refresh skipped: ' . $e->getMessage(), __METHOD__);
                    return null;
                }
                try {
                    $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                } catch (\Exception $e) {
                    Craft::error('Google token refresh failed: ' . $e->getMessage(), __METHOD__);
                    return null;
                }
                if (isset($token['error'])) {
                    Craft::error('Google token refresh error: ' . ($token['error_description'] ?? $token['error']), __METHOD__);
                    return null;
                }
                $this->saveToken($employee->id, 'google', [
                    'accessToken' => $token['access_token'],
                    'refreshToken' => $token['refresh_token'] ?? $refreshToken,
                    'expiresAt' => (new \DateTime())->modify('+' . (int) $token['expires_in'] . ' seconds')->format('Y-m-d H:i:s'),
                ]);
                return $token['access_token'];
            }

            if ($provider === 'outlook') {
                try {
                    $token = $this->getOutlookClient()->getAccessToken('refresh_token', ['refresh_token' => $refreshToken]);
                    $this->saveToken($employee->id, 'outlook', [
                        'accessToken' => $token->getToken(),
                        'refreshToken' => $token->getRefreshToken(),
                        'expiresAt' => (new \DateTime())->setTimestamp($token->getExpires())->format('Y-m-d H:i:s'),
                    ]);
                    return $token->getToken();
                } catch (\Exception $e) {
                    Craft::error('Outlook token refresh error: ' . $e->getMessage(), __METHOD__);
                    return null;
                }
            }

            return null;
        } finally {
            Craft::$app->getMutex()->release($mutexKey);
        }
    }

    public function getOutlookClient(?string $redirectUri = null): \League\OAuth2\Client\Provider\GenericProvider
    {
        $settings = Booked::getInstance()->getSettings();
        return new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId' => $settings->outlookCalendarClientId,
            'clientSecret' => $settings->outlookCalendarClientSecret,
            'redirectUri' => $redirectUri ?? $this->getRedirectUri(),
            'urlAuthorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'urlAccessToken' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'urlResourceOwnerDetails' => '',
            'scopes' => 'openid offline_access https://graph.microsoft.com/Calendars.ReadWrite',
            'scopeSeparator' => ' ',
        ]);
    }

    public function getGoogleClient(): GoogleClient
    {
        $settings = Booked::getInstance()->getSettings();

        if (empty($settings->googleCalendarClientId)) {
            Craft::error('Google Calendar Client ID is not configured in settings', __METHOD__);
            throw new InvalidConfigException('Google Calendar Client ID is required. Please configure it in Settings -> Booked -> Calendar.');
        }
        if (empty($settings->googleCalendarClientSecret)) {
            Craft::error('Google Calendar Client Secret is not configured in settings', __METHOD__);
            throw new InvalidConfigException('Google Calendar Client Secret is required. Please configure it in Settings -> Booked -> Calendar.');
        }

        Craft::info('Creating Google Client', __METHOD__);

        $client = new GoogleClient();
        $client->setClientId($settings->googleCalendarClientId);
        $client->setClientSecret($settings->googleCalendarClientSecret);
        $client->setRedirectUri($this->getRedirectUri());
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->addScope(GoogleCalendar::CALENDAR);

        return $client;
    }

    protected function getRedirectUri(): string
    {
        $redirectUri = \craft\helpers\UrlHelper::cpUrl('booked/calendar/callback');
        Craft::debug('OAuth Redirect URI: ' . $redirectUri, __METHOD__);
        return $redirectUri;
    }
}
