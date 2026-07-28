<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\Duration;

final class DurationTest extends TestCase
{
    public function testTwoYears(): void
    {
        self::assertSame('2 years', Duration::humanize(63072000));
    }

    public function testAnythingWithoutAFutureExpiryIsASessionCookie(): void
    {
        self::assertSame('Session', Duration::humanize(0));
        self::assertSame('Session', Duration::humanize(-1));
        self::assertSame('Session', Duration::fromSetCookieAttributes([]));
        self::assertSame('Session', Duration::fromSetCookieAttributes(['max-age' => '0']));
        self::assertSame('Session', Duration::fromSetCookieAttributes(['httponly' => true]));
    }

    public function testExpiresInThePastIsASessionCookie(): void
    {
        $now = 1_800_000_000;

        self::assertSame('Session', Duration::fromExpires('Thu, 01 Jan 1970 00:00:00 GMT', $now));
    }

    public function testUnparseableExpiresDoesNotGuess(): void
    {
        self::assertSame('Session', Duration::fromExpires('whenever', 1_800_000_000));
    }

    public function testCommonLifetimes(): void
    {
        self::assertSame('1 minute', Duration::humanize(60));
        self::assertSame('15 minutes', Duration::humanize(900));
        self::assertSame('2 hours', Duration::humanize(7200));
        self::assertSame('1 day', Duration::humanize(86400));
        self::assertSame('30 days', Duration::humanize(30 * 86400));
        self::assertSame('3 months', Duration::humanize(90 * 86400));
        self::assertSame('6 months', Duration::humanize(182 * 86400));
        self::assertSame('1 year', Duration::humanize(365 * 86400));
    }

    /**
     * Chrome refuses to store any cookie for longer than 400 days, so a tag
     * that asks for two years is really kept for 400 of them. Calling that
     * "1 year" in a cookie declaration understates it by a third.
     */
    public function testTheChromeCookieCapIsReportedHonestly(): void
    {
        self::assertSame('13 months', Duration::humanize(400 * 86400));
        self::assertSame('2 years', Duration::humanize(730 * 86400));
    }

    public function testSingularAndPluralAreDistinctStrings(): void
    {
        self::assertSame('1 second', Duration::humanize(1));
        self::assertSame('30 seconds', Duration::humanize(30));
    }

    public function testMaxAgeWinsOverExpires(): void
    {
        $duration = Duration::fromSetCookieAttributes([
            'max-age' => '60',
            'expires' => 'Thu, 01 Jan 2099 00:00:00 GMT',
        ]);

        self::assertSame('1 minute', $duration);
    }

    public function testExpiresIsUsedWhenThereIsNoMaxAge(): void
    {
        $now = strtotime('2026-01-01 00:00:00 UTC');
        self::assertIsInt($now);

        $duration = Duration::fromSetCookieAttributes(
            ['expires' => 'Fri, 02 Jan 2026 00:00:00 GMT'],
            $now,
        );

        self::assertSame('1 day', $duration);
    }

    public function testGarbageMaxAgeFallsThroughToExpires(): void
    {
        $now = strtotime('2026-01-01 00:00:00 UTC');
        self::assertIsInt($now);

        $duration = Duration::fromSetCookieAttributes(
            ['max-age' => 'nonsense', 'expires' => 'Fri, 02 Jan 2026 00:00:00 GMT'],
            $now,
        );

        self::assertSame('1 day', $duration);
    }
}
