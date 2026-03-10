<?php

namespace anvildev\booked\services\calendar;

use anvildev\booked\contracts\ReservationInterface;

interface CalendarProviderInterface
{
    public function getProviderName(): string;

    public function getAuthUrl(string $stateToken): string;

    /** @return array{accessToken: string, refreshToken: ?string, expiresAt: string}|null */
    public function exchangeCode(string $code): ?array;

    /** @return array{accessToken: string, refreshToken: string, expiresAt: string}|null */
    public function refreshToken(string $refreshToken): ?array;

    /** @return array{success: bool, eventId: ?string, error: ?string} */
    public function createEvent(ReservationInterface $reservation, string $accessToken): array;

    /** @return array{success: bool, error: ?string} */
    public function updateEvent(ReservationInterface $reservation, string $externalEventId, string $accessToken): array;

    /** @return array{success: bool, error: ?string} */
    public function deleteEvent(string $externalEventId, string $accessToken): array;

    public function isConfigured(): bool;
}
