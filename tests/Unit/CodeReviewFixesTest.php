<?php

namespace anvildev\booked\tests\Unit;

use anvildev\booked\tests\Support\TestCase;

/**
 * Tests for all critical fixes from CODE_REVIEW.md
 *
 * Uses source-code regression testing where full Craft init isn't available:
 * reads the actual source files and asserts the fix is present / the bug is absent.
 */
class CodeReviewFixesTest extends TestCase
{
    private function src(string $relativePath): string
    {
        return dirname(__DIR__, 2) . '/src/' . $relativePath;
    }

    private function readSource(string $relativePath): string
    {
        $path = $this->src($relativePath);
        $this->assertFileExists($path, "Source file not found: {$relativePath}");
        return file_get_contents($path);
    }

    private function webJs(string $relativePath): string
    {
        return $this->readSource('web/js/' . $relativePath);
    }

    // ────────────────────────────────────────────────────────
    // #1 — GraphQL ScheduleQuery bypasses schema authorization
    // ────────────────────────────────────────────────────────

    public function testScheduleQueryImportsGqlHelper(): void
    {
        $source = $this->readSource('gql/queries/ScheduleQuery.php');
        $this->assertStringContainsString(
            'use craft\\helpers\\Gql as GqlHelper',
            $source,
            'ScheduleQuery must import GqlHelper for authorization'
        );
    }

    public function testScheduleQueryChecksCanSchema(): void
    {
        $source = $this->readSource('gql/queries/ScheduleQuery.php');
        $this->assertStringContainsString(
            "GqlHelper::canSchema('bookedSchedules', 'read')",
            $source,
            'ScheduleQuery must check schema permissions before returning queries'
        );
    }

    public function testScheduleQueryReturnsEmptyWhenUnauthorized(): void
    {
        $source = $this->readSource('gql/queries/ScheduleQuery.php');
        // The pattern: check canSchema → return [] if unauthorized
        $this->assertMatchesRegularExpression(
            '/canSchema.*?return\s*\[\]/s',
            $source,
            'ScheduleQuery must return empty array when schema check fails'
        );
    }

    // ────────────────────────────────────────────────────────
    // #2 — GraphQL mutations leak internal error messages
    // ────────────────────────────────────────────────────────

    public function testReservationMutationsDoNotLeakExceptionMessages(): void
    {
        $source = $this->readSource('gql/mutations/ReservationMutations.php');

        // The generic \Exception catch blocks should NOT return $e->getMessage()
        // They should return a generic message instead
        preg_match_all('/catch\s*\(\\\\Exception\s+\$e\).*?\{(.*?)\}/s', $source, $matches);

        $this->assertNotEmpty($matches[1], 'Should have generic Exception catch blocks');

        foreach ($matches[1] as $catchBody) {
            // The catch body should NOT contain a direct return of $e->getMessage() to the client
            // It SHOULD log $e->getMessage() but return a generic message
            if (str_contains($catchBody, 'errorResponse') || str_contains($catchBody, 'errors')) {
                $this->assertStringNotContainsString(
                    "\$e->getMessage()",
                    // Only check the return/errorResponse line, not the Craft::error logging line
                    preg_replace('/Craft::error.*$/m', '', $catchBody),
                    'Generic Exception catch must not leak $e->getMessage() to client'
                );
            }
        }
    }

    public function testReservationMutationsReturnGenericErrorForInternalExceptions(): void
    {
        $source = $this->readSource('gql/mutations/ReservationMutations.php');
        $this->assertStringContainsString(
            'An internal error occurred. Please try again later.',
            $source,
            'Generic exceptions should return a safe, generic error message'
        );
    }

    // ────────────────────────────────────────────────────────
    // #3 — XSS in calendar sync Outlook event descriptions
    // ────────────────────────────────────────────────────────

    public function testOutlookProviderUsesHtmlEncode(): void
    {
        $source = $this->readSource('services/calendar/OutlookCalendarProvider.php');
        $this->assertStringContainsString(
            'Html::encode',
            $source,
            'OutlookCalendarProvider must use Html::encode for user data in HTML body'
        );
    }

