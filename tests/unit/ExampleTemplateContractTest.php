<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Pins the shipped example templates to the contract in cookiekit.js.
 *
 * That script is hand-written and has no tests of its own, so without this the
 * examples would drift the first time someone tidied one up. Every assertion
 * here corresponds to a way a custom banner fails silently in the browser.
 *
 * The files contain Twig tags, which the HTML parser does not understand and
 * does not need to: every attribute this checks survives parsing intact.
 */
final class ExampleTemplateContractTest extends TestCase
{
    private const ACTIONS = ['accept-all', 'deny', 'customize', 'save', 'back', 'close'];

    /**
     * Utilities that set `display`. Any of these on an element the script
     * toggles would beat Tailwind v3's `[hidden]` rule and leave it stuck open.
     */
    private const DISPLAY_UTILITY = '/(?:^|\s)(?:(?:sm|md|lg|xl|2xl|dark|hover|focus|peer-checked):)*'
        . '(?:flex|grid|block|inline|inline-flex|inline-block|inline-grid|table|contents|flow-root|list-item)(?:\s|$)/';

    /**
     * @return array<string, array{string}>
     */
    public static function variants(): array
    {
        return [
            'bar' => ['bar.twig'],
            'corner' => ['corner.twig'],
            'modal' => ['modal.twig'],
            'sheet' => ['sheet.twig'],
        ];
    }

    #[DataProvider('variants')]
    public function testThereIsExactlyOneRootAndItStartsHidden(string $file): void
    {
        $roots = $this->crawl($file)->filter('[data-cookiekit-root]');

        // The script uses querySelector, so a second root is silently dead.
        self::assertCount(1, $roots, "{$file} must have exactly one root");
        self::assertTrue(self::first($roots)->hasAttribute('hidden'), 'the root must start hidden');
    }

    /**
     * `|raw` on the config produces markup the script cannot parse, and it
     * bails out without a word when that happens.
     */
    #[DataProvider('variants')]
    public function testTheConfigIsPrintedWithoutRaw(string $file): void
    {
        $source = $this->source($file);

        self::assertStringContainsString('data-cookiekit-config="{{ config }}"', $source);
        self::assertStringNotContainsString('config|raw', $source);
    }

    #[DataProvider('variants')]
    public function testTheBannerIsPresentInsideTheRootAndStartsHidden(string $file): void
    {
        $banners = $this->crawl($file)->filter('[data-cookiekit-root] [data-ck-banner]');

        self::assertCount(1, $banners, "{$file} must have exactly one banner inside the root");
        self::assertTrue(self::first($banners)->hasAttribute('hidden'), 'the banner must start hidden');
    }

    /**
     * The panel is mandatory even when a design never opens it: hideAll() runs
     * at page load for every returning visitor and dereferences it without a
     * null check, which throws before the consented scripts are unblocked.
     */
    #[DataProvider('variants')]
    public function testTheMandatoryPanelIsPulledIn(string $file): void
    {
        self::assertMatchesRegularExpression(
            "/\{%\s*include\s+'_cookiekit\/panel'/",
            $this->source($file),
            "{$file} must include the panel partial",
        );
    }

    #[DataProvider('variants')]
    public function testEveryActionIsOneTheScriptKnowsAndSitsInsideTheRoot(string $file): void
    {
        $crawler = $this->crawl($file);
        $values = [];

        foreach (self::elements($crawler->filter('[data-ck-action]')) as $node) {
            $values[] = $node->getAttribute('data-ck-action');
        }

        self::assertNotSame([], $values, "{$file} has no actions at all");

        foreach ($values as $value) {
            // An unknown value still gets preventDefault(), so a typo turns the
            // button into something that swallows clicks and does nothing.
            self::assertContains($value, self::ACTIONS, "unknown action \"{$value}\" in {$file}");
        }

        // The click handler is bound to the root, not to the document.
        self::assertCount(
            count($values),
            $crawler->filter('[data-cookiekit-root] [data-ck-action]'),
            "every action in {$file} must sit inside the root",
        );

        self::assertContains('accept-all', $values);
        self::assertContains('deny', $values, 'refusing must be as reachable as accepting');
    }

