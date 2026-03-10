<?php

namespace anvildev\booked\tests\Unit\Records;

use anvildev\booked\migrations\Install;
use anvildev\booked\tests\Support\TestCase;

/**
 * Employee Manager Migration Test
 *
 * Verifies that the Install migration includes the booked_employee_managers table
 * and that the migration class structure is correct.
 */
class EmployeeManagerMigrationTest extends TestCase
{
    // =========================================================================
    // Install Migration Source Verification
    // =========================================================================

    public function testInstallMigrationClassExists(): void
    {
        $this->assertTrue(class_exists(Install::class));
    }

    public function testInstallMigrationHasSafeUpMethod(): void
    {
        $this->assertTrue(method_exists(Install::class, 'safeUp'));
    }

    public function testInstallMigrationHasSafeDownMethod(): void
    {
        $this->assertTrue(method_exists(Install::class, 'safeDown'));
    }

    public function testInstallMigrationSourceContainsEmployeeManagersTable(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/../src/migrations/Install.php'
        );

        $this->assertStringContainsString(
            'booked_employee_managers',
            $source,
            'Install migration must create the booked_employee_managers table'
        );
    }

    public function testInstallMigrationSourceContainsManagedEmployeeIdColumn(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/../src/migrations/Install.php'
        );

        $this->assertStringContainsString(
            'managedEmployeeId',
            $source,
            'Install migration must include managedEmployeeId column'
        );
    }

    public function testInstallMigrationSourceContainsEmployeeIdColumn(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/../src/migrations/Install.php'
        );

        // Check that the employee_managers section has employeeId
        $pattern = '/booked_employee_managers.*?employeeId/s';
        $this->assertMatchesRegularExpression(
            $pattern,
            $source,
            'Install migration must include employeeId column in employee_managers table'
        );
    }

    public function testInstallMigrationDropsEmployeeManagersTable(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/../src/migrations/Install.php'
        );

        // Table may be dropped directly or via a loop in safeDown
        $hasDirect = str_contains($source, "dropTableIfExists('{{%booked_employee_managers}}')");
        $hasInLoop = str_contains($source, "'booked_employee_managers'") && str_contains($source, 'safeDown');

        $this->assertTrue(
            $hasDirect || $hasInLoop,
            'Install migration safeDown must drop the booked_employee_managers table'
        );
    }

    public function testInstallMigrationHasUniqueIndexOnEmployeeManagerPair(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/../src/migrations/Install.php'
        );

        // The unique index should be on (employeeId, managedEmployeeId)
        $this->assertStringContainsString(
            "['employeeId', 'managedEmployeeId'], true",
            $source,
            'Install migration must create a unique index on (employeeId, managedEmployeeId)'
        );
    }

    public function testInstallMigrationHasForeignKeyToElements(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/../src/migrations/Install.php'
        );

        // Both employeeId and managedEmployeeId should FK to elements.id
        $employeeManagerSection = $this->extractTableSection($source, 'booked_employee_managers');
        $this->assertNotEmpty($employeeManagerSection, 'Could not find employee_managers table section');

        $fkCount = substr_count($employeeManagerSection, "{{%elements}}");
        $this->assertEquals(2, $fkCount, 'Both employeeId and managedEmployeeId must FK to elements table');
    }

    /**
     * Extract the section of Install.php that creates a specific table.
     *
     * Matches both comment-prefixed sections and if-tableExists guard patterns.
     *
     * @param string $source Full Install.php source
     * @param string $tableName Table name to find (e.g. 'booked_employee_managers')
     * @return string The matched section, or empty string if not found
     */
    private function extractTableSection(string $source, string $tableName): string
    {
        $escaped = preg_quote($tableName, '/');

        // Match a block starting from a comment or if-guard containing the table name,
        // through to the next table creation block or end of source
        $pattern = '/(?:\/\/[^\n]*|if\s*\([^\n]*)' . $escaped . '.*?\n(.*?)(?=(?:\/\/\s*Create\s|if\s*\(!\$this->db->tableExists)|$)/s';
        if (preg_match($pattern, $source, $matches)) {
            return $matches[1];
        }
        return '';
    }
}
