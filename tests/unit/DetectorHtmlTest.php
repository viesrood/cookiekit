<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\SignatureMatcher;
use viesrood\cookiekit\services\DetectorService;

/**
 * @phpstan-import-type VendorSignature from SignatureMatcher
 * @phpstan-import-type DetectedItem from DetectorService
 */
final class DetectorHtmlTest extends TestCase
{
    private DetectorService $detector;

    protected function setUp(): void
    {
        /** @var array<string, VendorSignature> $signatures */
        $signatures = require dirname(__DIR__, 2) . '/src/data/signatures.php';

        $this->detector = new DetectorService();
        $this->detector->setMatcher(new SignatureMatcher($signatures));
    }

    /**
     * The site this plugin is developed against loads exactly one tracker, and
     * loads it unblocked. If the scan cannot see that, nothing else matters.
     */
    public function testTheRealLabsHomepageYieldsAnUnblockedAnalyticsTag(): void
    {
        $items = $this->detect('labs-home.html', 'https://viesrood-labs.ddev.site/');

        $unblocked = $this->ofType($items, 'unblocked');

        // Two separate problems, and both need fixing: the loader script, and
        // the inline gtag('config', …) that would still run if you only
        // blocked the loader.
        self::assertSame(
            ['inline:google-analytics-4', 'www.googletagmanager.com/gtag/js'],
            $this->sortedNames($unblocked),
        );

        foreach ($unblocked as $item) {
            self::assertSame('google-analytics-4', $item['signatureKey']);
            self::assertSame('statistics', $item['category']);
        }

        // Nothing here is a tag container: plain gtag defines
        // `function gtag(){dataLayer.push(arguments);}` and that must not be
        // mistaken for Tag Manager.
        self::assertNotContains('google-tag-manager', array_column($items, 'signatureKey'));

        // And the snippet it hands you keeps the async that was on the tag.
        $loader = $this->named($unblocked, 'www.googletagmanager.com/gtag/js');
        self::assertStringContainsString('type="text/plain"', $loader['snippet']);
        self::assertStringContainsString('data-cookiekit="statistics"', $loader['snippet']);
        self::assertStringContainsString('async', $loader['snippet']);

        $cookies = $this->declaredNames($this->ofType($items, 'cookie'));
        self::assertContains('_ga', $cookies);
        self::assertContains('_ga_*', $cookies);
        self::assertContains('_gid', $cookies);

        // Inferred, not observed: no JavaScript was executed to see them.
        foreach ($this->ofType($items, 'cookie') as $cookie) {
            self::assertSame('inferred', $cookie['confidence']);
        }
    }

    public function testCorrectlyBlockedMarkupRaisesNoAlarm(): void
    {
        $items = $this->detect('blocked.html', 'https://example.test/');

        self::assertSame([], $this->ofType($items, 'unblocked'));
        self::assertSame([], $this->ofType($items, 'miscategorised'));

        // The cookies still belong in the declaration: blocked is not absent.
        self::assertContains('_ga', $this->declaredNames($this->ofType($items, 'cookie')));
        self::assertContains('YSC', $this->declaredNames($this->ofType($items, 'cookie')));
    }

    public function testBlockingUnderTheWrongCategoryIsReported(): void
    {
        $items = $this->detect('miscategorised.html', 'https://example.test/');

        $wrong = $this->ofType($items, 'miscategorised');
        self::assertCount(1, $wrong);
        self::assertSame('google-analytics-4', $wrong[0]['signatureKey']);
        self::assertStringContainsString('marketing', $wrong[0]['evidenceDetail']);
        self::assertStringContainsString('statistics', $wrong[0]['evidenceDetail']);

        // It is blocked, just filed wrong, so it is not also "unblocked".
        self::assertSame([], $this->ofType($items, 'unblocked'));
    }

