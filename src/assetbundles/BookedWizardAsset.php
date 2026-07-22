<?php

namespace anvildev\booked\assetbundles;

use craft\web\AssetBundle;
use craft\web\View;

/**
 * Front-end booking wizard assets — the zero-dependency vanilla bundle.
 *
 * Ships the framework-free `booked-wizard.umd.js` (headless core + renderer) and
 * the wizard stylesheet. Unlike {@see BookedAsset}, it loads no Alpine.js and no
 * legacy wizard scripts, so the page runs under a strict CSP (no `unsafe-eval`).
 * The bundle exposes the global `BookedWizard`, deferred so the DOM is parsed
 * before the init script in the template runs.
 */
class BookedWizardAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@anvildev/booked/web';
        $this->js = [
            ['js/booked-wizard.umd.js', 'defer' => true],
        ];
        $this->css = ['css/booked-wizard.css'];
        $this->jsOptions['position'] = View::POS_END;
        parent::init();
    }
}
