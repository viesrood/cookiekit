<?php

declare(strict_types=1);

namespace viesrood\cookiekit\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use viesrood\cookiekit\helpers\Duration;
use viesrood\cookiekit\models\Cookie;
use viesrood\cookiekit\models\Finding;
use viesrood\cookiekit\Plugin;
use viesrood\cookiekit\records\CookieRecord;
use viesrood\cookiekit\records\FindingRecord;

/**
 * The findings inbox: what a scan saw, what was done about it, and what still
 * needs a decision.
 *
 * Two rules shape everything in here.
 *
 * A finding is one row per (type, name, site), however many times and in
 * however many ways it is seen. The alternative, a row per source, means `_ga`
 * shows up once from the markup scan and once from the browser scan and has to
 * be dealt with twice.
 *
 * And only *observed* findings import themselves. A cookie name that came out
 * of the signature database because we saw the script that sets it is a good
 * guess, not a measurement, and a cookie declaration is a legal document.
 *
 * @phpstan-import-type DetectedItem from DetectorService
 */
class FindingsService extends Component
{
    /**
     * @param array{status?: string, type?: string, types?: list<string>, siteId?: int, preConsent?: bool} $criteria
     * @return Finding[]
     */
    public function getFindings(array $criteria = []): array
    {
        $query = FindingRecord::find();

        if (isset($criteria['status'])) {
            $query->andWhere(['status' => $criteria['status']]);
        }

        if (isset($criteria['type'])) {
            $query->andWhere(['type' => $criteria['type']]);
        }

        if (isset($criteria['types'])) {
            $query->andWhere(['type' => $criteria['types']]);
        }

        if (isset($criteria['siteId'])) {
            $query->andWhere(['siteId' => $criteria['siteId']]);
        }

        if (isset($criteria['preConsent'])) {
            $query->andWhere(['preConsent' => $criteria['preConsent']]);
        }

        /** @var FindingRecord[] $records */
        $records = $query->orderBy(['preConsent' => SORT_DESC, 'lastSeen' => SORT_DESC, 'name' => SORT_ASC])->all();

        return array_map(static fn(FindingRecord $record): Finding => self::createModel($record), $records);
    }

    public function getFindingById(int $id): ?Finding
    {
        $record = FindingRecord::findOne(['id' => $id]);

        return $record instanceof FindingRecord ? self::createModel($record) : null;
    }

    /**
     * Writes detected items into the inbox, merging anything already there.
     *
     * @param list<DetectedItem> $items
     * @return array{new: int, updated: int, ids: list<int>}
     */
    public function recordFindings(array $items): array
    {
        $now = Db::prepareDateForDb(new DateTime());
        $new = 0;
        $updated = 0;
        $ids = [];

        foreach ($items as $item) {
            $record = FindingRecord::findOne([
                'type' => $item['type'],
                'name' => $item['name'],
                'siteId' => $item['siteId'],
            ]);

            if ($record instanceof FindingRecord) {
                $this->mergeInto($record, $item, $now);
                $updated++;
            } else {
                $record = $this->buildRecord($item, $now);
                $new++;
            }

            if ($record->save()) {
                $ids[] = (int)$record->id;
            }
        }

        return ['new' => $new, 'updated' => $updated, 'ids' => $ids];
    }

    /**
     * Promotes the findings that need no human judgement into the declaration.
     *
     * Three conditions, all required: it was actually observed, it is
     * recognised (so category, purpose and expiry are grounded in something),
     * and the declaration does not already cover it. Everything else waits in
     * the inbox rather than getting an invented description.
     *
     * @param list<int> $ids
     * @return array{batch: string, imported: int, inbox: int}
     */
    public function applyAutoImport(array $ids, ?string $batch = null): array
    {
        $batch ??= StringHelper::UUID();
        $imported = 0;
        $inbox = 0;

        foreach ($ids as $id) {
            $finding = $this->getFindingById($id);

            if ($finding === null || $finding->status !== FindingRecord::STATUS_NEW) {
                continue;
            }

            if (!$this->qualifiesForAutoImport($finding)) {
                $inbox++;
                continue;
            }

            if ($this->importOne($finding, $finding->category, $batch)) {
                $imported++;
            } else {
                $inbox++;
            }
        }

        return ['batch' => $batch, 'imported' => $imported, 'inbox' => $inbox];
    }

    public function qualifiesForAutoImport(Finding $finding): bool
    {
        return $finding->getIsDeclarable()
            && $finding->getIsObserved()
            && $finding->signatureKey !== null
            && $finding->category !== '';
    }

