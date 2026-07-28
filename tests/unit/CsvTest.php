<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\Csv;

/**
 * Part of what this plugin exports arrives on a public endpoint, so an export
 * is a path from a visitor's keyboard to code running on an administrator's
 * machine. These are the payloads that path has to survive.
 */
final class CsvTest extends TestCase
{
    public function testFormulaTriggersAreNeutralised(): void
    {
        self::assertSame("'=cmd|'/C calc'!A0", Csv::cell("=cmd|'/C calc'!A0"));
        self::assertSame("'+HYPERLINK(\"https://evil.test\")", Csv::cell('+HYPERLINK("https://evil.test")'));
        self::assertSame("'-2+3", Csv::cell('-2+3'));
        self::assertSame("'@SUM(A1:A9)", Csv::cell('@SUM(A1:A9)'));
        self::assertSame("'\tcmd", Csv::cell("\tcmd"));
        self::assertSame("'\rcmd", Csv::cell("\rcmd"));
    }

    public function testOrdinaryValuesAreLeftAlone(): void
    {
        self::assertSame('acceptAll', Csv::cell('acceptAll'));
        self::assertSame('nl-NL', Csv::cell('nl-NL'));
        self::assertSame('2026-07-27 12:00:00', Csv::cell('2026-07-27 12:00:00'));
        self::assertSame('necessary,statistics', Csv::cell('necessary,statistics'));

        // A trigger anywhere but the first character is not a formula.
        self::assertSame('a=b', Csv::cell('a=b'));
        self::assertSame('1-2', Csv::cell('1-2'));
    }

    public function testScalarsBecomeStrings(): void
    {
        self::assertSame('42', Csv::cell(42));
        self::assertSame('1', Csv::cell(true));
        self::assertSame('0', Csv::cell(false));
        self::assertSame('', Csv::cell(null));
        self::assertSame('', Csv::cell(''));
    }

    public function testAWholeRowGoesThrough(): void
    {
        $row = Csv::row(['2026-07-27', '=1+1', 'acceptAll', null, 365]);

        self::assertSame(['2026-07-27', "'=1+1", 'acceptAll', '', '365'], $row);
    }

    /**
     * A negative number is the one honest-looking value that trips the guard.
     * Prefixing it is still correct: the spreadsheet strips the quote and shows
     * the text, which beats evaluating it.
     */
    public function testNegativeNumbersAreQuotedToo(): void
    {
        self::assertSame("'-5", Csv::cell(-5));
    }
}
