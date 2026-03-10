<?php

namespace anvildev\booked\records;

use craft\db\ActiveRecord;

/**
 * Many-to-many: staff employee (manager) -> managed employees.
 *
 * @property int $id
 * @property int $employeeId
 * @property int $managedEmployeeId
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class EmployeeManagerRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%booked_employee_managers}}';
    }

    public function rules(): array
    {
        return [
            [['employeeId', 'managedEmployeeId'], 'required'],
            [['employeeId', 'managedEmployeeId'], 'integer'],
        ];
    }
}
