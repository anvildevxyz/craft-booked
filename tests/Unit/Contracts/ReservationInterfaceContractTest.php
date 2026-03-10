<?php

namespace anvildev\booked\tests\Unit\Contracts;

use anvildev\booked\contracts\ReservationInterface;
use anvildev\booked\contracts\ReservationQueryInterface;
use anvildev\booked\models\db\ReservationModelQuery;
use anvildev\booked\models\ReservationModel;
use anvildev\booked\tests\Support\TestCase;

/**
 * Reservation Interface Contract Test
 *
 * Verifies that ReservationModel properly implements all methods defined in ReservationInterface.
 *
 * Note: Tests for Reservation Element are skipped as they require Commerce plugin.
 */
class ReservationInterfaceContractTest extends TestCase
{
    private function requiresCommerce(): void
    {
        $this->markTestSkipped('Requires Craft Commerce plugin');
    }

    // =========================================================================
    // Model Implements Interface
    // =========================================================================

    public function testReservationModelImplementsInterface(): void
    {
        $this->assertTrue(
            is_a(ReservationModel::class, ReservationInterface::class, true),
            'ReservationModel must implement ReservationInterface'
        );
    }

    public function testReservationModelQueryImplementsInterface(): void
    {
        $this->assertTrue(
            is_a(ReservationModelQuery::class, ReservationQueryInterface::class, true),
            'ReservationModelQuery must implement ReservationQueryInterface'
        );
    }

    // =========================================================================
    // Element Tests (Require Commerce - Skipped)
    // =========================================================================

    public function testReservationElementImplementsInterface(): void
    {
        $this->requiresCommerce();
    }

    public function testReservationQueryImplementsInterface(): void
    {
        $this->requiresCommerce();
    }

    // =========================================================================
    // Interface Method Coverage - ReservationInterface on Model
    // =========================================================================

    /**
     * @dataProvider reservationInterfaceMethodsProvider
     */
    public function testReservationModelHasAllInterfaceMethods(string $methodName): void
    {
        $this->assertTrue(
            method_exists(ReservationModel::class, $methodName),
            "ReservationModel is missing method: {$methodName}"
        );
    }

    public static function reservationInterfaceMethodsProvider(): array
    {
        return [
            // Identity
            ['getId'],
            ['getUid'],

            // Customer Data
            ['getUserName'],
            ['getUserEmail'],
            ['getUserPhone'],
            ['getUserId'],
            ['getUserTimezone'],
            ['customerEmail'],
            ['customerName'],

            // Booking Details
            ['getBookingDate'],
            ['getStartTime'],
            ['getEndTime'],
            ['getStatus'],
            ['getNotes'],
            ['getQuantity'],
            ['getConfirmationToken'],

            // Virtual Meeting
            ['getVirtualMeetingUrl'],
            ['getVirtualMeetingProvider'],
            ['getVirtualMeetingId'],

            // Calendar Integration
            ['getGoogleEventId'],
            ['getOutlookEventId'],

            // Notification Tracking
            ['getNotificationSent'],
            ['getEmailReminder24hSent'],
            ['getEmailReminder1hSent'],
            ['getSmsReminder24hSent'],
            ['getSmsConfirmationSent'],
            ['getSmsConfirmationSentAt'],
            ['getSmsCancellationSent'],
            ['getSmsDeliveryStatus'],

            // Foreign Keys
            ['getEmployeeId'],
            ['getLocationId'],
            ['getServiceId'],
            ['getEventDateId'],

            // Timestamps
            ['getDateCreated'],
            ['getDateUpdated'],

            // Relationships
            ['getService'],
            ['getEmployee'],
            ['getLocation'],
            ['getEventDate'],
            ['getUser'],
            ['getExtras'],

            // Business Logic
            ['cancel'],
            ['canBeCancelled'],
            ['getFormattedDateTime'],
            ['getDurationMinutes'],
            ['conflictsWith'],
            ['getBookingDateTime'],
            ['getStatusLabel'],
            ['isEventBased'],

            // URLs
            ['getManagementUrl'],
            ['getCancelUrl'],
            ['getIcsUrl'],
            ['getCpEditUrl'],

            // Pricing
            ['getExtrasPrice'],
            ['getExtrasSummary'],
            ['getTotalPrice'],
            ['getTotalDuration'],
            ['hasExtras'],

            // Persistence
            ['save'],
            ['delete'],
            ['validate'],
            ['getErrors'],
            ['hasErrors'],
            ['addError'],
        ];
    }

    // =========================================================================
    // Interface Method Coverage - ReservationQueryInterface on Model Query
    // =========================================================================

