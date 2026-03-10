<?php

namespace anvildev\booked\assetbundles;

use craft\web\AssetBundle;

class BookedEmployeeEditAsset extends AssetBundle
{
    public $depends = [BookedCpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/booked/web';
        $this->js = [
            'js/cp/user-selector-disabled.js',
            'js/cp/calendar-action-buttons.js',
        ];
        parent::init();
    }
}
