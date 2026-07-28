<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

/**
 * Makes a value safe to put in a spreadsheet.
 *
 * A CSV cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return is
 * read as a formula by Excel, LibreOffice and Google Sheets. Since part of what
 * this plugin exports arrives on a public endpoint, an export is a path from a
 * visitor's keyboard to code running on an administrator's machine. Neutralise
 * it on the way out, whatever the value claims to be.
 */
final class Csv
{
    /**
     * Characters a spreadsheet treats as the start of a formula.
     */
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Prefixes a leading trigger with a single quote, which spreadsheets read
     * as "this is text" and strip on display.
     */
    public static function cell(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            $value = $value === true ? '1' : ($value === false ? '0' : '');
        }

        $value = (string)$value;

        if ($value === '') {
            return '';
        }

        return in_array($value[0], self::TRIGGERS, true) ? "'" . $value : $value;
    }

    /**
     * @param list<mixed> $row
     * @return list<string>
     */
    public static function row(array $row): array
    {
        return array_map(static fn(mixed $value): string => self::cell($value), $row);
    }
}
