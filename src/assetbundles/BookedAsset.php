<?php

namespace anvildev\booked\assetbundles;

use craft\web\AssetBundle;

class BookedAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@anvildev/booked/web';
        $this->js = [
            'js/utils/validation.js',
            'js/utils/date-time.js',
            'js/booking-availability.js',
            'js/wizard-common.js',
            'js/booking-wizard.js',
            'js/event-wizard.js',
        ];
        $this->css = ['css/booked.css'];
        $this->jsOptions['position'] = \craft\web\View::POS_HEAD;
        parent::init();
    }
}
