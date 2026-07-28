<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

/**
 * Matches URLs, inline scripts, cookie names and storage keys against the
 * signature database.
 *
 * Deliberately free of Craft: it takes the signatures as an array and does
 * nothing else, which is what makes the whole matching layer unit-testable
 * without a database, a bootstrap or a network.
 *
 * @phpstan-type CookieSignature array{
 *     name: string,
 *     match: 'exact'|'prefix'|'regex',
 *     declaredAs: string,
 *     category: string,
 *     duration: string,
 *     purpose: string
 * }
 * @phpstan-type StorageSignature array{
 *     key: string,
 *     match: 'exact'|'prefix'|'regex',
 *     type: 'local'|'session',
 *     category: string,
 *     purpose: string
 * }
 * @phpstan-type VendorSignature array{
 *     label: string,
 *     provider: string,
 *     category: string,
 *     container: bool,
 *     blockAs: string,
 *     hosts: list<string>,
 *     paths: list<string>,
 *     inline: list<string>,
 *     cookies: list<CookieSignature>,
 *     storage: list<StorageSignature>
 * }
 * @phpstan-type VendorMatch array{key: string, signature: VendorSignature, score: int}
 * @phpstan-type CookieMatch array{key: string, signature: VendorSignature, cookie: CookieSignature}
 * @phpstan-type StorageMatch array{key: string, signature: VendorSignature, storage: StorageSignature}
 */
final class SignatureMatcher
{
    public const SCORE_EXACT_HOST_AND_PATH = 40;
    public const SCORE_EXACT_HOST = 30;
    public const SCORE_WILDCARD_HOST_AND_PATH = 25;
    public const SCORE_WILDCARD_HOST = 20;
    public const SCORE_INLINE = 15;

    /**
     * @param array<string, VendorSignature> $signatures
     */
    public function __construct(private readonly array $signatures)
    {
    }

    /**
     * @return array<string, VendorSignature>
     */
    public function all(): array
    {
        return $this->signatures;
    }

    /**
     * @return VendorSignature|null
     */
    public function get(string $key): ?array
    {
        return $this->signatures[$key] ?? null;
    }

    /**
     * Scores every signature against a resource URL and returns the best hit.
     *
     * The rule that carries the whole design: if a signature declares `paths`,
     * a host match without a path match scores nothing. That is what separates
     * GA4 (`/gtag/js`) from Tag Manager (`/gtm.js`) on the host they share.
     *
     * @return VendorMatch|null
     */
    public function matchUrl(string $url): ?array
    {
        $host = $this->hostOf($url);
        if ($host === null) {
            return null;
        }

        $path = $this->pathOf($url);
        $best = null;

        foreach ($this->sortedKeys() as $key) {
            $signature = $this->signatures[$key];

            $hostScore = $this->hostScore($signature['hosts'], $host);
            if ($hostScore === null) {
                continue;
            }

            $pathHits = $this->countPathHits($signature['paths'], $path);

            if ($signature['paths'] !== [] && $pathHits === 0) {
                continue;
            }

            $exact = $hostScore === self::SCORE_EXACT_HOST;
            $score = match (true) {
                $exact && $pathHits > 0 => self::SCORE_EXACT_HOST_AND_PATH,
                $exact => self::SCORE_EXACT_HOST,
                $pathHits > 0 => self::SCORE_WILDCARD_HOST_AND_PATH,
                default => self::SCORE_WILDCARD_HOST,
            };

            // Higher score wins; equal scores are broken by the number of path
            // patterns hit, then by key order, so the result never depends on
            // the order the signatures happen to be declared in.
            if ($best === null || $score > $best['score'] || ($score === $best['score'] && $pathHits > $best['pathHits'])) {
                $best = ['key' => $key, 'signature' => $signature, 'score' => $score, 'pathHits' => $pathHits];
            }
        }

        if ($best === null) {
            return null;
        }

        return ['key' => $best['key'], 'signature' => $best['signature'], 'score' => $best['score']];
    }