    public function testOutlookProviderDoesNotInjectRawUserData(): void
    {
        $source = $this->readSource('services/calendar/OutlookCalendarProvider.php');
        // The buildEventData method's body content should not have raw $reservation->userName etc.
        // It should use Html::encode() wrapping
        preg_match('/function\s+buildEventData.*?\{(.*)\}/s', $source, $match);
        $this->assertNotEmpty($match[1], 'buildEventData method should exist');
        $bodySection = $match[1];
        // userName should be wrapped in Html::encode, not used raw in HTML
        $this->assertStringContainsString(
            'Html::encode($reservation->userName)',
            $bodySection,
            'userName must be HTML-encoded in Outlook event body'
        );
    }

    // ────────────────────────────────────────────────────────
    // #4 — XSS in CP service-schedules.js
    // ────────────────────────────────────────────────────────

    public function testServiceSchedulesJsHasEscapeHtmlFunction(): void
    {
        $source = $this->webJs('cp/service-schedules.js');
        $this->assertStringContainsString(
            'function escapeHtml',
            $source,
            'service-schedules.js must have escapeHtml utility function'
        );
    }

    public function testServiceSchedulesJsEscapesTitles(): void
    {
        $source = $this->webJs('cp/service-schedules.js');
        $this->assertStringContainsString(
            'escapeHtml(title)',
            $source,
            'Schedule title must be escaped with escapeHtml() before HTML insertion'
        );
    }

    // ────────────────────────────────────────────────────────
    // #5 — OAuth tokens stored in plaintext
    // ────────────────────────────────────────────────────────

    public function testCalendarTokenRecordEncryptsOnSave(): void
    {
        $source = $this->readSource('records/CalendarTokenRecord.php');
        $this->assertStringContainsString(
            'encryptByKey',
            $source,
            'CalendarTokenRecord must encrypt tokens in beforeSave()'
        );
    }

    public function testCalendarTokenRecordDecryptsOnFind(): void
    {
        $source = $this->readSource('records/CalendarTokenRecord.php');
        $this->assertStringContainsString(
            'decryptByKey',
            $source,
            'CalendarTokenRecord must decrypt tokens in afterFind()'
        );
    }

    public function testCalendarTokenRecordHandlesPreMigrationData(): void
    {
        $source = $this->readSource('records/CalendarTokenRecord.php');
        // afterFind should have try/catch for tokens that aren't encrypted yet
        $this->assertStringContainsString(
            'pre-migration data',
            $source,
            'CalendarTokenRecord afterFind() must handle pre-migration unencrypted tokens'
        );
    }

    // ────────────────────────────────────────────────────────
    // #7 — CSRF disabled entirely on CalendarConnectController
    // ────────────────────────────────────────────────────────

    public function testCalendarConnectControllerEnablesCsrfByDefault(): void
    {
        $source = $this->readSource('controllers/CalendarConnectController.php');
        $this->assertStringContainsString(
            '$enableCsrfValidation = true',
            $source,
            'CalendarConnectController must enable CSRF validation by default'
        );
    }

    public function testCalendarConnectControllerSelectivelyDisablesCsrf(): void
    {
        $source = $this->readSource('controllers/CalendarConnectController.php');
        $this->assertStringContainsString(
            'beforeAction',
            $source,
            'CalendarConnectController must use beforeAction() for selective CSRF disabling'
        );
        // Only callback/success/error should have CSRF disabled
        $this->assertStringContainsString('callback', $source);
        $this->assertStringContainsString('success', $source);
        $this->assertStringContainsString('error', $source);
    }

    // ────────────────────────────────────────────────────────
    // #8 — Timing-unsafe token comparisons
    // ────────────────────────────────────────────────────────

    public function testReservationMutationsUseHashEquals(): void
    {
        $source = $this->readSource('gql/mutations/ReservationMutations.php');
        $this->assertStringContainsString(
            'hash_equals',
            $source,
            'ReservationMutations must use hash_equals() for token comparison'
        );
        // Ensure no timing-unsafe !== for token comparison
        $this->assertStringNotContainsString(
            "->getConfirmationToken() !== \$token",
            $source,
            'Must NOT use !== for confirmation token comparison (timing attack)'
        );
    }

