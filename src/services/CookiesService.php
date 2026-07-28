<?php

declare(strict_types=1);

namespace viesrood\cookiekit\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;
use viesrood\cookiekit\helpers\CookieNameMatcher;
use viesrood\cookiekit\models\Cookie;
use viesrood\cookiekit\Plugin;
use viesrood\cookiekit\records\CookieRecord;

/**
 * Manages the cookie declaration.
 */
class CookiesService extends Component
{
    /**
     * Returns all cookies, ordered by category (fixed order) and sortOrder.
     *
     * @return Cookie[]
     */
    public function getAllCookies(): array
    {
        /** @var CookieRecord[] $records */
        $records = CookieRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        $cookies = array_map(
            static fn(CookieRecord $record): Cookie => self::createModel($record),
            $records,
        );

        // Sort by the fixed category order, keeping sortOrder within a category.
        $order = array_flip(Plugin::CATEGORIES);
        usort(
            $cookies,
            static fn(Cookie $a, Cookie $b): int =>
                [$order[$a->category] ?? 99, $a->sortOrder] <=> [$order[$b->category] ?? 99, $b->sortOrder],
        );

        return $cookies;
    }

    /**
     * Returns the declaration grouped per category. Categories without
     * cookies are included with an empty list, so the banner can still
     * render every category toggle.
     *
     * @return array<string, Cookie[]>
     */
    public function getCookiesByCategory(): array
    {
        $grouped = array_fill_keys(Plugin::CATEGORIES, []);

        foreach ($this->getAllCookies() as $cookie) {
            $grouped[$cookie->category][] = $cookie;
        }

        return $grouped;
    }

    public function getCookieById(int $id): ?Cookie
    {
        $record = CookieRecord::findOne(['id' => $id]);

        return $record instanceof CookieRecord ? self::createModel($record) : null;
    }

    public function saveCookie(Cookie $cookie): bool
    {
        if (!$cookie->validate()) {
            return false;
        }

        if ($cookie->id !== null) {
            $record = CookieRecord::findOne(['id' => $cookie->id]);
            if (!$record instanceof CookieRecord) {
                return false;
            }
        } else {
            $record = new CookieRecord();
        }

        $record->category = $cookie->category;
        $record->name = $cookie->name;
        $record->provider = $cookie->provider;
        $record->purpose = $cookie->purpose;
        $record->duration = $cookie->duration;
        $record->sortOrder = $cookie->sortOrder;
        $record->source = $cookie->source;
        $record->importBatch = $cookie->importBatch;

        if ($cookie->lastDetected !== null) {
            $record->lastDetected = Db::prepareDateForDb($cookie->lastDetected);
        }

        if (!$record->save()) {
            return false;
        }

        $cookie->id = (int)$record->id;
        $cookie->uid = (string)$record->uid;

        return true;
    }

    public function deleteCookieById(int $id): bool
    {
        $record = CookieRecord::findOne(['id' => $id]);

        return $record instanceof CookieRecord && $record->delete() !== false;
    }

    /**
     * Exact lookup by declared name. Note that this does not resolve wildcards:
     * use CookieNameMatcher against getDeclaredNames() to find out whether an
     * observed `_ga_G3Y7GKHRGGR` is already covered by a declared `_ga_*`.
     */
    public function getCookieByName(string $name): ?Cookie
    {
        $record = CookieRecord::findOne(['name' => $name]);

        return $record instanceof CookieRecord ? self::createModel($record) : null;
    }

    /**
     * Every declared name, wildcards included.
     *
     * @return list<string>
     */
    public function getDeclaredNames(): array
    {
        /** @var list<string> $names */
        $names = CookieRecord::find()->select(['name'])->column();

        return $names;
    }

    /**
     * Returns the declaration row already covering an observed cookie name, or
     * null when it is genuinely new. This is the single dedupe gate: everything
     * that writes into the declaration goes through it.
     */
    public function findCoveringCookie(string $observedName): ?Cookie
    {
        $declared = CookieNameMatcher::findDeclared($this->getDeclaredNames(), $observedName);

        return $declared !== null ? $this->getCookieByName($declared) : null;
    }

    /**
     * @param Cookie[] $cookies
     * @return array{saved: int, failed: int}
     */
    public function saveCookies(array $cookies): array
    {
        $saved = 0;
        $failed = 0;

        foreach ($cookies as $cookie) {
            if ($this->saveCookie($cookie)) {
                $saved++;
            } else {
                $failed++;
            }
        }

        return ['saved' => $saved, 'failed' => $failed];
    }

    /**
     * Records that a scan has just seen this cookie for real. Deliberately a
     * direct column write: it is bookkeeping, not a content change, and must
     * not touch dateUpdated or trip any save logic.
     */
    public function markDetected(int $cookieId, ?DateTimeInterface $when = null): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(
                CookieRecord::TABLE,
                ['lastDetected' => Db::prepareDateForDb($when ?? new DateTime())],
                ['id' => $cookieId],
            )
            ->execute();
    }

    /**
     * Declaration rows no scan has ever confirmed. Either they are stale, or
     * they only appear on a page the scan never reached: worth a look, never an
     * automatic deletion.
     *
     * @return Cookie[]
     */
    public function getNeverDetectedCookies(): array
    {
        return array_values(array_filter(
            $this->getAllCookies(),
            static fn(Cookie $cookie): bool => $cookie->lastDetected === null,
        ));
    }

    /**
     * Every row written by one import, so it can be taken back out in one go.
     *
     * @return Cookie[]
     */
    public function getCookiesByBatch(string $batch): array
    {
        /** @var CookieRecord[] $records */
        $records = CookieRecord::find()->where(['importBatch' => $batch])->all();

        return array_map(static fn(CookieRecord $record): Cookie => self::createModel($record), $records);
    }

    private static function createModel(CookieRecord $record): Cookie
    {
        return new Cookie([
            'id' => (int)$record->id,
            'category' => $record->category,
            'name' => $record->name,
            'provider' => $record->provider,
            'purpose' => (string)$record->purpose,
            'duration' => $record->duration,
            'sortOrder' => (int)$record->sortOrder,
            'source' => (string)($record->source ?? Cookie::SOURCE_MANUAL),
            'lastDetected' => DateTimeHelper::toDateTime($record->lastDetected) ?: null,
            'importBatch' => $record->importBatch,
            'uid' => (string)$record->uid,
        ]);
    }
}
