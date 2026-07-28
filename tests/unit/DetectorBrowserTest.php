<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\SignatureMatcher;
use viesrood\cookiekit\services\DetectorService;

/**
 * @phpstan-import-type VendorSignature from SignatureMatcher
 * @phpstan-import-type DetectedItem from DetectorService
 * @phpstan-import-type BrowserReport from DetectorService
 */
final class DetectorBrowserTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private DetectorService $detector;

    protected function setUp(): void
    {
        /** @var array<string, VendorSignature> $signatures */
        $signatures = require dirname(__DIR__, 2) . '/src/data/signatures.php';

        $this->detector = new DetectorService();
        $this->detector->setMatcher(new SignatureMatcher($signatures));
    }

    public function testObservedCookiesCarryTheirRealLifetime(): void
    {
        $items = $this->detect([
            'cookies' => [
                ['name' => '_ga', 'domain' => '.example.test', 'expires' => self::NOW + (730 * 86400)],
                ['name' => '_ga_G3Y7GKHRGGR', 'domain' => '.example.test', 'expires' => self::NOW + (730 * 86400)],
            ],
            'consent' => ['necessary', 'statistics'],
        ]);

        self::assertCount(2, $items);

        foreach ($items as $item) {
            self::assertSame('observed', $item['confidence']);
            self::assertSame('browser', $item['source']);
            self::assertSame('2 years', $item['duration']);
            self::assertSame('statistics', $item['category']);
            self::assertSame('Google Ireland Ltd.', $item['provider']);
        }

        // The concrete property id folds onto the wildcard declaration, so one
        // declared row keeps covering it.
        self::assertSame('_ga_*', $items[1]['declaredAs']);
    }

    public function testASessionCookieIsReportedAsSuch(): void
    {
        $items = $this->detect([
            'cookies' => [['name' => 'CraftSessionId', 'domain' => 'example.test', 'expires' => -1]],
            'consent' => ['necessary'],
        ]);

        self::assertSame('Session', $items[0]['duration']);
        self::assertSame('necessary', $items[0]['category']);
        self::assertFalse($items[0]['preConsent']);
    }

    /**
     * The finding that actually gets sites fined: a tracking cookie present
     * while the visitor granted nothing beyond the strictly necessary.
     */
    public function testTrackingBeforeConsentIsFlagged(): void
    {
        $items = $this->detect([
            'cookies' => [
                ['name' => '_ga', 'domain' => '.example.test', 'expires' => self::NOW + 86400],
                ['name' => 'CraftSessionId', 'domain' => 'example.test', 'expires' => -1],
            ],
            'consent' => [],
        ]);

        self::assertTrue($items[0]['preConsent'], '_ga before consent is a violation');
        self::assertFalse($items[1]['preConsent'], 'a session cookie is not');
    }

    public function testNothingIsFlaggedOnceTheCategoryWasGranted(): void
    {
        $items = $this->detect([
            'cookies' => [['name' => '_fbp', 'domain' => '.example.test', 'expires' => self::NOW + 86400]],
            'consent' => ['necessary', 'marketing'],
        ]);

        self::assertFalse($items[0]['preConsent']);
        self::assertSame(['necessary', 'marketing'], $items[0]['consentSeen']);
    }

    public function testAnUnknownCookieGetsNoInventedCategory(): void
    {
        $items = $this->detect([
            'cookies' => [['name' => 'sid_9f2', 'domain' => 'example.test', 'expires' => self::NOW + 86400]],
            'consent' => ['necessary'],
        ]);

        self::assertSame(DetectorService::CATEGORY_UNKNOWN, $items[0]['category']);
        self::assertSame('', $items[0]['purpose']);
        self::assertNull($items[0]['signatureKey']);

        // Unknown means unknown, so it cannot be a pre-consent verdict either.
        self::assertFalse($items[0]['preConsent']);
    }

    public function testStorageKeysAreRecognised(): void
    {
        $items = $this->detect([
            'cookies' => [],
            'local' => ['yt-remote-device-id', 'my-own-app-state'],
            'session' => ['yt-remote-session-app'],
            'consent' => [],
        ]);

        self::assertCount(3, $items);
        self::assertSame('storage', $items[0]['type']);
        self::assertSame('youtube', $items[0]['signatureKey']);
        self::assertSame('localStorage', $items[0]['evidenceDetail']);
        self::assertTrue($items[0]['preConsent']);

        self::assertNull($items[1]['signatureKey']);
        self::assertSame('sessionStorage', $items[2]['evidenceDetail']);
    }

    public function testMalformedNamesAreDropped(): void
    {
        $items = $this->detect([
            'cookies' => [
                ['name' => '_ga=GA1.1.123', 'domain' => 'example.test', 'expires' => -1],
                ['name' => '', 'domain' => 'example.test', 'expires' => -1],
                ['name' => str_repeat('a', 200), 'domain' => 'example.test', 'expires' => -1],
                ['name' => '_ga', 'domain' => 'example.test', 'expires' => -1],
            ],
            'consent' => ['necessary', 'statistics'],
        ]);

        self::assertCount(1, $items);
        self::assertSame('_ga', $items[0]['name']);
    }

    /**
     * @param array{cookies: list<array{name: string, domain?: string, expires?: float|int|null}>, local?: list<string>, session?: list<string>, consent: list<string>} $report
     * @return list<DetectedItem>
     */
    private function detect(array $report): array
    {
        /** @var BrowserReport $full */
        $full = $report + [
            'url' => 'https://example.test/',
            'local' => [],
            'session' => [],
        ];

        return $this->detector->detectFromBrowser($full, 1, self::NOW);
    }
}
