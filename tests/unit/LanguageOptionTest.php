<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\LanguageOption;

final class LanguageOptionTest extends TestCase
{
    public function testEmptyInputMeansDoNotForceAnything(): void
    {
        self::assertNull(LanguageOption::normalize(null));
        self::assertNull(LanguageOption::normalize(''));
        self::assertNull(LanguageOption::normalize('   '));
    }

    public function testNonStringsAreRejected(): void
    {
        self::assertNull(LanguageOption::normalize(42));
        self::assertNull(LanguageOption::normalize(true));
        self::assertNull(LanguageOption::normalize(['nl']));
    }

    public function testPlainTagsSurvive(): void
    {
        self::assertSame('nl', LanguageOption::normalize('nl'));
        self::assertSame('nl', LanguageOption::normalize('NL'));
        self::assertSame('nl', LanguageOption::normalize(' nl '));
    }

    /**
     * A settings field filled in by hand should behave the same as one copied
     * out of Craft, so underscores and casing are smoothed over.
     */
    public function testRegionalTagsAreCanonicalised(): void
    {
        self::assertSame('nl-NL', LanguageOption::normalize('nl-NL'));
        self::assertSame('nl-NL', LanguageOption::normalize('nl_nl'));
        self::assertSame('nl-NL', LanguageOption::normalize('NL-nl'));
        self::assertSame('nl-BE', LanguageOption::normalize('nl-be'));
        self::assertSame('en-US', LanguageOption::normalize('en_US'));
    }

    public function testScriptSubtagsSurvive(): void
    {
        self::assertSame('zh-Hant-TW', LanguageOption::normalize('zh-hant-tw'));
    }

    /**
     * An unknown but well-formed tag is allowed through on purpose: Yii finds
     * no translations folder and falls back to the English source strings,
     * which beats throwing at render time.
     */
    public function testAWellFormedButUnknownTagIsAllowedThrough(): void
    {
        self::assertSame('zz-ZZ', LanguageOption::normalize('zz-ZZ'));
    }

    public function testGarbageIsRejected(): void
    {
        self::assertNull(LanguageOption::normalize('not a language'));
        self::assertNull(LanguageOption::normalize('n'));
        self::assertNull(LanguageOption::normalize('../etc/passwd'));
        self::assertNull(LanguageOption::normalize('nl;DROP TABLE'));
        self::assertNull(LanguageOption::normalize('nederlands-maar-dan-veel-te-lang'));
    }
}
