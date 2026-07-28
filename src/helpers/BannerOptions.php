<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

/**
 * Works out what a `craft.cookiekit.render()` call actually asked for.
 *
 * Options beat plugin settings, settings beat the built-in defaults, and
 * `registerAssets` is the fallback for both of the finer-grained switches so
 * existing projects keep behaving exactly as they did.
 *
 * Twig hands over whatever the template wrote, so every value is coerced here
 * rather than trusted.
 */
final class BannerOptions
{
    /**
     * @param array<string, mixed> $options as passed from Twig
     * @param array{template: string, registerCss: bool, language: string} $defaults from the plugin settings
     * @return array{template: string|null, registerJs: bool, registerCss: bool, language: string|null}
     */
    public static function resolve(array $options, array $defaults): array
    {
        // Only an explicitly passed registerAssets counts as a fallback; an
        // absent key must not be read as false.
        $assets = array_key_exists('registerAssets', $options) ? (bool)$options['registerAssets'] : null;

        return [
            'template' => self::path($options['template'] ?? null) ?? self::path($defaults['template']),
            // The script is on unless someone says otherwise: without it the
            // banner is inert markup and nothing says why.
            'registerJs' => self::flag($options['registerJs'] ?? null) ?? $assets ?? true,
            'registerCss' => self::flag($options['registerCss'] ?? null) ?? $assets ?? $defaults['registerCss'],
            'language' => LanguageOption::normalize($options['language'] ?? null)
                ?? LanguageOption::normalize($defaults['language']),
        ];
    }

    /**
     * Distinguishes "not given" from "given as false", which `??` alone cannot.
     */
    private static function flag(mixed $value): ?bool
    {
        return $value === null ? null : (bool)$value;
    }

    /**
     * An empty template path means "use the one bundled with the plugin".
     */
    private static function path(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
