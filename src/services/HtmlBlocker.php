<?php

declare(strict_types=1);

namespace viesrood\cookiekit\services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Throwable;
use viesrood\cookiekit\helpers\SignatureMatcher;

/**
 * Rewrites recognised non-essential resources before HTML reaches the browser.
 *
 * Two passes, and the split is the whole point.
 *
 * The first pass parses a copy of the document purely to decide *what* has to
 * be blocked. A tree is needed for that: whether a tag sits inside a
 * `data-cookiekit-ignore` subtree, inside the banner itself, or inside a
 * `<template>` are all questions about ancestry. Nothing is serialised.
 *
 * The second pass rewrites the original string, and only the opening tags that
 * pass one asked for. Everything else comes out byte for byte as it went in.
 *
 * The earlier version serialised the parsed tree back out, and that is not
 * survivable: `DOMDocument` is libxml's HTML4 parser, so a round trip
 * entity-encodes every non-ASCII character inside `<script>` and `<style>`
 * (where browsers do not decode them, so `é` really does become the five
 * characters `&eacute;`), lowercases the camelCase names inline SVG depends on
 * (`viewBox`, `linearGradient`), and restructures nesting it disagrees with.
 * On a Dutch site with an inline SVG logo that is immediate, silent damage.
 *
 * Unknown resources are deliberately left alone; the detector reports those for
 * a person to classify rather than this service guessing legal purpose.
 */
class HtmlBlocker
{
    public const IGNORE_ATTRIBUTE = 'data-cookiekit-ignore';

    /**
     * Matches one opening tag. The attribute part steps over quoted values so a
     * `>` inside an attribute cannot end the match early.
     */
    private const OPENING_TAG = '/<(script|iframe|img)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>/i';

    /**
     * Attribute name, optionally followed by a quoted or bare value.
     */
    private const ATTRIBUTE = '/([^\s=\/>"\']+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?/';

    public function __construct(private readonly SignatureMatcher $matcher)
    {
    }

    public function rewrite(string $html): string
    {
        if (trim($html) === '' || stripos($html, '<html') === false) {
            return $html;
        }

        try {
            $decisions = $this->decide($html);

            return $decisions === [] ? $html : $this->apply($html, $decisions);
        } catch (Throwable) {
            // Never hand back something we are unsure about: an unrewritten page
            // sets a cookie too many, a mangled one is broken for everybody.
            return $html;
        }
    }

    /**
     * Pass one: what has to be blocked, keyed so it can be found again in the
     * source without relying on document order.
     *
     * @return array<string, string> key => category
     */
    private function decide(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );

            if (!$loaded) {
                return [];
            }

            $blocked = [];
            $skipped = [];

            foreach (iterator_to_array($document->getElementsByTagName('script')) as $node) {
                if ($node instanceof DOMElement) {
                    $this->considerScript($node, $blocked, $skipped);
                }
            }

            foreach (['iframe', 'img'] as $tag) {
                foreach (iterator_to_array($document->getElementsByTagName($tag)) as $node) {
                    if ($node instanceof DOMElement) {
                        $this->considerResource($node, $blocked, $skipped);
                    }
                }
            }

            // The same resource can appear both inside and outside an ignored
            // subtree. The key cannot tell them apart, so the conservative
            // reading wins: leave it alone and let Detection report it.
            return array_diff_key($blocked, $skipped);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param array<string, string> $blocked
     * @param array<string, true> $skipped
     */
    private function considerScript(DOMElement $element, array &$blocked, array &$skipped): void
    {
        $src = trim($element->getAttribute('src'));
        $code = trim($element->textContent);
        $key = $src !== '' ? self::srcKey('script', $src) : self::inlineKey($code);

        if ($code === '' && $src === '') {
            return;
        }

        if ($this->mustSkip($element) || $element->hasAttribute('data-cookiekit')) {
            $skipped[$key] = true;

            return;
        }

        if ($src !== '') {
            $match = $this->matcher->matchUrl($src);

            if ($match !== null && $match['signature']['blockAs'] !== 'necessary') {
                $blocked[$key] = $match['signature']['blockAs'];
            }

            return;
        }

        $categories = array_values(array_unique(array_map(
            static fn(array $match): string => $match['signature']['blockAs'],
            array_filter(
                $this->matcher->matchInline($code),
                static fn(array $match): bool => $match['signature']['blockAs'] !== 'necessary',
            ),
        )));

        // Ambiguous inline code stays visible to Detection rather than being
        // filed under whichever signature happened to match first.
        if (count($categories) === 1) {
            $blocked[$key] = $categories[0];
        }
    }

