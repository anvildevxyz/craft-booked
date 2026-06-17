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

    /**
     * Cache-backed throttle for tools that send real notifications (email/SMS).
     *
     * Booked's own rate limiting is IP-keyed and unreachable in the MCP/console
     * context, so without this an untrusted client could loop a notifying tool
     * and spam arbitrary addresses. Returns false once $max calls have been
     * recorded for $key within the rolling window.
     */
    private function withinRateLimit(string $key, int $max, int $windowSeconds = 3600): bool
    {
        $cache = Craft::$app->getCache();
        $cacheKey = "booked:mcp:ratelimit:{$key}";
        $count = (int)$cache->get($cacheKey);

        if ($count >= $max) {
            return false;
        }

        $cache->set($cacheKey, $count + 1, $windowSeconds);
        return true;
    }
}
