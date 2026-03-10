<?php

namespace anvildev\booked\tests\Unit\Services;

use anvildev\booked\elements\Employee;
use anvildev\booked\records\EmployeeManagerRecord;
use anvildev\booked\services\PermissionService;
use anvildev\booked\tests\Support\TestCase;

/**
 * Managed Employee Scoping Test
 *
 * Tests the managed employees feature end-to-end:
 * - Employee-to-employee manager relationship
 * - PermissionService resolves own + managed employees
 * - Scoping logic applies correct filters for single vs multiple employees
 * - Filter options are scoped for staff (services, locations, employees)
 */
class ManagedEmployeeScopingTest extends TestCase
{
    public function testEmployeeManagerRecordHasCorrectTableName(): void
    {
        $this->assertEquals(
            '{{%booked_employee_managers}}',
            EmployeeManagerRecord::tableName()
        );
    }

    public function testEmployeeManagerRecordRequiresEmployeeId(): void
    {
        $record = new EmployeeManagerRecord();
        $rules = $record->rules();

        $requiredFields = $this->extractFieldsForValidator($rules, 'required');
        $this->assertContains('employeeId', $requiredFields);
    }

    public function testEmployeeManagerRecordRequiresManagedEmployeeId(): void
    {
        $record = new EmployeeManagerRecord();
        $rules = $record->rules();

        $requiredFields = $this->extractFieldsForValidator($rules, 'required');
        $this->assertContains('managedEmployeeId', $requiredFields);
    }

    public function testEmployeeManagerRecordDoesNotRequireUserId(): void
    {
        $record = new EmployeeManagerRecord();
        $rules = $record->rules();

        $requiredFields = $this->extractFieldsForValidator($rules, 'required');
        $this->assertNotContains(
            'userId',
            $requiredFields,
            'EmployeeManagerRecord should not have a userId field — it links employees to employees, not to users'
        );
    }

    public function testEmployeeManagerRecordBothFieldsAreIntegers(): void
    {
        $record = new EmployeeManagerRecord();
        $rules = $record->rules();

        $integerFields = $this->extractFieldsForValidator($rules, 'integer');
        $this->assertContains('employeeId', $integerFields);
        $this->assertContains('managedEmployeeId', $integerFields);
    }

    // =========================================================================
    // PermissionService: getEmployeesForUser Contract
    // =========================================================================

    public function testGetEmployeesForUserAlwaysReturnsArray(): void
    {
        $this->requiresCraft();

        $service = new PermissionService();
        $result = $service->getEmployeesForUser(999999);

        $this->assertIsArray($result);
    }

    public function testGetEmployeesForUserResultIsCached(): void
    {
        $this->requiresCraft();

        $service = new PermissionService();
        $first = $service->getEmployeesForUser(12345);
        $second = $service->getEmployeesForUser(12345);

        // Same array reference from cache
        $this->assertSame($first, $second);
    }

    public function testGetEmployeesForUserReturnsDifferentResultsPerUserId(): void
    {
        $this->requiresCraft();

        $service = new PermissionService();

        $reflection = new \ReflectionClass($service);
        $cacheProperty = $reflection->getProperty('employeesCache');
        $cacheProperty->setAccessible(true);

        $service->getEmployeesForUser(111);
        $service->getEmployeesForUser(222);

        $cache = $cacheProperty->getValue($service);
        $this->assertCount(2, $cache);
        $this->assertArrayHasKey(111, $cache);
        $this->assertArrayHasKey(222, $cache);
    }

    // =========================================================================
    // PermissionService: scopeReservationQuery Branch Logic
    // =========================================================================

    public function testScopeQueryNullMeansFullAccess(): void
    {
        $this->requiresCraft();

        $service = \Mockery::mock(PermissionService::class)->makePartial();
        $service->shouldReceive('getStaffEmployeeIds')->andReturn(null);

        $query = \Mockery::mock();
        $query->shouldNotReceive('employeeId');
        $query->shouldNotReceive('andWhere');

        $result = $service->scopeReservationQuery($query);
        $this->assertSame($query, $result);
    }

