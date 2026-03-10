<?php

namespace anvildev\booked\elements;

use anvildev\booked\Booked;
use anvildev\booked\elements\db\EventDateQuery;
use anvildev\booked\helpers\DateHelper;
use anvildev\booked\helpers\ValidationHelper;
use anvildev\booked\records\EventDateRecord;
use anvildev\booked\records\ReservationRecord;
use anvildev\booked\traits\HasEnabledStatus;
use anvildev\booked\traits\HasPropagation;
use anvildev\booked\traits\ValidatesTimeRange;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\SetStatus;
use craft\elements\User;
use craft\helpers\Html;

class EventDate extends Element
{
    use HasEnabledStatus;
    use HasPropagation;
    use ValidatesTimeRange;

    public ?int $locationId = null;
    public string $eventDate = '';
    public ?string $endDate = null;
    public string $startTime = '';
    public string $endTime = '';
    public ?string $description = null;
    public ?int $capacity = null;
    public bool $allowCancellation = true;
    public ?int $cancellationPolicyHours = null;
    public bool $allowRefund = false;
    public array|string|null $refundTiers = null;
    public ?float $price = null;
    public ?bool $enableWaitlist = null;
    public bool $enabled = true;
    public ?string $deletedAt = null;

    private ?Location $_location = null;
    private ?int $_bookedCount = null;

    public function softDelete(): void
    {
        $this->deletedAt = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    public function isSoftDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function beforeDelete(): bool
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        $activeCount = $this->getActiveReservationCount();

        if ($activeCount > 0) {
            $this->addError('id', Craft::t('booked', 'error.cannotDeleteEventDateWithReservations', ['count' => $activeCount]));
            return false;
        }

        return true;
    }

    public static function displayName(): string
    {
        return Craft::t('booked', 'element.eventDate');
    }
    public static function pluralDisplayName(): string
    {
        return Craft::t('booked', 'element.eventDates');
    }
    public static function refHandle(): ?string
    {
        return 'eventDate';
    }
    public static function gqlTypeNameByContext(mixed $context): string
    {
        return 'BookedEventDate';
    }
    public function getGqlTypeName(): string
    {
        return static::gqlTypeNameByContext(null);
    }
    public static function createCondition(): \craft\elements\conditions\ElementConditionInterface
    {
        return \Craft::createObject(conditions\EventDateCondition::class, [static::class]);
    }

    public static function hasTitles(): bool
    {
        return true;
    }
    public static function isLocalized(): bool
    {
        return true;
    }
    /** @return EventDateQuery */
    public static function find(): EventDateQuery
    {
        return new EventDateQuery(static::class);
    }

