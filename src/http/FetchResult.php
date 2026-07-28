<?php

declare(strict_types=1);

namespace viesrood\cookiekit\http;

/**
 * The outcome of fetching one page.
 */
final class FetchResult
{
    /**
     * @param list<string> $setCookieLines raw Set-Cookie response headers
     */
    private function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $setCookieLines,
        public readonly string $contentType,
        public readonly ?string $error,
    ) {
    }

    /**
     * @param list<string> $setCookieLines
     */
    public static function ok(
        string $url,
        int $statusCode,
        string $body,
        array $setCookieLines,
        string $contentType,
    ): self {
        return new self($url, $statusCode, $body, $setCookieLines, $contentType, null);
    }

    public static function failed(string $url, string $error, int $statusCode = 0): self
    {
        return new self($url, $statusCode, '', [], '', $error);
    }

    public function isSuccess(): bool
    {
        return $this->error === null && $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Only HTML is worth handing to the detector. A PDF or a JSON feed cannot
     * carry a script tag.
     */
    public function isHtml(): bool
    {
        return str_contains(strtolower($this->contentType), 'text/html');
    }
}
