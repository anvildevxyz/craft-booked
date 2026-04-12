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

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if (!self::isAlpineRegistered($view)) {
            $am = $view->getAssetManager();
            $baseUrl = $am->getPublishedUrl($this->sourcePath, true);
            if ($baseUrl !== false) {
                $view->registerJsFile(
                    $baseUrl . '/js/vendor/alpine.min.js',
                    ['position' => \craft\web\View::POS_END, 'defer' => true]
                );
            }
        }
    }

    /**
     * Check if Alpine.js is already registered to avoid double-loading.
     *
     */
    private static function isAlpineRegistered(\yii\web\View $view): bool
    {
        foreach ($view->jsFiles as $position) {
            foreach ($position as $html) {
                if (str_contains($html, 'alpine')) {
                    return true;
                }
            }
        }

        return false;
    }
}