    public function testScopeQuerySingleIdUsesAndWhere(): void
    {
        $this->requiresCraft();

        $service = \Mockery::mock(PermissionService::class)->makePartial();
        $service->shouldReceive('getStaffEmployeeIds')->andReturn([50]);

        $query = \Mockery::mock();
        $query->shouldNotReceive('employeeId');
        $query->shouldReceive('andWhere')->with(['employeeId' => [50]])->once()->andReturnSelf();

        $service->scopeReservationQuery($query);
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function testScopeQueryMultipleIdsUsesAndWhere(): void
    {
        $this->requiresCraft();

        $service = \Mockery::mock(PermissionService::class)->makePartial();
        $service->shouldReceive('getStaffEmployeeIds')->andReturn([10, 20, 30]);

        $query = \Mockery::mock();
        $query->shouldNotReceive('employeeId');
        $query->shouldReceive('andWhere')->with(['employeeId' => [10, 20, 30]])->once()->andReturnSelf();

        $service->scopeReservationQuery($query);
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function testScopeQueryTwoIdsUsesAndWhere(): void
    {
        $this->requiresCraft();

        $service = \Mockery::mock(PermissionService::class)->makePartial();
        $service->shouldReceive('getStaffEmployeeIds')->andReturn([7, 14]);

        $query = \Mockery::mock();
        $query->shouldNotReceive('employeeId');
        $query->shouldReceive('andWhere')->with(['employeeId' => [7, 14]])->once()->andReturnSelf();

        $service->scopeReservationQuery($query);
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    // =========================================================================
    // PermissionService: getStaffEmployeeIds Contract
    // =========================================================================

    public function testGetStaffEmployeeIdsReturnsNullForNonStaff(): void
    {
        $this->requiresCraft();

        $service = \Mockery::mock(PermissionService::class)->makePartial();
        $service->shouldReceive('isStaffMember')->andReturn(false);

        $this->assertNull($service->getStaffEmployeeIds());
    }

    public function testGetStaffEmployeeIdsReturnsArrayForStaff(): void
    {
        $this->requiresCraft();

        $employee = \Mockery::mock(Employee::class);
        $employee->id = 42;

        $service = \Mockery::mock(PermissionService::class)->makePartial();
        $service->shouldReceive('isStaffMember')->andReturn(true);
        $service->shouldReceive('getEmployeesForCurrentUser')->andReturn([$employee]);

        $result = $service->getStaffEmployeeIds();
        $this->assertEquals([42], $result);
    }

    public function testGetStaffEmployeeIdsReturnsMultipleIds(): void
    {
        $this->requiresCraft();

        $emp1 = \Mockery::mock(Employee::class);
        $emp1->id = 10;
        $emp2 = \Mockery::mock(Employee::class);
        $emp2->id = 20;
        $emp3 = \Mockery::mock(Employee::class);
        $emp3->id = 30;

        $service = \Mockery::mock(PermissionService::class)->makePartial();
        $service->shouldReceive('isStaffMember')->andReturn(true);
        $service->shouldReceive('getEmployeesForCurrentUser')->andReturn([$emp1, $emp2, $emp3]);

        $result = $service->getStaffEmployeeIds();
        $this->assertEquals([10, 20, 30], $result);
    }

    // =========================================================================
    // Employee Element: serviceIds and locationId (used for filter scoping)
    // =========================================================================

    public function testEmployeeHasServiceIdsProperty(): void
    {
        $this->assertTrue(
            property_exists(Employee::class, 'serviceIds'),
            'Employee must have a serviceIds property for service filter scoping'
        );
    }

    public function testEmployeeHasLocationIdProperty(): void
    {
        $this->assertTrue(
            property_exists(Employee::class, 'locationId'),
            'Employee must have a locationId property for location filter scoping'
        );
    }

    public function testEmployeeHasGetServiceIdsMethod(): void
    {
        $this->assertTrue(
            method_exists(Employee::class, 'getServiceIds'),
            'Employee must have getServiceIds() for filter scoping'
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Extract field names for a given validator type from rules array
     */
    private function extractFieldsForValidator(array $rules, string $validator): array
    {
        $fields = [];
        foreach ($rules as $rule) {
            if (isset($rule[1]) && $rule[1] === $validator) {
                $fields = array_merge($fields, (array)$rule[0]);
            }
        }
        return $fields;
    }
}
