<?php

declare(strict_types=1);

namespace viesrood\cookiekit\http;

use Craft;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Fetches the site's own pages, concurrently and politely.
 *
 * No cookie jar on purpose: every page has to be fetched as if by a first-time
 * visitor, otherwise the second request no longer shows the Set-Cookie headers
 * that are half of what the scan is looking for.
 */
final class Fetcher
{
    /**
     * Pages are HTML. Anything much larger than this is not a page, and reading
     * it into memory helps nobody.
     */
    private const MAX_BODY_BYTES = 2_000_000;

    public function __construct(
        private readonly SitePolicy $policy,
        private readonly int $timeout = 10,
        private readonly int $concurrency = 5,
    ) {
    }

    /**
     * @param list<string> $urls
     * @param (callable(FetchResult, int): void)|null $onEach
     * @return array<string, FetchResult> keyed by URL
     */
    public function fetchMany(array $urls, ?callable $onEach = null): array
    {
        $urls = array_values(array_unique($urls));
        $results = [];
        $allowed = [];

        foreach ($urls as $url) {
            if ($this->policy->allows($url)) {
                $allowed[] = $url;
                continue;
            }

            $results[$url] = FetchResult::failed($url, 'Refused: not one of this installation’s own hosts.');
        }

        if ($allowed === []) {
            return $results;
        }

        $client = $this->createClient();
        $done = 0;
        $total = count($allowed);

        $requests = static function () use ($allowed): \Generator {
            foreach ($allowed as $url) {
                yield $url => new Request('GET', $url);
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => max(1, $this->concurrency),
            'fulfilled' => function (ResponseInterface $response, string $url) use (&$results, &$done, $total, $onEach): void {
                $results[$url] = $this->toResult($url, $response);
                $done++;

                if ($onEach !== null) {
                    $onEach($results[$url], (int)round($done / $total * 100));
                }
            },
            'rejected' => function (mixed $reason, string $url) use (&$results, &$done, $total, $onEach): void {
                $results[$url] = FetchResult::failed($url, $this->describe($reason));
                $done++;

                if ($onEach !== null) {
                    $onEach($results[$url], (int)round($done / $total * 100));
                }
            },
        ]);

        $pool->promise()->wait();

        // Restore the order the caller asked for.
        $ordered = [];
        foreach ($urls as $url) {
            if (isset($results[$url])) {
                $ordered[$url] = $results[$url];
            }
        }

        return $ordered;
    }

    public function fetch(string $url): FetchResult
    {
        return $this->fetchMany([$url])[$url] ?? FetchResult::failed($url, 'No response.');
    }

    private function createClient(): \GuzzleHttp\Client
    {
        return Craft::createGuzzleClient([
            'timeout' => $this->timeout,
            'connect_timeout' => min(5, $this->timeout),
            'http_errors' => false,
            'allow_redirects' => [
                'max' => 3,
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
            ],
            'headers' => [
                'User-Agent' => 'CookieKit cookie scanner (+https://github.com/viesrood/cookiekit)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => '*',
            ],
            // A local development site serves a self-signed certificate, and
            // refusing to scan it would make the feature untestable where it is
            // built. Production keeps full verification.
            'verify' => !Craft::$app->getConfig()->getGeneral()->devMode,
        ]);
    }

    private function toResult(string $url, ResponseInterface $response): FetchResult
    {
        $body = (string)$response->getBody();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        /** @var list<string> $setCookie */
        $setCookie = $response->getHeader('Set-Cookie');

        return FetchResult::ok(
            $url,
            $response->getStatusCode(),
            $body,
            $setCookie,
            $response->getHeaderLine('Content-Type'),
        );
    }

    private function describe(mixed $reason): string
    {
        if ($reason instanceof TransferException || $reason instanceof Throwable) {
            return $reason->getMessage();
        }

        return 'Request failed.';
    }
}
