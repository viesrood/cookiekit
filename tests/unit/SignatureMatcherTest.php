<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\SignatureMatcher;

/**
 * @phpstan-import-type VendorSignature from SignatureMatcher
 */
final class SignatureMatcherTest extends TestCase
{
    private SignatureMatcher $matcher;

    protected function setUp(): void
    {
        /** @var array<string, VendorSignature> $signatures */
        $signatures = require dirname(__DIR__, 2) . '/src/data/signatures.php';

        $this->matcher = new SignatureMatcher($signatures);
    }

    /**
     * The rule the whole scoring model exists for: Analytics and Tag Manager
     * share a host and are only told apart by their path.
     */
    public function testTagManagerAndAnalyticsAreSeparatedByPath(): void
    {
        self::assertSame(
            'google-tag-manager',
            $this->matcher->matchUrl('https://www.googletagmanager.com/gtm.js?id=GTM-ABCD123')['key'] ?? null,
        );

        self::assertSame(
            'google-analytics-4',
            $this->matcher->matchUrl('https://www.googletagmanager.com/gtag/js?id=G-3Y7GKHRGGR')['key'] ?? null,
        );

        self::assertSame(
            'google-tag-manager',
            $this->matcher->matchUrl('https://www.googletagmanager.com/ns.html?id=GTM-ABCD123')['key'] ?? null,
        );
    }

    public function testAHostMatchWithoutAPathMatchIsNoMatch(): void
    {
        // googletagmanager.com only ever serves gtm.js, ns.html or gtag/js;
        // anything else on that host must not be attributed to either vendor.
        self::assertNull($this->matcher->matchUrl('https://www.googletagmanager.com/something-else.js'));
    }

    public function testGoogleComIsSharedBetweenMapsAndRecaptcha(): void
    {
        self::assertSame(
            'google-maps',
            $this->matcher->matchUrl('https://www.google.com/maps/embed?pb=!1m18')['key'] ?? null,
        );

        self::assertSame(
            'google-recaptcha',
            $this->matcher->matchUrl('https://www.google.com/recaptcha/api.js')['key'] ?? null,
        );
    }

    public function testCommonThirdParties(): void
    {
        $cases = [
            'https://connect.facebook.net/en_US/fbevents.js' => 'facebook-pixel',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' => 'youtube',
            'https://player.vimeo.com/video/123456' => 'vimeo',
            'https://static.hotjar.com/c/hotjar-123.js' => 'hotjar',
            'https://www.clarity.ms/tag/abcdef' => 'microsoft-clarity',
            'https://consent.cookiefirst.com/sites/example.nl/consent.js' => 'cookiefirst',
            'https://fonts.googleapis.com/css2?family=Inter' => 'google-fonts',
            'https://shop.eventix.io/build/integrate.js' => 'eventix',
        ];

        foreach ($cases as $url => $expected) {
            self::assertSame($expected, $this->matcher->matchUrl($url)['key'] ?? null, $url);
        }
    }

    public function testWildcardHostsMatchSubdomainsOnly(): void
    {
        self::assertSame(
            'microsoft-clarity',
            $this->matcher->matchUrl('https://x.clarity.ms/collect')['key'] ?? null,
        );

        self::assertNull($this->matcher->matchUrl('https://notclarity.example.com/tag.js'));
    }

    public function testUnknownHostsDoNotMatch(): void
    {
        self::assertNull($this->matcher->matchUrl('https://example.com/app.js'));
        self::assertNull($this->matcher->matchUrl('/local/script.js'));
        self::assertNull($this->matcher->matchUrl(''));
    }

    public function testProtocolRelativeUrlsStillResolve(): void
    {
        self::assertSame(
            'google-analytics-4',
            $this->matcher->matchUrl('//www.googletagmanager.com/gtag/js?id=G-3Y7GKHRGGR')['key'] ?? null,
        );
    }

    public function testScoringIsDeterministic(): void
    {
        $url = 'https://www.googletagmanager.com/gtag/js?id=G-3Y7GKHRGGR';

        $first = $this->matcher->matchUrl($url);
        $second = $this->matcher->matchUrl($url);

        self::assertSame($first, $second);
        self::assertSame(SignatureMatcher::SCORE_EXACT_HOST_AND_PATH, $first['score'] ?? null);
    }

