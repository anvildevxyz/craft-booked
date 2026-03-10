<?php

namespace anvildev\booked\tests\Unit\Records;

use anvildev\booked\records\CalendarTokenRecord;
use anvildev\booked\records\EmployeeRecord;
use anvildev\booked\records\EmployeeScheduleAssignmentRecord;
use anvildev\booked\records\EventDateRecord;
use anvildev\booked\records\LocationRecord;
use anvildev\booked\records\OAuthStateTokenRecord;
use anvildev\booked\records\ReservationExtraRecord;
use anvildev\booked\records\ScheduleRecord;
use anvildev\booked\records\ServiceExtraRecord;
use anvildev\booked\records\ServiceExtraServiceRecord;
use anvildev\booked\records\ServiceRecord;
use anvildev\booked\records\ServiceScheduleAssignmentRecord;
use anvildev\booked\records\SettingsRecord;
use anvildev\booked\records\SoftLockRecord;
use anvildev\booked\tests\Support\TestCase;

/**
 * Tests for records with minimal pure logic (tableName only).
 * Instance methods on these records require DB access.
 */
class SimpleRecordsTest extends TestCase
{
    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    // =========================================================================
    // tableName() for all simple records
    // =========================================================================

    public function testEmployeeRecordTableName(): void
    {
        $this->assertEquals('{{%booked_employees}}', EmployeeRecord::tableName());
    }

    public function testServiceRecordTableName(): void
    {
        $this->assertEquals('{{%booked_services}}', ServiceRecord::tableName());
    }

    public function testLocationRecordTableName(): void
    {
        $this->assertEquals('{{%booked_locations}}', LocationRecord::tableName());
    }

    public function testScheduleRecordTableName(): void
    {
        $this->assertEquals('{{%booked_schedules}}', ScheduleRecord::tableName());
    }

    public function testEventDateRecordTableName(): void
    {
        $this->assertEquals('{{%booked_event_dates}}', EventDateRecord::tableName());
    }

    public function testServiceExtraRecordTableName(): void
    {
        $this->assertEquals('{{%booked_service_extras}}', ServiceExtraRecord::tableName());
    }

    public function testServiceExtraServiceRecordTableName(): void
    {
        $this->assertEquals('{{%booked_service_extras_services}}', ServiceExtraServiceRecord::tableName());
    }

    public function testReservationExtraRecordTableName(): void
    {
        $this->assertEquals('{{%booked_reservation_extras}}', ReservationExtraRecord::tableName());
    }

    public function testSettingsRecordTableName(): void
    {
        $this->assertEquals('{{%booked_settings}}', SettingsRecord::tableName());
    }

    public function testCalendarTokenRecordTableName(): void
    {
        $this->assertEquals('{{%booked_calendar_tokens}}', CalendarTokenRecord::tableName());
    }

    public function testOAuthStateTokenRecordTableName(): void
    {
        $this->assertEquals('{{%booked_oauth_state_tokens}}', OAuthStateTokenRecord::tableName());
    }

    public function testSoftLockRecordTableName(): void
    {
        $this->assertEquals('{{%booked_soft_locks}}', SoftLockRecord::tableName());
    }

    public function testEmployeeScheduleAssignmentRecordTableName(): void
    {
        $this->assertEquals('{{%booked_employee_schedule_assignments}}', EmployeeScheduleAssignmentRecord::tableName());
    }

    public function testServiceScheduleAssignmentRecordTableName(): void
    {
        $this->assertEquals('{{%booked_service_schedule_assignments}}', ServiceScheduleAssignmentRecord::tableName());
    }
}
