<?php

namespace anvildev\booked\services\calendar;

use anvildev\booked\Booked;
use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\helpers\DateHelper;
use Craft;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;

class GoogleCalendarProvider implements CalendarProviderInterface
{
    private ?GoogleClient $client = null;

    public function getProviderName(): string
    {
        return 'google';
    }

    public function getAuthUrl(string $stateToken): string
    {
        $client = clone $this->getClient();
        $client->setState($stateToken);
        return $client->createAuthUrl();
    }

    public function exchangeCode(string $code): ?array
    {
        try {
            $token = $this->getClient()->fetchAccessTokenWithAuthCode($code);

            if (isset($token['error'])) {
                Craft::error('Google OAuth error: ' . ($token['error_description'] ?? $token['error']), __METHOD__);
                return null;
            }

            return $this->formatToken($token['access_token'], $token['refresh_token'] ?? null, $token['expires_in']);
        } catch (\Exception $e) {
            Craft::error('Google OAuth exchange error: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    public function refreshToken(string $refreshToken): ?array
    {
        try {
            $client = $this->getClient();
            $client->fetchAccessTokenWithRefreshToken($refreshToken);
            $token = $client->getAccessToken();

            if (!$token || isset($token['error'])) {
                return null;
            }

            return $this->formatToken($token['access_token'], $token['refresh_token'] ?? $refreshToken, $token['expires_in']);
        } catch (\Exception $e) {
            Craft::error('Google token refresh error: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    public function createEvent(ReservationInterface $reservation, string $accessToken): array
    {
        try {
            $timezone = $reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone();

            $event = new Event([
                'summary' => $this->eventSummary($reservation),
                'description' => $this->eventDescription($reservation),
                'start' => $this->eventDateTime($reservation->bookingDate, $reservation->startTime, $timezone),
                'end' => $this->eventDateTime($reservation->bookingDate, $reservation->endTime, $timezone),
            ]);

            $createdEvent = $this->getCalendarService($accessToken)->events->insert('primary', $event);

            return ['success' => true, 'eventId' => $createdEvent->getId(), 'error' => null];
        } catch (\Exception $e) {
            Craft::error('Google Calendar create event error: ' . $e->getMessage(), __METHOD__);
            return ['success' => false, 'eventId' => null, 'error' => $e->getMessage()];
        }
    }

    public function updateEvent(ReservationInterface $reservation, string $externalEventId, string $accessToken): array
    {
        try {
            $timezone = $reservation->getLocation()?->timezone ?? Craft::$app->getTimeZone();
            $service = $this->getCalendarService($accessToken);
            $event = $service->events->get('primary', $externalEventId);
            $event->setSummary($this->eventSummary($reservation));
            $event->setDescription($this->eventDescription($reservation));
            $event->setStart(new EventDateTime($this->eventDateTime($reservation->bookingDate, $reservation->startTime, $timezone)));
            $event->setEnd(new EventDateTime($this->eventDateTime($reservation->bookingDate, $reservation->endTime, $timezone)));

            $service->events->update('primary', $externalEventId, $event);

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            Craft::error('Google Calendar update event error: ' . $e->getMessage(), __METHOD__);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function deleteEvent(string $externalEventId, string $accessToken): array
    {
        try {
            $this->getCalendarService($accessToken)->events->delete('primary', $externalEventId);
            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            Craft::error('Google Calendar delete event error: ' . $e->getMessage(), __METHOD__);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function isConfigured(): bool
    {
        $settings = Booked::getInstance()->getSettings();
        return !empty($settings->googleCalendarClientId) && !empty($settings->googleCalendarClientSecret);
    }

    private function getClient(): GoogleClient
    {
        if ($this->client === null) {
            $settings = Booked::getInstance()->getSettings();
            $this->client = new GoogleClient();
            $this->client->setClientId($settings->googleCalendarClientId);
            $this->client->setClientSecret($settings->googleCalendarClientSecret);
            $this->client->setRedirectUri($settings->googleRedirectUri);
            $this->client->setScopes([GoogleCalendar::CALENDAR]);
            $this->client->setAccessType('offline');
            $this->client->setPrompt('consent');
        }

        return $this->client;
    }

    private function getCalendarService(string $accessToken): GoogleCalendar
    {
        $client = clone $this->getClient();
        $client->setAccessToken($accessToken);
        return new GoogleCalendar($client);
    }

    private function formatToken(string $accessToken, ?string $refreshToken, int $expiresIn): array
    {
        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresAt' => (new \DateTime())->modify("+{$expiresIn} seconds")->format('Y-m-d H:i:s'),
        ];
    }

    private function eventSummary(ReservationInterface $reservation): string
    {
        return $reservation->getService()?->title ?? Craft::t('booked', 'element.booking');
    }

    private function eventDescription(ReservationInterface $reservation): string
    {
        return Craft::t('booked', 'calendar.customer', ['name' => $reservation->userName]) . "\n" .
               Craft::t('booked', 'ics.email', ['email' => $reservation->userEmail]) . "\n" .
               Craft::t('booked', 'ics.notes', ['notes' => $reservation->notes ?? '-']);
    }

    private function eventDateTime(string $date, string $time, string $timezone): array
    {
        return ['dateTime' => DateHelper::ensureSeconds("{$date}T{$time}"), 'timeZone' => $timezone];
    }
}
