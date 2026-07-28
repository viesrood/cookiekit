<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

use Craft;

/**
 * A cheap ceiling on how often one address may hit an anonymous endpoint.
 *
 * Both public endpoints write a database row per request: a consent receipt or
 * a counter update. Without a ceiling, a loop fills the disk and, worse,
 * drowns the genuine receipts in forged ones, which is the opposite of what an
 * audit trail is for.
 *
 * The limit is generous on purpose. A real visitor records a handful of choices
 * per minute at most, and people behind one office address should never notice
 * this.
 */
final class RateLimit
{
    /**
     * Returns false when this address has had its allowance for the window.
     */
    public static function hit(string $bucket, int $max, int $windowSeconds = 60): bool
    {
        $ip = Craft::$app->getRequest()->getUserIP() ?? 'unknown';
        $key = 'cookiekit:rate:' . $bucket . ':' . sha1($ip);
        $cache = Craft::$app->getCache();

        $used = (int)$cache->get($key);

        if ($used >= $max) {
            return false;
        }

        // Not sliding: the counter simply expires. Precise enough for a ceiling
        // whose job is to stop a loop, not to meter traffic.
        $cache->set($key, $used + 1, $windowSeconds);

        return true;
    }
}