    public function testBookingManagementControllerUsesHashEquals(): void
    {
        $source = $this->readSource('controllers/BookingManagementController.php');
        $this->assertStringContainsString(
            'hash_equals',
            $source,
            'BookingManagementController must use hash_equals() for token comparison'
        );
        $this->assertStringNotContainsString(
            "->getConfirmationToken() !== \$token",
            $source,
            'Must NOT use !== for confirmation token comparison (timing attack)'
        );
    }

    // ────────────────────────────────────────────────────────
    // #9 — PII logged in BookingController
    // ────────────────────────────────────────────────────────

    public function testBookingControllerDoesNotLogPii(): void
    {
        $source = $this->readSource('controllers/BookingController.php');
        // Should NOT log full form attributes
        $this->assertStringNotContainsString(
            'json_encode($form->getAttributes())',
            $source,
            'BookingController must NOT log full form attributes (contains PII)'
        );
    }

    public function testBookingControllerLogsOnlyNonPiiFields(): void
    {
        $source = $this->readSource('controllers/BookingController.php');
        // The error log line should reference non-PII fields like serviceId, employeeId, date
        $this->assertStringContainsString('serviceId', $source);
        $this->assertStringContainsString('employeeId', $source);
        $this->assertStringContainsString('bookingDate', $source);
    }

    // ────────────────────────────────────────────────────────
    // #10 — updateReservation missing mutex lock
    // ────────────────────────────────────────────────────────

    public function testUpdateReservationUsesMutex(): void
    {
        $source = $this->readSource('services/BookingService.php');
        // Extract updateReservation method — use a broader match that captures the full method
        $pos = strpos($source, 'function updateReservation');
        $this->assertNotFalse($pos, 'updateReservation method should exist');
        $methodBody = substr($source, $pos, 2000);

        $this->assertStringContainsString(
            'mutex',
            strtolower($methodBody),
            'updateReservation must use mutex locking'
        );
        $this->assertStringContainsString(
            'acquire',
            $methodBody,
            'updateReservation must acquire a mutex lock'
        );
    }

    public function testUpdateReservationUsesTransaction(): void
    {
        $source = $this->readSource('services/BookingService.php');
        $pos = strpos($source, 'function updateReservation');
        $methodBody = substr($source, $pos, 2000);

        $this->assertStringContainsString(
            'beginTransaction',
            $methodBody,
            'updateReservation must wrap operations in a DB transaction'
        );
    }

    // ────────────────────────────────────────────────────────
    // #11 — updateReservation incomplete availability check
    // ────────────────────────────────────────────────────────

    public function testUpdateReservationPassesAllIdsToAvailabilityCheck(): void
    {
        $source = $this->readSource('services/BookingService.php');
        $pos = strpos($source, 'function updateReservation');
        $methodBody = substr($source, $pos, 3000);

        // The availability check should include employeeId, locationId, serviceId
        $this->assertStringContainsString('employeeId', $methodBody);
        $this->assertStringContainsString('locationId', $methodBody);
        $this->assertStringContainsString('serviceId', $methodBody);

        // Verify isSlotAvailable is called with these parameters
        $this->assertStringContainsString(
            'isSlotAvailable',
            $methodBody,
            'updateReservation must call isSlotAvailable'
        );
    }

    // ────────────────────────────────────────────────────────
    // #12 — Calendar reschedule bypasses availability check
    // ────────────────────────────────────────────────────────

    public function testCalendarViewRescheduleValidatesDateFormat(): void
    {
        $source = $this->readSource('controllers/cp/CalendarViewController.php');
        // The reschedule action should validate date format with regex
        $this->assertStringContainsString(
            'preg_match',
            $source,
            'CalendarViewController reschedule must validate date format'
        );
    }

    public function testCalendarViewRescheduleChecksAvailability(): void
    {
        $source = $this->readSource('controllers/cp/CalendarViewController.php');
        // Reschedule delegates to BookingService::updateReservation which has
        // mutex locking and availability checking built in.
        $this->assertStringContainsString(
            'updateReservation',
            $source,
            'CalendarViewController reschedule must delegate to BookingService::updateReservation'
        );
    }

