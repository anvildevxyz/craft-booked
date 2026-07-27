<?php

namespace anvildev\booked;

use yii\web\ForbiddenHttpException;

/**
 * Edition + capability gate — the single source of truth for what each edition
 * may do.
 *
 * Booked ships two editions: **Lite** (a lightweight "paid bookings for a small
 * business" tier) and **Pro** (the full product). Every capability listed here
 * ({@see self::CAP_*}) is Pro-only; Lite gets the always-on booking baseline
 * (core wizard, direct payments, multiple staff/locations, extras, a basic
 * revenue report). See docs/prd/booked-lite-edition-and-direct-payments.md §6.
 *
 * **Default-open.** Only an *explicit* Lite license is treated as non-Pro, so
 * anything else — an existing install (stored as `pro`), an unresolved instance,
 * or a dev/unlicensed environment — is treated as Pro. A mis-resolved edition
 * therefore never locks a customer out of a feature they paid for; it only ever
 * over-grants in dev.
 *
 * @since 1.4.0
 */
final class Editions
{
    public const LITE = 'lite';
    public const PRO = 'pro';

    // Pro-only capability handles. One exists for every place that actually
    // enforces or reports the boundary, so there are no dead constants.
    public const CAP_EVENTS = 'events';
    public const CAP_WAITLIST = 'waitlist';
    public const CAP_CALENDAR_SYNC = 'calendarSync';
    public const CAP_SMS = 'sms';
    public const CAP_VIRTUAL_MEETINGS = 'virtualMeetings';
    public const CAP_MULTI_DAY = 'multiDay';
    public const CAP_TIERED_REFUNDS = 'tieredRefunds';
    public const CAP_WEBHOOKS = 'webhooks';
    public const CAP_FULL_REPORTS = 'fullReports';
    public const CAP_GRAPHQL = 'graphql';
    public const CAP_MCP = 'mcp';
    public const CAP_COMMERCE = 'commerce';

    /**
     * The active edition handle (what the site is running as). Default-open: if
     * the plugin instance can't be resolved yet, treat it as Pro rather than
     * fatalling or silently downgrading.
     */
    public static function current(): string
    {
        return Booked::getInstance()?->edition ?? self::PRO;
    }

    /**
     * Whether the given (or active) edition is Pro. Only an explicit Lite
     * edition is non-Pro.
     */
    public static function isPro(?string $edition = null): bool
    {
        return ($edition ?? self::current()) !== self::LITE;
    }

    /** Whether the given (or active) edition is Lite. */
    public static function isLite(?string $edition = null): bool
    {
        return !self::isPro($edition);
    }

    /**
     * Whether the given (or active) edition may use a capability. Every gated
     * capability ({@see self::CAP_*}) is Pro-only.
     */
    public static function can(string $capability, ?string $edition = null): bool
    {
        return self::isPro($edition);
    }

    /**
     * Guard for Pro-only entry points (CP controllers, API actions). Throws a
     * 403 when the active edition is Lite; callers in a JSON/GraphQL context
     * surface it as an edition error. No-op under Pro.
     *
     * @throws ForbiddenHttpException
     */
    public static function requirePro(?string $message = null): void
    {
        if (!self::isPro()) {
            throw new ForbiddenHttpException($message ?? \Craft::t('booked', 'This feature requires Booked Pro.'));
        }
    }
}
