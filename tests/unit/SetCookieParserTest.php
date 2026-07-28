<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\SetCookieParser;

final class SetCookieParserTest extends TestCase
{
    public function testParsesNameAndAttributes(): void
    {
        $parsed = SetCookieParser::parse('CraftSessionId=abc123; path=/; httponly; samesite=Lax');

        self::assertNotNull($parsed);
        self::assertSame('CraftSessionId', $parsed['name']);
        self::assertSame('/', $parsed['attributes']['path']);
        self::assertTrue($parsed['attributes']['httponly']);
        self::assertSame('Lax', $parsed['attributes']['samesite']);
    }

    /**
     * The whole point of this parser: a session id or a consent payload must
     * never make it into a findings row.
     */
    public function testTheValueNeverAppearsInTheOutput(): void
    {
        $parsed = SetCookieParser::parse('CraftSessionId=super-secret-session-value; path=/');

        self::assertNotNull($parsed);
        self::assertStringNotContainsString('super-secret-session-value', json_encode($parsed, JSON_THROW_ON_ERROR));
    }

    public function testAValueContainingAnEqualsSignDoesNotBleedIntoTheName(): void
    {
        $parsed = SetCookieParser::parse('_ga=GA1.1.123=456; path=/');

        self::assertNotNull($parsed);
        self::assertSame('_ga', $parsed['name']);
    }

    public function testRejectsLinesWithoutANameValuePair(): void
    {
        self::assertNull(SetCookieParser::parse('just-a-flag'));
        self::assertNull(SetCookieParser::parse(''));
    }

    public function testRejectsNamesOutsideTheTokenCharset(): void
    {
        self::assertNull(SetCookieParser::parse('bad name=value'));
        self::assertNull(SetCookieParser::parse('"quoted"=value'));
        self::assertNull(SetCookieParser::parse(str_repeat('a', 129) . '=value'));
    }

    public function testAcceptsTheFullTokenCharset(): void
    {
        self::assertTrue(SetCookieParser::isValidName('__Secure-YEC'));
        self::assertTrue(SetCookieParser::isValidName('_ga_G3Y7GKHRGGR'));
        self::assertTrue(SetCookieParser::isValidName('cookiefirst-consent'));
        self::assertFalse(SetCookieParser::isValidName('name=value'));
        self::assertFalse(SetCookieParser::isValidName(''));
    }

    public function testParseManySkipsUnparseableLines(): void
    {
        $parsed = SetCookieParser::parseMany([
            'CraftSessionId=abc; path=/',
            'garbage',
            'CRAFT_CSRF_TOKEN=def; path=/',
        ]);

        self::assertCount(2, $parsed);
        self::assertSame(['CraftSessionId', 'CRAFT_CSRF_TOKEN'], array_column($parsed, 'name'));
    }
}