    /**
     * The one rule that makes these templates work in both Tailwind majors.
     */
    #[DataProvider('variants')]
    public function testNoDisplayUtilityOnAnElementTheScriptToggles(string $file): void
    {
        $this->assertNoDisplayUtility($this->crawl($file), $file);
    }

    public function testThePanelPartialSatisfiesTheContract(): void
    {
        $crawler = $this->crawl('panel.twig');

        $panels = $crawler->filter('[data-ck-panel]');
        self::assertCount(1, $panels);
        self::assertTrue(self::first($panels)->hasAttribute('hidden'), 'the panel must start hidden');

        $this->assertNoDisplayUtility($crawler, 'panel.twig');
    }

    /**
     * A checkbox hidden with `hidden` or `display:none` leaves the tab order,
     * and the script's focus() call on it fails without a word.
     */
    public function testTheCategorySwitchesAreRealFocusableInputs(): void
    {
        $crawler = $this->crawl('panel.twig');
        $inputs = $crawler->filter('input[data-ck-category]');

        self::assertGreaterThan(0, $inputs->count());

        foreach (self::elements($inputs) as $node) {
            $class = $node->getAttribute('class');

            self::assertStringContainsString('sr-only', $class, 'use sr-only, which keeps the tab stop');
            self::assertFalse($node->hasAttribute('hidden'), 'a hidden input cannot be focused');
            self::assertDoesNotMatchRegularExpression(
                '/(?:^|\s)hidden(?:\s|$)/',
                $class,
                'the hidden utility removes the input from the tab order',
            );
        }
    }

    public function testTheDetailsBlocksStartClosedAndMatchTheirToggle(): void
    {
        $crawler = $this->crawl('panel.twig');

        foreach (self::elements($crawler->filter('[data-ck-details]')) as $node) {
            self::assertTrue($node->hasAttribute('hidden'), 'details must start hidden');
        }

        $toggles = $crawler->filter('[data-ck-toggle-details]');
        self::assertGreaterThan(0, $toggles->count());

        foreach (self::elements($toggles) as $node) {
            // aria-expanded is the single source of truth the handler reads.
            self::assertSame('false', $node->getAttribute('aria-expanded'));
        }

        // The handler walks up to the section and back down to the details.
        self::assertSame(
            $toggles->count(),
            $crawler->filter('[data-ck-section] [data-ck-toggle-details]')->count(),
            'every toggle must sit inside a section',
        );
    }

    /**
     * These three class names live only inside cookiekit.js, so Tailwind never
     * sees them and the examples have to bring their own plain CSS.
     */
    public function testThePlaceholderClassesAreStyled(): void
    {
        $source = $this->source('panel.twig');

        foreach (['.ck-placeholder', '.ck-btn', '.ck-btn--primary'] as $class) {
            self::assertStringContainsString($class, $source, "no styling for {$class}");
        }
    }

    public function testTheHiddenGuardIsPresent(): void
    {
        self::assertStringContainsString(
            '[data-cookiekit-root][hidden]',
            $this->source('panel.twig'),
            'the guard that makes Tailwind v3 behave like v4 is missing',
        );
    }

    /**
     * DomCrawler yields DOMNode; only elements carry attributes.
     *
     * @return list<\DOMElement>
     */
    private static function elements(Crawler $crawler): array
    {
        $elements = [];

        foreach ($crawler as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private static function first(Crawler $crawler): \DOMElement
    {
        $node = $crawler->getNode(0);
        self::assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }

    private function assertNoDisplayUtility(Crawler $crawler, string $file): void
    {
        $selectors = ['[data-cookiekit-root]', '[data-ck-banner]', '[data-ck-panel]', '[data-ck-details]'];

        foreach ($selectors as $selector) {
            foreach (self::elements($crawler->filter($selector)) as $node) {
                self::assertDoesNotMatchRegularExpression(
                    self::DISPLAY_UTILITY,
                    $node->getAttribute('class'),
                    "{$file}: {$selector} carries a display utility, which breaks hiding it",
                );
            }
        }
    }

    private function crawl(string $file): Crawler
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent($this->source($file), 'UTF-8');

        return $crawler;
    }

    private function source(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/examples/templates/' . $file;
        $source = file_get_contents($path);

        self::assertIsString($source, "cannot read {$path}");

        return $source;
    }
}
