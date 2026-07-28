<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\SignatureMatcher;
use viesrood\cookiekit\services\HtmlBlocker;

/**
 * The guarantee: automatic blocking touches the tags it was asked to touch and
 * nothing else.
 *
 * Every case here is something the first implementation silently broke by
 * serialising the parsed document back out.
 *
 * @phpstan-import-type VendorSignature from SignatureMatcher
 */
final class HtmlBlockerGoldenTest extends TestCase
{
    private HtmlBlocker $blocker;
    private string $source;
    private string $result;

    protected function setUp(): void
    {
        /** @var array<string, VendorSignature> $signatures */
        $signatures = require dirname(__DIR__, 2) . '/src/data/signatures.php';

        $this->blocker = new HtmlBlocker(new SignatureMatcher($signatures));

        $source = file_get_contents(dirname(__DIR__) . '/fixtures/blocker-page.html');
        self::assertIsString($source);

        $this->source = $source;
        $this->result = $this->blocker->rewrite($source);
    }

    /**
     * Non-ASCII inside a script is not decoded by browsers, so an entity there
     * is not a cosmetic difference: the string literally changes.
     */
    public function testTextInsideScriptsAndStylesIsUntouched(): void
    {
        foreach ([
            'Goedemiddag, privé bezoeker',
            'vanaf €5 — inclusief btw',
            'Café Zwölf',
            'Privé feest — vanaf €5',
            'content: "→";',
            'content: "€";',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $this->result, "mangled: {$fragment}");
        }

        // Not asserted as "contains no entities anywhere": the fixture also
        // carries entities the author typed, and those have to survive too.
        // See testEntitiesTheAuthorWroteStayAsTheyWere.
        self::assertSame(
            substr_count($this->source, '&eacute;'),
            substr_count($this->result, '&eacute;'),
            'the number of entities changed, so something was re-encoded',
        );
    }

    /**
     * SVG is case-sensitive and libxml's HTML parser is not.
     */
    public function testInlineSvgKeepsItsCamelCase(): void
    {
        foreach (['viewBox=', '<linearGradient', '<clipPath', 'textLength=', 'lengthAdjust='] as $fragment) {
            self::assertStringContainsString($fragment, $this->result, "lowercased: {$fragment}");
        }
    }

    public function testEntitiesTheAuthorWroteStayAsTheyWere(): void
    {
        self::assertStringContainsString('&euro;5, een caf&eacute;', $this->result);
        self::assertStringContainsString('Zw&ouml;lf', $this->result);
    }

    public function testTheRecognisedTagsAreActuallyBlocked(): void
    {
        // The analytics loader.
        self::assertStringContainsString('data-cookiekit="statistics"', $this->result);
        self::assertStringContainsString(
            'data-cookiekit-src="https://www.googletagmanager.com/gtag/js?id=G-3Y7GKHRGGR"',
            $this->result,
        );
        self::assertStringNotContainsString('async src="https://www.googletagmanager.com', $this->result);

        // The YouTube embed.
        self::assertStringContainsString(
            'data-cookiekit-src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"',
            $this->result,
        );
    }

    /**
     * Moving `src` while leaving `srcset` behind means the browser still makes
     * the request, while the control panel reports the pixel as blocked.
     */
    public function testSrcsetMovesToo(): void
    {
        self::assertStringContainsString('data-cookiekit-srcset=', $this->result);
        self::assertDoesNotMatchRegularExpression(
            '/<img[^>]*\ssrcset="https:\/\/www\.facebook\.com/i',
            $this->result,
        );
        self::assertStringContainsString('data-ck-silent', $this->result);
    }

    /**
     * A module recreated as a classic script turns `import` into a SyntaxError.
     */
    public function testTheOriginalScriptTypeIsPreserved(): void
    {
        // The module here is first-party and not blocked at all, so it must be
        // completely untouched.
        self::assertStringContainsString('<script type="module">', $this->result);
    }

    /**
     * `document.querySelectorAll` never descends into a template's fragment, so
     * blocking in there means blocking something that can never come back.
     */
    public function testScriptsInsideATemplateAreLeftAlone(): void
    {
        self::assertStringContainsString(
            '<script src="https://www.googletagmanager.com/gtag/js?id=G-INSIDE-TEMPLATE"></script>',
            $this->result,
        );
    }

    public function testTheIgnoreEscapeHatchIsHonoured(): void
    {
        self::assertStringContainsString(
            '<script src="https://static.hotjar.com/c/hotjar-999.js"></script>',
            $this->result,
        );
    }

    public function testFirstPartyResourcesAreLeftAlone(): void
    {
        self::assertStringContainsString('<img src="/images/local.png"', $this->result);
    }

    /**
     * The whole promise in one assertion: everything except the tags we
     * deliberately rewrote is byte for byte what came in.
     */
    public function testEverythingElseIsByteIdentical(): void
    {
        $strip = static fn(string $html): string => preg_replace(
            '/<(script|iframe|img)\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/i',
            '<TAG>',
            $html,
        ) ?? '';

        self::assertSame($strip($this->source), $strip($this->result));
    }

    public function testAPageWithNothingToBlockComesBackUnchanged(): void
    {
        $html = "<html><head><title>Privé — €5</title></head><body><svg viewBox=\"0 0 2 2\"/></body></html>";

        self::assertSame($html, $this->blocker->rewrite($html));
    }

    public function testNonHtmlIsLeftAlone(): void
    {
        $xml = '<?xml version="1.0"?><rss><channel><title>Privé</title></channel></rss>';

        self::assertSame($xml, $this->blocker->rewrite($xml));
        self::assertSame('', $this->blocker->rewrite(''));
    }
}
