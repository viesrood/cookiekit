<?php

declare(strict_types=1);

namespace viesrood\cookiekit\web\assets\banner;

use craft\web\AssetBundle;

/**
 * The bundled stylesheet on its own.
 *
 * Plain CSS built on custom properties, so a site can restyle the banner
 * without a build step. Drop it entirely with `registerCss: false` when your
 * own template brings its own styles.
 */
class BannerCssAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $css = ['cookiekit.css'];
}
