<?php

declare(strict_types=1);

namespace viesrood\cookiekit\http;

use Craft;

/**
 * Keeps the scan pointed at this installation's own sites.
 *
 * The crawler only ever visits pages it was told about by Craft itself, but a
 * URL can still be supplied by hand in the settings, and an open fetcher that
 * takes any URL is a server-side request forgery waiting to happen. Everything
 * outside the site's own hosts is refused.
 */
final class SitePolicy
{
    /**
     * @param list<string> $allowedHosts
     */
    private function __construct(private readonly array $allowedHosts)
    {
    }

    public static function fromSites(): self
    {
        $hosts = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();

            if ($baseUrl === null) {
                continue;
            }

            $host = parse_url($baseUrl, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return new self(array_values(array_unique($hosts)));
    }

    /**
     * @param list<string> $hosts
     */
    public static function fromHosts(array $hosts): self
    {
        return new self(array_map('strtolower', $hosts));
    }

    public function allows(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        // Credentials in a URL are never legitimate here and are a classic way
        // of smuggling one host past a check that reads the other.
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), $this->allowedHosts, true);
    }

    /**
     * @return list<string>
     */
    public function getAllowedHosts(): array
    {
        return $this->allowedHosts;
    }
}
