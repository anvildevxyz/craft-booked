<?php

namespace anvildev\booked\assetbundles;

use craft\web\AssetBundle;

class BookedSettingsAsset extends AssetBundle
{
    public $depends = [BookedCpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/booked/web';
        $this->js = [
            'js/cp/booking-settings.js',
            'js/cp/security-settings.js',
            'js/cp/calendar-settings.js',
            'js/cp/connection-test.js',
            'js/cp/zoom-test.js',
            'js/cp/teams-test.js',
            'js/cp/sms-settings.js',
        ];
        parent::init();
    }
}