    /**
     * @param array<string, string> $blocked
     * @param array<string, true> $skipped
     */
    private function considerResource(DOMElement $element, array &$blocked, array &$skipped): void
    {
        $src = trim($element->getAttribute('src'));

        if ($src === '') {
            return;
        }

        $tag = strtolower($element->tagName);
        $key = self::srcKey($tag, $src);

        if ($this->mustSkip($element) || $element->hasAttribute('data-cookiekit')) {
            $skipped[$key] = true;

            return;
        }

        $match = $this->matcher->matchUrl($src);

        if ($match !== null && $match['signature']['blockAs'] !== 'necessary') {
            $blocked[$key] = $match['signature']['blockAs'];
        }
    }

    /**
     * Pass two: rewrite only the opening tags pass one selected, leaving every
     * other byte of the document untouched.
     *
     * @param array<string, string> $decisions
     */
    private function apply(string $html, array $decisions): string
    {
        $offset = 0;
        $result = '';

        while (preg_match(self::OPENING_TAG, $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $whole = $match[0][0];
            $start = (int)$match[0][1];
            $tag = strtolower($match[1][0]);
            $attributes = self::parseAttributes($match[2][0]);

            $result .= substr($html, $offset, $start - $offset);
            $offset = $start + strlen($whole);

            $src = trim($attributes['src'] ?? '');
            $key = $src !== '' ? self::srcKey($tag, $src) : null;

            if ($key === null && $tag === 'script') {
                // An inline script is keyed on its body, so the body has to be
                // read. Only the opening tag is ever rewritten.
                $end = stripos($html, '</script', $offset);
                $code = trim(substr($html, $offset, $end === false ? 0 : $end - $offset));
                $key = self::inlineKey($code);
            }

            $result .= $key !== null && isset($decisions[$key])
                ? self::buildTag($tag, $attributes, $decisions[$key])
                : $whole;
        }

        return $result . substr($html, $offset);
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function buildTag(string $tag, array $attributes, string $category): string
    {
        $rewritten = [];

        foreach ($attributes as $name => $value) {
            $lower = strtolower($name);

            // Everything the browser would fetch from has to move, or the
            // request still goes out while the control panel reports it as
            // blocked. srcset is the one the previous version missed.
            if (in_array($lower, ['src', 'srcset', 'data-src'], true)) {
                $rewritten['data-cookiekit-' . ($lower === 'src' ? 'src' : $lower)] = $value;
                continue;
            }

            if ($tag === 'script' && $lower === 'type') {
                // Preserved so a module comes back as a module. Recreating it
                // as a classic script makes `import` a SyntaxError.
                if ($value !== '' && strtolower($value) !== 'text/javascript') {
                    $rewritten['data-cookiekit-type'] = $value;
                }
                continue;
            }

            $rewritten[$name] = $value;
        }

        if ($tag === 'script') {
            $rewritten['type'] = 'text/plain';
        }

        $rewritten['data-cookiekit'] = $category;

        if ($tag === 'img') {
            // A tracking pixel should not turn into a visible placeholder.
            $rewritten['data-ck-silent'] = '';
        }

        $out = '<' . $tag;

        foreach ($rewritten as $name => $value) {
            $out .= $value === ''
                ? ' ' . $name
                : ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $out . '>';
    }

    /**
     * @return array<string, string>
     */
    private static function parseAttributes(string $source): array
    {
        if (preg_match_all(self::ATTRIBUTE, $source, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $attributes = [];

        foreach ($matches as $match) {
            $name = $match[1];

            // The pattern cannot produce an empty name, but a lone `/` from a
            // self-closing tag is not an attribute.
            if ($name === '/') {
                continue;
            }

            $value = $match[2] ?? '';

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $value = substr($value, 1, -1);
            }

            $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attributes;
    }

    private static function srcKey(string $tag, string $src): string
    {
        return $tag . '|' . $src;
    }

    private static function inlineKey(string $code): string
    {
        return 'inline|' . sha1($code);
    }

    /**
     * A tag is left alone when it sits inside an explicitly ignored subtree, in
     * the banner's own markup, or in a `<template>`. Template contents are
     * never reachable by `document.querySelectorAll`, so blocking them would
     * mean blocking something that can never be unblocked again.
     */
    private function mustSkip(DOMElement $element): bool
    {
        for ($node = $element; $node instanceof DOMNode; $node = $node->parentNode) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if (
                $node->hasAttribute(self::IGNORE_ATTRIBUTE)
                || $node->hasAttribute('data-cookiekit-root')
                || strtolower($node->tagName) === 'template'
            ) {
                return true;
            }
        }

        return false;
    }
}
