<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\services\CustomerService;
use anvildev\booked\tests\Support\TestCase;

/**
 * The customer index is an aggregation over bookings — there is no customer
 * table — so most of what can go wrong is in the SQL and in how a customer is
 * identified. Executing it needs a database, so these assert the properties
 * that decide whether the numbers come out right.
 */
class CustomerServiceTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/src/services/CustomerService.php');
    }

    private function controllerSource(): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/src/controllers/cp/CustomersController.php');
    }

    private function templateSource(string $name): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/src/templates/customers/' . $name);
    }

    /**
     * Source with comments removed. A docblock that names a hazard must not be
     * read as the hazard — this test file was failing on its own explanation of
     * why urldecode() is wrong here.
     */
    private function codeOnly(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * Every SQL-looking string literal in the file.
     *
     * @return list<string>
     */
    private function sqlLiterals(string $source): array
    {
        $literals = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = trim($token[1], "'\"");
            if (preg_match('/\b(SELECT|SUM|COUNT|MIN|MAX|LOWER|CASE WHEN)\b|\[\[/', $value)) {
                $literals[] = $value;
            }
        }

        return $literals;
    }

    /**
     * `firstBooking` was dropped: SORTABLE advertised it, but the index table
     * has no "First booking" column to hang a sort link on, so the backend
     * accepted a sort no part of the UI could ever send.
     */
    public function testSortableColumnsAreDeclared(): void
    {
        $this->assertSame(
            ['name', 'email', 'totalBookings', 'lastBooking'],
            CustomerService::SORTABLE,
        );
    }

    /**
     * A sort column reaches ORDER BY, so anything outside the allowlist has to
     * be rejected rather than interpolated.
     */
    public function testUnknownSortFallsBackRatherThanReachingTheQuery(): void
    {
        $this->assertStringContainsString(
            "if (!in_array(\$sort, self::SORTABLE, true)) {",
            $this->source(),
            'search() must reject a sort column it does not recognise',
        );
    }

    /**
     * Email is the customer identity. Someone booking as `Ann@` and later
     * `ann@` is one person, and comparing case-sensitively splits their
     * history — and their spend — in two.
     */
    public function testEmailIsMatchedCaseInsensitively(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('LOWER(', $source);
        $this->assertStringContainsString('mb_strtolower', $source);
    }

    /**
     * Postgres folds unquoted identifiers to lowercase, so every camelCase
     * column in raw SQL has to be bracket-quoted for Yii to quote it properly.
     */
    public function testCamelCaseColumnsAreBracketQuotedForPostgres(): void
    {
        $columns = ['userEmail', 'userName', 'userPhone', 'employeeId', 'bookingDate', 'refundedAmount', 'reservationId', 'dateCreated', 'userId'];
        $unquoted = [];

        foreach ($this->sqlLiterals($this->source()) as $sql) {
            foreach ($columns as $column) {
                if (preg_match('/(?<!\[\[)\b' . $column . '\b(?!\]\])/', $sql)) {
                    $unquoted[] = "{$column} in \"{$sql}\"";
                }
            }
        }

        $this->assertSame([], $unquoted, 'These columns appear unquoted in SQL and will break on Postgres');
    }

    /**
     * `SUM(status = 'x')` is MySQL-only and `FILTER` is Postgres-only.
     */
    public function testConditionalCountsUsePortableSql(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('SUM(CASE WHEN', $source);
        $this->assertStringNotContainsString('FILTER (WHERE', $source);
    }

    /**
     * Staff are scoped to the employees they manage on the bookings index, and
     * the customer list shows the same records grouped differently — leaving it
     * unscoped would leak every customer to a limited user.
     */
    public function testTheListIsScopedToWhatTheUserMaySee(): void
    {
        $this->assertStringContainsString('getStaffEmployeeIds()', $this->sourceOfMethod(CustomerService::class, 'employeeScopeCondition'));
        $this->assertStringContainsString('scopeReservationQuery', $this->source());
    }

    /**
     * baseQuery() being scoped is not enough: attachLatestDetails() and
     * attachSpend() each re-query the reservations/payments tables from
     * scratch, filtered only by the emails baseQuery() found. Without their
     * own employeeId check, a staff member scoped to one employee sees a
     * shared customer's name, phone, linked account, or spend pulled from a
     * *different* employee's booking they have no permission to view — this
     * shipped once as exactly that gap.
     */
    public function testLatestDetailsAndSpendReapplyTheEmployeeScope(): void
    {
        $this->assertStringContainsString(
            'employeeScopeCondition(',
            $this->sourceOfMethod(CustomerService::class, 'attachLatestDetails'),
        );
        $this->assertStringContainsString(
            'employeeScopeCondition(',
            $this->sourceOfMethod(CustomerService::class, 'attachSpend'),
        );
        $this->assertStringContainsString(
            'employeeScopeCondition(',
            $this->sourceOfMethod(CustomerService::class, 'baseQuery'),
        );
    }

    /**
     * Only settled money counts. A pending intent is not revenue, and counting
     * it would overstate what a customer has paid.
     */
    public function testSpendCountsOnlySettledPaymentsNetOfRefunds(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('STATUS_PAID', $source);
        $this->assertStringContainsString('STATUS_PARTIALLY_REFUNDED', $source);
        $this->assertStringNotContainsString('STATUS_PENDING', $source);
        $this->assertStringContainsString('p.[[amount]] - p.[[refundedAmount]]', $source);
    }

    /**
     * A customer's payments can only be summed as one number when they are
     * all the same currency. Summing across currencies into one minor-units
     * total, then labelling it with whichever currency sorts first, produces
     * a number that is simply wrong — so a stray payment in another currency
     * must be excluded from the sum, not swept into it.
     */
    public function testSpendIsScopedToOneCurrency(): void
    {
        $body = $this->sourceOfMethod(CustomerService::class, 'attachSpend');

        $this->assertStringContainsString('p.[[currency]]', $body);
        $this->assertStringNotContainsString(
            'MIN(p.[[currency]])',
            $body,
            'Labelling a multi-currency sum with an arbitrary currency is the bug, not the fix',
        );
    }

    /**
     * The displayed name comes from the newest booking. An aggregate cannot say
     * "the name they used last" — MIN() gives the alphabetically first, which
     * is how a customer ends up filed under a name they used once.
     *
     * "Last" means when the booking was entered (dateCreated), not the date it
     * books (bookingDate): a correction entered today for next month is more
     * current than an untouched booking made last week for next year.
     */
    public function testDisplayNameComesFromTheMostRecentlyEnteredBooking(): void
    {
        $body = $this->sourceOfMethod(CustomerService::class, 'attachLatestDetails');

        $this->assertStringContainsString('attachLatestDetails', $this->source());
        $this->assertMatchesRegularExpression(
            '/orderBy\(\[\'\[\[dateCreated\]\]\'\s*=>\s*SORT_ASC/',
            $body,
            'dateCreated must be the primary sort key, not a tiebreaker behind bookingDate',
        );
    }

    /**
     * SUM(CASE WHEN bookingDate >= :today …) has to compare against "today" in
     * the site's timezone, not the PHP process's default one — otherwise near
     * local midnight a booking for the real local "today" can be counted on
     * the wrong side of the upcoming/past line.
     */
    public function testUpcomingCountUsesTheSiteTimezoneNotTheProcessDefault(): void
    {
        $body = $this->sourceOfMethod(CustomerService::class, 'summaryQuery');

        $this->assertStringContainsString('Craft::$app->getTimeZone()', $body);
        $this->assertStringContainsString('DateTimeZone', $body);
    }

    /**
     * Minor-units-to-major-units conversion has to go through
     * PaymentService::fromMinorUnits(), which special-cases zero-decimal
     * currencies (JPY, KRW, …) where the minor unit already is the major
     * unit. A template-side `/ 100` is wrong for exactly those currencies,
     * and wrong in both templates that show spend, not just one.
     */
    public function testTemplatesDoNotDivideMinorUnitsThemselves(): void
    {
        foreach (['_index.twig', 'detail.twig'] as $template) {
            $source = $this->templateSource($template);
            $this->assertStringNotContainsString(
                '/ 100',
                $source,
                "{$template} must not convert minor units itself — fromMinorUnits() already did it",
            );
            $this->assertStringContainsString('paidAmount', $source);
        }

        $this->assertStringContainsString('PaymentService::fromMinorUnits(', $this->source());
    }

    /**
     * A linked Craft user's name, email, and CP edit link are exposure this
     * view adds beyond what booked-viewBookings grants elsewhere in the
     * plugin — gating it on the Users-section permission keeps a staff member
     * who can only view bookings from getting a deep link into the Users CP
     * area for every customer with an account.
     */
    public function testLinkedAccountIdentityIsGatedOnUsersPermission(): void
    {
        $this->assertStringContainsString("can('editUsers')", $this->controllerSource());
    }

    /**
     * ReservationModelQuery wraps a bare, alias-less ActiveQuery on
     * ReservationRecord. Referencing an `r.` alias there — correct for the
     * raw Query() aggregation, wrong here — would 500 on every detail page.
     */
    public function testBookingsForDoesNotReferenceAnAliasTheModelQueryDoesNotHave(): void
    {
        $this->assertStringContainsString("emailMatches(\$email, '')", $this->source());
    }

    /**
     * Yii percent-decodes a route parameter before the action sees it. Decoding
     * again turns `+` into a space, so `first+tag@example.com` — ordinary
     * plus-addressing — silently becomes an address that matches nobody. This
     * shipped, and the detail page 404'd for every customer with a `+`.
     */
    public function testTheDetailActionDoesNotDecodeTheEmailTwice(): void
    {
        $this->assertStringNotContainsString(
            'urldecode(',
            $this->codeOnly($this->controllerSource()),
            'The route parameter is already decoded; decoding again breaks plus-addressed emails',
        );
    }

    public function testTheControllerRequiresPermissionToViewBookings(): void
    {
        $this->assertStringContainsString(
            "requirePermission('booked-viewBookings')",
            $this->controllerSource(),
        );
    }

    public function testRoutesAndNavAreRegistered(): void
    {
        $plugin = file_get_contents(dirname(__DIR__, 3) . '/src/Booked.php');

        $this->assertStringContainsString("'booked/customers' => 'booked/cp/customers/index'", $plugin);
        $this->assertStringContainsString("'booked/customers/<email:.+>' => 'booked/cp/customers/detail'", $plugin);
        $this->assertStringContainsString("'nav.customers'", $plugin);
    }
}
