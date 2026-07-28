<?php

declare(strict_types=1);

namespace viesrood\cookiekit\services;

use craft\base\Component;
use DOMElement;
use DOMNode;
use Symfony\Component\DomCrawler\Crawler;
use viesrood\cookiekit\helpers\Duration;
use viesrood\cookiekit\helpers\SetCookieParser;
use viesrood\cookiekit\helpers\SignatureMatcher;
use viesrood\cookiekit\Plugin;

/**
 * Turns raw scan material into findings.
 *
 * Every method here is a pure function of what it is handed: no HTTP, no
 * database, no `Craft::$app`. That is what lets the entire detection layer be
 * proven against saved HTML fixtures before a single page is ever fetched.
 *
 * The distinction that runs through all of it is `confidence`:
 *
 * - `observed`  we saw the cookie itself, in a Set-Cookie header or in a real
 *               browser. It exists.
 * - `inferred`  we saw a script that is known to set it. Educated guess, and
 *               labelled as such everywhere it surfaces.
 *
 * @phpstan-import-type VendorSignature from SignatureMatcher
 * @phpstan-import-type CookieSignature from SignatureMatcher
 *
 * @phpstan-type DetectedItem array{
 *     type: 'cookie'|'storage'|'vendor'|'unblocked'|'miscategorised',
 *     name: string,
 *     declaredAs: string,
 *     signatureKey: string|null,
 *     provider: string,
 *     category: string,
 *     purpose: string,
 *     duration: string,
 *     source: 'header'|'markup'|'inline'|'browser',
 *     confidence: 'observed'|'inferred',
 *     evidenceUrl: string,
 *     evidenceDetail: string,
 *     snippet: string,
 *     siteId: int,
 *     consentSeen: list<string>,
 *     preConsent: bool
 * }
 * @phpstan-type BrowserCookie array{name: string, domain?: string, expires?: float|int|null}
 * @phpstan-type BrowserReport array{
 *     url: string,
 *     cookies: list<BrowserCookie>,
 *     local: list<string>,
 *     session: list<string>,
 *     consent: list<string>
 * }
 */
class DetectorService extends Component
{
    /**
     * A cookie nobody recognises gets no category at all rather than a
     * plausible-looking guess: the declaration is a legal document, and an
     * invented purpose is worse than an empty one.
     */
    public const CATEGORY_UNKNOWN = '';

    private ?SignatureMatcher $matcher = null;

    public function setMatcher(SignatureMatcher $matcher): void
    {
        $this->matcher = $matcher;
    }

    public function getMatcher(): SignatureMatcher
    {
        return $this->matcher ??= Plugin::getInstance()?->getSignatures()->getMatcher()
            ?? new SignatureMatcher([]);
    }

    /**
     * Reads the cookies the server itself sets. These are observed facts, with
     * a real lifetime straight off the header.
     *
     * @param list<string> $headerLines
     * @return list<DetectedItem>
     */
    public function detectFromSetCookie(array $headerLines, string $pageUrl, int $siteId): array
    {
        $items = [];

        foreach (SetCookieParser::parseMany($headerLines) as $cookie) {
            $duration = Duration::fromSetCookieAttributes($cookie['attributes']);
            $match = $this->getMatcher()->matchCookieName($cookie['name']);

            $items[] = $this->cookieItem(
                observedName: $cookie['name'],
                match: $match,
                source: 'header',
                confidence: 'observed',
                duration: $duration,
                evidenceUrl: $pageUrl,
                evidenceDetail: 'Set-Cookie response header',
                siteId: $siteId,
            );
        }

        return $items;
    }

