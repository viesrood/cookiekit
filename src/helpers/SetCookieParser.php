<?php

declare(strict_types=1);

namespace viesrood\cookiekit\helpers;

/**
 * Reads a raw `Set-Cookie` response header.
 *
 * The cookie value is dropped on the floor and never returned: a session id or
 * a consent payload is exactly the kind of thing that must not end up in a
 * findings table. Only the name and the attributes come out.
 */
final class SetCookieParser
{
    /**
     * RFC 6265 token characters. Anything outside this set is not a cookie name.
     */
    public const NAME_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    public const MAX_NAME_LENGTH = 128;

    /**
     * @return array{name: string, attributes: array<string, string|bool>}|null
     */
    public static function parse(string $headerLine): ?array
    {
        $parts = explode(';', $headerLine);
        $pair = array_shift($parts);

        if ($pair === null) {
            return null;
        }

        $separator = strpos($pair, '=');
        if ($separator === false) {
            return null;
        }

        $name = trim(substr($pair, 0, $separator));
        if (!self::isValidName($name)) {
            return null;
        }

        $attributes = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $separator = strpos($part, '=');
            if ($separator === false) {
                $attributes[strtolower($part)] = true;
                continue;
            }

            $key = strtolower(trim(substr($part, 0, $separator)));
            $attributes[$key] = trim(substr($part, $separator + 1));
        }

        return ['name' => $name, 'attributes' => $attributes];
    }

    /**
     * Parses several header lines at once, skipping anything unparseable.
     *
     * @param list<string> $headerLines
     * @return list<array{name: string, attributes: array<string, string|bool>}>
     */
    public static function parseMany(array $headerLines): array
    {
        $parsed = [];

        foreach ($headerLines as $line) {
            $cookie = self::parse($line);
            if ($cookie !== null) {
                $parsed[] = $cookie;
            }
        }

        return $parsed;
    }

    /**
     * Guards every path where a cookie name arrives from outside: a name that
     * still contains `=` means someone sent a whole `name=value` pair.
     */
    public static function isValidName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= self::MAX_NAME_LENGTH
            && preg_match(self::NAME_PATTERN, $name) === 1;
    }
}