    public function testCalendarViewRescheduleValidatesTimeFormat(): void
    {
        $source = $this->readSource('controllers/cp/CalendarViewController.php');
        // Time format regex for HH:MM
        $this->assertMatchesRegularExpression(
            '/preg_match.*\\\\d\{2\}.*\\\\d\{2\}/',
            $source,
            'CalendarViewController must validate time format with regex'
        );
    }

    // ────────────────────────────────────────────────────────
    // #13 — N+1 queries in afterFind() on Reservation and EventDate
    // ────────────────────────────────────────────────────────

    public function testReservationDoesNotOverrideAfterFind(): void
    {
        $source = $this->readSource('elements/Reservation.php');
        // afterFind should NOT exist as it caused N+1 queries
        $this->assertStringNotContainsString(
            'function afterFind',
            $source,
            'Reservation element must NOT override afterFind() (causes N+1 queries)'
        );
    }

    public function testEventDateDoesNotOverrideAfterFind(): void
    {
        $source = $this->readSource('elements/EventDate.php');
        $this->assertStringNotContainsString(
            'function afterFind',
            $source,
            'EventDate element must NOT override afterFind() (causes N+1 queries)'
        );
    }

    // ────────────────────────────────────────────────────────
    // #14 — Missing siteId('*') on non-localized element lookups
    // ────────────────────────────────────────────────────────

    public function testReservationElementUsesSiteIdWildcard(): void
    {
        $source = $this->readSource('elements/Reservation.php');
        // Employee and Location lookups in Reservation should use siteId('*')
        $this->assertStringContainsString(
            "siteId('*')",
            $source,
            'Reservation element must use siteId(\'*\') for non-localized element lookups'
        );
    }

    public function testEmployeeElementUsesSiteIdWildcard(): void
    {
        $source = $this->readSource('elements/Employee.php');
        $this->assertStringContainsString(
            "siteId('*')",
            $source,
            'Employee element must use siteId(\'*\') for non-localized element lookups'
        );
    }

    public function testEventDateElementUsesSiteIdWildcard(): void
    {
        $source = $this->readSource('elements/EventDate.php');
        $this->assertStringContainsString(
            "siteId('*')",
            $source,
            'EventDate element must use siteId(\'*\') for non-localized element lookups'
        );
    }

    public function testBlackoutDateElementUsesSiteIdWildcard(): void
    {
        $source = $this->readSource('elements/BlackoutDate.php');
        $this->assertStringContainsString(
            "siteId('*')",
            $source,
            'BlackoutDate element must use siteId(\'*\') for non-localized element lookups'
        );
    }

    public function testReservationModelUsesSiteIdWildcard(): void
    {
        $source = $this->readSource('models/ReservationModel.php');
        $this->assertStringContainsString(
            "siteId('*')",
            $source,
            'ReservationModel must use siteId(\'*\') for non-localized element lookups'
        );
    }

    // ────────────────────────────────────────────────────────
    // #15 — Hardcoded timezones (covered in TimezoneTest.php)
    // These are additional regression checks
    // ────────────────────────────────────────────────────────

    public function testNoHardcodedEuropeZurichAsDefaultInCalendarSync(): void
    {
        $source = $this->readSource('services/CalendarSyncService.php');
        // Remove the timezone mapping table (it legitimately contains 'Europe/Zurich' as a key)
        $sourceWithoutMap = preg_replace('/\$map\s*=\s*\[.*?\];/s', '', $source);
        $this->assertStringNotContainsString(
            "'Europe/Zurich'",
            $sourceWithoutMap,
            'CalendarSyncService must not use Europe/Zurich as a hardcoded default (mapping table is OK)'
        );
    }

    public function testNoHardcodedEuropeBerlinInGoogleProvider(): void
    {
        $source = $this->readSource('services/calendar/GoogleCalendarProvider.php');
        $this->assertStringNotContainsString(
            "'Europe/Berlin'",
            $source,
            'GoogleCalendarProvider must not hardcode Europe/Berlin timezone'
        );
    }

    public function testNoHardcodedEuropeZurichInVirtualMeetingService(): void
    {
        $source = $this->readSource('services/VirtualMeetingService.php');
        $this->assertStringNotContainsString(
            "'Europe/Zurich'",
            $source,
            'VirtualMeetingService must not hardcode Europe/Zurich timezone'
        );
    }

