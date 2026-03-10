<?php

namespace anvildev\booked\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class BookedCpAsset extends AssetBundle
{
    public $depends = [CpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/booked/web';
        $this->js = [
            'js/utils/date-time.js',
            'js/cp/lightswitch-toggle.js',
        ];
        $this->css = ['css/cp/booked-cp.css'];
        parent::init();
    }
}
