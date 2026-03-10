<?php

namespace anvildev\booked\tests\Unit\Records;

use anvildev\booked\records\EmployeeManagerRecord;
use anvildev\booked\tests\Support\TestCase;
use craft\db\ActiveRecord;

/**
 * EmployeeManagerRecord Test
 *
 * Tests the junction table record that links a staff employee (manager)
 * to the employees they oversee.
 */
class EmployeeManagerRecordTest extends TestCase
{
    public function testExtendsActiveRecord(): void
    {
        $this->assertTrue(
            is_a(EmployeeManagerRecord::class, ActiveRecord::class, true),
            'EmployeeManagerRecord must extend ActiveRecord'
        );
    }

    public function testTableName(): void
    {
        $this->assertEquals(
            '{{%booked_employee_managers}}',
            EmployeeManagerRecord::tableName()
        );
    }

    // =========================================================================
    // Validation Rules
    // =========================================================================

    public function testRulesReturnArray(): void
    {
        $record = new EmployeeManagerRecord();
        $rules = $record->rules();

        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules);
    }

    public function testRequiredFields(): void
    {
        $record = new EmployeeManagerRecord();
        $rules = $record->rules();

        // Find the 'required' rule
        $requiredFields = [];
        foreach ($rules as $rule) {
            if (isset($rule[1]) && $rule[1] === 'required') {
                $requiredFields = array_merge($requiredFields, (array)$rule[0]);
            }
        }

        $this->assertContains('employeeId', $requiredFields);
        $this->assertContains('managedEmployeeId', $requiredFields);
    }

    public function testIntegerFields(): void
    {
        $record = new EmployeeManagerRecord();
        $rules = $record->rules();

        // Find the 'integer' rule
        $integerFields = [];
        foreach ($rules as $rule) {
            if (isset($rule[1]) && $rule[1] === 'integer') {
                $integerFields = array_merge($integerFields, (array)$rule[0]);
            }
        }

        $this->assertContains('employeeId', $integerFields);
        $this->assertContains('managedEmployeeId', $integerFields);
    }

    // =========================================================================
    // Property Assignment
    // =========================================================================

    public function testCanSetEmployeeId(): void
    {
        $this->requiresCraft();

        $record = new EmployeeManagerRecord();
        $record->employeeId = 42;

        $this->assertEquals(42, $record->employeeId);
    }

    public function testCanSetManagedEmployeeId(): void
    {
        $this->requiresCraft();

        $record = new EmployeeManagerRecord();
        $record->managedEmployeeId = 99;

        $this->assertEquals(99, $record->managedEmployeeId);
    }

    public function testCanSetBothIds(): void
    {
        $this->requiresCraft();

        $record = new EmployeeManagerRecord();
        $record->employeeId = 10;
        $record->managedEmployeeId = 20;

        $this->assertEquals(10, $record->employeeId);
        $this->assertEquals(20, $record->managedEmployeeId);
    }
}