    // ────────────────────────────────────────────────────────
    // #16 — Hardcoded German strings
    // ────────────────────────────────────────────────────────

    public function testBookingFormUsesTranslation(): void
    {
        $source = $this->readSource('models/forms/BookingForm.php');
        // Validation messages should use Yii::t() instead of German text
        $this->assertStringNotContainsString(
            'Dieses Feld',
            $source,
            'BookingForm must not contain hardcoded German strings'
        );
        $this->assertStringNotContainsString(
            'Bitte geben',
            $source,
            'BookingForm must not contain hardcoded German strings'
        );
    }

    public function testBookingFormUsesYiiTranslate(): void
    {
        $source = $this->readSource('models/forms/BookingForm.php');
        $this->assertStringContainsString(
            "Yii::t('booked'",
            $source,
            'BookingForm validation messages must use Yii::t() for i18n'
        );
    }

    public function testBookingWizardJsUsesConfigMessages(): void
    {
        $source = $this->webJs('booking-wizard.js');
        // Should not contain hardcoded German Flatpickr titles
        $this->assertStringNotContainsString(
            'Nicht verfügbar',
            $source,
            'booking-wizard.js must not contain hardcoded German strings'
        );
        $this->assertStringNotContainsString(
            'Ausgebucht',
            $source,
            'booking-wizard.js must not contain hardcoded German strings'
        );
    }

    public function testBookingWizardJsUsesConfigMessagesForAvailability(): void
    {
        $source = $this->webJs('booking-wizard.js');
        // Should use config.messages pattern
        $this->assertStringContainsString(
            'config.messages',
            $source,
            'booking-wizard.js must use config.messages for translated strings'
        );
    }

    // ────────────────────────────────────────────────────────
    // #17 — ICS PRODID typo
    // ────────────────────────────────────────────────────────

    public function testIcsHelperHasCorrectProdid(): void
    {
        $source = $this->readSource('helpers/IcsHelper.php');
        $this->assertStringContainsString(
            'PRODID',
            $source,
            'IcsHelper must have correct PRODID (not PROID) per RFC 5545'
        );
        $this->assertStringNotContainsString(
            'PROID',
            $source,
            'IcsHelper must not have PROID typo'
        );
    }

    public function testIcsHelperUsesEnLocale(): void
    {
        $source = $this->readSource('helpers/IcsHelper.php');
        $this->assertStringContainsString(
            '//EN',
            $source,
            'IcsHelper PRODID must use //EN locale, not //DE'
        );
    }

    // ────────────────────────────────────────────────────────
    // #18 — Availability calendar endpoint unbounded date range (DoS)
    // ────────────────────────────────────────────────────────

    public function testSlotControllerValidatesDateFormat(): void
    {
        $source = $this->readSource('controllers/SlotController.php');
        // Should validate date format with regex
        $this->assertStringContainsString(
            'preg_match',
            $source,
            'SlotController must validate date format'
        );
        $this->assertMatchesRegularExpression(
            '/\\\\d\{4\}-\\\\d\{2\}-\\\\d\{2\}/',
            $source,
            'SlotController must validate Y-m-d date format via regex'
        );
    }

    public function testSlotControllerCapsDateRange(): void
    {
        $source = $this->readSource('controllers/SlotController.php');
        $this->assertStringContainsString(
            'P90D',
            $source,
            'SlotController must cap date range to 90 days'
        );
    }

    public function testSlotControllerThrowsOnInvalidDate(): void
    {
        $source = $this->readSource('controllers/SlotController.php');
        $this->assertStringContainsString(
            'BadRequestHttpException',
            $source,
            'SlotController must throw BadRequestHttpException for invalid dates'
        );
    }

    // ────────────────────────────────────────────────────────
    // Cross-cutting: ensure no new hardcoded timezones introduced
    // ────────────────────────────────────────────────────────

    public function testIcsHelperDoesNotHardcodeTimezone(): void
    {
        $source = $this->readSource('helpers/IcsHelper.php');
        $this->assertStringNotContainsString(
            "'Europe/Zurich'",
            $source,
            'IcsHelper must not hardcode Europe/Zurich'
        );
    }