    public function testInlineSnippetsAreMatched(): void
    {
        $keys = array_column($this->matcher->matchInline("gtag('config', 'G-3Y7GKHRGGR');"), 'key');
        self::assertContains('google-analytics-4', $keys);

        $keys = array_column($this->matcher->matchInline("(function(w,d,s,l,i){})(window,document,'script','dataLayer','GTM-ABCD123');"), 'key');
        self::assertContains('google-tag-manager', $keys);

        $keys = array_column($this->matcher->matchInline("fbq('init', '123456');"), 'key');
        self::assertContains('facebook-pixel', $keys);

        self::assertSame([], $this->matcher->matchInline('console.log("hello");'));
    }

    /**
     * Every plain gtag bootstrap contains `dataLayer.push(arguments)`. Reading
     * that as a tag container would send the user looking for a GTM account
     * they do not have.
     */
    public function testThePlainGtagBootstrapIsNotMistakenForTagManager(): void
    {
        $bootstrap = "window.dataLayer = window.dataLayer || [];\n"
            . "function gtag(){dataLayer.push(arguments);}\n"
            . "gtag('js', new Date());\n"
            . "gtag('config', 'G-3Y7GKHRGGR');";

        $keys = array_column($this->matcher->matchInline($bootstrap), 'key');

        self::assertContains('google-analytics-4', $keys);
        self::assertNotContains('google-tag-manager', $keys);
    }

    public function testCookieNamesResolveToAVendor(): void
    {
        $match = $this->matcher->matchCookieName('_ga');
        self::assertSame('google-analytics-4', $match['key'] ?? null);
        self::assertSame('_ga', $match['cookie']['declaredAs'] ?? null);

        $match = $this->matcher->matchCookieName('_ga_G3Y7GKHRGGR');
        self::assertSame('google-analytics-4', $match['key'] ?? null);
        self::assertSame('_ga_*', $match['cookie']['declaredAs'] ?? null);

        $match = $this->matcher->matchCookieName('CraftSessionId');
        self::assertSame('craft-cms', $match['key'] ?? null);
        self::assertSame('necessary', $match['cookie']['category'] ?? null);

        self::assertNull($this->matcher->matchCookieName('sid_9f2'));
    }

    public function testAnExactCookieDeclarationBeatsAPrefixOne(): void
    {
        // `_gcl_au` is declared exactly and would also be caught by `_gcl_`.
        $match = $this->matcher->matchCookieName('_gcl_au');

        self::assertSame('_gcl_au', $match['cookie']['declaredAs'] ?? null);
    }

    public function testStorageKeysResolve(): void
    {
        $match = $this->matcher->matchStorageKey('yt-remote-device-id', 'local');
        self::assertSame('youtube', $match['key'] ?? null);

        // The exact session-storage rule wins over the `yt-remote-` prefix.
        $match = $this->matcher->matchStorageKey('yt-remote-session-app', 'session');
        self::assertSame('youtube', $match['key'] ?? null);
        self::assertSame('exact', $match['storage']['match'] ?? null);

        // A key that only exists as a local-storage rule is not a session hit.
        self::assertNull($this->matcher->matchStorageKey('yt-player-quality', 'session'));
        self::assertNull($this->matcher->matchStorageKey('my-app-state', 'local'));
    }

    public function testBlockingSnippetIsPasteReady(): void
    {
        $snippet = SignatureMatcher::blockingSnippet(
            'script',
            'https://www.googletagmanager.com/gtag/js?id=G-3Y7GKHRGGR',
            'statistics',
            ['async' => ''],
        );

        self::assertSame(
            '<script type="text/plain" data-cookiekit="statistics" async'
            . ' data-cookiekit-src="https://www.googletagmanager.com/gtag/js?id=G-3Y7GKHRGGR"></script>',
            $snippet,
        );
    }

    public function testBlockingSnippetForAnIframeKeepsItsAttributes(): void
    {
        $snippet = SignatureMatcher::blockingSnippet(
            'iframe',
            'https://www.youtube-nocookie.com/embed/abc',
            'marketing',
            ['width' => '560', 'height' => '315'],
        );

        self::assertSame(
            '<iframe data-cookiekit="marketing" width="560" height="315"'
            . ' data-cookiekit-src="https://www.youtube-nocookie.com/embed/abc"></iframe>',
            $snippet,
        );
    }

    public function testBlockingSnippetEscapesItsInput(): void
    {
        $snippet = SignatureMatcher::blockingSnippet('script', 'https://evil.test/"><script>', 'marketing');

        self::assertStringNotContainsString('"><script>', $snippet);
    }
}