    protected static function defineActions(?string $source = null): array
    {
        return [SetStatus::class, Delete::class];
    }

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('booked', 'element.allEventDates'),
                'defaultSort' => ['eventDate', 'desc'],
                'type' => 'native',
            ],
            [
                'heading' => Craft::t('app', 'Status'),
            ],
            [
                'key' => 'enabled',
                'label' => Craft::t('app', 'Enabled'),
                'criteria' => ['enabled' => true],
                'defaultSort' => ['eventDate', 'desc'],
                'type' => 'native',
            ],
            [
                'key' => 'disabled',
                'label' => Craft::t('app', 'Disabled'),
                'criteria' => ['enabled' => false],
                'defaultSort' => ['eventDate', 'desc'],
                'type' => 'native',
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'id' => ['label' => Craft::t('app', 'ID')],
            'title' => ['label' => Craft::t('app', 'Title')],
            'date' => ['label' => Craft::t('app', 'Date')],
            'time' => ['label' => Craft::t('app', 'Time')],
            'capacity' => ['label' => Craft::t('booked', 'labels.capacity')],
            'booked' => ['label' => Craft::t('booked', 'labels.booked')],
            'location' => ['label' => Craft::t('booked', 'labels.location')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['title', 'date', 'time', 'capacity', 'booked'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['description'];
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
                'label' => Craft::t('app', 'Date'),
                'orderBy' => 'booked_event_dates.eventDate',
                'attribute' => 'eventDate',
            ],
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
            ],
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        $url = $this->getCpEditUrl();
        return match ($attribute) {
            'id' => $url ? Html::a('#' . $this->id, $url) : '#' . $this->id,
            'title' => (function() use ($url) {
                $t = $this->title ?: Craft::t('booked', 'eventDate.untitledEvent');
                return $url ? Html::a(Html::encode($t), $url) : Html::encode($t);
            })(),
            'date' => Html::encode($this->getFormattedDate()),
            'time' => Html::encode($this->getFormattedTimeRange()),
            'capacity' => $this->capacity === null
                ? Html::tag('span', "\u{221E}", ['class' => 'light'])
                : (string)$this->capacity,
            'booked' => (function() {
                $booked = $this->getBookedCountCached();
                if ($this->capacity === null) {
                    return (string)$booked;
                }
                $remaining = $this->getRemainingCapacity();
                return $booked . ' / ' . $this->capacity
                    . ($remaining !== null && $remaining > 0 ? ' (' . $remaining . ' ' . Craft::t('booked', 'eventDate.remaining') . ')' : '');
            }
            )(),
            'location' => ($loc = $this->getLocation())
                ? Html::encode($loc->title)
                : Html::tag('span', "\u{2014}", ['class' => 'light']),
            default => parent::attributeHtml($attribute),
        };
    }

    protected function cpEditUrl(): ?string
    {
        return 'booked/cp/event-dates/' . $this->id;
    }
    public function canView(User $user): bool
    {
        return $user->admin || $user->can('booked-manageBookings');
    }
    public function canSave(User $user): bool
    {
        return $user->admin || $user->can('booked-manageBookings');
    }

    public function canDelete(User $user): bool
    {
        return ($user->admin || $user->can('booked-manageBookings'))
            && $this->getActiveReservationCount() === 0;
    }

    public function canDuplicate(User $user): bool
    {
        return true;
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['eventDate', 'startTime', 'endTime', 'title'], 'required'],
            [['locationId', 'capacity'], 'integer'],
            [['eventDate', 'endDate'], ValidationHelper::DATE_VALIDATOR, 'format' => ValidationHelper::DATE_FORMAT],
            [['endDate'], 'validateEndDateAfterStart'],
            [['startTime', 'endTime'], 'match', 'pattern' => ValidationHelper::TIME_FORMAT_PATTERN],
            [['title'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['capacity'], 'integer', 'min' => 1],
            [['allowCancellation'], 'boolean'],
            [['cancellationPolicyHours'], 'integer', 'min' => 0],
            [['allowRefund'], 'boolean'],
            [['price'], 'number', 'min' => 0],
            [['enabled'], 'boolean'],
        ]);
    }

    public function beforeValidate(): bool
    {
        $this->validateTimeRange();
        return parent::beforeValidate();
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            if (empty($this->eventDate) || empty($this->startTime) || empty($this->endTime)) {
                throw new \Exception('Required fields (eventDate, startTime, endTime) must be set before saving EventDate element. eventDate: ' . var_export($this->eventDate, true) . ', startTime: ' . var_export($this->startTime, true) . ', endTime: ' . var_export($this->endTime, true));
            }

            $record = (!$isNew ? EventDateRecord::findOne($this->id) : null) ?? new EventDateRecord();
            if (!$record->id) {
                $record->id = (int)$this->id;
            }

            $record->propagationMethod = $this->propagationMethod->value;
            $record->locationId = $this->locationId;
            $record->eventDate = $this->eventDate;
            $record->endDate = $this->endDate;
            $record->startTime = $this->startTime;
            $record->endTime = $this->endTime;
            $record->title = $this->title;
            $record->description = $this->description;
            $record->capacity = $this->capacity;
            $record->allowCancellation = $this->allowCancellation;
            $record->cancellationPolicyHours = $this->cancellationPolicyHours;
            $record->allowRefund = $this->allowRefund;
            $record->refundTiers = $this->refundTiers ? json_encode($this->refundTiers) : null;
            $record->price = $this->price;
            $record->enableWaitlist = $this->enableWaitlist;
            $record->enabled = $this->enabled;
            $record->deletedAt = $this->deletedAt;

            if (!$record->save(false)) {
                throw new \Exception('Failed to save EventDate record: ' . implode(', ', $record->getFirstErrors()));
            }
        }
        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        EventDateRecord::findOne($this->id)?->delete();
        parent::afterDelete();
    }

    private function getActiveReservationCount(): int
    {
        return (int)ReservationRecord::find()
            ->where(['eventDateId' => $this->id])
            ->andWhere(['not', ['status' => ReservationRecord::STATUS_CANCELLED]])
            ->count();
    }

    /** Used by ValidatesTimeRange trait to allow overnight events */
    public function getEffectiveEndDate(): string
    {
        return $this->endDate ?? $this->eventDate;
    }

    public function validateEndDateAfterStart(string $attribute): void
    {
        if ($this->endDate && $this->eventDate && $this->endDate < $this->eventDate) {
            $this->addError($attribute, Craft::t('booked', 'validation.endDateAfterStart'));
        }
    }

    public function afterPopulate(): void
    {
        parent::afterPopulate();
        if (is_string($this->refundTiers)) {
            $this->refundTiers = json_decode($this->refundTiers, true);
        }
    }

    public function extraFields(): array
    {
        return [
            'location' => 'getLocation',
            'remainingCapacity' => 'getRemainingCapacity',
            'isFullyBooked' => 'isFullyBooked',
            'isAvailable' => 'isAvailable',
            'formattedDate' => 'getFormattedDate',
            'formattedTimeRange' => 'getFormattedTimeRange',
        ];
    }

    public function getLocation(): ?Location
    {
        if ($this->_location === null && $this->locationId) {
            $this->_location = Location::find()->id($this->locationId)->siteId('*')->one();
        }
        return $this->_location;
    }

    public function getBookedCountCached(): int
    {
        return $this->_bookedCount ??= Booked::getInstance()->getEventDate()->getBookedCount($this->id);
    }

    /** Returns null if capacity is unlimited */
    public function getRemainingCapacity(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }
        return max(0, $this->capacity - $this->getBookedCountCached());
    }

    public function isFullyBooked(): bool
    {
        return $this->capacity !== null && ($this->getRemainingCapacity() ?? 0) <= 0;
    }

    public function isAvailable(): bool
    {
        $tz = $this->locationId
            ? ($this->getLocation()?->timezone ?? Craft::$app->getTimeZone())
            : Craft::$app->getTimeZone();
        $eventDt = \DateTime::createFromFormat(
            'Y-m-d H:i',
            $this->eventDate . ' ' . substr($this->startTime, 0, 5),
            new \DateTimeZone($tz)
        );

        return $this->enabled
            && $eventDt
            && $eventDt > new \DateTime('now', new \DateTimeZone($tz))
            && !$this->isFullyBooked();
    }

    public function getFormattedTimeRange(): string
    {
        if (empty($this->startTime) || empty($this->endTime)) {
            return '';
        }
        $start = \DateTime::createFromFormat('H:i:s', $this->startTime) ?: \DateTime::createFromFormat('H:i', $this->startTime);
        $end = \DateTime::createFromFormat('H:i:s', $this->endTime) ?: \DateTime::createFromFormat('H:i', $this->endTime);
        if (!$start || !$end) {
            return $this->startTime . ' - ' . $this->endTime;
        }

        return DateHelper::formatTimeLocale($start)
            . ' - '
            . DateHelper::formatTimeLocale($end);
    }

    public function getFormattedDate(): string
    {
        if (empty($this->eventDate)) {
            return '';
        }

        return DateHelper::formatDateLocale($this->eventDate);
    }

    public function getTitle(): ?string
    {
        return $this->title ?: Craft::t('booked', 'eventDate.eventOnDate', ['date' => $this->getFormattedDate()]);
    }
}
