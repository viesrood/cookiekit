<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

use viesrood\cookiekit\Plugin;

/**
 * Normalises and sanitises a browser scan payload.
 *
 * This is the gate between the outside world and the findings table, and it is
 * deliberately strict. The scanner is supposed to send cookie *names* only, so
 * anything that looks like a value is dropped rather than trimmed: a payload
 * carrying `name=value` means either a broken scanner or someone poking at the
 * endpoint, and neither deserves the benefit of the doubt.
 *
 * @phpstan-import-type BrowserReport from \viesrood\cookiekit\services\DetectorService
 */
final class ScanPayload
{
    public const MAX_PAGES = 200;
    public const MAX_ITEMS_PER_PAGE = 300;
    public const MAX_URL_LENGTH = 500;

    /**
     * Flattens the passes of a scan into one report per page, each carrying the
     * consent that was in effect when it was recorded.
     *
     * @param array<string, mixed> $payload
     * @return list<BrowserReport>
     */
    public static function normalise(array $payload): array
    {
        $passes = $payload['passes'] ?? [];

        if (!is_array($passes)) {
            return [];
        }

        $reports = [];

        foreach ($passes as $pass) {
            if (!is_array($pass)) {
                continue;
            }

            $consent = self::consentList($pass['consent'] ?? []);
            $pages = $pass['pages'] ?? [];

            if (!is_array($pages)) {
                continue;
            }

            foreach ($pages as $page) {
                if (!is_array($page) || count($reports) >= self::MAX_PAGES) {
                    continue;
                }

                $reports[] = self::page($page, $consent);
            }
        }

        return $reports;
    }

    /**
     * @param array<string, mixed> $page
     * @param list<string> $consent
     * @return BrowserReport
     */
    private static function page(array $page, array $consent): array
    {
        return [
            'url' => self::url(is_string($page['url'] ?? null) ? $page['url'] : ''),
            'cookies' => self::cookies($page['cookies'] ?? []),
            'local' => self::storageKeys($page['local'] ?? []),
            'session' => self::storageKeys($page['session'] ?? []),
            'consent' => $consent,
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array{name: string, domain?: string, expires?: float|int|null}>
     */
    private static function cookies(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $cookies = [];

        foreach ($raw as $entry) {
            if (count($cookies) >= self::MAX_ITEMS_PER_PAGE) {
                break;
            }

            // A bare string is accepted so a minimal scanner can just send names.
            $name = is_string($entry) ? $entry : (is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : null);

            if ($name === null || !SetCookieParser::isValidName($name)) {
                continue;
            }

            $cookie = ['name' => $name];

            if (is_array($entry)) {
                if (is_string($entry['domain'] ?? null)) {
                    $cookie['domain'] = mb_substr($entry['domain'], 0, 255);
                }

                if (is_numeric($entry['expires'] ?? null)) {
                    $cookie['expires'] = $entry['expires'] + 0;
                }
            }

            $cookies[] = $cookie;
        }

        return $cookies;
    }

    /**
     * @return list<string>
     */
    private static function storageKeys(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $keys = [];

        foreach ($raw as $key) {
            if (count($keys) >= self::MAX_ITEMS_PER_PAGE) {
                break;
            }

            // Storage keys are freer than cookie names, but a value-looking
            // blob or a novel is still a sign something is wrong.
            if (!is_string($key) || $key === '' || mb_strlen($key) > 128 || str_contains($key, '=')) {
                continue;
            }

            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private static function consentList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_filter($raw, 'is_string'),
            static fn(string $category): bool => in_array($category, Plugin::CATEGORIES, true),
        ));
    }

    /**
     * Keeps the path, drops the query string: it can carry search terms, tokens
     * or an email address, and none of that belongs in a findings table.
     */
    private static function url(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '';
        }

        $clean = '';

        if (isset($parts['scheme'], $parts['host'])) {
            $clean = $parts['scheme'] . '://' . $parts['host'];

            if (isset($parts['port'])) {
                $clean .= ':' . $parts['port'];
            }
        }

        $clean .= $parts['path'] ?? '/';

        return mb_substr($clean, 0, self::MAX_URL_LENGTH);
    }
}