    public function testReservationModelDoesNotHardcodeTimezone(): void
    {
        $source = $this->readSource('models/ReservationModel.php');
        $this->assertStringNotContainsString(
            "'Europe/Zurich'",
            $source,
            'ReservationModel must not hardcode Europe/Zurich'
        );
    }

    public function testReservationElementDoesNotHardcodeTimezone(): void
    {
        $source = $this->readSource('elements/Reservation.php');
        $this->assertStringNotContainsString(
            "'Europe/Zurich'",
            $source,
            'Reservation element must not hardcode Europe/Zurich'
        );
    }

    // ────────────────────────────────────────────────────────
    // C1 — Employee.afterDelete uses wrong column name
    // ────────────────────────────────────────────────────────

    public function testEmployeeAfterDeleteUsesCorrectColumnName(): void
    {
        $source = $this->readSource('elements/Employee.php');
        $this->assertStringContainsString("'managedEmployeeId'", $source,
            'Employee.afterDelete must reference managedEmployeeId column, not managerId');
        $this->assertStringNotContainsString("'managerId'", $source,
            'Employee.afterDelete must not reference non-existent managerId column');
    }

    // ────────────────────────────────────────────────────────
    // H1 — SMS job non-atomic tracking update
    // ────────────────────────────────────────────────────────

    public function testSendSmsJobUsesAtomicUpdate(): void
    {
        $source = $this->readSource('queue/jobs/SendSmsJob.php');
        $this->assertStringContainsString('createCommand()->update', $source,
            'SendSmsJob must use atomic DB update for tracking, not save(false)');
        $this->assertStringNotContainsString('->save(false)', $source,
            'SendSmsJob must not use save(false) for tracking updates');
    }

    // ────────────────────────────────────────────────────────
    // H3 — GraphQL quantity mutations missing upper-bound cap
    // ────────────────────────────────────────────────────────

    public function testQuantityMutationsCapUpperBound(): void
    {
        $source = $this->readSource('gql/mutations/QuantityMutations.php');
        $this->assertStringContainsString('min($reduceBy', $source,
            'GraphQL reduceQuantity must cap upper bound');
        $this->assertStringContainsString('min($increaseBy', $source,
            'GraphQL increaseQuantity must cap upper bound');
    }

    // ────────────────────────────────────────────────────────
    // H5 — Calendar sync save(false) without error handling
    // ────────────────────────────────────────────────────────

    public function testCalendarSyncUsesDirectDbUpdate(): void
    {
        $source = $this->readSource('Booked.php');
        $pos = strpos($source, 'EVENT_AFTER_CALENDAR_SYNC');
        $this->assertNotFalse($pos);
        $listenerBody = substr($source, $pos, 1500);
        $this->assertStringContainsString('createCommand()->update', $listenerBody,
            'Calendar sync listener must use direct DB update, not save(false)');
        $this->assertStringNotContainsString('->save(false)', $listenerBody,
            'Calendar sync listener must not use save(false)');
    }

    // ────────────────────────────────────────────────────────
    // H6 — XSS in service-schedules modal translation strings
    // ────────────────────────────────────────────────────────

    public function testServiceSchedulesModalEscapesStrings(): void
    {
        $source = $this->webJs('cp/service-schedules.js');
        // The h1 uses a ternary: escapeHtml(isNew ? strings.addSchedule : strings.editSchedule)
        $this->assertStringContainsString("escapeHtml(isNew ? strings.addSchedule : strings.editSchedule)", $source);
        $this->assertStringContainsString("escapeHtml(strings.title)", $source);
        $this->assertStringContainsString("escapeHtml(strings.startDate)", $source);
        $this->assertStringContainsString("escapeHtml(strings.endDate)", $source);
        $this->assertStringContainsString("escapeHtml(strings.capacity)", $source);
        $this->assertStringContainsString("escapeHtml(strings.cancel)", $source);
        $this->assertStringContainsString("escapeHtml(strings.saveSchedule)", $source);
    }

    // ────────────────────────────────────────────────────────
    // M4 — Missing status validation in updateReservation
    // ────────────────────────────────────────────────────────

