<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\CategoryText;
use viesrood\cookiekit\Plugin;

final class CategoryTextTest extends TestCase
{
    /**
     * The test that catches a fifth category being added to the constant and
     * forgotten here, which would otherwise surface as an empty heading in the
     * preferences panel.
     */
    public function testEveryCategoryHasALabelAndADescription(): void
    {
        self::assertSame([], CategoryText::missingKeys());
    }

    public function testTheKeysMatchThePluginCategoriesExactly(): void
    {
        self::assertSame(Plugin::CATEGORIES, array_keys(CategoryText::sourceLabels()));
        self::assertSame(Plugin::CATEGORIES, array_keys(CategoryText::sourceDescriptions()));
    }

    public function testNothingIsEmpty(): void
    {
        foreach (CategoryText::sourceLabels() as $category => $label) {
            self::assertNotSame('', trim($label), "label for {$category}");
        }

        foreach (CategoryText::sourceDescriptions() as $category => $description) {
            self::assertNotSame('', trim($description), "description for {$category}");
        }
    }

    /**
     * The source strings double as translation keys, so they have to match the
     * bundled templates character for character.
     */
    public function testTheSourceStringsAreTranslationKeys(): void
    {
        $nl = require dirname(__DIR__, 2) . '/src/translations/nl/cookiekit.php';

        foreach (CategoryText::sourceLabels() as $label) {
            self::assertArrayHasKey($label, $nl, "no Dutch translation for label \"{$label}\"");
        }

        foreach (CategoryText::sourceDescriptions() as $description) {
            self::assertArrayHasKey($description, $nl, 'no Dutch translation for a category description');
        }
    }
}
