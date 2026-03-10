<?php

namespace anvildev\booked\services;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use Craft;
use craft\base\Component;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleEvent;

/**
 * Creates, updates, and deletes virtual meeting links for reservations via Zoom, Google Meet, and Microsoft Teams.
 */
class VirtualMeetingService extends Component
{
    private ?\GuzzleHttp\Client $httpClient = null;

    private function getHttpClient(): \GuzzleHttp\Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 10,
            ]);
        }

        return $this->httpClient;
    }

    /** @return array{url: string|null, id: string, provider: string}|null */
    public function createMeeting(ReservationInterface $reservation, string $provider): ?array
    {
        $result = match ($provider) {
            'zoom' => $this->createZoomMeeting($reservation),
            'google' => $this->createGoogleMeetLink($reservation),
            'teams' => $this->createTeamsMeeting($reservation),
            default => null,
        };

        if ($result) {
            return [
                'url' => $result['url'],
                'id' => $result['id'],
                'provider' => $provider,
            ];
        }

        return null;
    }

    /** @return array{url: string, id: string}|null */
    protected function createZoomMeeting(ReservationInterface $reservation): ?array
    {
        if (!Booked::getInstance()->getSettings()->zoomEnabled) {
            Craft::warning('Zoom meeting creation skipped: Zoom is not enabled', __METHOD__);
            return null;
        }

        $token = $this->getZoomAccessToken();
        if (!$token) {
            Craft::warning('Zoom meeting creation skipped: Could not get access token', __METHOD__);
            return null;
        }

        $startTime = $this->ensureSeconds($reservation->startTime);

        try {
            Craft::info('Creating Zoom meeting for reservation ' . $reservation->id, __METHOD__);

            $response = $this->getHttpClient()->post('https://api.zoom.us/v2/users/me/meetings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'topic' => $reservation->getService()?->title ?? 'Booking',
                    'type' => 2,
                    'start_time' => $reservation->bookingDate . 'T' . $startTime,
                    'duration' => $reservation->getDurationMinutes(),
                    'timezone' => $reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone(),
                    'settings' => [
                        'join_before_host' => true,
                        'mute_upon_entry' => true,
                        'waiting_room' => false,
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!isset($data['join_url'], $data['id'])) {
                Craft::error('Zoom response missing expected fields: ' . json_encode(array_keys($data ?? [])), __METHOD__);
                return null;
            }
            return ['url' => $data['join_url'], 'id' => (string)$data['id']];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = $e->getResponse()->getBody()->getContents();
            Craft::error('Zoom meeting creation failed: ' . $e->getMessage() . ' - Response: ' . $body, __METHOD__);
            return null;
        } catch (\Exception $e) {
            Craft::error('Zoom meeting creation failed (general): ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /** @return array{url: string|null, id: string}|null */
    protected function createGoogleMeetLink(ReservationInterface $reservation): ?array
    {
        $employee = $reservation->getEmployee();
        if (!$employee) {
            Craft::warning('Google Meet creation skipped: No employee assigned to reservation', __METHOD__);
            return null;
        }

        $syncService = Booked::getInstance()->getCalendarSync();
        $token = $syncService->getAccessToken($employee, 'google');
        if (!$token) {
            Craft::warning('Google Meet creation skipped: No Google access token for employee ' . $employee->id, __METHOD__);
            return null;
        }

        try {
            $client = $syncService->getGoogleClient();
        } catch (\yii\base\InvalidConfigException $e) {
            Craft::warning('Google Meet creation skipped: ' . $e->getMessage(), __METHOD__);
            return null;
        }
        $client->setAccessToken($token);

        $startTime = $this->ensureSeconds($reservation->startTime);
        $endTime = $this->ensureSeconds($reservation->endTime);
        $timezone = $reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone();

        $event = new GoogleEvent([
            'summary' => $reservation->getService()?->title ?? 'Booking',
            'description' => 'Virtual meeting for booking #' . $reservation->id,
            'start' => ['dateTime' => $reservation->bookingDate . 'T' . $startTime, 'timeZone' => $timezone],
            'end' => ['dateTime' => $reservation->bookingDate . 'T' . $endTime, 'timeZone' => $timezone],
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => 'booked-' . $reservation->id . '-' . time(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
        ]);

        try {
            Craft::info('Creating Google Meet event for reservation ' . $reservation->id . ' with start: ' . $reservation->bookingDate . 'T' . $startTime, __METHOD__);

            $createdEvent = (new GoogleCalendar($client))->events->insert('primary', $event, ['conferenceDataVersion' => 1]);
            $meetLink = $createdEvent->getHangoutLink();

            if (!$meetLink) {
                Craft::warning('Google event created but no Meet link returned. Event ID: ' . $createdEvent->getId(), __METHOD__);
            }

            return ['url' => $meetLink, 'id' => $createdEvent->getId()];
        } catch (\Exception $e) {
            Craft::error('Google Meet creation failed: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /** @return array{url: string, id: string}|null */
    protected function createTeamsMeeting(ReservationInterface $reservation): ?array
    {
        if (!Booked::getInstance()->getSettings()->teamsEnabled) {
            Craft::warning('Teams meeting creation skipped: Teams is not enabled', __METHOD__);
            return null;
        }

        $employee = $reservation->getEmployee();
        if (!$employee) {
            Craft::warning('Teams meeting creation skipped: No employee assigned to reservation', __METHOD__);
            return null;
        }

        $employeeEmail = $employee->email;
        if (empty($employeeEmail)) {
            Craft::warning('Teams meeting creation skipped: Employee has no email configured', __METHOD__);
            return null;
        }

        $token = $this->getTeamsAccessToken();
        if (!$token) {
            Craft::warning('Teams meeting creation skipped: Could not get access token', __METHOD__);
            return null;
        }

        $startTime = $this->ensureSeconds($reservation->startTime);
        $endTime = $this->ensureSeconds($reservation->endTime);
        $timezone = $reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone();

        try {
            Craft::info('Creating Teams meeting for reservation ' . $reservation->id . ' via user ' . $employeeEmail, __METHOD__);

            $response = $this->getHttpClient()->post("https://graph.microsoft.com/v1.0/users/" . rawurlencode($employeeEmail) . "/onlineMeetings", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'subject' => $reservation->getService()?->title ?? 'Booking',
                    'startDateTime' => (new \DateTime($reservation->bookingDate . 'T' . $startTime, new \DateTimeZone($timezone)))->format('c'),
                    'endDateTime' => (new \DateTime($reservation->bookingDate . 'T' . $endTime, new \DateTimeZone($timezone)))->format('c'),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!isset($data['joinWebUrl'], $data['id'])) {
                Craft::error('Teams response missing expected fields: ' . json_encode(array_keys($data ?? [])), __METHOD__);
                return null;
            }

            return ['url' => $data['joinWebUrl'], 'id' => (string)$data['id']];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = $e->getResponse()->getBody()->getContents();
            Craft::error('Teams meeting creation failed: ' . $e->getMessage() . ' - Response: ' . $body, __METHOD__);
            return null;
        } catch (\Exception $e) {
            Craft::error('Teams meeting creation failed (general): ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    public function deleteMeeting(ReservationInterface $reservation): void
    {
        $meetingId = $reservation->virtualMeetingId;
        $provider = $reservation->virtualMeetingProvider;

        if (empty($meetingId) || empty($provider)) {
            return;
        }

        try {
            match ($provider) {
                'zoom' => $this->deleteZoomMeeting($meetingId),
                'google' => $this->deleteGoogleMeetEvent($reservation, $meetingId),
                'teams' => $this->deleteTeamsMeeting($reservation, $meetingId),
                default => Craft::warning("Unknown virtual meeting provider '{$provider}' for deletion", __METHOD__),
            };
        } catch (\Exception $e) {
            Craft::warning("Failed to delete {$provider} meeting {$meetingId}: {$e->getMessage()}", __METHOD__);
        }
    }

    public function updateMeeting(ReservationInterface $reservation): void
    {
        $meetingId = $reservation->virtualMeetingId;
        $provider = $reservation->virtualMeetingProvider;

        if (empty($meetingId) || empty($provider)) {
            return;
        }

        try {
            match ($provider) {
                'zoom' => $this->updateZoomMeeting($reservation, $meetingId),
                'google' => $this->updateGoogleMeetEvent($reservation, $meetingId),
                'teams' => $this->updateTeamsMeeting($reservation, $meetingId),
                default => Craft::warning("Unknown virtual meeting provider '{$provider}' for update", __METHOD__),
            };
        } catch (\Exception $e) {
            Craft::warning("Failed to update {$provider} meeting {$meetingId}: {$e->getMessage()}", __METHOD__);
        }
    }

    private function deleteZoomMeeting(string $meetingId): void
    {
        $token = $this->getZoomAccessToken();
        if (!$token) {
            Craft::warning('Zoom meeting deletion skipped: Could not get access token', __METHOD__);
            return;
        }

        Craft::info('Deleting Zoom meeting ' . $meetingId, __METHOD__);

        $this->getHttpClient()->delete('https://api.zoom.us/v2/meetings/' . rawurlencode($meetingId), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);
    }

    private function deleteGoogleMeetEvent(ReservationInterface $reservation, string $eventId): void
    {
        $employee = $reservation->getEmployee();
        if (!$employee) {
            Craft::warning('Google Meet deletion skipped: No employee assigned to reservation', __METHOD__);
            return;
        }

        $syncService = Booked::getInstance()->getCalendarSync();
        $token = $syncService->getAccessToken($employee, 'google');
        if (!$token) {
            Craft::warning('Google Meet deletion skipped: No Google access token for employee ' . $employee->id, __METHOD__);
            return;
        }

        try {
            $client = $syncService->getGoogleClient();
        } catch (\yii\base\InvalidConfigException $e) {
            Craft::warning('Google Meet deletion skipped: ' . $e->getMessage(), __METHOD__);
            return;
        }
        $client->setAccessToken($token);

        Craft::info('Deleting Google Calendar event ' . $eventId, __METHOD__);

        (new GoogleCalendar($client))->events->delete('primary', $eventId);
    }

    private function deleteTeamsMeeting(ReservationInterface $reservation, string $meetingId): void
    {
        $employee = $reservation->getEmployee();
        if (!$employee) {
            Craft::warning('Teams meeting deletion skipped: No employee assigned to reservation', __METHOD__);
            return;
        }

        $employeeEmail = $employee->email;
        if (empty($employeeEmail)) {
            Craft::warning('Teams meeting deletion skipped: Employee has no email configured', __METHOD__);
            return;
        }

        $token = $this->getTeamsAccessToken();
        if (!$token) {
            Craft::warning('Teams meeting deletion skipped: Could not get access token', __METHOD__);
            return;
        }

        Craft::info('Deleting Teams meeting ' . $meetingId . ' via user ' . $employeeEmail, __METHOD__);

        $this->getHttpClient()->delete("https://graph.microsoft.com/v1.0/users/" . rawurlencode($employeeEmail) . "/onlineMeetings/" . rawurlencode($meetingId), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);
    }

    private function updateZoomMeeting(ReservationInterface $reservation, string $meetingId): void
    {
        $token = $this->getZoomAccessToken();
        if (!$token) {
            Craft::warning('Zoom meeting update skipped: Could not get access token', __METHOD__);
            return;
        }

        $startTime = $this->ensureSeconds($reservation->startTime);

        Craft::info('Updating Zoom meeting ' . $meetingId, __METHOD__);

        $this->getHttpClient()->patch('https://api.zoom.us/v2/meetings/' . rawurlencode($meetingId), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'topic' => $reservation->getService()?->title ?? 'Booking',
                'start_time' => $reservation->bookingDate . 'T' . $startTime,
                'duration' => $reservation->getDurationMinutes(),
                'timezone' => $reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone(),
            ],
        ]);
    }

    private function updateGoogleMeetEvent(ReservationInterface $reservation, string $eventId): void
    {
        $employee = $reservation->getEmployee();
        if (!$employee) {
            Craft::warning('Google Meet update skipped: No employee assigned to reservation', __METHOD__);
            return;
        }

        $syncService = Booked::getInstance()->getCalendarSync();
        $token = $syncService->getAccessToken($employee, 'google');
        if (!$token) {
            Craft::warning('Google Meet update skipped: No Google access token for employee ' . $employee->id, __METHOD__);
            return;
        }

        try {
            $client = $syncService->getGoogleClient();
        } catch (\yii\base\InvalidConfigException $e) {
            Craft::warning('Google Meet update skipped: ' . $e->getMessage(), __METHOD__);
            return;
        }
        $client->setAccessToken($token);

        $startTime = $this->ensureSeconds($reservation->startTime);
        $endTime = $this->ensureSeconds($reservation->endTime);
        $timezone = $reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone();

        $event = new GoogleEvent([
            'summary' => $reservation->getService()?->title ?? 'Booking',
            'start' => ['dateTime' => $reservation->bookingDate . 'T' . $startTime, 'timeZone' => $timezone],
            'end' => ['dateTime' => $reservation->bookingDate . 'T' . $endTime, 'timeZone' => $timezone],
        ]);

        Craft::info('Updating Google Calendar event ' . $eventId . ' with start: ' . $reservation->bookingDate . 'T' . $startTime, __METHOD__);

        (new GoogleCalendar($client))->events->patch('primary', $eventId, $event);
    }

    private function updateTeamsMeeting(ReservationInterface $reservation, string $meetingId): void
    {
        $employee = $reservation->getEmployee();
        if (!$employee) {
            Craft::warning('Teams meeting update skipped: No employee assigned to reservation', __METHOD__);
            return;
        }

        $employeeEmail = $employee->email;
        if (empty($employeeEmail)) {
            Craft::warning('Teams meeting update skipped: Employee has no email configured', __METHOD__);
            return;
        }

        $token = $this->getTeamsAccessToken();
        if (!$token) {
            Craft::warning('Teams meeting update skipped: Could not get access token', __METHOD__);
            return;
        }

        $startTime = $this->ensureSeconds($reservation->startTime);
        $endTime = $this->ensureSeconds($reservation->endTime);
        $timezone = $reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone();

        Craft::info('Updating Teams meeting ' . $meetingId . ' via user ' . $employeeEmail, __METHOD__);

        $this->getHttpClient()->patch("https://graph.microsoft.com/v1.0/users/" . rawurlencode($employeeEmail) . "/onlineMeetings/" . rawurlencode($meetingId), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'subject' => $reservation->getService()?->title ?? 'Booking',
                'startDateTime' => (new \DateTime($reservation->bookingDate . 'T' . $startTime, new \DateTimeZone($timezone)))->format('c'),
                'endDateTime' => (new \DateTime($reservation->bookingDate . 'T' . $endTime, new \DateTimeZone($timezone)))->format('c'),
            ],
        ]);
    }

    /** Append :00 seconds to HH:MM time strings */
    private function ensureSeconds(string $time): string
    {
        return substr_count($time, ':') === 1 ? $time . ':00' : $time;
    }

    protected function getTeamsAccessToken(): ?string
    {
        $settings = Booked::getInstance()->getSettings();
        $cacheKey = 'booked_teams_access_token_' . md5(implode('|', [(string) $settings->teamsTenantId, $settings->teamsClientId, $settings->teamsClientSecret]));

        $token = Craft::$app->cache->get($cacheKey);
        if ($token) {
            return $token;
        }

        if (empty($settings->teamsTenantId) || empty($settings->teamsClientId) || empty($settings->teamsClientSecret)) {
            Craft::error('Teams credentials not configured. Please check Settings -> Booked -> Virtual Meetings.', __METHOD__);
            return null;
        }

        try {
            if (!preg_match('/^[a-f0-9\-]{36}$/i', $settings->teamsTenantId)
                && !preg_match('/^[a-z0-9-]+\.onmicrosoft\.com$/i', $settings->teamsTenantId)) {
                Craft::error('Invalid Teams tenant ID format', __METHOD__);
                return null;
            }

            Craft::info('Requesting Teams access token for tenant: ' . substr($settings->teamsTenantId, 0, 8) . '...', __METHOD__);

            $response = $this->getHttpClient()->post("https://login.microsoftonline.com/{$settings->teamsTenantId}/oauth2/v2.0/token", [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $settings->teamsClientId,
                    'client_secret' => $settings->teamsClientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!isset($data['access_token'], $data['expires_in'])) {
                Craft::error('Teams token response missing expected fields', __METHOD__);
                return null;
            }

            Craft::$app->cache->set($cacheKey, $data['access_token'], $data['expires_in'] - 60);
            Craft::info('Teams access token obtained successfully', __METHOD__);
            return $data['access_token'];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = $e->getResponse()->getBody()->getContents();
            Craft::error('Teams auth failed: ' . $e->getMessage(), __METHOD__);
            Craft::error('Teams error response: ' . $body, __METHOD__);
            return null;
        } catch (\Exception $e) {
            Craft::error('Teams auth failed (general): ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    protected function getZoomAccessToken(): ?string
    {
        $settings = Booked::getInstance()->getSettings();
        $cacheKey = 'booked_zoom_access_token_' . md5(implode('|', [(string) $settings->zoomAccountId, $settings->zoomClientId, $settings->zoomClientSecret]));

        $token = Craft::$app->cache->get($cacheKey);
        if ($token) {
            return $token;
        }

        if (empty($settings->zoomAccountId) || empty($settings->zoomClientId) || empty($settings->zoomClientSecret)) {
            Craft::error('Zoom credentials not configured. Please check Settings -> Booked -> Virtual Meetings.', __METHOD__);
            Craft::error('Missing: ' .
                (empty($settings->zoomAccountId) ? 'Account ID, ' : '') .
                (empty($settings->zoomClientId) ? 'Client ID, ' : '') .
                (empty($settings->zoomClientSecret) ? 'Client Secret' : ''), __METHOD__);
            return null;
        }

        try {
            Craft::info('Requesting Zoom access token for account: ' . substr($settings->zoomAccountId, 0, 8) . '...', __METHOD__);

            $response = $this->getHttpClient()->post('https://zoom.us/oauth/token', [
                'form_params' => [
                    'grant_type' => 'account_credentials',
                    'account_id' => $settings->zoomAccountId,
                ],
                'auth' => [$settings->zoomClientId, $settings->zoomClientSecret],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (!isset($data['access_token'], $data['expires_in'])) {
                Craft::error('Zoom token response missing expected fields', __METHOD__);
                return null;
            }

            Craft::$app->cache->set($cacheKey, $data['access_token'], $data['expires_in'] - 60);
            Craft::info('Zoom access token obtained successfully', __METHOD__);
            return $data['access_token'];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = $e->getResponse()->getBody()->getContents();
            Craft::error('Zoom auth failed (400/401): ' . $e->getMessage(), __METHOD__);
            Craft::error('Zoom error response: ' . $body, __METHOD__);
            Craft::error('Check your Zoom credentials: Account ID, Client ID, Client Secret must match your Server-to-Server OAuth app in Zoom Marketplace.', __METHOD__);
            return null;
        } catch (\Exception $e) {
            Craft::error('Zoom auth failed (general): ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }
}
