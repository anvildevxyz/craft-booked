<?php

namespace anvildev\booked\elements;

use anvildev\booked\elements\db\ScheduleQuery;
use anvildev\booked\elements\traits\HasWeeklySchedule;
use anvildev\booked\records\ScheduleRecord;
use anvildev\booked\traits\HasElementPermissions;
use anvildev\booked\traits\HasEnabledStatus;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\SetStatus;
use craft\elements\User;
use craft\helpers\Html;

class Schedule extends Element
{
    use HasEnabledStatus;
    use HasElementPermissions;
    use HasWeeklySchedule;

    public array|string|null $workingHours = null;
    public ?string $startDate = null;
    public ?string $endDate = null;
    /** Sort order from assignment (set during query, not persisted on element) */
    public ?int $sortOrder = null;

    public static function displayName(): string
    {
        return Craft::t('booked', 'element.schedule');
    }
    public static function pluralDisplayName(): string
    {
        return Craft::t('booked', 'element.schedules');
    }
    public static function refHandle(): ?string
    {
        return 'schedule';
    }
    public static function gqlTypeNameByContext(mixed $context): string
    {
        return 'BookedSchedule';
    }
    public function getGqlTypeName(): string
    {
        return static::gqlTypeNameByContext(null);
    }
    public static function hasTitles(): bool
    {
        return true;
    }
    /** @return ScheduleQuery */
    public static function find(): ScheduleQuery
    {
        return new ScheduleQuery(static::class);
    }

    protected static function defineActions(?string $source = null): array
    {
        return [SetStatus::class, Delete::class];
    }

    protected static function defineSources(string $context): array
    {
        return [['key' => '*', 'label' => Craft::t('booked', 'element.allSchedules'), 'defaultSort' => ['title', 'asc']]];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'startDate' => ['label' => Craft::t('booked', 'labels.startDate')],
            'endDate' => ['label' => Craft::t('booked', 'labels.endDate')],
            'workingDays' => ['label' => Craft::t('booked', 'schedule.workingDays')],
            'assignedEmployees' => ['label' => Craft::t('booked', 'schedule.assignedEmployees')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['title', 'workingDays', 'startDate', 'endDate'];
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
                'label' => Craft::t('booked', 'labels.startDate'),
                'orderBy' => 'booked_schedules.startDate',
                'attribute' => 'startDate',
            ],
            [
                'label' => Craft::t('booked', 'labels.endDate'),
                'orderBy' => 'booked_schedules.endDate',
                'attribute' => 'endDate',
            ],
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
            ],
        ];
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['startDate', 'endDate'], 'date', 'format' => 'php:Y-m-d', 'skipOnEmpty' => true],
            [['workingHours'], 'safe'],
        ]);
    }

    protected function attributeHtml(string $attribute): string
    {
        $dash = Html::tag('span', '–', ['class' => 'light']);
        return match ($attribute) {
            'startDate', 'endDate' => ($v = $this->$attribute) ? Html::encode(Craft::$app->formatter->asDate($v, 'short')) : $dash,
            'workingDays' => ($days = $this->getScheduledDays())
                ? Html::encode(implode(', ', array_map(fn($d) => [
                    1 => Craft::t('booked', 'labels.mon'), 2 => Craft::t('booked', 'labels.tue'),
                    3 => Craft::t('booked', 'labels.wed'), 4 => Craft::t('booked', 'labels.thu'),
                    5 => Craft::t('booked', 'labels.fri'), 6 => Craft::t('booked', 'labels.sat'),
                    7 => Craft::t('booked', 'labels.sun'),
                ][$d] ?? '', $days)))
                : $dash,
            'assignedEmployees' => ($emps = $this->getAssignedEmployees())
                ? implode(', ', array_map(fn($e) => Html::encode($e->title), $emps))
                : $dash,
            default => parent::attributeHtml($attribute),
        };
    }

    protected function cpEditUrl(): ?string
    {
        return 'booked/schedules/' . $this->id;
    }
    public function canDuplicate(User $user): bool
    {
        return true;
    }

    public function init(): void
    {
        parent::init();
        $this->workingHours = $this->normalizeWorkingHours($this->workingHours);
    }

    protected function permissionKey(): string
    {
        return 'booked-manageEmployees';
    }

    public function afterSave(bool $isNew): void
    {
        $record = (!$isNew ? ScheduleRecord::findOne($this->id) : null) ?? new ScheduleRecord();
        if (!$record->id) {
            $record->id = (int)$this->id;
        }

        $record->startDate = $this->startDate;
        $record->endDate = $this->endDate;
        $record->workingHours = $this->normalizeWorkingHours($this->workingHours);
        $record->save(false);

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        ScheduleRecord::findOne($this->id)?->delete();
        \anvildev\booked\records\EmployeeScheduleAssignmentRecord::deleteAll(['scheduleId' => $this->id]);
        parent::afterDelete();
    }

    public function getCapacityForDay(int $dayOfWeek): ?int
    {
        $hours = $this->workingHours[$dayOfWeek] ?? null;
        if (!$hours || empty($hours['enabled'])) {
            return null;
        }
        $cap = $hours['capacity'] ?? null;
        return $cap !== null && $cap !== '' ? (int)$cap : null;
    }

    public function getScheduleData(): ?array
    {
        return is_array($this->workingHours) ? $this->workingHours : null;
    }

    public function isActiveOn(string|\DateTime $date): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $dateStr = is_string($date) ? (new \DateTime($date))->format('Y-m-d') : $date->format('Y-m-d');
        return (!$this->startDate || $dateStr >= $this->startDate)
            && (!$this->endDate || $dateStr <= $this->endDate);
    }

    /** @return Employee[] */
    public function getAssignedEmployees(): array
    {
        if (!$this->id) {
            return [];
        }
        $records = \anvildev\booked\records\EmployeeScheduleAssignmentRecord::find()->where(['scheduleId' => $this->id])->all();
        if (empty($records)) {
            return [];
        }
        return Employee::find()->siteId('*')->id(array_map(fn($r) => $r->employeeId, $records))->status(null)->all();
    }
}
