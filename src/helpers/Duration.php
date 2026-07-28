<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

/**
 * Turns a cookie lifetime into the short human-readable string the declaration
 * shows visitors, e.g. `2 years`, `30 minutes` or `Session`.
 *
 * The output is deliberately a canonical English phrase: the plugin's static
 * translations use English source strings as keys, so `2 years` doubles as the
 * translation key that renders as "2 jaar" on a Dutch site.
 */
final class Duration
{
    public const SESSION = 'Session';

    private const MINUTE = 60;
    private const HOUR = 3600;
    private const DAY = 86400;

    /**
     * Reads the lifetime off parsed Set-Cookie attributes. `Max-Age` wins over
     * `Expires` per RFC 6265, and a cookie with neither is a session cookie.
     *
     * @param array<string, string|bool> $attributes lowercased attribute names
     */
    public static function fromSetCookieAttributes(array $attributes, ?int $now = null): string
    {
        $maxAge = $attributes['max-age'] ?? null;
        if (is_string($maxAge) && $maxAge !== '' && preg_match('/^-?\d+$/', $maxAge) === 1) {
            return self::humanize((int)$maxAge);
        }

        $expires = $attributes['expires'] ?? null;
        if (is_string($expires) && $expires !== '') {
            return self::fromExpires($expires, $now);
        }

        return self::SESSION;
    }

    /**
     * An unparseable or already-past expiry is reported as a session cookie
     * rather than guessed at.
     */
    public static function fromExpires(string $expires, ?int $now = null): string
    {
        $timestamp = strtotime($expires);
        if ($timestamp === false) {
            return self::SESSION;
        }

        return self::humanize($timestamp - ($now ?? time()));
    }

    public static function humanize(int $seconds): string
    {
        if ($seconds <= 0) {
            return self::SESSION;
        }

        if ($seconds < self::MINUTE) {
            return self::plural($seconds, 'second');
        }

        if ($seconds < self::HOUR) {
            return self::plural((int)round($seconds / self::MINUTE), 'minute');
        }

        if ($seconds < self::DAY) {
            return self::plural((int)round($seconds / self::HOUR), 'hour');
        }

        $days = $seconds / self::DAY;

        // Up to about six weeks a count of days reads more naturally and stays
        // literal ("30 days", not "1 month"); beyond a year, so do years.
        if ($days < 45) {
            return self::plural((int)round($days), 'day');
        }

        // Months run well past a year on purpose. Chrome caps any cookie at 400
        // days, so a tag that asks for two years is really stored for 400 of
        // them, and rounding that to "1 year" understates it by a third.
        if ($days < 548) {
            $months = max(1, (int)round($days / 30.44));

            return $months === 12 ? '1 year' : self::plural($months, 'month');
        }

        return self::plural(max(1, (int)round($days / 365.25)), 'year');
    }

    /**
     * Splits a generated phrase back into its number and its translation key,
     * so the declaration can be written in the site's own language.
     *
     * Returns null for anything this class did not produce, which includes
     * every lifetime that was typed in by hand.
     *
     * @return array{count: int, key: string}|null
     */
    public static function toTranslationKey(string $duration): ?array
    {
        if (preg_match('/^(\d+) (second|minute|hour|day|month|year)s?$/', $duration, $match) !== 1) {
            return null;
        }

        $count = (int)$match[1];

        return [
            'count' => $count,
            'key' => $count === 1 ? "1 {$match[2]}" : "{n} {$match[2]}s",
        ];
    }

    private static function plural(int $count, string $unit): string
    {
        return $count === 1 ? "1 {$unit}" : "{$count} {$unit}s";
    }
}
