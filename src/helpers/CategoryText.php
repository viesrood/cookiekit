<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

use Craft;
use viesrood\cookiekit\Plugin;

/**
 * The labels and descriptions shown per consent category.
 *
 * These used to be built inside `banner.twig`, which meant every custom
 * template had to copy the maps over by hand and keep them in step with the
 * translation file. They are passed into the template now, and live here so
 * the English source strings can be asserted in a test without booting Craft.
 */
final class CategoryText
{
    /**
     * The untranslated strings, keyed exactly like Plugin::CATEGORIES.
     *
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            'necessary' => 'Necessary',
            'preferences' => 'Preferences',
            'statistics' => 'Statistics',
            'marketing' => 'Marketing',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sourceDescriptions(): array
    {
        return [
            'necessary' => 'Necessary cookies make the website work: they enable core functions such as navigation, forms and secure areas. The website cannot function properly without them.',
            'preferences' => 'Preference cookies remember choices that change how the website behaves or looks, such as your preferred language or region.',
            'statistics' => 'Statistics cookies help us understand how visitors use the website, by collecting and reporting information anonymously.',
            'marketing' => 'Marketing cookies track visitors across websites to show ads that are relevant and engaging for the individual user.',
        ];
    }

    /**
     * Translated into whatever language is current at the moment of the call.
     *
     * That timing matters: when the banner language is forced, this has to run
     * inside the language swap, or the labels come out in the site language
     * while the rest of the banner is translated.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return self::translate(self::sourceLabels());
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return self::translate(self::sourceDescriptions());
    }

    /**
     * Guards against a category being added to Plugin::CATEGORIES without a
     * matching label, which would otherwise surface as an empty heading.
     *
     * @return list<string>
     */
    public static function missingKeys(): array
    {
        $labels = self::sourceLabels();
        $descriptions = self::sourceDescriptions();

        return array_values(array_filter(
            Plugin::CATEGORIES,
            static fn(string $category): bool => !isset($labels[$category]) || !isset($descriptions[$category]),
        ));
    }

    /**
     * @param array<string, string> $strings
     * @return array<string, string>
     */
    private static function translate(array $strings): array
    {
        return array_map(
            static fn(string $string): string => Craft::t('cookiekit', $string),
            $strings,
        );
    }
}
