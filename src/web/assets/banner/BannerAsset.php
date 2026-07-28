<?php

declare(strict_types=1);

namespace viesrood\cookiekit\web\assets\banner;

use craft\web\AssetBundle;

/**
 * Front-end banner assets: the script and the stylesheet together.
 *
 * Kept as a facade over the two separate bundles so a project that registers
 * this by hand keeps getting both. The plugin itself registers the halves it
 * needs, because a custom template usually wants the script without the
 * stylesheet.
 *
 * Both halves publish from the same directory, and the asset manager caches
 * publication by path, so `dist/` is still copied exactly once.
 */
class BannerAsset extends AssetBundle
{
    public $depends = [
        BannerJsAsset::class,
        BannerCssAsset::class,
    ];
}
