<?php

namespace anvildev\booked\models;

use craft\base\Model;

class SoftLock extends Model
{
    public ?int $id = null;
    public ?string $token = null;
    public ?int $serviceId = null;
    public ?int $employeeId = null;
    public ?int $locationId = null;
    public ?string $date = null;
    public ?string $startTime = null;
    public ?string $endTime = null;
    public int $quantity = 1;
    public ?string $expiresAt = null;

    public function rules(): array
    {
        return [
            [['token', 'serviceId', 'date', 'startTime', 'endTime', 'expiresAt'], 'required'],
            [['id', 'serviceId', 'employeeId', 'locationId', 'quantity'], 'integer'],
            [['token', 'date', 'startTime', 'endTime'], 'string'],
            [['expiresAt'], 'datetime', 'format' => 'php:Y-m-d H:i:s'],
        ];
    }
}
