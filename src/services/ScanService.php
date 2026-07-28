<?php

declare(strict_types=1);

namespace viesrood\cookiekit\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\Section;
use DateTime;
use RuntimeException;
use viesrood\cookiekit\helpers\ScanPayload;
use viesrood\cookiekit\http\Fetcher;
use viesrood\cookiekit\http\FetchResult;
use viesrood\cookiekit\http\SitePolicy;
use viesrood\cookiekit\models\ScanRun;
use viesrood\cookiekit\Plugin;
use viesrood\cookiekit\records\ScanRecord;

/**
 * Decides what to scan, runs it, and keeps the books.
 *
 * @phpstan-import-type DetectedItem from DetectorService
 * @phpstan-type ScanTarget array{url: string, siteId: int}
 * @phpstan-type ScanSummary array{
 *     scanId: int,
 *     urlsScanned: int,
 *     urlsFailed: int,
 *     new: int,
 *     updated: int,
 *     imported: int,
 *     batch: string|null,
 *     errors: list<string>
 * }
 */
class ScanService extends Component
{
    /**
     * Which pages to visit.
     *
     * Sampling is per section *and entry type*, not per section: entry types
     * are what differ in template, and the template is what decides which third
     * parties a page loads. The homepage always survives the cap.
     *
     * @return list<ScanTarget>
     */
    public function discoverUrls(?int $siteId = null): array
    {
        $settings = Plugin::getInstance()?->getSettings();

        if ($settings === null) {
            return [];
        }

        $targets = [];
        $seen = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if ($siteId !== null && $site->id !== $siteId) {
                continue;
            }

            $baseUrl = $site->getBaseUrl();

            if ($baseUrl === null) {
                continue;
            }

            $this->addTarget($targets, $seen, $baseUrl, $site->id);

            foreach ($settings->getExtraUrls() as $extra) {
                $this->addTarget($targets, $seen, UrlHelper::isFullUrl($extra)
                    ? $extra
                    : rtrim($baseUrl, '/') . '/' . ltrim($extra, '/'), $site->id);
            }

            foreach ($this->sampleEntryUrls($site->id, $settings->scanUrlsPerSection) as $url) {
                $this->addTarget($targets, $seen, $url, $site->id);
            }
        }