    /**
     * Manual import from the CP, where the admin may have picked a category for
     * something nobody recognised.
     *
     * @param list<int> $ids
     * @param array<int, string> $categoryOverrides finding id => category
     * @return array{batch: string, imported: int, skipped: int, errors: list<string>}
     */
    public function importFindings(array $ids, array $categoryOverrides = []): array
    {
        $batch = StringHelper::UUID();
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($ids as $id) {
            $finding = $this->getFindingById($id);

            if ($finding === null) {
                continue;
            }

            if (!$finding->getIsDeclarable()) {
                $skipped++;
                $errors[] = Craft::t('cookiekit', '{name} is not a cookie, so it cannot be added to the declaration.', [
                    'name' => $finding->name,
                ]);
                continue;
            }

            $category = $categoryOverrides[$id] ?? $finding->category;

            if (!in_array($category, Plugin::CATEGORIES, true)) {
                $skipped++;
                $errors[] = Craft::t('cookiekit', 'Choose a category for {name} first.', ['name' => $finding->name]);
                continue;
            }

            if ($this->importOne($finding, $category, $batch)) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        return ['batch' => $batch, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param list<int> $ids
     */
    public function ignoreFindings(array $ids): int
    {
        return $this->setStatus($ids, FindingRecord::STATUS_IGNORED);
    }

    /**
     * @param list<int> $ids
     */
    public function resetFindings(array $ids): int
    {
        return $this->setStatus($ids, FindingRecord::STATUS_NEW);
    }

    /**
     * Takes one import back out of the declaration.
     *
     * A row that has been edited since it was written is left alone: somebody
     * put work into it, and undoing an import is not a licence to throw that
     * away. Those are reported instead.
     *
     * @return array{removed: int, kept: list<string>}
     */
    public function revertBatch(string $batch): array
    {
        $cookies = Plugin::getInstance()?->getCookies();

        if ($cookies === null) {
            return ['removed' => 0, 'kept' => []];
        }

        $removed = 0;
        $kept = [];

        foreach ($cookies->getCookiesByBatch($batch) as $cookie) {
            if ($cookie->id === null) {
                continue;
            }

            if ($this->wasEditedAfterImport($cookie->id)) {
                $kept[] = $cookie->name;
                continue;
            }

            $this->releaseFindingsFor($cookie->id);

            if ($cookies->deleteCookieById($cookie->id)) {
                $removed++;
            }
        }

        return ['removed' => $removed, 'kept' => $kept];
    }

    /**
     * Clears blocking problems that have actually been fixed.
     *
     * Only for pages the scan just fetched successfully, and only for the
     * blocking types. A cookie finding is never cleared this way: not seeing a
     * cookie in one scan is not evidence that it is gone.
     *
     * @param list<string> $scannedUrls
     * @param list<int> $keepIds findings seen again in this very scan
     */
    public function clearResolvedForUrls(array $scannedUrls, array $keepIds): int
    {
        if ($scannedUrls === []) {
            return 0;
        }

        $condition = [
            'and',
            ['type' => [FindingRecord::TYPE_UNBLOCKED, FindingRecord::TYPE_MISCATEGORISED]],
            ['evidenceUrl' => $scannedUrls],
        ];

        if ($keepIds !== []) {
            $condition[] = ['not', ['id' => $keepIds]];
        }

        return Craft::$app->getDb()->createCommand()
            ->delete(FindingRecord::TABLE, $condition)
            ->execute();
    }

    public function pruneStale(int $days): int
    {
        $threshold = (new DateTimeImmutable())->sub(new DateInterval(sprintf('P%dD', max(1, $days))));

        return Craft::$app->getDb()->createCommand()
            ->delete(FindingRecord::TABLE, [
                'and',
                ['status' => FindingRecord::STATUS_NEW],
                ['<', 'lastSeen', Db::prepareDateForDb($threshold)],
            ])
            ->execute();
    }

    /**
     * Everything the CP puts at the top of the screen, worst first.
     *
     * @return array{
     *     preConsent: Finding[],
     *     unblocked: Finding[],
     *     undeclared: Finding[],
     *     miscategorised: Finding[],
     *     containers: Finding[],
     *     stale: Cookie[]
     * }
     */
    public function getComplianceReport(): array
    {
        $open = $this->getFindings(['status' => FindingRecord::STATUS_NEW]);
        $cookies = Plugin::getInstance()?->getCookies();

        return [
            'preConsent' => array_values(array_filter($open, static fn(Finding $f): bool => $f->preConsent)),
            'unblocked' => array_values(array_filter(
                $open,
                static fn(Finding $f): bool => $f->type === FindingRecord::TYPE_UNBLOCKED,
            )),
            'undeclared' => array_values(array_filter(
                $open,
                static fn(Finding $f): bool => $f->getIsDeclarable(),
            )),
            'miscategorised' => array_values(array_filter(
                $open,
                static fn(Finding $f): bool => $f->type === FindingRecord::TYPE_MISCATEGORISED,
            )),
            'containers' => array_values(array_filter(
                $open,
                fn(Finding $f): bool => $f->type === FindingRecord::TYPE_VENDOR
                    && $this->isContainer($f->signatureKey),
            )),
            'stale' => $cookies?->getNeverDetectedCookies() ?? [],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        $counts = array_fill_keys(
            [FindingRecord::STATUS_NEW, FindingRecord::STATUS_IMPORTED, FindingRecord::STATUS_IGNORED],
            0,
        );

        foreach (FindingRecord::find()->select(['status'])->column() as $status) {
            $status = (string)$status;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Writes one finding into the declaration, unless it is already covered.
     */
    private function importOne(Finding $finding, string $category, string $batch): bool
    {
        $cookies = Plugin::getInstance()?->getCookies();

        if ($cookies === null || $finding->id === null) {
            return false;
        }

        $name = $finding->getDeclarationName();
        $existing = $cookies->findCoveringCookie($finding->name)
            ?? $cookies->findCoveringCookie($name);

        if ($existing !== null) {
            // Already declared, possibly under a wildcard that covers it. Link
            // the finding to that row and confirm we have now seen it.
            if ($existing->id !== null) {
                if ($finding->getIsObserved()) {
                    $cookies->markDetected($existing->id);
                }

                $this->linkToCookie($finding->id, $existing->id);
            }

            return true;
        }

        $cookie = new Cookie([
            'name' => $name,
            'category' => $category,
            'provider' => $finding->provider,
            'purpose' => $finding->purpose,
            'duration' => self::localiseDuration($finding->duration),
            'source' => Cookie::SOURCE_DETECTED,
            'importBatch' => $batch,
            'lastDetected' => $finding->getIsObserved() ? new DateTime() : null,
        ]);

        if (!$cookies->saveCookie($cookie) || $cookie->id === null) {
            return false;
        }

        $this->linkToCookie($finding->id, $cookie->id);

        return true;
    }

    private function linkToCookie(int $findingId, int $cookieId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(
                FindingRecord::TABLE,
                ['status' => FindingRecord::STATUS_IMPORTED, 'cookieId' => $cookieId],
                ['id' => $findingId],
            )
            ->execute();
    }

    private function releaseFindingsFor(int $cookieId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(
                FindingRecord::TABLE,
                ['status' => FindingRecord::STATUS_NEW, 'cookieId' => null],
                ['cookieId' => $cookieId],
            )
            ->execute();
    }

    private function wasEditedAfterImport(int $cookieId): bool
    {
        $row = (new Query())
            ->select(['dateCreated', 'dateUpdated'])
            ->from(CookieRecord::TABLE)
            ->where(['id' => $cookieId])
            ->one();

        if (!is_array($row)) {
            return false;
        }

        $created = DateTimeHelper::toDateTime($row['dateCreated']);
        $updated = DateTimeHelper::toDateTime($row['dateUpdated']);

        return $created !== false && $updated !== false && $updated > $created;
    }

    private function isContainer(?string $signatureKey): bool
    {
        if ($signatureKey === null) {
            return false;
        }

        $signature = Plugin::getInstance()?->getSignatures()->getByKey($signatureKey);

        return $signature !== null && $signature['container'];
    }

    /**
     * @param list<int> $ids
     */
    private function setStatus(array $ids, string $status): int
    {
        if ($ids === []) {
            return 0;
        }

        return Craft::$app->getDb()->createCommand()
            ->update(FindingRecord::TABLE, ['status' => $status], ['id' => $ids])
            ->execute();
    }

    /**
     * @param DetectedItem $item
     */
    private function buildRecord(array $item, string $now): FindingRecord
    {
        $record = new FindingRecord();
        $record->type = $item['type'];
        $record->name = $item['name'];
        $record->declaredAs = $item['declaredAs'];
        $record->signatureKey = $item['signatureKey'];
        $record->provider = $item['provider'];
        $record->category = $item['category'];
        $record->purpose = $item['purpose'];
        $record->duration = $item['duration'];
        $record->sources = $item['source'];
        $record->confidence = $item['confidence'];
        $record->evidenceUrl = self::truncate($item['evidenceUrl'], 500);
        $record->evidenceDetail = self::truncate($item['evidenceDetail'], 500);
        $record->snippet = $item['snippet'];
        $record->siteId = $item['siteId'];
        $record->consentSeen = implode(',', $item['consentSeen']);
        $record->preConsent = $item['preConsent'];
        $record->status = FindingRecord::STATUS_NEW;
        $record->hits = 1;
        $record->firstSeen = $now;
        $record->lastSeen = $now;

        return $record;
    }

    /**
     * @param DetectedItem $item
     */
    private function mergeInto(FindingRecord $record, array $item, string $now): void
    {
        $sources = array_values(array_unique(array_filter(
            array_merge(explode(',', (string)$record->sources), [$item['source']]),
        )));
        sort($sources);

        $record->sources = implode(',', $sources);
        $record->hits = (int)$record->hits + 1;
        $record->lastSeen = $now;

        // Seeing something for real always beats having guessed it.
        if ($item['confidence'] === FindingRecord::CONFIDENCE_OBSERVED) {
            $record->confidence = FindingRecord::CONFIDENCE_OBSERVED;
            $record->duration = $item['duration'];
        }

        // A category already on the row may have been picked by hand in the CP,
        // so it is only ever filled in, never overwritten.
        if ((string)$record->category === '' && $item['category'] !== '') {
            $record->category = $item['category'];
        }

        foreach (['declaredAs', 'provider', 'purpose', 'snippet'] as $field) {
            if ((string)$record->$field === '' && $item[$field] !== '') {
                $record->$field = $item[$field];
            }
        }

        if ($record->signatureKey === null && $item['signatureKey'] !== null) {
            $record->signatureKey = $item['signatureKey'];
        }

        // The most recent sighting is the most useful thing to link to.
        $record->evidenceUrl = self::truncate($item['evidenceUrl'], 500);
        $record->evidenceDetail = self::truncate($item['evidenceDetail'], 500);

        if ($item['consentSeen'] !== []) {
            $record->consentSeen = implode(',', $item['consentSeen']);
        }

        // A violation stays on the record until it is dealt with: one clean
        // scan does not undo the fact that it happened.
        $record->preConsent = (bool)$record->preConsent || $item['preConsent'];
    }

    /**
     * The detector works in canonical English so it can stay free of Craft.
     * The declaration is read by visitors, so the lifetime is translated on the
     * way in rather than left as "2 years" on a Dutch site.
     *
     * Explicitly into the site's language, not the current one: whether this
     * import came from a console command, a queue job or an admin with a
     * different language preference must not change what visitors read.
     */
    private static function localiseDuration(string $duration): string
    {
        if ($duration === '') {
            return '';
        }

        $language = Craft::$app->getSites()->getPrimarySite()->language;
        $parsed = Duration::toTranslationKey($duration);

        if ($parsed === null) {
            return Craft::t('cookiekit', $duration, [], $language);
        }

        return Craft::t('cookiekit', $parsed['key'], ['n' => $parsed['count']], $language);
    }

    private static function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    private static function createModel(FindingRecord $record): Finding
    {
        return new Finding([
            'id' => (int)$record->id,
            'type' => $record->type,
            'name' => $record->name,
            'declaredAs' => $record->declaredAs,
            'signatureKey' => $record->signatureKey,
            'provider' => $record->provider,
            'category' => $record->category,
            'purpose' => (string)$record->purpose,
            'duration' => $record->duration,
            'sources' => $record->sources,
            'confidence' => $record->confidence,
            'evidenceUrl' => $record->evidenceUrl,
            'evidenceDetail' => $record->evidenceDetail,
            'snippet' => (string)$record->snippet,
            'siteId' => (int)$record->siteId,
            'consentSeen' => $record->consentSeen,
            'preConsent' => (bool)$record->preConsent,
            'status' => $record->status,
            'cookieId' => $record->cookieId !== null ? (int)$record->cookieId : null,
            'hits' => (int)$record->hits,
            'firstSeen' => DateTimeHelper::toDateTime($record->firstSeen) ?: null,
            'lastSeen' => DateTimeHelper::toDateTime($record->lastSeen) ?: null,
            'uid' => (string)$record->uid,
        ]);
    }
}
