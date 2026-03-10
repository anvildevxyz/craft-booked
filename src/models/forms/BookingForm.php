<?php

namespace anvildev\booked\models\forms;

use anvildev\booked\helpers\ValidationHelper;
use anvildev\booked\records\ServiceExtraServiceRecord;
use craft\base\Model;
use Yii;

class BookingForm extends Model
{
    public ?string $userName = null;
    public ?string $userEmail = null;
    public ?string $userPhone = null;
    public ?string $userTimezone = null;
    public ?string $bookingDate = null;
    public ?string $startTime = null;
    public ?string $endTime = null;
    public ?string $notes = null;
    public ?int $serviceId = null;
    public ?int $employeeId = null;
    public ?int $locationId = null;
    public ?int $eventDateId = null;
    public int $quantity = 1;
    public ?string $honeypot = null;
    public ?string $captchaToken = null;
    public array $extras = [];
    public bool $smsEnabled = false;

    public function rules(): array
    {
        return [
            [['userName', 'userEmail'], 'required', 'message' => Yii::t('booked', 'This field is required.')],
            [['bookingDate', 'startTime', 'endTime', 'serviceId'], 'required', 'message' => Yii::t('booked', 'This field is required.'), 'when' => fn($model) => $model->eventDateId === null],
            [['userName', 'userEmail', 'userPhone', 'notes'], 'filter', 'filter' => fn($value) =>
                $value ? trim(strip_tags($value)) : null, ],
            ['userEmail', 'filter', 'filter' => 'strtolower'],
            ['userEmail', 'email', 'message' => Yii::t('booked', 'Please enter a valid email address.')],
            [['userName', 'userEmail', 'userPhone'], 'string', 'max' => 255],
            ['notes', 'string', 'max' => 5000],
            [['bookingDate'], ValidationHelper::DATE_VALIDATOR, 'format' => ValidationHelper::DATE_FORMAT, 'when' => fn($model) => $model->bookingDate !== null],
            [['startTime', 'endTime'], 'match', 'pattern' => ValidationHelper::TIME_FORMAT_PATTERN, 'when' => fn($model) => $model->startTime !== null],
            [['serviceId', 'employeeId', 'locationId', 'eventDateId'], 'integer'],
            [['quantity'], 'integer', 'min' => 1, 'max' => 10000],
            [['userTimezone'], 'string', 'max' => 50],
            [['userTimezone'], 'validateTimezone'],
            [['honeypot', 'captchaToken'], 'string'],
            [['userPhone'], 'validatePhone'],
            [['extras'], 'validateExtras'],
        ];
    }

    public function validateTimezone(string $attribute, ?array $params = null): void
    {
        if (!empty($this->$attribute) && !in_array($this->$attribute, \DateTimeZone::listIdentifiers(), true)) {
            $this->addError($attribute, Yii::t('booked', 'validation.invalidTimezone'));
        }
    }

    public function validatePhone(string $attribute, ?array $params = null): void
    {
        $phone = $this->$attribute;
        if ($this->smsEnabled && $phone !== null && $phone !== '' && preg_match('/[a-zA-Z]/', $phone)) {
            $this->addError($attribute, Yii::t('booked', 'validation.invalidPhone'));
        }
    }

    public function validateExtras(string $attribute, ?array $params = null): void
    {
        if (empty($this->$attribute)) {
            return;
        }

        if (!is_array($this->$attribute)) {
            $this->addError($attribute, Yii::t('booked', 'validation.invalidExtras'));
            return;
        }

        foreach ($this->$attribute as $extraId => $quantity) {
            if (!is_numeric($extraId) || (int)$extraId <= 0) {
                $this->addError($attribute, Yii::t('booked', 'validation.invalidExtraId'));
                return;
            }
            if (!is_numeric($quantity) || (int)$quantity < 0) {
                $this->addError($attribute, Yii::t('booked', 'validation.invalidExtraQuantity'));
                return;
            }
        }

        // Verify extras belong to the selected service
        if ($this->serviceId !== null) {
            $validExtraIds = $this->getValidExtraIdsForService($this->serviceId);

            foreach (array_keys($this->$attribute) as $extraId) {
                if (!in_array((int)$extraId, $validExtraIds, true)) {
                    $this->addError($attribute, Yii::t('booked', 'validation.extraNotForService'));
                    return;
                }
            }
        }
    }

    /**
     * Returns the valid extra IDs for a given service.
     *
     * @return int[]
     */
    protected function getValidExtraIdsForService(int $serviceId): array
    {
        return array_map('intval', ServiceExtraServiceRecord::find()
            ->select('extraId')
            ->where(['serviceId' => $serviceId])
            ->column());
    }

    public function isSpam(): bool
    {
        return !empty($this->honeypot);
    }

    public function getReservationData(): array
    {
        return [
            'userName' => $this->userName,
            'userEmail' => $this->userEmail,
            'userPhone' => $this->userPhone,
            'userTimezone' => $this->userTimezone,
            'bookingDate' => $this->bookingDate,
            'startTime' => $this->startTime,
            'endTime' => $this->endTime,
            'serviceId' => $this->serviceId,
            'employeeId' => $this->employeeId,
            'locationId' => $this->locationId,
            'eventDateId' => $this->eventDateId,
            'notes' => $this->notes,
            'quantity' => $this->quantity,
            'extras' => array_map('intval', array_combine(
                array_map('intval', array_keys($this->extras)),
                array_values($this->extras)
            ) ?: []),
        ];
    }
}
