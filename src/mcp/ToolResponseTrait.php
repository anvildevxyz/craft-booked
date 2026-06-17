<?php

namespace anvildev\booked\mcp;

use anvildev\booked\mcp\support\Presenter;
use Craft;
use Throwable;

/**
 * Shared error handling for Booked's MCP tools.
 *
 * Booked's services throw typed exceptions (validation, conflict, not-found)
 * to drive the booking flow. Over MCP we never want those to surface as a
 * transport-level fault; {@see self::guard()} converts any throwable into a
 * structured `['error' => …]` payload the AI client can read and react to.
 *
 * The trait deliberately references nothing from the craft-mcp package, so a
 * tool class is fully usable (and unit-testable) even when that plugin is not
 * installed — the `#[McpTool]` attributes are only read reflectively by the
 * craft-mcp registry when it is present.
 */
trait ToolResponseTrait
{
    /**
     * Run a tool body, translating exceptions into an error response.
     *
     * @param \Closure(): array<string, mixed> $fn
     * @return array<string, mixed>
     */
    private function guard(\Closure $fn): array
    {
        try {
            /** @var array<string, mixed> $result */
            $result = Presenter::jsonSafe($fn());
            return $result;
        } catch (Throwable $e) {
            Craft::warning('Booked MCP tool failed: ' . $e->getMessage(), __METHOD__);

            // Only Booked's own typed exceptions carry client-safe, intentional messages.
            // Everything else (PDO/Yii/driver/internal) may embed SQL, schema or paths,
            // so it is reduced to a generic message — details stay in the server logs.
            $isBookedException = str_starts_with($e::class, 'anvildev\\booked\\exceptions\\');

            return [
                'error' => $isBookedException
                    ? $e->getMessage()
                    : 'An internal error occurred while running the tool; see the Booked/Craft logs for details.',
                'type' => (new \ReflectionClass($e))->getShortName(),
            ];
        }
    }

    private const RATE_LIMIT_MAX = 120;
    private const RATE_LIMIT_WINDOW = 3600;

    /**
     * Throttle for tools with real side effects (email/SMS/refunds), since
     * Booked's own IP-keyed rate limiting is unreachable in the MCP/console
     * context. Split into this peek and {@see self::recordRateLimitedCall()} so
     * callers record only after the operation succeeds — a failed call never
     * burns a slot. Distinct $keys ('notify', 'refund') keep one class of
     * side effect from locking out another.
     *
     * Peek only: returns true when the budget for $key is already exhausted.
     */
    private function rateLimitReached(string $key = 'notify', int $max = self::RATE_LIMIT_MAX): bool
    {
        $bucket = Craft::$app->getCache()->get("booked:mcp:ratelimit:{$key}");
        return is_array($bucket) && (int)($bucket['count'] ?? 0) >= $max;
    }

    /**
     * Record one successful call against the counter for $key. The window end is
     * pinned in the payload so later calls never extend the TTL (a fixed window,
     * not a sliding one), and the read-modify-write is mutex-serialised so
     * concurrent calls can't both slip past the ceiling.
     */
    private function recordRateLimitedCall(string $key = 'notify', int $windowSeconds = self::RATE_LIMIT_WINDOW): void
    {
        $cache = Craft::$app->getCache();
        $cacheKey = "booked:mcp:ratelimit:{$key}";
        $mutex = Craft::$app->getMutex();
        $lockKey = "booked:mcp:ratelimit-lock:{$key}";
        $locked = $mutex->acquire($lockKey, 2);

        try {
            $now = time();
            $bucket = $cache->get($cacheKey);
            if (!is_array($bucket) || (int)($bucket['expiresAt'] ?? 0) <= $now) {
                $cache->set($cacheKey, ['count' => 1, 'expiresAt' => $now + $windowSeconds], $windowSeconds);
                return;
            }

            $bucket['count'] = (int)$bucket['count'] + 1;
            // TTL tracks the original window end, never reset.
            $cache->set($cacheKey, $bucket, max(1, (int)$bucket['expiresAt'] - $now));
        } finally {
            if ($locked) {
                $mutex->release($lockKey);
            }
        }
    }

    /**
     * Standard rate-limit error payload.
     *
     * @return array{error: string}
     */
    private function rateLimitError(string $action): array
    {
        return ['error' => sprintf(
            'Booked MCP rate limit reached (%d/hour); pause before %s.',
            self::RATE_LIMIT_MAX,
            $action,
        )];
    }
}
