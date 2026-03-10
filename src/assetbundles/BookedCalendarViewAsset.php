<?php

namespace anvildev\booked\assetbundles;

use craft\web\AssetBundle;

class BookedCalendarViewAsset extends AssetBundle
{
    public $depends = [BookedCpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/booked/web';
        $this->js = [
            'js/cp/calendar-filters.js',
        ];
        parent::init();
    }
}
