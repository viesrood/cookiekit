<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\CategoryText;

/**
 * Every string a visitor or an administrator can read has to have a Dutch
 * translation.
 *
 * Without this, "add the Dutch strings too" is a good intention that survives
 * exactly as long as nobody is in a hurry. The failure message lists the
 * missing keys ready to paste into the translation file.
 */
final class TranslationCoverageTest extends TestCase
{
    /**
     * Matches `'some string'|t('cookiekit')` in a template, including the
     * variant with parameters.
     */
    private const TWIG_PATTERN = "/(['\"])((?:\\\\.|(?!\\1).)*)\\1\s*\|\s*t\(\s*'cookiekit'/s";

    public function testEveryTemplateStringHasADutchTranslation(): void
    {
        $translations = $this->translations();
        $missing = [];

        foreach ($this->templates() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);

            if (preg_match_all(self::TWIG_PATTERN, $source, $matches) === false) {
                continue;
            }

            foreach ($matches[2] as $string) {
                $string = self::unescape($string);

                if ($string !== '' && !array_key_exists($string, $translations)) {
                    $missing[$string] = basename($path);
                }
            }
        }

        self::assertSame([], $missing, $this->pasteable($missing));
    }

    /**
     * Guards the other direction as well: an unused key is dead weight and
     * usually the trace of a string that was reworded and half-updated.
     */
    public function testTheTranslationFileHasNoObviouslyDeadKeys(): void
    {
        $used = [];

        foreach ($this->templates() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);

            if (preg_match_all(self::TWIG_PATTERN, $source, $matches) !== false) {
                foreach ($matches[2] as $string) {
                    $used[self::unescape($string)] = true;
                }
            }

            // Strings translated from JavaScript inside a {% js %} block.
            if (preg_match_all("/Craft\.t\(\s*'cookiekit',\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1/s", $source, $matches) !== false) {
                foreach ($matches[2] as $string) {
                    $used[self::unescape($string)] = true;
                }
            }
        }

        // The category labels and descriptions live in a PHP array rather than
        // in a Craft::t() call, but they are translation keys all the same.
        foreach (array_merge(CategoryText::sourceLabels(), CategoryText::sourceDescriptions()) as $string) {
            $used[$string] = true;
        }

        // Strings translated from PHP rather than from a template: controllers,
        // console output, validators, settings labels and the lifetimes the
        // scan writes. Checking those by regex would be guesswork.
        foreach ($this->phpSources() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);

            if (preg_match_all("/Craft::t\(\s*'cookiekit',\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1/s", $source, $matches) !== false) {
                foreach ($matches[2] as $string) {
                    $used[self::unescape($string)] = true;
                }
            }
        }

        $unused = array_values(array_filter(
            array_keys($this->translations()),
            static fn(string $key): bool => !isset($used[$key]),
        ));

        // Lifetimes are generated from numbers, so they never appear literally.
        $generated = '/^(?:Session|Until removed|1 (?:second|minute|hour|day|month|year)|\{n\} \w+)$/';
        $unused = array_values(array_filter(
            $unused,
            static fn(string $key): bool => preg_match($generated, $key) !== 1,
        ));

        self::assertSame([], $unused, "Unused translation keys:\n  " . implode("\n  ", $unused));
    }

    /**
     * Twig and PHP both escape a quote inside a same-quoted string, so the
     * captured text is not yet the translation key.
     */
    private static function unescape(string $string): string
    {
        return str_replace(["\\'", '\\"'], ["'", '"'], $string);
    }

    /**
     * @return array<string, string>
     */
    private function translations(): array
    {
        /** @var array<string, string> $translations */
        $translations = require dirname(__DIR__, 2) . '/src/translations/nl/cookiekit.php';

        return $translations;
    }

    /**
     * @return list<string>
     */
    private function templates(): array
    {
        return array_merge(
            $this->glob('/src/templates'),
            $this->glob('/examples/templates'),
        );
    }

    /**
     * @return list<string>
     */
    private function phpSources(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function glob(string $relative): array
    {
        $root = dirname(__DIR__, 2) . $relative;

        if (!is_dir($root)) {
            return [];
        }

        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @param array<string, string> $missing
     */
    private function pasteable(array $missing): string
    {
        if ($missing === []) {
            return '';
        }

        $lines = [];
        foreach ($missing as $string => $file) {
            $escaped = str_replace("'", "\\'", $string);
            $lines[] = "    '{$escaped}' => '',  // {$file}";
        }

        return "Missing Dutch translations, paste into src/translations/nl/cookiekit.php:\n"
            . implode("\n", $lines);
    }
}
