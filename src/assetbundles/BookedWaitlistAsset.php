<?php

namespace anvildev\booked\assetbundles;

use craft\web\AssetBundle;

class BookedWaitlistAsset extends AssetBundle
{
    public $depends = [BookedCpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/booked/web';
        $this->js = [
            'js/cp/waitlist-index.js',
        ];
        parent::init();
    }
}
