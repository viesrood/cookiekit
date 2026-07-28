<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\SignatureMatcher;
use viesrood\cookiekit\services\HtmlBlocker;

final class HtmlBlockerTest extends TestCase
{
    private HtmlBlocker $blocker;

    protected function setUp(): void
    {
        $signatures = require dirname(__DIR__, 2) . '/src/data/signatures.php';
        $this->blocker = new HtmlBlocker(new SignatureMatcher($signatures));
    }

    public function testItBlocksRecognisedExternalAndInlineScripts(): void
    {
        $html = <<<'HTML'
        <!doctype html><html><head>
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>
        <script>gtag('config', 'G-ABC123');</script>
        </head><body></body></html>
        HTML;

        $result = $this->blocker->rewrite($html);

        self::assertStringContainsString('type="text/plain"', $result);
        self::assertStringContainsString('data-cookiekit="statistics"', $result);
        self::assertStringContainsString('data-cookiekit-src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"', $result);
        self::assertStringNotContainsString('<script async src=', $result);
        self::assertStringContainsString('async', $result);
    }

    public function testItBlocksFramesAndSilentlyBlocksPixels(): void
    {
        $html = <<<'HTML'
        <!doctype html><html><body>
        <iframe src="https://www.youtube.com/embed/demo"></iframe>
        <img src="https://www.facebook.com/tr?id=1">
        </body></html>
        HTML;

        $result = $this->blocker->rewrite($html);

        self::assertStringContainsString('data-cookiekit-src="https://www.youtube.com/embed/demo"', $result);
        self::assertStringContainsString('data-cookiekit="marketing"', $result);
        self::assertStringContainsString('data-ck-silent', $result);
    }

    public function testNecessaryUnknownManualAndIgnoredMarkupStayUntouched(): void
    {
        $html = <<<'HTML'
        <!doctype html><html><body>
        <script src="/assets/app.js"></script>
        <script src="https://www.google.com/recaptcha/api.js"></script>
        <script type="text/plain" data-cookiekit="statistics" data-cookiekit-src="https://www.googletagmanager.com/gtag/js"></script>
        <div data-cookiekit-ignore><iframe src="https://www.youtube.com/embed/demo"></iframe></div>
        <div data-cookiekit-root><script>gtag('config', 'G-ABC123');</script></div>
        </body></html>
        HTML;

        $result = $this->blocker->rewrite($html);

        self::assertStringContainsString('<script src="/assets/app.js"></script>', $result);
        self::assertStringContainsString('<script src="https://www.google.com/recaptcha/api.js"></script>', $result);
        self::assertSame(1, substr_count($result, 'data-cookiekit-src="https://www.googletagmanager.com/gtag/js"'));
        self::assertStringContainsString('<iframe src="https://www.youtube.com/embed/demo"></iframe>', $result);
        self::assertStringContainsString("<script>gtag('config', 'G-ABC123');</script>", $result);
    }

    public function testItIsIdempotentAndLeavesFragmentsAlone(): void
    {
        $html = '<!doctype html><html><body><iframe src="https://www.youtube.com/embed/demo"></iframe></body></html>';
        $once = $this->blocker->rewrite($html);

        self::assertSame($once, $this->blocker->rewrite($once));
        self::assertSame('<script src="https://example.test/a.js"></script>', $this->blocker->rewrite(
            '<script src="https://example.test/a.js"></script>',
        ));
    }
}