    public function testTheTagManagerFallbackFrameInsideNoscriptIsFound(): void
    {
        $items = $this->detect('gtm-noscript.html', 'https://example.test/');

        $unblocked = $this->ofType($items, 'unblocked');
        $names = array_column($unblocked, 'name');

        self::assertContains('www.googletagmanager.com/ns.html', $names);

        // A container is reported as such, and claims no cookies of its own.
        $vendors = $this->ofType($items, 'vendor');
        self::assertContains('google-tag-manager', array_column($vendors, 'signatureKey'));
    }

    public function testEmbedsAndPixelsAreEachRecognised(): void
    {
        $items = $this->detect('embeds.html', 'https://example.test/');

        $keys = array_unique(array_column($this->ofType($items, 'unblocked'), 'signatureKey'));
        sort($keys);

        self::assertSame(['facebook-pixel', 'google-maps', 'vimeo', 'youtube'], $keys);
    }

    public function testStylesheetsAreReportedButNeverAsUnblocked(): void
    {
        $items = $this->detect('embeds.html', 'https://example.test/');

        // cookiekit.js can swap scripts and [data-cookiekit-src] elements; it
        // has no mechanism at all for a <link>, so calling it "unblocked" would
        // be telling the user to fix something the plugin cannot fix.
        self::assertNotContains('google-fonts', array_column($this->ofType($items, 'unblocked'), 'signatureKey'));
        self::assertContains('google-fonts', array_column($this->ofType($items, 'vendor'), 'signatureKey'));
    }

    public function testFirstPartyResourcesAreIgnored(): void
    {
        $items = $this->detect('embeds.html', 'https://example.test/');

        foreach ($items as $item) {
            self::assertStringNotContainsString('/images/local.jpg', $item['evidenceDetail']);
            self::assertStringNotContainsString('/css/style.css', $item['evidenceDetail']);
        }
    }

    /**
     * autoInject writes the banner into every page, and that banner contains a
     * declaration table full of third-party names plus example markup. If the
     * scan read its own output it would report the plugin to itself forever.
     */
    public function testThePluginsOwnBannerIsSkipped(): void
    {
        self::assertSame([], $this->detect('own-banner.html', 'https://example.test/'));
    }

    public function testAnEmptyDocumentYieldsNothing(): void
    {
        self::assertSame([], $this->detector->detectFromHtml('', 'https://example.test/', 1));
        self::assertSame([], $this->detector->detectFromHtml('   ', 'https://example.test/', 1));
    }

    public function testRawSourceSweepCatchesUrlsBuiltInsideJavaScript(): void
    {
        $html = <<<'HTML'
        <html><body><script>
            var host = 'https://static.hotjar.com/c/hotjar-1234.js';
            loadLater(host);
        </script></body></html>
        HTML;

        $keys = array_column($this->detector->detectFromRawSource($html, 'https://example.test/', 1), 'signatureKey');

        self::assertContains('hotjar', $keys);
    }

    /**
     * @param list<DetectedItem> $items
     * @return list<DetectedItem>
     */
    private function ofType(array $items, string $type): array
    {
        return array_values(array_filter($items, static fn(array $item): bool => $item['type'] === $type));
    }

    /**
     * @param list<DetectedItem> $items
     * @return DetectedItem
     */
    private function named(array $items, string $name): array
    {
        foreach ($items as $item) {
            if ($item['name'] === $name) {
                return $item;
            }
        }

        self::fail("No finding named {$name}");
    }

    /**
     * @param list<DetectedItem> $items
     * @return list<string>
     */
    private function sortedNames(array $items): array
    {
        $names = array_values(array_unique(array_column($items, 'name')));
        sort($names);

        return $names;
    }

    /**
     * @param list<DetectedItem> $items
     * @return list<string>
     */
    private function declaredNames(array $items): array
    {
        return array_values(array_unique(array_column($items, 'declaredAs')));
    }

    /**
     * @return list<DetectedItem>
     */
    private function detect(string $fixture, string $pageUrl): array
    {
        $html = file_get_contents(dirname(__DIR__) . '/fixtures/' . $fixture);
        self::assertIsString($html);

        return $this->detector->detectFromHtml($html, $pageUrl, 1);
    }
}
