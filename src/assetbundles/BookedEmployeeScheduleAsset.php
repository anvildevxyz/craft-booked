<?php

namespace anvildev\booked\assetbundles;

use craft\web\AssetBundle;

class BookedEmployeeScheduleAsset extends AssetBundle
{
    public $depends = [BookedAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/booked/web';
        $this->js = [
            'js/employee-schedule.js',
        ];
        $this->jsOptions['position'] = \craft\web\View::POS_END;
        parent::init();
    }
}
