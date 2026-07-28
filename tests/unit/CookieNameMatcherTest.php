<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\CookieNameMatcher;

final class CookieNameMatcherTest extends TestCase
{
    public function testWildcardCoversTheWholeFamily(): void
    {
        self::assertTrue(CookieNameMatcher::matches('_ga_*', '_ga_G3Y7GKHRGGR'));
        self::assertTrue(CookieNameMatcher::matches('_ga_*', '_ga_XYZ123'));
        self::assertTrue(CookieNameMatcher::matches('_hjSessionUser_*', '_hjSessionUser_123456'));
    }

    public function testNamesWithoutAWildcardAreExact(): void
    {
        self::assertTrue(CookieNameMatcher::matches('_ga', '_ga'));
        self::assertFalse(CookieNameMatcher::matches('_ga', '_ga_G3Y7GKHRGGR'));
        self::assertFalse(CookieNameMatcher::matches('_ga_*', '_ga'));
    }

    public function testRegexMetacharactersAreQuoted(): void
    {
        self::assertTrue(CookieNameMatcher::matches('__utm.gif', '__utm.gif'));
        self::assertFalse(CookieNameMatcher::matches('__utm.gif', '__utmXgif'));
    }

    public function testMatchingIsCaseSensitive(): void
    {
        self::assertTrue(CookieNameMatcher::matches('YSC', 'YSC'));
        self::assertFalse(CookieNameMatcher::matches('YSC', 'ysc'));
    }

    public function testABareWildcardIsRejected(): void
    {
        self::assertFalse(CookieNameMatcher::isMeaningful('*'));
        self::assertFalse(CookieNameMatcher::isMeaningful('**'));
        self::assertFalse(CookieNameMatcher::isMeaningful(''));
        self::assertFalse(CookieNameMatcher::isMeaningful('  '));
        self::assertTrue(CookieNameMatcher::isMeaningful('_ga_*'));

        // And it must never be usable as a catch-all declaration.
        self::assertFalse(CookieNameMatcher::matches('*', '_ga'));
    }

    public function testFindDeclaredPrefersAnExactDeclaration(): void
    {
        $declared = ['_g*', '_ga', '_ga_*'];

        self::assertSame('_ga', CookieNameMatcher::findDeclared($declared, '_ga'));
        self::assertSame('_ga_*', CookieNameMatcher::findDeclared($declared, '_ga_G3Y7GKHRGGR'));
        self::assertNull(CookieNameMatcher::findDeclared(['_ga', '_gid'], '_fbp'));
    }

    public function testFindDeclaredReturnsNullOnAnEmptyDeclaration(): void
    {
        self::assertNull(CookieNameMatcher::findDeclared([], '_ga'));
    }
}
