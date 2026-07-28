<?php

declare(strict_types=1);

namespace viesrood\cookiekit\jobs;

use Craft;
use craft\queue\BaseJob;
use viesrood\cookiekit\Plugin;

/**
 * Runs a scan in the background.
 *
 * Crawling a few dozen pages takes longer than a control panel request should,
 * so the button queues this and Craft's own job progress reports back.
 */
class ScanJob extends BaseJob
{
    public ?int $siteId = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return;
        }

        $plugin->getScan()->runFullScan($this->siteId, function (int $done, int $total, string $url) use ($queue): void {
            $this->setProgress($queue, $total > 0 ? $done / $total : 1, Craft::t('cookiekit', 'Scanned {done} of {total} pages', [
                'done' => $done,
                'total' => $total,
            ]));
        });
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('cookiekit', 'CookieKit: scanning the site for cookies');
    }
}