        // The base URLs were added first, so capping here never drops a
        // homepage in favour of a random entry.
        return array_slice($targets, 0, max(1, $settings->scanMaxUrls));
    }

    /**
     * Fetches every target, hands the material to the detector, and records
     * what came out.
     *
     * @param list<ScanTarget> $targets
     * @param (callable(int, int, string): void)|null $onProgress
     * @return ScanSummary
     */
    public function scanTargets(array $targets, string $type = ScanRecord::TYPE_CRAWL, ?callable $onProgress = null): array
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new RuntimeException('CookieKit is not installed.');
        }

        $settings = $plugin->getSettings();
        $run = $this->startRun($type, $targets[0]['siteId'] ?? 0);

        $fetcher = new Fetcher(SitePolicy::fromSites(), $settings->scanTimeout, $settings->scanConcurrency);
        $siteIdByUrl = [];
        $urls = [];

        foreach ($targets as $target) {
            $url = $settings->scanCacheBust ? $this->addCacheBuster($target['url'], $run->uid ?? '') : $target['url'];
            $urls[] = $url;
            $siteIdByUrl[$url] = $target['siteId'];
        }

        $items = [];
        $scannedUrls = [];
        $errors = [];
        $scanned = 0;
        $failed = 0;
        $total = count($urls);
        $done = 0;

        $results = $fetcher->fetchMany($urls, static function (FetchResult $result) use (&$done, $total, $onProgress): void {
            $done++;

            if ($onProgress !== null) {
                $onProgress($done, $total, $result->url);
            }
        });

        foreach ($results as $url => $result) {
            if (!$result->isSuccess()) {
                $failed++;
                $errors[] = sprintf('%s: %s', $url, $result->error ?? 'HTTP ' . $result->statusCode);
                continue;
            }

            $scanned++;
            $siteId = $siteIdByUrl[$url] ?? 0;
            $reportUrl = $this->stripCacheBuster($url);
            $scannedUrls[] = $reportUrl;

            foreach ($plugin->getDetector()->detectFromSetCookie($result->setCookieLines, $reportUrl, $siteId) as $item) {
                $items[] = $item;
            }

            if (!$result->isHtml()) {
                continue;
            }

            foreach ($plugin->getDetector()->detectFromHtml($result->body, $reportUrl, $siteId) as $item) {
                $items[] = $item;
            }

            foreach ($plugin->getDetector()->detectFromRawSource($result->body, $reportUrl, $siteId) as $item) {
                $items[] = $item;
            }
        }

        return $this->finishRun($run, $items, $scannedUrls, $scanned, $failed, $errors);
    }

    /**
     * @param (callable(int, int, string): void)|null $onProgress
     * @return ScanSummary
     */
    public function runFullScan(?int $siteId = null, ?callable $onProgress = null): array
    {
        return $this->scanTargets($this->discoverUrls($siteId), ScanRecord::TYPE_CRAWL, $onProgress);
    }

    /**
     * Takes in the output of the Playwright scanner.
     *
     * Everything here was seen in a real browser, so unlike the crawl these
     * findings are observed rather than inferred, and the recognised ones go
     * straight into the declaration.
     *
     * @param array<string, mixed> $payload
     * @return ScanSummary
     */
    public function importBrowserReport(array $payload, ?int $siteId = null): array
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new RuntimeException('CookieKit is not installed.');
        }

        $reports = ScanPayload::normalise($payload);
        $siteId ??= Craft::$app->getSites()->getPrimarySite()->id;
        $run = $this->startRun(ScanRecord::TYPE_BROWSER, $siteId);

        $items = [];
        $urls = [];

        foreach ($reports as $report) {
            $urls[] = $report['url'];

            foreach ($plugin->getDetector()->detectFromBrowser($report, $siteId) as $item) {
                $items[] = $item;
            }
        }

        // A browser run says nothing about markup, so it must not clear the
        // blocking findings the crawl raised.
        return $this->finishRun($run, $items, [], count($reports), 0, []);
    }

    /**
     * Records the findings of a run and, if enabled, promotes the ones that
     * need no human judgement.
     *
     * @param list<DetectedItem> $items
     * @param list<string> $scannedUrls
     * @param list<string> $errors
     * @return ScanSummary
     */
    public function recordResults(
        ScanRun $run,
        array $items,
        array $scannedUrls,
        int $scanned,
        int $failed,
        array $errors = [],
    ): array {
        return $this->finishRun($run, $items, $scannedUrls, $scanned, $failed, $errors);
    }

    public function startRun(string $type, int $siteId = 0): ScanRun
    {
        $record = new ScanRecord();
        $record->type = $type;
        $record->status = ScanRecord::STATUS_RUNNING;
        $record->siteId = $siteId;
        $record->save();

        return new ScanRun([
            'id' => (int)$record->id,
            'type' => $record->type,
            'status' => $record->status,
            'siteId' => (int)$record->siteId,
            'uid' => (string)$record->uid,
        ]);
    }

    public function getLastScan(?int $siteId = null): ?ScanRun
    {
        $query = ScanRecord::find()->orderBy(['dateCreated' => SORT_DESC]);
        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }
        $record = $query->one();

        return $record instanceof ScanRecord ? self::createModel($record) : null;
    }

    /**
     * @return ScanRun[]
     */
    public function getRecentScans(int $limit = 10): array
    {
        /** @var ScanRecord[] $records */
        $records = ScanRecord::find()->orderBy(['dateCreated' => SORT_DESC])->limit($limit)->all();

        return array_map(static fn(ScanRecord $record): ScanRun => self::createModel($record), $records);
    }

    /**
     * @param list<DetectedItem> $items
     * @param list<string> $scannedUrls
     * @param list<string> $errors
     * @return ScanSummary
     */
    private function finishRun(
        ScanRun $run,
        array $items,
        array $scannedUrls,
        int $scanned,
        int $failed,
        array $errors,
    ): array {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new RuntimeException('CookieKit is not installed.');
        }

        $findings = $plugin->getFindings();
        $recorded = $findings->recordFindings($items);

        // Blocking problems that no longer show up on a page we just fetched
        // successfully have been fixed, and a permanent red alert teaches
        // people to ignore red alerts.
        $findings->clearResolvedForUrls($scannedUrls, $recorded['ids']);

        $imported = 0;
        $batch = null;

        if ($plugin->getSettings()->autoImport) {
            $result = $findings->applyAutoImport($recorded['ids']);
            $imported = $result['imported'];
            $batch = $result['imported'] > 0 ? $result['batch'] : null;
        }

        $this->closeRun($run, $scanned, $failed, $recorded['new'], count($items), $batch, $errors);

        return [
            'scanId' => $run->id ?? 0,
            'urlsScanned' => $scanned,
            'urlsFailed' => $failed,
            'new' => $recorded['new'],
            'updated' => $recorded['updated'],
            'imported' => $imported,
            'batch' => $batch,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<string> $errors
     */
    private function closeRun(
        ScanRun $run,
        int $scanned,
        int $failed,
        int $new,
        int $total,
        ?string $batch,
        array $errors,
    ): void {
        if ($run->id === null) {
            return;
        }

        $record = ScanRecord::findOne(['id' => $run->id]);

        if (!$record instanceof ScanRecord) {
            return;
        }

        $record->status = $scanned === 0 && $failed > 0 ? ScanRecord::STATUS_FAILED : ScanRecord::STATUS_DONE;
        $record->urlsScanned = $scanned;
        $record->urlsFailed = $failed;
        $record->findingsNew = $new;
        $record->findingsTotal = $total;
        $record->importBatch = $batch;
        $record->errorMessage = mb_substr(implode(' | ', $errors), 0, 500);
        $record->dateFinished = Db::prepareDateForDb(new DateTime());
        $record->save();
    }

    /**
     * @return list<string>
     */
    private function sampleEntryUrls(int $siteId, int $perType): array
    {
        $urls = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($section->type === Section::TYPE_SINGLE) {
                // A single has exactly one entry, so there is nothing to sample.
                $urls = array_merge($urls, $this->entryUrls($siteId, $section->handle, null, 1));
                continue;
            }

            foreach ($section->getEntryTypes() as $entryType) {
                $urls = array_merge($urls, $this->entryUrls($siteId, $section->handle, $entryType->handle, $perType));
            }
        }

        return array_values($urls);
    }

    /**
     * @return list<string>
     */
    private function entryUrls(int $siteId, string $sectionHandle, ?string $typeHandle, int $limit): array
    {
        $query = Entry::find()
            ->siteId($siteId)
            ->section($sectionHandle)
            ->uri(':notempty:')
            ->status(Entry::STATUS_LIVE)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit);

        if ($typeHandle !== null) {
            $query->type($typeHandle);
        }

        $urls = [];

        foreach ($query->all() as $entry) {
            $url = $entry->getUrl();

            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @param list<ScanTarget> $targets
     * @param array<string, true> $seen
     */
    private function addTarget(array &$targets, array &$seen, string $url, int $siteId): void
    {
        $url = rtrim($url, '/') === '' ? $url : $url;

        if (isset($seen[$url])) {
            return;
        }

        $seen[$url] = true;
        $targets[] = ['url' => $url, 'siteId' => $siteId];
    }

    /**
     * Blitz and friends do not cache URLs carrying a query string, so this is
     * what gets the scan a freshly rendered page. The trade-off is real and
     * documented: you are then measuring a variant no visitor receives.
     */
    private function addCacheBuster(string $url, string $token): string
    {
        $token = $token !== '' ? $token : StringHelper::UUID();

        return $url . (str_contains($url, '?') ? '&' : '?') . 'cookiekit-scan=' . urlencode($token);
    }

    private function stripCacheBuster(string $url): string
    {
        $clean = preg_replace('/[?&]cookiekit-scan=[^&]*/', '', $url);

        return is_string($clean) ? rtrim($clean, '?&') : $url;
    }

    private static function createModel(ScanRecord $record): ScanRun
    {
        return new ScanRun([
            'id' => (int)$record->id,
            'type' => $record->type,
            'status' => $record->status,
            'siteId' => (int)$record->siteId,
            'urlsScanned' => (int)$record->urlsScanned,
            'urlsFailed' => (int)$record->urlsFailed,
            'findingsNew' => (int)$record->findingsNew,
            'findingsTotal' => (int)$record->findingsTotal,
            'importBatch' => $record->importBatch,
            'errorMessage' => $record->errorMessage,
            'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated) ?: null,
            'dateFinished' => $record->dateFinished !== null
                ? (DateTimeHelper::toDateTime($record->dateFinished) ?: null)
                : null,
            'uid' => (string)$record->uid,
        ]);
    }
}
