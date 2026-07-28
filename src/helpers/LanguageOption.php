<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

/**
 * Normalises whatever a template or a settings field handed us into a language
 * tag Craft can work with.
 *
 * A well-formed but unknown tag is deliberately let through: Yii looks for a
 * matching translations folder, finds none, and falls back to the English
 * source strings. That is graceful degradation, not an error worth throwing.
 */
final class LanguageOption
{
    /**
     * Two or three letters, optionally followed by subtags. Covers `nl`,
     * `nl-NL`, `nl-BE` and `zh-Hant-TW`.
     */
    public const PATTERN = '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/';

    /**
     * Returns a canonical tag, or null when the input is empty or is not a
     * language tag at all.
     *
     * `nl_nl`, `NL-nl` and `nl-NL` all come out as `nl-NL`, so a settings field
     * filled in by hand behaves the same as one copied from Craft.
     */
    public static function normalize(mixed $language): ?string
    {
        if (!is_string($language)) {
            return null;
        }

        $language = str_replace('_', '-', trim($language));

        if ($language === '' || preg_match(self::PATTERN, $language) !== 1) {
            return null;
        }

        $parts = explode('-', $language);
        $parts[0] = strtolower($parts[0]);

        // A two-letter region is written in caps by convention (nl-NL); script
        // subtags like Hant keep their own casing.
        foreach ($parts as $index => $part) {
            if ($index === 0) {
                continue;
            }

            $parts[$index] = strlen($part) === 2 ? strtoupper($part) : ucfirst(strtolower($part));
        }

        return implode('-', $parts);
    }
}
