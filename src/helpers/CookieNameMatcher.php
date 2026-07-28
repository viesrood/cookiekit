<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

/**
 * Matches declared cookie names against observed ones.
 *
 * A declared name may contain `*` as a wildcard, which is what makes a single
 * declaration cover a family of cookies: `_ga_*` covers `_ga_G3Y7GKHRGGR`,
 * `_ga_XYZ123` and every other GA4 property id. Names without a `*` are exact.
 *
 * Cookie names are case-sensitive per RFC 6265, and so is every method here.
 */
final class CookieNameMatcher
{
    /**
     * Turns a declared name into an anchored regex. Everything except `*` is
     * quoted, so a name like `__utm.gif` does not accidentally match `__utmXgif`.
     */
    public static function toRegex(string $declaredName): string
    {
        $parts = array_map(
            static fn(string $part): string => preg_quote($part, '/'),
            explode('*', $declaredName),
        );

        return '/^' . implode('.*', $parts) . '$/';
    }

    public static function matches(string $declaredName, string $observedName): bool
    {
        if (!self::isMeaningful($declaredName)) {
            return false;
        }

        if (!str_contains($declaredName, '*')) {
            return $declaredName === $observedName;
        }

        return preg_match(self::toRegex($declaredName), $observedName) === 1;
    }

    /**
     * Returns the declared name covering the observed one.
     *
     * An exact declaration always wins, so `_ga` beats `_g*` for the name
     * `_ga`. Between wildcards the most specific one wins: `_ga_G3Y7GKHRGGR`
     * belongs to `_ga_*`, not to a stray `_g*` that happens to be declared too.
     *
     * @param list<string> $declaredNames
     */
    public static function findDeclared(array $declaredNames, string $observedName): ?string
    {
        $wildcardHit = null;

        foreach ($declaredNames as $declaredName) {
            if (!self::matches($declaredName, $observedName)) {
                continue;
            }

            if (!str_contains($declaredName, '*')) {
                return $declaredName;
            }

            if ($wildcardHit === null || self::specificity($declaredName) > self::specificity($wildcardHit)) {
                $wildcardHit = $declaredName;
            }
        }

        return $wildcardHit;
    }

    /**
     * A declared name of `*` (or `**`, or an empty string) would match every
     * cookie on earth and silently swallow the whole declaration. Reject it.
     */
    public static function isMeaningful(string $declaredName): bool
    {
        return str_replace('*', '', trim($declaredName)) !== '';
    }

    /**
     * How much of a declared name is literal. The more characters a pattern
     * pins down, the more specific it is.
     */
    private static function specificity(string $declaredName): int
    {
        return strlen(str_replace('*', '', $declaredName));
    }
}