    /**
     * @dataProvider reservationQueryInterfaceMethodsProvider
     */
    public function testReservationModelQueryHasAllInterfaceMethods(string $methodName): void
    {
        $this->assertTrue(
            method_exists(ReservationModelQuery::class, $methodName),
            "ReservationModelQuery is missing method: {$methodName}"
        );
    }

    public static function reservationQueryInterfaceMethodsProvider(): array
    {
        return [
            // Filter Methods
            ['id'],
            ['userName'],
            ['userEmail'],
            ['userId'],
            ['bookingDate'],
            ['startTime'],
            ['endTime'],
            ['employeeId'],
            ['locationId'],
            ['serviceId'],
            ['eventDateId'],
            ['status'],
            ['reservationStatus'],
            ['confirmationToken'],
            ['forCurrentUser'],

            // Eager Loading
            ['withEmployee'],
            ['withService'],
            ['withLocation'],
            ['withRelations'],

            // Ordering
            ['orderBy'],

            // Pagination
            ['limit'],
            ['offset'],

            // Results
            ['one'],
            ['all'],
            ['count'],
            ['exists'],
            ['ids'],

            // Raw Query
            ['where'],
            ['andWhere'],
        ];
    }

    // =========================================================================
    // Instantiation
    // =========================================================================

    public function testCanInstantiateReservationModel(): void
    {
        $model = new ReservationModel();
        $this->assertInstanceOf(ReservationInterface::class, $model);
    }

    public function testCanInstantiateReservationModelQuery(): void
    {
        $this->requiresCraft();
        $query = new ReservationModelQuery();
        $this->assertInstanceOf(ReservationQueryInterface::class, $query);
    }

    // =========================================================================
    // Model Getter Return Types
    // =========================================================================

    public function testIdentityGettersReturnCorrectTypes(): void
    {
        $model = new ReservationModel();

        $this->assertNull($model->getId());
        $this->assertTrue(
            is_string($model->getUid()) || is_null($model->getUid())
        );
    }

    public function testCustomerDataGettersReturnStrings(): void
    {
        $model = new ReservationModel();

        $this->assertIsString($model->getUserName());
        $this->assertIsString($model->getUserEmail());
    }

    public function testBookingDetailsGettersReturnStrings(): void
    {
        $model = new ReservationModel();

        $this->assertIsString($model->getBookingDate());
        $this->assertIsString($model->getStartTime());
        $this->assertIsString($model->getEndTime());
    }

    public function testQuantityGetterReturnsInt(): void
    {
        $model = new ReservationModel();
        $this->assertIsInt($model->getQuantity());
    }

    public function testBooleanGettersReturnBools(): void
    {
        $model = new ReservationModel();

        $this->assertIsBool($model->getNotificationSent());
        $this->assertIsBool($model->getEmailReminder24hSent());
        $this->assertIsBool($model->isEventBased());
        $this->assertIsBool($model->hasExtras());
    }

    public function testDurationGettersReturnInts(): void
    {
        $model = new ReservationModel();

        $this->assertIsInt($model->getDurationMinutes());
        $this->assertIsInt($model->getTotalDuration());
    }

    public function testPriceGettersReturnFloats(): void
    {
        $model = new ReservationModel();

        $this->assertIsFloat($model->getExtrasPrice());
        $this->assertIsFloat($model->getTotalPrice());
    }

    public function testExtrasGettersReturnCorrectTypes(): void
    {
        $model = new ReservationModel();

        $this->assertIsArray($model->getExtras());
        $this->assertIsString($model->getExtrasSummary());
    }

    // =========================================================================
    // Business Logic on Model
    // =========================================================================

    public function testConflictsWithWorksBetweenModels(): void
    {
        $model1 = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $model2 = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:30',
            'endTime' => '15:30',
        ]);

        $this->assertTrue($model1->conflictsWith($model2));
        $this->assertTrue($model2->conflictsWith($model1));
    }

    public function testNoConflictWhenNoOverlap(): void
    {
        $model1 = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ]);

        $model2 = new ReservationModel([
            'bookingDate' => '2025-06-15',
            'startTime' => '16:00',
            'endTime' => '17:00',
        ]);

        $this->assertFalse($model1->conflictsWith($model2));
        $this->assertFalse($model2->conflictsWith($model1));
    }

    // =========================================================================
    // Status Methods
    // =========================================================================

    public function testModelHasGetStatuses(): void
    {
        $statuses = ReservationModel::getStatuses();

        $this->assertIsArray($statuses);
        $this->assertNotEmpty($statuses);
    }

    public function testStatusLabelWorksForModel(): void
    {
        $model = new ReservationModel(['status' => 'confirmed']);
        $this->assertIsString($model->getStatusLabel());
    }
}