    /**
     * Inline scripts can carry several vendors at once (a GTM snippet next to a
     * gtag config), so this returns every hit rather than the best one.
     *
     * @return list<VendorMatch>
     */
    public function matchInline(string $script): array
    {
        $matches = [];

        foreach ($this->sortedKeys() as $key) {
            $signature = $this->signatures[$key];

            foreach ($signature['inline'] as $pattern) {
                if (preg_match($pattern, $script) === 1) {
                    $matches[] = ['key' => $key, 'signature' => $signature, 'score' => self::SCORE_INLINE];
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * @return CookieMatch|null
     */
    public function matchCookieName(string $name): ?array
    {
        $fallback = null;

        foreach ($this->sortedKeys() as $key) {
            $signature = $this->signatures[$key];

            foreach ($signature['cookies'] as $cookie) {
                if (!$this->matchesPattern($cookie['name'], $cookie['match'], $name)) {
                    continue;
                }

                // An exact declaration beats a prefix or regex one.
                if ($cookie['match'] === 'exact') {
                    return ['key' => $key, 'signature' => $signature, 'cookie' => $cookie];
                }

                $fallback ??= ['key' => $key, 'signature' => $signature, 'cookie' => $cookie];
            }
        }

        return $fallback;
    }

    /**
     * @param 'local'|'session' $type
     * @return StorageMatch|null
     */
    public function matchStorageKey(string $storageKey, string $type): ?array
    {
        $fallback = null;

        foreach ($this->sortedKeys() as $key) {
            $signature = $this->signatures[$key];

            foreach ($signature['storage'] as $storage) {
                if ($storage['type'] !== $type) {
                    continue;
                }

                if (!$this->matchesPattern($storage['key'], $storage['match'], $storageKey)) {
                    continue;
                }

                if ($storage['match'] === 'exact') {
                    return ['key' => $key, 'signature' => $signature, 'storage' => $storage];
                }

                $fallback ??= ['key' => $key, 'signature' => $signature, 'storage' => $storage];
            }
        }

        return $fallback;
    }

    /**
     * Builds the paste-ready markup that turns an unblocked resource into a
     * blocked one, preserving the attributes that matter for how it loads.
     *
     * @param array<string, string> $extraAttrs
     */
    public static function blockingSnippet(string $tag, string $src, string $category, array $extraAttrs = []): string
    {
        $attributes = '';
        foreach ($extraAttrs as $name => $value) {
            $attributes .= $value === ''
                ? ' ' . $name
                : ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        }

        $src = htmlspecialchars($src, ENT_QUOTES);
        $category = htmlspecialchars($category, ENT_QUOTES);

        if (strtolower($tag) === 'script') {
            return sprintf(
                '<script type="text/plain" data-cookiekit="%s"%s data-cookiekit-src="%s"></script>',
                $category,
                $attributes,
                $src,
            );
        }

        return sprintf(
            '<%1$s data-cookiekit="%2$s"%3$s data-cookiekit-src="%4$s"></%1$s>',
            strtolower($tag),
            $category,
            $attributes,
            $src,
        );
    }

    /**
     * @return list<string>
     */
    private function sortedKeys(): array
    {
        $keys = array_keys($this->signatures);
        sort($keys);

        return $keys;
    }

    /**
     * @param list<string> $hosts
     * @return self::SCORE_EXACT_HOST|self::SCORE_WILDCARD_HOST|null
     */
    private function hostScore(array $hosts, string $host): ?int
    {
        $wildcardHit = false;

        foreach ($hosts as $candidate) {
            if (str_starts_with($candidate, '*.')) {
                $suffix = substr($candidate, 1);
                if (str_ends_with($host, $suffix)) {
                    $wildcardHit = true;
                }
                continue;
            }

            if ($candidate === $host) {
                return self::SCORE_EXACT_HOST;
            }
        }

        return $wildcardHit ? self::SCORE_WILDCARD_HOST : null;
    }

    /**
     * @param list<string> $patterns
     */
    private function countPathHits(array $patterns, string $path): int
    {
        $hits = 0;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @param 'exact'|'prefix'|'regex' $mode
     */
    private function matchesPattern(string $pattern, string $mode, string $subject): bool
    {
        return match ($mode) {
            'exact' => $pattern === $subject,
            'prefix' => $pattern !== '' && str_starts_with($subject, $pattern),
            'regex' => preg_match($pattern, $subject) === 1,
        };
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }

        // Protocol-relative and bare-host references still carry a host.
        if (str_starts_with($url, '//')) {
            return $this->hostOf('https:' . $url);
        }

        return null;
    }

    /**
     * The query string is part of what identifies a resource (`?id=G-…`), so it
     * stays in the subject the path patterns are matched against.
     */
    private function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $query = parse_url($url, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }
}
