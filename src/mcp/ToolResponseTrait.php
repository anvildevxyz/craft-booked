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
            return [
                'error' => $e->getMessage(),
                'type' => (new \ReflectionClass($e))->getShortName(),
            ];
        }
    }
}
