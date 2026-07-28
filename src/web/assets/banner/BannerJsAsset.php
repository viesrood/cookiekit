<?php

declare(strict_types=1);

namespace viesrood\cookiekit\web\assets\banner;

use craft\web\AssetBundle;
use yii\web\View;

/**
 * The banner script on its own, so a site with its own styling can keep the
 * behaviour and drop the stylesheet.
 *
 * Dependency-free vanilla JS, no build step.
 */
class BannerJsAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $js = ['cookiekit.js'];

    /**
     * Load early so Google Consent Mode defaults are set before gtag.js runs.
     */
    public $jsOptions = ['position' => View::POS_HEAD];
}