    /**
     * Walks the markup for third-party resources, and reports both what they
     * mean for the declaration and whether they are actually being blocked.
     *
     * @return list<DetectedItem>
     */
    public function detectFromHtml(string $html, string $pageUrl, int $siteId): array
    {
        if (trim($html) === '') {
            return [];
        }

        $crawler = new Crawler();
        $crawler->addHtmlContent($html, 'UTF-8');

        $items = [];

        foreach ($crawler->filter('script, iframe, img, link, [data-cookiekit-src]') as $node) {
            if (!$node instanceof DOMElement || $this->isInsideOwnBanner($node)) {
                continue;
            }

            foreach ($this->inspectElement($node, $pageUrl, $siteId) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * A regex sweep over the raw source for signature hosts the DOM walk cannot
     * reach: inside JSON-LD, string-concatenated loader snippets, HTML comments
     * that get revived by other scripts. Reports presence only, never blocking
     * state, because there is no element to judge.
     *
     * @return list<DetectedItem>
     */
    public function detectFromRawSource(string $html, string $pageUrl, int $siteId): array
    {
        $items = [];
        $seen = [];

        if (!preg_match_all('#https?://[^\s"\'<>\\\\]+#i', $html, $matches)) {
            return [];
        }

        foreach ($matches[0] as $url) {
            $match = $this->getMatcher()->matchUrl($url);
            if ($match === null || isset($seen[$match['key']])) {
                continue;
            }

            $seen[$match['key']] = true;

            foreach ($this->vendorItems($match['key'], $match['signature'], 'markup', $pageUrl, $url, $siteId) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Reads a single page of the browser scan: the cookies that actually landed,
     * the storage keys that were written, and which consent was in effect.
     *
     * @param BrowserReport $report
     * @return list<DetectedItem>
     */
    public function detectFromBrowser(array $report, int $siteId, ?int $now = null): array
    {
        $now ??= time();
        $consent = array_values(array_filter($report['consent'], 'is_string'));
        $items = [];

        foreach ($report['cookies'] as $cookie) {
            $name = $cookie['name'] ?? '';
            if (!is_string($name) || !SetCookieParser::isValidName($name)) {
                continue;
            }

            $match = $this->getMatcher()->matchCookieName($name);
            $expires = $cookie['expires'] ?? null;

            // Playwright reports a session cookie as -1; anything in the future
            // is a real lifetime and beats whatever the signature guessed.
            $duration = is_numeric($expires) && (int)$expires > $now
                ? Duration::humanize((int)$expires - $now)
                : ($match !== null ? $match['cookie']['duration'] : Duration::SESSION);

            $item = $this->cookieItem(
                observedName: $name,
                match: $match,
                source: 'browser',
                confidence: 'observed',
                duration: $duration,
                evidenceUrl: $report['url'],
                evidenceDetail: isset($cookie['domain']) && is_string($cookie['domain'])
                    ? 'Set on ' . $cookie['domain']
                    : 'Observed in the browser',
                siteId: $siteId,
            );

            $item['consentSeen'] = $consent;
            $item['preConsent'] = $this->isPreConsentViolation($item['category'], $consent);

            $items[] = $item;
        }

        foreach (['local' => $report['local'], 'session' => $report['session']] as $type => $keys) {
            foreach ($keys as $key) {
                if (!is_string($key) || $key === '') {
                    continue;
                }

                $items[] = $this->storageItem($key, $type, $report['url'], $consent, $siteId);
            }
        }

        return $items;
    }

    /**
     * A non-necessary cookie present while nothing beyond `necessary` was
     * granted means tracking happened before consent. Legally this is the
     * finding that matters most.
     *
     * @param list<string> $consent
     */
    public function isPreConsentViolation(string $category, array $consent): bool
    {
        if ($category === self::CATEGORY_UNKNOWN || $category === 'necessary') {
            return false;
        }

        return !in_array($category, $consent, true);
    }

    /**
     * @return list<DetectedItem>
     */
    private function inspectElement(DOMElement $node, string $pageUrl, int $siteId): array
    {
        $tag = strtolower($node->tagName);
        $blockedAs = $node->getAttribute('data-cookiekit');
        $blockedSrc = $node->getAttribute('data-cookiekit-src');
        $realSrc = $tag === 'link' ? $node->getAttribute('href') : $node->getAttribute('src');

        // An inline script has no source of either kind.
        if ($tag === 'script' && $realSrc === '' && $blockedSrc === '') {
            return $this->inspectInlineScript($node, $blockedAs, $pageUrl, $siteId);
        }

        $resource = $realSrc !== '' ? $realSrc : $blockedSrc;
        if ($resource === '') {
            return [];
        }

        $match = $this->getMatcher()->matchUrl($resource);
        if ($match === null) {
            return [];
        }

        $signature = $match['signature'];
        $items = $this->vendorItems($match['key'], $signature, 'markup', $pageUrl, $resource, $siteId);

        // Stylesheets are outside what the banner can gate: cookiekit.js swaps
        // scripts and [data-cookiekit-src] elements, never a <link>.
        if ($tag === 'link') {
            return $items;
        }

        $isBlocked = $this->isBlocked($tag, $node, $realSrc, $blockedSrc, $blockedAs);

        if ($isBlocked) {
            if ($signature['blockAs'] !== 'necessary' && $blockedAs !== $signature['blockAs']) {
                $items[] = $this->markupItem(
                    type: 'miscategorised',
                    name: $this->resourceKey($resource),
                    signatureKey: $match['key'],
                    signature: $signature,
                    pageUrl: $pageUrl,
                    detail: sprintf('Blocked as "%s" but belongs in "%s"', $blockedAs, $signature['blockAs']),
                    snippet: $this->snippetFor($tag, $resource, $signature['blockAs'], $node),
                    siteId: $siteId,
                );
            }

            return $items;
        }

        if ($signature['blockAs'] !== 'necessary') {
            $items[] = $this->markupItem(
                type: 'unblocked',
                name: $this->resourceKey($resource),
                signatureKey: $match['key'],
                signature: $signature,
                pageUrl: $pageUrl,
                detail: sprintf('<%s> loads without any data-cookiekit markup', $tag),
                snippet: $this->snippetFor($tag, $resource, $signature['blockAs'], $node),
                siteId: $siteId,
            );
        }

        return $items;
    }

    /**
     * @return list<DetectedItem>
     */
    private function inspectInlineScript(DOMElement $node, string $blockedAs, string $pageUrl, int $siteId): array
    {
        $code = $node->textContent;
        if (trim($code) === '') {
            return [];
        }

        $isBlocked = strtolower($node->getAttribute('type')) === 'text/plain' && $blockedAs !== '';
        $items = [];

        foreach ($this->getMatcher()->matchInline($code) as $match) {
            $signature = $match['signature'];

            foreach ($this->vendorItems($match['key'], $signature, 'inline', $pageUrl, 'inline script', $siteId) as $item) {
                $items[] = $item;
            }

            if ($isBlocked || $signature['blockAs'] === 'necessary') {
                continue;
            }

            $items[] = $this->markupItem(
                type: 'unblocked',
                name: 'inline:' . $match['key'],
                signatureKey: $match['key'],
                signature: $signature,
                pageUrl: $pageUrl,
                detail: 'Inline script runs without any data-cookiekit markup',
                snippet: sprintf(
                    '<script type="text/plain" data-cookiekit="%s">%s</script>',
                    $signature['blockAs'],
                    trim($code),
                ),
                siteId: $siteId,
            );
        }

        return $items;
    }

    /**
     * The vendor itself plus every cookie it is known to set. Inferred: the
     * script is there, so the cookies follow, but we never saw them.
     *
     * @param VendorSignature $signature
     * @param 'markup'|'inline' $source
     * @return list<DetectedItem>
     */
    private function vendorItems(
        string $key,
        array $signature,
        string $source,
        string $pageUrl,
        string $resource,
        int $siteId,
    ): array {
        $items = [];

        if ($signature['container'] || $signature['cookies'] === []) {
            // Nothing to declare on its own account: either a tag container
            // whose contents we cannot see, or a party that sets no cookies but
            // still receives the visitor's IP address.
            $items[] = $this->markupItem(
                type: 'vendor',
                name: $key,
                signatureKey: $key,
                signature: $signature,
                pageUrl: $pageUrl,
                detail: $resource,
                snippet: '',
                siteId: $siteId,
            );
        }

        foreach ($signature['cookies'] as $cookie) {
            $items[] = [
                'type' => 'cookie',
                'name' => $cookie['declaredAs'],
                'declaredAs' => $cookie['declaredAs'],
                'signatureKey' => $key,
                'provider' => $signature['provider'],
                'category' => $cookie['category'],
                'purpose' => $cookie['purpose'],
                'duration' => $cookie['duration'],
                'source' => $source,
                'confidence' => 'inferred',
                'evidenceUrl' => $pageUrl,
                'evidenceDetail' => $resource,
                'snippet' => '',
                'siteId' => $siteId,
                'consentSeen' => [],
                'preConsent' => false,
            ];
        }

        return $items;
    }

    /**
     * @param array{key: string, signature: VendorSignature, cookie: CookieSignature}|null $match
     * @param 'header'|'browser' $source
     * @param 'observed'|'inferred' $confidence
     * @return DetectedItem
     */
    private function cookieItem(
        string $observedName,
        ?array $match,
        string $source,
        string $confidence,
        string $duration,
        string $evidenceUrl,
        string $evidenceDetail,
        int $siteId,
    ): array {
        return [
            'type' => 'cookie',
            'name' => $observedName,
            'declaredAs' => $match !== null ? $match['cookie']['declaredAs'] : $observedName,
            'signatureKey' => $match !== null ? $match['key'] : null,
            'provider' => $match !== null ? $match['signature']['provider'] : '',
            'category' => $match !== null ? $match['cookie']['category'] : self::CATEGORY_UNKNOWN,
            'purpose' => $match !== null ? $match['cookie']['purpose'] : '',
            'duration' => $duration,
            'source' => $source,
            'confidence' => $confidence,
            'evidenceUrl' => $evidenceUrl,
            'evidenceDetail' => $evidenceDetail,
            'snippet' => '',
            'siteId' => $siteId,
            'consentSeen' => [],
            'preConsent' => false,
        ];
    }

    /**
     * @param 'local'|'session' $type
     * @param list<string> $consent
     * @return DetectedItem
     */
    private function storageItem(string $key, string $type, string $pageUrl, array $consent, int $siteId): array
    {
        $match = $this->getMatcher()->matchStorageKey($key, $type);
        $category = $match !== null ? $match['storage']['category'] : self::CATEGORY_UNKNOWN;

        return [
            'type' => 'storage',
            'name' => $key,
            'declaredAs' => $key,
            'signatureKey' => $match !== null ? $match['key'] : null,
            'provider' => $match !== null ? $match['signature']['provider'] : '',
            'category' => $category,
            'purpose' => $match !== null ? $match['storage']['purpose'] : '',
            'duration' => $type === 'session' ? Duration::SESSION : 'Until removed',
            'source' => 'browser',
            'confidence' => 'observed',
            'evidenceUrl' => $pageUrl,
            'evidenceDetail' => $type === 'session' ? 'sessionStorage' : 'localStorage',
            'snippet' => '',
            'siteId' => $siteId,
            'consentSeen' => $consent,
            'preConsent' => $this->isPreConsentViolation($category, $consent),
        ];
    }

    /**
     * @param 'vendor'|'unblocked'|'miscategorised' $type
     * @param VendorSignature $signature
     * @return DetectedItem
     */
    private function markupItem(
        string $type,
        string $name,
        string $signatureKey,
        array $signature,
        string $pageUrl,
        string $detail,
        string $snippet,
        int $siteId,
    ): array {
        return [
            'type' => $type,
            'name' => $name,
            'declaredAs' => '',
            'signatureKey' => $signatureKey,
            'provider' => $signature['provider'],
            'category' => $signature['blockAs'],
            'purpose' => $signature['label'],
            'duration' => '',
            'source' => 'markup',
            'confidence' => 'observed',
            'evidenceUrl' => $pageUrl,
            'evidenceDetail' => $detail,
            'snippet' => $snippet,
            'siteId' => $siteId,
            'consentSeen' => [],
            'preConsent' => false,
        ];
    }

    /**
     * Mirrors what cookiekit.js actually acts on: a script counts as blocked
     * only when it is inert (`type="text/plain"`) and carries a category, and
     * any other element when its real source has been moved out of the way.
     */
    private function isBlocked(string $tag, DOMElement $node, string $realSrc, string $blockedSrc, string $blockedAs): bool
    {
        if ($blockedAs === '') {
            return false;
        }

        if ($tag === 'script') {
            return strtolower($node->getAttribute('type')) === 'text/plain' && $blockedSrc !== '';
        }

        return $realSrc === '' && $blockedSrc !== '';
    }

    /**
     * Keeps the attributes that decide how a resource loads, so the snippet the
     * CP hands you is a drop-in replacement rather than a rough sketch.
     */
    private function snippetFor(string $tag, string $resource, string $category, DOMElement $node): string
    {
        $keep = ['async', 'defer', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'loading', 'crossorigin'];
        $attributes = [];

        foreach ($keep as $name) {
            if ($node->hasAttribute($name)) {
                $attributes[$name] = $node->getAttribute($name);
            }
        }

        return SignatureMatcher::blockingSnippet($tag, $resource, $category, $attributes);
    }

    /**
     * Host plus path, so the same tag with a different property id collapses
     * onto one finding instead of one per id.
     */
    private function resourceKey(string $resource): string
    {
        $host = parse_url($resource, PHP_URL_HOST);
        $path = parse_url($resource, PHP_URL_PATH);

        if (!is_string($host)) {
            return $resource;
        }

        return $host . (is_string($path) ? $path : '');
    }

    private function isInsideOwnBanner(DOMNode $node): bool
    {
        for ($current = $node; $current !== null; $current = $current->parentNode) {
            if ($current instanceof DOMElement && $current->hasAttribute('data-cookiekit-root')) {
                return true;
            }
        }

        return false;
    }
}
