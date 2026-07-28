<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\VisibleCategories;
use viesrood\cookiekit\Plugin;

final class VisibleCategoriesTest extends TestCase
{
    /**
     * Shaped like CookiesService::getCookiesByCategory(), which pads every
     * category with an empty list.
     *
     * @param list<string> $filled
     * @return array<string, array<mixed>>
     */
    private function declaration(array $filled): array
    {
        $grouped = array_fill_keys(Plugin::CATEGORIES, []);

        foreach ($filled as $category) {
            $grouped[$category] = ['a cookie'];
        }

        return $grouped;
    }

    public function testEmptyCategoriesAreLeftOut(): void
    {
        $visible = VisibleCategories::resolve($this->declaration(['statistics']), true);

        self::assertSame(['necessary', 'statistics'], $visible);
    }

    /**
     * "Deny" and "Save preferences" both hard-code necessary in the script, so
     * hiding it would make the offered list disagree with what gets granted.
     */
    public function testNecessaryStaysEvenWithNothingDeclared(): void
    {
        self::assertSame(['necessary'], VisibleCategories::resolve($this->declaration([]), true));
    }

    public function testAFullDeclarationKeepsEverythingInOrder(): void
    {
        $visible = VisibleCategories::resolve($this->declaration(Plugin::CATEGORIES), true);

        self::assertSame(Plugin::CATEGORIES, $visible);
    }

    /**
     * The off switch has to be exactly the old behaviour, or turning it off is
     * not a way back out.
     */
    public function testTurningItOffOffersEverything(): void
    {
        self::assertSame(Plugin::CATEGORIES, VisibleCategories::resolve($this->declaration([]), false));
        self::assertSame(Plugin::CATEGORIES, VisibleCategories::resolve([], false));
    }

    public function testTheOriginalOrderIsPreserved(): void
    {
        $visible = VisibleCategories::resolve($this->declaration(['marketing', 'preferences']), true);

        self::assertSame(['necessary', 'preferences', 'marketing'], $visible);
    }

    /**
     * The result is handed to Twig and JSON-encoded into the config attribute,
     * so it has to be a list. A filtered array with holes would arrive in the
     * browser as an object and break `config.categories.slice()`.
     */
    public function testTheResultIsAListAndNotASparseArray(): void
    {
        $visible = VisibleCategories::resolve($this->declaration(['marketing']), true);

        self::assertSame([0, 1], array_keys($visible));
        self::assertSame('["necessary","marketing"]', json_encode($visible));
    }

    public function testAMissingKeyIsTreatedAsEmpty(): void
    {
        self::assertSame(['necessary'], VisibleCategories::resolve([], true));
    }
}
