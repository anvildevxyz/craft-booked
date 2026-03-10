<?php

namespace anvildev\booked\assetbundles;

use craft\web\AssetBundle;
use craft\web\View;

class BookedWebhooksAsset extends AssetBundle
{
    public $depends = [BookedCpAsset::class];

    public function init(): void
    {
        $this->sourcePath = '@anvildev/booked/web';
        $this->js = [
            'js/cp/webhook-edit.js',
            'js/cp/webhook-index.js',
        ];
        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('booked', [
                'webhook.js.secretCopied',
                'webhook.js.enabled',
                'webhook.js.disabled',
                'webhook.js.disable',
                'webhook.js.enable',
                'webhook.js.webhookEnabled',
                'webhook.js.webhookDisabled',
                'webhook.js.regenerateConfirm',
                'webhook.js.secretRegenerated',
                'webhook.deleteConfirm',
                'webhook.deleted',
                'webhook.headerNamePlaceholder',
                'webhook.headerValuePlaceholder',
            ]);
        }
    }
}
