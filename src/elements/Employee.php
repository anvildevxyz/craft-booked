<?php

namespace anvildev\booked\elements;

use anvildev\booked\elements\db\EmployeeQuery;
use anvildev\booked\elements\traits\HasWeeklySchedule;
use anvildev\booked\records\EmployeeRecord;
use anvildev\booked\traits\HasEnabledStatus;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\SetStatus;
use craft\elements\db\EagerLoadPlan;
use craft\elements\User;
use craft\helpers\Html;

class Employee extends Element
{
    use HasEnabledStatus;
    use HasWeeklySchedule;

    public ?int $userId = null;
    public ?int $locationId = null;
    public ?string $email = null;
    public array|string|null $workingHours = null;
    public array|string|null $serviceIds = null;

    private ?User $_user = null;
    private ?Location $_location = null;

    public static function displayName(): string
    {
        return Craft::t('booked', 'element.employee');
    }
    public static function pluralDisplayName(): string
    {
        return Craft::t('booked', 'element.employees');
    }
    public static function refHandle(): ?string
    {
        return 'employee';
    }
    public static function gqlTypeNameByContext(mixed $context): string
    {
        return 'BookedEmployee';
    }
    public function getGqlTypeName(): string
    {
        return static::gqlTypeNameByContext(null);
    }
    public static function hasTitles(): bool
    {
        return true;
    }
    /** @return EmployeeQuery */
    public static function find(): EmployeeQuery
    {
        return new EmployeeQuery(static::class);
    }

    protected static function defineActions(?string $source = null): array
    {
        return [SetStatus::class, Delete::class];
    }

    protected static function defineExporters(string $source): array
    {
        $exporters = parent::defineExporters($source);
        $exporters[] = exporters\EmployeeScheduleCsvExporter::class;
        return $exporters;
    }

    protected static function defineSources(string $context): array
    {
        return [['key' => '*', 'label' => Craft::t('booked', 'element.allEmployees'), 'defaultSort' => ['title', 'asc']]];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'user' => ['label' => Craft::t('booked', 'labels.user')],
            'location' => ['label' => Craft::t('booked', 'labels.location')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'serviceIds' => ['label' => Craft::t('booked', 'labels.services')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['user', 'location'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['email'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('app', 'Title'),
                'orderBy' => 'elements_sites.title',
                'attribute' => 'title',
            ],
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
            ],
        ];
    }

    public static function eagerLoadingMap(array $sourceElements, string $handle): array|null|false
    {
        if ($handle === 'user' || $handle === 'location') {
            $prop = $handle === 'user' ? 'userId' : 'locationId';
            $elementType = $handle === 'user' ? User::class : Location::class;
            $map = [];
            foreach ($sourceElements as $el) {
                if ($el->$prop) {
                    $map[] = ['source' => $el->id, 'target' => $el->$prop];
                }
            }
            return ['elementType' => $elementType, 'map' => $map];
        }
        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    public function setEagerLoadedElements(string $handle, array $elements, EagerLoadPlan $plan): void
    {
        switch ($handle) {
            case 'user':
                $el = $elements[0] ?? null;
                $this->_user = $el instanceof User ? $el : null;
                break;
            case 'location':
                $el = $elements[0] ?? null;
                $this->_location = $el instanceof Location ? $el : null;
                break;
            default:
                parent::setEagerLoadedElements($handle, $elements, $plan);
        }
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['userId', 'locationId'], 'integer'],
            [['email'], 'email', 'skipOnEmpty' => true],
            [['email'], 'string', 'max' => 255],
            [['email'], 'safe'],
        ]);
    }

    protected function attributeHtml(string $attribute): string
    {
        $dash = Html::tag('span', '–', ['class' => 'light']);
        return match ($attribute) {
            'user' => ($u = $this->getUser()) ? Html::encode($u->getName()) : $dash,
            'location' => ($l = $this->getLocation()) ? Html::encode($l->title) : $dash,
            'serviceIds' => ($s = $this->getServices())
                ? implode(', ', array_map(fn($svc) => Html::encode($svc->title), $s))
                : $dash,
            default => parent::attributeHtml($attribute),
        };
    }

    protected function cpEditUrl(): ?string
    {
        return 'booked/employees/' . $this->id;
    }
    public function canDuplicate(User $user): bool
    {
        return true;
    }

    public function init(): void
    {
        parent::init();
        $this->workingHours = $this->normalizeWorkingHours($this->workingHours);
        $this->serviceIds = is_string($this->serviceIds)
            ? (json_decode($this->serviceIds, true) ?? [])
            : ($this->serviceIds ?? []);
    }

    public function canView(User $user): bool
    {
        return $user->admin || $this->userId === $user->id || $user->can('booked-manageEmployees');
    }

    public function canSave(User $user): bool
    {
        return $user->admin || $user->can('booked-manageEmployees');
    }

    public function canDelete(User $user): bool
    {
        return $user->admin || $user->can('booked-manageEmployees');
    }

    public function afterSave(bool $isNew): void
    {
        $record = $isNew ? new EmployeeRecord() : (EmployeeRecord::findOne($this->id)
            ?? throw new \Exception('Invalid employee ID: ' . $this->id));
        if ($isNew) {
            $record->id = (int)$this->id;
        }

        $record->userId = $this->userId;
        $record->locationId = $this->locationId;
        $record->email = $this->email;

        $record->workingHours = $this->normalizeWorkingHours($this->workingHours);

        $sids = is_string($this->serviceIds) ? json_decode($this->serviceIds, true) : $this->serviceIds;
        $record->serviceIds = !empty($sids) ? $sids : null;

        $record->save(false);
        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        EmployeeRecord::findOne($this->id)?->delete();

        // Clean up junction tables
        $db = \Craft::$app->db;
        $db->createCommand()->delete('{{%booked_employee_managers}}', ['or', ['employeeId' => $this->id], ['managedEmployeeId' => $this->id]])->execute();

        parent::afterDelete();
    }

    public function extraFields(): array
    {
        return [
            'user' => 'getUser',
            'location' => 'getLocation',
            'services' => 'getServices',
            'schedules' => 'getSchedules',
            'serviceIds' => 'getServiceIds',
        ];
    }

    public function getScheduleData(): ?array
    {
        return is_array($this->workingHours) ? $this->workingHours : null;
    }

    /** @return int[] */
    public function getServiceIds(): array
    {
        $ids = is_string($this->serviceIds) ? (json_decode($this->serviceIds, true) ?? []) : ($this->serviceIds ?? []);
        return array_map('intval', $ids);
    }

    public function hasService(int $serviceId): bool
    {
        return in_array($serviceId, $this->getServiceIds(), true);
    }

    public function getUser(): ?User
    {
        if ($this->_user === null && $this->userId) {
            $this->_user = Craft::$app->users->getUserById($this->userId);
        }
        return $this->_user;
    }

    public function getLocation(): ?Location
    {
        if ($this->_location === null && $this->locationId) {
            $this->_location = Location::find()->id($this->locationId)->siteId('*')->one();
        }
        return $this->_location;
    }

    /** @return Service[] */
    public function getServices(): array
    {
        return ($ids = $this->getServiceIds()) ? Service::find()->id($ids)->siteId('*')->status(null)->all() : [];
    }

    /** @return Schedule[] */
    public function getSchedules(): array
    {
        return $this->id ? Schedule::find()->employeeId($this->id)->siteId('*')->status(null)->all() : [];
    }

    /** @return Schedule[] */
    public function getEnabledSchedules(): array
    {
        return $this->id ? Schedule::find()->employeeId($this->id)->siteId('*')->enabled()->all() : [];
    }
}