    public function testUpdateReservationValidatesStatus(): void
    {
        $source = $this->readSource('services/BookingService.php');
        $pos = strpos($source, 'function updateReservation');
        $this->assertNotFalse($pos);
        $methodBody = substr($source, $pos, 3000);
        $this->assertStringContainsString('STATUS_CONFIRMED', $methodBody,
            'updateReservation must validate status against allowed values');
        $this->assertStringContainsString('STATUS_CANCELLED', $methodBody,
            'updateReservation must validate status against allowed values');
        // Validate against $data['status'] (incoming), not $reservation->status (already assigned)
        $this->assertStringContainsString("\$data['status']", $methodBody,
            'updateReservation must validate $data[\'status\'], not $reservation->status');
    }

    // ────────────────────────────────────────────────────────
    // M3 — SettingsRecord double-encryption on key rotation
    // ────────────────────────────────────────────────────────

    public function testSettingsRecordNullsOnDecryptionFailure(): void
    {
        $source = $this->readSource('records/SettingsRecord.php');
        $pos = strpos($source, 'function afterFind');
        $this->assertNotFalse($pos);
        $methodBody = substr($source, $pos, 1500);
        $this->assertStringContainsString('= null', $methodBody,
            'afterFind must set field to null on decryption failure to prevent double-encryption');
    }

    // ────────────────────────────────────────────────────────
    // M1 — EmployeeQuery uses fragile LIKE for JSON serviceIds
    // ────────────────────────────────────────────────────────

    public function testEmployeeQueryUsesJsonContains(): void
    {
        $source = $this->readSource('elements/db/EmployeeQuery.php');
        $this->assertStringContainsString('JSON_CONTAINS', $source,
            'EmployeeQuery serviceId filter must use JSON_CONTAINS instead of LIKE patterns');
    }

    // ────────────────────────────────────────────────────────
    // M5 — Location missing max-length constraints
    // ────────────────────────────────────────────────────────

    public function testLocationDefineRulesHasMaxLength(): void
    {
        $source = $this->readSource('elements/Location.php');
        $pos = strpos($source, 'function defineRules');
        $this->assertNotFalse($pos);
        $methodBody = substr($source, $pos, 1000);
        $this->assertStringContainsString("'max'", $methodBody,
            'Location defineRules must include max-length constraints');
    }

    // ────────────────────────────────────────────────────────
    // M8 — Booking wizard resetWizard missing soft lock release
    // ────────────────────────────────────────────────────────

    public function testBookingWizardResetReleaseSoftLock(): void
    {
        $source = $this->webJs('booking-wizard.js');
        $pos = strpos($source, 'resetWizard()');
        $this->assertNotFalse($pos);
        $methodBody = substr($source, $pos, 500);
        $this->assertStringContainsString('releaseSoftLock', $methodBody,
            'booking-wizard resetWizard must release soft lock before resetting');
        $this->assertStringContainsString('softLockToken = null', $methodBody,
            'booking-wizard resetWizard must clear softLockToken');
    }

    // ────────────────────────────────────────────────────────
    // M10 — SendWebhookJob canRetry queries DB on every retry
    // ────────────────────────────────────────────────────────

    public function testWebhookJobCanRetryDoesNotQueryDb(): void
    {
        $source = $this->readSource('queue/jobs/SendWebhookJob.php');
        $pos = strpos($source, 'function canRetry');
        $this->assertNotFalse($pos);
        $methodBody = substr($source, $pos, 300);
        $this->assertStringNotContainsString('findOne', $methodBody,
            'canRetry must not query database — use cached maxRetries property');
        $this->assertStringContainsString('maxRetries', $methodBody,
            'canRetry must use maxRetries property');
    }

    // ────────────────────────────────────────────────────────
    // M7 — Availability calendar max range too permissive
    // ────────────────────────────────────────────────────────

    public function testAvailabilityCalendarMaxRange90Days(): void
    {
        $source = $this->readSource('controllers/SlotController.php');
        $this->assertStringContainsString('P90D', $source,
            'Availability calendar max range should be 90 days, not 180');
        $this->assertStringNotContainsString('P180D', $source,
            'Availability calendar max range of 180 days is too permissive');
    }
}
