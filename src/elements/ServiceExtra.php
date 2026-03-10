<?php

namespace anvildev\booked\elements;

use anvildev\booked\elements\db\ServiceExtraQuery;
use anvildev\booked\records\ServiceExtraRecord;
use anvildev\booked\records\ServiceExtraServiceRecord;
use anvildev\booked\traits\HasElementPermissions;
use anvildev\booked\traits\HasEnabledStatus;
use anvildev\booked\traits\HasPropagation;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Duplicate;
use craft\elements\actions\SetStatus;
use craft\elements\User;

class ServiceExtra extends Element
{
    use HasEnabledStatus;
    use HasElementPermissions;
    use HasPropagation;

    public float $price = 0.0;
    public int $duration = 0;
    public int $maxQuantity = 1;
    public bool $isRequired = false;
    public ?string $description = null;

    public static function displayName(): string
    {
        return Craft::t('booked', 'element.addOn');
    }
    public static function pluralDisplayName(): string
    {
        return Craft::t('booked', 'element.addOns');
    }
    public static function refHandle(): ?string
    {
        return 'serviceExtra';
    }
    public static function hasTitles(): bool
    {
        return true;
    }
    public static function hasUris(): bool
    {
        return false;
    }
    public static function isLocalized(): bool
    {
        return true;
    }

    /** @return ServiceExtraQuery */
    public static function find(): ServiceExtraQuery
    {
        return new ServiceExtraQuery(static::class);
    }

    protected static function defineActions(?string $source = null): array
    {
        return [SetStatus::class, Duplicate::class, Delete::class];
    }

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('booked', 'element.allAddOns'),
                'defaultSort' => ['title', 'asc'],
            ],
            [
                'heading' => Craft::t('booked', 'labels.status'),
            ],
            [
                'key' => 'enabled',
                'label' => Craft::t('booked', 'labels.enabled'),
                'criteria' => ['status' => 'enabled'],
            ],
            [
                'key' => 'disabled',
                'label' => Craft::t('booked', 'labels.disabled'),
                'criteria' => ['status' => 'disabled'],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'price' => ['label' => Craft::t('booked', 'labels.price')],
            'duration' => ['label' => Craft::t('booked', 'labels.duration')],
            'maxQuantity' => ['label' => Craft::t('booked', 'serviceExtra.maxQty')],
            'isRequired' => ['label' => Craft::t('booked', 'labels.required')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['price', 'duration', 'maxQuantity', 'isRequired'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'description'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Name'),
            'price' => Craft::t('booked', 'labels.price'),
            'duration' => Craft::t('booked', 'labels.duration'),
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
            [['title'], 'required'],
            [['title'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['price'], 'number', 'min' => 0],
            [['duration'], 'integer', 'min' => 0],
            [['maxQuantity'], 'integer', 'min' => 0],
            [['isRequired'], 'boolean'],
        ]);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = (!$isNew ? ServiceExtraRecord::findOne($this->id) : null) ?? new ServiceExtraRecord();
            if (!$record->id) {
                $record->id = (int) $this->id;
            }
            $record->propagationMethod = $this->propagationMethod->value;
            $record->price = $this->price;
            $record->duration = $this->duration;
            $record->maxQuantity = $this->maxQuantity;
            $record->isRequired = $this->isRequired;
            $record->description = $this->description;
            $record->save(false);
        }
        parent::afterSave($isNew);
    }

    protected function cpEditUrl(): ?string
    {
        return 'booked/service-extras/' . $this->id;
    }
    public function canDuplicate(User $user): bool
    {
        return true;
    }

    protected function permissionKey(): string
    {
        return 'booked-manageServices';
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'price' => Craft::$app->formatter->asCurrency($this->price),
            'duration' => $this->duration > 0 ? "+{$this->duration} min" : '-',
            'isRequired' => $this->isRequired ? '<span class="status green"></span>' : '',
            default => parent::attributeHtml($attribute),
        };
    }

    /** @return Service[] */
    public function getServices(): array
    {
        if (!$this->id) {
            return [];
        }

        $serviceIds = ServiceExtraServiceRecord::find()
            ->select('serviceId')
            ->where(['extraId' => $this->id])
            ->column();

        if (empty($serviceIds)) {
            return [];
        }

        return Service::find()->id($serviceIds)->status(null)->siteId('*')->all();
    }

    public function extraFields(): array
    {
        return [
            'services' => 'getServices',
        ];
    }

    public function isValidQuantity(int $quantity): bool
    {
        return $quantity >= 1 && ($this->maxQuantity === 0 || $quantity <= $this->maxQuantity);
    }

    private function effectiveQuantity(int $quantity): int
    {
        return $this->maxQuantity === 0 ? $quantity : min($quantity, $this->maxQuantity);
    }

    public function getTotalPrice(int $quantity = 1): float
    {
        return $this->price * $this->effectiveQuantity($quantity);
    }
    public function getTotalDuration(int $quantity = 1): int
    {
        return $this->duration * $this->effectiveQuantity($quantity);
    }
    public function getFormattedPrice(): string
    {
        return Craft::$app->formatter->asCurrency($this->price);
    }

    public function afterDelete(): void
    {
        ServiceExtraRecord::findOne($this->id)?->delete();
        parent::afterDelete();
    }
}
