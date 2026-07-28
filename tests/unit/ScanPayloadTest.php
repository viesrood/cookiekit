<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\ScanPayload;

/**
 * The gate between the outside world and the findings table.
 */
final class ScanPayloadTest extends TestCase
{
    public function testFlattensPassesIntoReports(): void
    {
        $reports = ScanPayload::normalise([
            'site' => 'https://example.test/',
            'passes' => [
                [
                    'name' => 'noConsent',
                    'consent' => [],
                    'pages' => [['url' => 'https://example.test/', 'cookies' => [['name' => '_ga']]]],
                ],
                [
                    'name' => 'allAccepted',
                    'consent' => ['necessary', 'statistics'],
                    'pages' => [['url' => 'https://example.test/over-ons', 'cookies' => [['name' => '_gid']]]],
                ],
            ],
        ]);

        self::assertCount(2, $reports);
        self::assertSame([], $reports[0]['consent']);
        self::assertSame(['necessary', 'statistics'], $reports[1]['consent']);
        self::assertSame('_gid', $reports[1]['cookies'][0]['name']);
    }

    /**
     * The scanner sends names. A payload carrying values is either a broken
     * scanner or someone poking at the endpoint.
     */
    public function testEntriesCarryingAValueAreDropped(): void
    {
        $report = $this->firstReport([
            ['name' => '_ga=GA1.1.1234567890.1234567890'],
            ['name' => '_ga'],
        ]);

        self::assertCount(1, $report['cookies']);
        self::assertSame('_ga', $report['cookies'][0]['name']);
    }

    public function testMalformedNamesAreDropped(): void
    {
        $report = $this->firstReport([
            ['name' => ''],
            ['name' => 'has space'],
            ['name' => str_repeat('a', 129)],
            ['name' => '"quoted"'],
            ['name' => '__Secure-YEC'],
        ]);

        self::assertSame(['__Secure-YEC'], array_column($report['cookies'], 'name'));
    }

    public function testABareStringIsAcceptedAsAName(): void
    {
        $report = $this->firstReport(['_fbp', '_ga']);

        self::assertSame(['_fbp', '_ga'], array_column($report['cookies'], 'name'));
    }

    public function testOversizedPayloadsAreTruncated(): void
    {
        $cookies = [];
        for ($i = 0; $i < ScanPayload::MAX_ITEMS_PER_PAGE + 50; $i++) {
            $cookies[] = ['name' => 'c' . $i];
        }

        self::assertCount(ScanPayload::MAX_ITEMS_PER_PAGE, $this->firstReport($cookies)['cookies']);
    }

    public function testTooManyPagesAreTruncated(): void
    {
        $pages = [];
        for ($i = 0; $i < ScanPayload::MAX_PAGES + 20; $i++) {
            $pages[] = ['url' => "https://example.test/p{$i}", 'cookies' => []];
        }

        $reports = ScanPayload::normalise([
            'passes' => [['consent' => [], 'pages' => $pages]],
        ]);

        self::assertCount(ScanPayload::MAX_PAGES, $reports);
    }

    /**
     * A query string can carry a search term, a token or an email address, and
     * none of that belongs in a findings table that the CP later renders.
     */
    public function testTheQueryStringIsStripped(): void
    {
        $reports = ScanPayload::normalise([
            'passes' => [[
                'consent' => [],
                'pages' => [['url' => 'https://example.test/zoeken?q=jan%40example.nl&token=secret', 'cookies' => []]],
            ]],
        ]);

        self::assertSame('https://example.test/zoeken', $reports[0]['url']);
    }

    public function testUnknownConsentCategoriesAreDiscarded(): void
    {
        $reports = ScanPayload::normalise([
            'passes' => [[
                'consent' => ['necessary', 'everything', 42, 'marketing'],
                'pages' => [['url' => 'https://example.test/', 'cookies' => []]],
            ]],
        ]);

        self::assertSame(['necessary', 'marketing'], $reports[0]['consent']);
    }

    public function testStorageKeysAreDeduplicatedAndBounded(): void
    {
        $reports = ScanPayload::normalise([
            'passes' => [[
                'consent' => [],
                'pages' => [[
                    'url' => 'https://example.test/',
                    'cookies' => [],
                    'local' => ['yt-remote-device-id', 'yt-remote-device-id', 'a=b', str_repeat('x', 200), ''],
                    'session' => ['yt-remote-session-app'],
                ]],
            ]],
        ]);

        self::assertSame(['yt-remote-device-id'], $reports[0]['local']);
        self::assertSame(['yt-remote-session-app'], $reports[0]['session']);
    }

    public function testGarbageInputYieldsNothingRatherThanAnError(): void
    {
        self::assertSame([], ScanPayload::normalise([]));
        self::assertSame([], ScanPayload::normalise(['passes' => 'nope']));
        self::assertSame([], ScanPayload::normalise(['passes' => ['not-an-array']]));
        self::assertSame([], ScanPayload::normalise(['passes' => [['pages' => 'nope']]]));
    }

    /**
     * @param list<mixed> $cookies
     * @return array{url: string, cookies: list<array{name: string, domain?: string, expires?: float|int|null}>, local: list<string>, session: list<string>, consent: list<string>}
     */
    private function firstReport(array $cookies): array
    {
        $reports = ScanPayload::normalise([
            'passes' => [[
                'consent' => ['necessary'],
                'pages' => [['url' => 'https://example.test/', 'cookies' => $cookies]],
            ]],
        ]);

        self::assertNotSame([], $reports);

        return $reports[0];
    }
}
