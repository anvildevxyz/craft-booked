<?php

namespace anvildev\booked\services\calendar;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\helpers\DateHelper;
use Craft;
use League\OAuth2\Client\Provider\GenericProvider;

class OutlookCalendarProvider implements CalendarProviderInterface
{
    private ?GenericProvider $client = null;

    public function getProviderName(): string
    {
        return 'outlook';
    }

    public function getAuthUrl(string $stateToken): string
    {
        return $this->getClient()->getAuthorizationUrl([
            'state' => $stateToken,
            'scope' => ['openid', 'offline_access', 'https://graph.microsoft.com/Calendars.ReadWrite'],
        ]);
    }

    public function exchangeCode(string $code): ?array
    {
        try {
            $token = $this->getClient()->getAccessToken('authorization_code', ['code' => $code]);
            return $this->formatToken($token->getToken(), $token->getRefreshToken(), $token->getExpires());
        } catch (\Exception $e) {
            Craft::error('Outlook OAuth exchange error: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    public function refreshToken(string $refreshToken): ?array
    {
        try {
            $token = $this->getClient()->getAccessToken('refresh_token', ['refresh_token' => $refreshToken]);
            return $this->formatToken($token->getToken(), $token->getRefreshToken() ?? $refreshToken, $token->getExpires());
        } catch (\Exception $e) {
            Craft::error('Outlook token refresh error: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    public function createEvent(ReservationInterface $reservation, string $accessToken): array
    {
        try {
            $response = $this->graph($accessToken)
                ->createRequest('POST', '/me/events')
                ->attachBody($this->buildEventData($reservation))
                ->execute();

            return ['success' => true, 'eventId' => $response->getBody()['id'] ?? null, 'error' => null];
        } catch (\Exception $e) {
            Craft::error('Outlook Calendar create event error: ' . $e->getMessage(), __METHOD__);
            return ['success' => false, 'eventId' => null, 'error' => $e->getMessage()];
        }
    }

    public function updateEvent(ReservationInterface $reservation, string $externalEventId, string $accessToken): array
    {
        try {
            $this->graph($accessToken)
                ->createRequest('PATCH', '/me/events/' . rawurlencode($externalEventId))
                ->attachBody($this->buildEventData($reservation))
                ->execute();

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            Craft::error('Outlook Calendar update event error: ' . $e->getMessage(), __METHOD__);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function deleteEvent(string $externalEventId, string $accessToken): array
    {
        try {
            $this->graph($accessToken)
                ->createRequest('DELETE', '/me/events/' . rawurlencode($externalEventId))
                ->execute();

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            Craft::error('Outlook Calendar delete event error: ' . $e->getMessage(), __METHOD__);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function isConfigured(): bool
    {
        $settings = Booked::getInstance()->getSettings();
        return !empty($settings->outlookClientId) && !empty($settings->outlookClientSecret);
    }

    private function getClient(): GenericProvider
    {
        if ($this->client === null) {
            $settings = Booked::getInstance()->getSettings();
            $this->client = new GenericProvider([
                'clientId' => $settings->outlookClientId,
                'clientSecret' => $settings->outlookClientSecret,
                'redirectUri' => $settings->outlookRedirectUri,
                'urlAuthorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'urlAccessToken' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'urlResourceOwnerDetails' => '',
                'scopes' => 'openid offline_access https://graph.microsoft.com/Calendars.ReadWrite',
                'scopeSeparator' => ' ',
            ]);
        }

        return $this->client;
    }

    private function graph(string $accessToken): \Microsoft\Graph\Graph
    {
        $graph = new \Microsoft\Graph\Graph();
        $graph->setAccessToken($accessToken);
        return $graph;
    }

    private function formatToken(string $accessToken, ?string $refreshToken, int $expiresTimestamp): array
    {
        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresAt' => (new \DateTime())->setTimestamp($expiresTimestamp)->format('Y-m-d H:i:s'),
        ];
    }

    private function buildEventData(ReservationInterface $reservation): array
    {
        $timezone = $reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone();

        return [
            'subject' => $reservation->getService()?->title ?? Craft::t('booked', 'element.booking'),
            'body' => [
                'contentType' => 'HTML',
                'content' => Craft::t('booked', 'calendar.customer', ['name' => \craft\helpers\Html::encode($reservation->userName)]) . '<br>' .
                             Craft::t('booked', 'ics.email', ['email' => \craft\helpers\Html::encode($reservation->userEmail)]) . '<br>' .
                             Craft::t('booked', 'ics.notes', ['notes' => \craft\helpers\Html::encode($reservation->notes ?? '-')]),
            ],
            'start' => ['dateTime' => DateHelper::ensureSeconds("{$reservation->bookingDate}T{$reservation->startTime}"), 'timeZone' => DateHelper::toWindowsTimezone($timezone)],
            'end' => ['dateTime' => DateHelper::ensureSeconds("{$reservation->bookingDate}T{$reservation->endTime}"), 'timeZone' => DateHelper::toWindowsTimezone($timezone)],
        ];
    }
}
