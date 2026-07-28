<?php

declare(strict_types=1);

namespace viesrood\cookiekit\services;

use Craft;
use craft\base\Component;
use viesrood\cookiekit\events\RegisterSignaturesEvent;
use viesrood\cookiekit\helpers\SignatureMatcher;

/**
 * Loads the signature database and hands out a matcher for it.
 *
 * Three layers are merged, later wins:
 *
 * 1. the database shipped with the plugin (`src/data/signatures.php`)
 * 2. a project file `config/cookiekit-signatures.php`
 * 3. handlers on EVENT_REGISTER_SIGNATURES
 *
 * In layers 2 and 3 a partial entry is merged over the shipped one, so
 * overriding a single category does not mean restating every host. A key set
 * to `false` removes a shipped signature outright.
 *
 * @phpstan-import-type VendorSignature from SignatureMatcher
 * @phpstan-import-type VendorMatch from SignatureMatcher
 * @phpstan-import-type CookieMatch from SignatureMatcher
 * @phpstan-import-type StorageMatch from SignatureMatcher
 */
class SignatureService extends Component
{
    /**
     * @event RegisterSignaturesEvent
     */
    public const EVENT_REGISTER_SIGNATURES = 'registerSignatures';

    public const CONFIG_FILE = 'cookiekit-signatures';

    private ?SignatureMatcher $matcher = null;

    /**
     * @var array<string, VendorSignature>|null
     */
    private ?array $signatures = null;

    /**
     * @return array<string, VendorSignature>
     */
    public function getAll(): array
    {
        if ($this->signatures !== null) {
            return $this->signatures;
        }

        /** @var array<string, array<string, mixed>> $shipped */
        $shipped = require dirname(__DIR__) . '/data/signatures.php';

        /** @var array<string, array<string, mixed>|false> $fromConfig */
        $fromConfig = Craft::$app->getConfig()->getConfigFromFile(self::CONFIG_FILE);

        $event = new RegisterSignaturesEvent();
        $this->trigger(self::EVENT_REGISTER_SIGNATURES, $event);

        $merged = $shipped;

        foreach ([$fromConfig, $event->signatures] as $overrides) {
            foreach ($overrides as $key => $override) {
                if ($override === false) {
                    unset($merged[$key]);
                    continue;
                }

                $merged[$key] = array_replace($merged[$key] ?? [], $override);
            }
        }

        $normalised = [];
        foreach ($merged as $key => $signature) {
            $normalised[$key] = self::normalise($signature);
        }

        return $this->signatures = $normalised;
    }

    /**
     * @return VendorSignature|null
     */
    public function getByKey(string $key): ?array
    {
        return $this->getAll()[$key] ?? null;
    }

    public function getMatcher(): SignatureMatcher
    {
        return $this->matcher ??= new SignatureMatcher($this->getAll());
    }

    /**
     * @return VendorMatch|null
     */
    public function matchUrl(string $url): ?array
    {
        return $this->getMatcher()->matchUrl($url);
    }

    /**
     * @return list<VendorMatch>
     */
    public function matchInline(string $script): array
    {
        return $this->getMatcher()->matchInline($script);
    }

    /**
     * @return CookieMatch|null
     */
    public function matchCookieName(string $name): ?array
    {
        return $this->getMatcher()->matchCookieName($name);
    }

    /**
     * @param 'local'|'session' $type
     * @return StorageMatch|null
     */
    public function matchStorageKey(string $storageKey, string $type): ?array
    {
        return $this->getMatcher()->matchStorageKey($storageKey, $type);
    }

    /**
     * Drops the memoised database, so a test or a console command can pick up
     * changed overrides without a new request.
     */
    public function flush(): void
    {
        $this->signatures = null;
        $this->matcher = null;
    }

    /**
     * Fills in every key a signature may omit, so consumers never have to guard
     * against a partial entry supplied by a project override.
     *
     * @param array<string, mixed> $signature
     * @return VendorSignature
     */
    private static function normalise(array $signature): array
    {
        $category = is_string($signature['category'] ?? null) ? $signature['category'] : 'statistics';

        /** @var VendorSignature $normalised */
        $normalised = [
            'label' => is_string($signature['label'] ?? null) ? $signature['label'] : '',
            'provider' => is_string($signature['provider'] ?? null) ? $signature['provider'] : '',
            'category' => $category,
            'container' => (bool)($signature['container'] ?? false),
            'blockAs' => is_string($signature['blockAs'] ?? null) ? $signature['blockAs'] : $category,
            'hosts' => array_values((array)($signature['hosts'] ?? [])),
            'paths' => array_values((array)($signature['paths'] ?? [])),
            'inline' => array_values((array)($signature['inline'] ?? [])),
            'cookies' => array_values(array_map(
                static fn(array $cookie): array => self::normaliseCookie($cookie, $category),
                (array)($signature['cookies'] ?? []),
            )),
            'storage' => array_values(array_map(
                static fn(array $storage): array => self::normaliseStorage($storage, $category),
                (array)($signature['storage'] ?? []),
            )),
        ];

        return $normalised;
    }

    /**
     * @param array<string, mixed> $cookie
     * @return array{name: string, match: 'exact'|'prefix'|'regex', declaredAs: string, category: string, duration: string, purpose: string}
     */
    private static function normaliseCookie(array $cookie, string $fallbackCategory): array
    {
        $name = is_string($cookie['name'] ?? null) ? $cookie['name'] : '';
        $mode = $cookie['match'] ?? 'exact';

        return [
            'name' => $name,
            'match' => in_array($mode, ['exact', 'prefix', 'regex'], true) ? $mode : 'exact',
            'declaredAs' => is_string($cookie['declaredAs'] ?? null) && $cookie['declaredAs'] !== ''
                ? $cookie['declaredAs']
                : $name,
            'category' => is_string($cookie['category'] ?? null) ? $cookie['category'] : $fallbackCategory,
            'duration' => is_string($cookie['duration'] ?? null) ? $cookie['duration'] : '',
            'purpose' => is_string($cookie['purpose'] ?? null) ? $cookie['purpose'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $storage
     * @return array{key: string, match: 'exact'|'prefix'|'regex', type: 'local'|'session', category: string, purpose: string}
     */
    private static function normaliseStorage(array $storage, string $fallbackCategory): array
    {
        $mode = $storage['match'] ?? 'exact';
        $type = $storage['type'] ?? 'local';

        return [
            'key' => is_string($storage['key'] ?? null) ? $storage['key'] : '',
            'match' => in_array($mode, ['exact', 'prefix', 'regex'], true) ? $mode : 'exact',
            'type' => in_array($type, ['local', 'session'], true) ? $type : 'local',
            'category' => is_string($storage['category'] ?? null) ? $storage['category'] : $fallbackCategory,
            'purpose' => is_string($storage['purpose'] ?? null) ? $storage['purpose'] : '',
        ];
    }
}
