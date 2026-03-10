<?php

namespace anvildev\booked\assetbundles;

use craft\web\AssetBundle;

class BookedBookingsAsset extends AssetBundle
{
    public $depends = [BookedCpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/booked/web';
        $this->js = [
            'js/cp/bookings-index.js',
            'js/cp/booking-time.js',
        ];
        parent::init();
    }
}
