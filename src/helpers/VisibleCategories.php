<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

use viesrood\cookiekit\Plugin;

/**
 * Which consent categories the banner actually offers.
 *
 * A category with nothing declared under it is a switch with no content, so it
 * is left out. The result is used in two places at once, and they have to move
 * together: the list the template loops over, and `config.categories`, which is
 * what "Accept all" grants. Filter only the first and the two buttons disagree,
 * because "Save preferences" reads the checkboxes that exist while "Accept all"
 * reads the config.
 */
final class VisibleCategories
{
    /**
     * Always offered, whatever the declaration says: the script hard-codes it
     * in both "Deny" and "Save preferences", so hiding it would let the list
     * and reality drift apart. It is also the category that explains to a
     * visitor why there are cookies at all.
     */
    public const ALWAYS = 'necessary';

    /**
     * @param array<string, array<mixed>> $cookiesByCategory
     * @return list<string>
     */
    public static function resolve(array $cookiesByCategory, bool $hideEmpty): array
    {
        if (!$hideEmpty) {
            return Plugin::CATEGORIES;
        }

        return array_values(array_filter(
            Plugin::CATEGORIES,
            static fn(string $category): bool => $category === self::ALWAYS
                || ($cookiesByCategory[$category] ?? []) !== [],
        ));
    }
}
