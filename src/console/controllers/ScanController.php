<?php

declare(strict_types=1);

namespace viesrood\cookiekit\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use Throwable;
use viesrood\cookiekit\Plugin;
use viesrood\cookiekit\records\FindingRecord;
use yii\console\ExitCode;

/**
 * Scans this site for cookies and trackers.
 *
 *     php craft cookiekit/scan/urls              which pages would be visited
 *     php craft cookiekit/scan/run               scan them
 *     php craft cookiekit/scan/status            last run and open findings
 *     php craft cookiekit/scan/revert <batch>    undo one automatic import
 *     php craft cookiekit/scan/prune             drop findings nobody acted on
 */
class ScanController extends Controller
{
    public $defaultAction = 'run';

    /**
     * Print every finding as it is recorded.
     */
    public bool $verbose = false;

    /**
     * Limit the scan to one site handle.
     */
    public ?string $site = null;

    /**
     * How many days of untouched findings to keep when pruning.
     */
    public ?int $days = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'run' => ['verbose', 'site'],
            'import' => ['verbose', 'site'],
            'urls' => ['site'],
            'prune' => ['days'],
            default => [],
        });
    }

    /**
     * @return array<string, string>
     */
    public function optionAliases(): array
    {
        return ['v' => 'verbose'];
    }

    /**
     * Crawls the site and records what it finds.
     */
    public function actionRun(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            $this->stderr("CookieKit is not installed.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $siteId = $this->resolveSiteId();

        if ($siteId === false) {
            return ExitCode::CONFIG;
        }

        $targets = $plugin->getScan()->discoverUrls($siteId);

        if ($targets === []) {
            $this->stderr("No URLs to scan. Check that at least one site has a base URL.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout(sprintf("Scanning %d page(s)...\n", count($targets)));

        try {
            $summary = $plugin->getScan()->scanTargets($targets, 'crawl', function (int $done, int $total, string $url): void {
                if ($this->verbose) {
                    $this->stdout(sprintf("  [%d/%d] %s\n", $done, $total, $url));
                }
            });
        } catch (Throwable $exception) {
            $this->stderr('Scan failed: ' . $exception->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf(
            "\nScanned %d page(s), %d failed.\n",
            $summary['urlsScanned'],
            $summary['urlsFailed'],
        ));

        $this->stdout(sprintf(
            "%d new finding(s), %d already known, %d added to the declaration automatically.\n",
            $summary['new'],
            $summary['updated'],
            $summary['imported'],
        ), $summary['new'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        if ($summary['batch'] !== null) {
            $this->stdout("Undo that import with: php craft cookiekit/scan/revert {$summary['batch']}\n");
        }

        foreach ($summary['errors'] as $error) {
            $this->stderr('  ! ' . $error . "\n", Console::FG_RED);
        }

        if ($this->verbose) {
            $this->printFindings();
        }

        $this->printAlerts();

        return ExitCode::OK;
    }

    /**
     * Prints the pages a scan would visit, without visiting them.
     */
    public function actionUrls(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return ExitCode::CONFIG;
        }

        $siteId = $this->resolveSiteId();

        if ($siteId === false) {
            return ExitCode::CONFIG;
        }

        $targets = $plugin->getScan()->discoverUrls($siteId);

        foreach ($targets as $target) {
            $this->stdout($target['url'] . "\n");
        }

        $this->stdout(sprintf("\n%d URL(s).\n", count($targets)), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Shows the last run and what is still open.
     */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return ExitCode::CONFIG;
        }

        $last = $plugin->getScan()->getLastScan();

        if ($last === null) {
            $this->stdout("No scan has run yet.\n", Console::FG_YELLOW);
        } else {
            $this->stdout(sprintf(
                "Last scan: %s, %s, %d page(s), %d new finding(s).\n",
                $last->dateCreated?->format('Y-m-d H:i') ?? 'unknown',
                $last->status,
                $last->urlsScanned,
                $last->findingsNew,
            ));
        }

        $counts = $plugin->getFindings()->getStatusCounts();

        $this->stdout(sprintf(
            "Findings: %d open, %d imported, %d ignored.\n",
            $counts[FindingRecord::STATUS_NEW] ?? 0,
            $counts[FindingRecord::STATUS_IMPORTED] ?? 0,
            $counts[FindingRecord::STATUS_IGNORED] ?? 0,
        ));

        $this->printAlerts();

        return ExitCode::OK;
    }

    /**
     * Imports the JSON written by the Playwright scanner.
     *
     *     node scanner/scan.js https://example.test --out scan.json
     *     php craft cookiekit/scan/import scan.json
     */
    public function actionImport(string $file): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return ExitCode::CONFIG;
        }

        if (!is_file($file) || !is_readable($file)) {
            $this->stderr("Cannot read {$file}\n", Console::FG_RED);

            return ExitCode::NOINPUT;
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            $this->stderr("Cannot read {$file}\n", Console::FG_RED);

            return ExitCode::NOINPUT;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->stderr('Not valid JSON: ' . $exception->getMessage() . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $siteId = $this->resolveSiteId();

        if ($siteId === false) {
            return ExitCode::CONFIG;
        }

        $summary = $plugin->getScan()->importBrowserReport($payload, $siteId);

        $this->stdout(sprintf(
            "Imported %d page(s) from the browser scan.\n",
            $summary['urlsScanned'],
        ), Console::FG_GREEN);

        $this->stdout(sprintf(
            "%d new finding(s), %d already known, %d added to the declaration automatically.\n",
            $summary['new'],
            $summary['updated'],
            $summary['imported'],
        ));

        if ($summary['batch'] !== null) {
            $this->stdout("Undo that import with: php craft cookiekit/scan/revert {$summary['batch']}\n");
        }

        if ($this->verbose) {
            $this->printFindings();
        }

        $this->printAlerts();

        return ExitCode::OK;
    }

    /**
     * Takes one automatic import back out of the declaration.
     */
    public function actionRevert(string $batch): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return ExitCode::CONFIG;
        }

        $result = $plugin->getFindings()->revertBatch($batch);

        $this->stdout(sprintf("Removed %d cookie(s) from the declaration.\n", $result['removed']), Console::FG_GREEN);

        foreach ($result['kept'] as $name) {
            $this->stdout("  kept {$name}: it was edited after the import.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Drops findings nobody acted on.
     */
    public function actionPrune(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return ExitCode::CONFIG;
        }

        $days = $this->days ?? $plugin->getSettings()->findingRetentionDays;

        if ($this->interactive && !$this->confirm("Delete open findings not seen for {$days} days?")) {
            return ExitCode::OK;
        }

        $deleted = $plugin->getFindings()->pruneStale($days);

        $this->stdout("Deleted {$deleted} finding(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function printFindings(): void
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return;
        }

        $this->stdout("\nOpen findings\n", Console::FG_CYAN);

        foreach ($plugin->getFindings()->getFindings(['status' => FindingRecord::STATUS_NEW]) as $finding) {
            $this->stdout(sprintf(
                "  %-15s %-34s %-11s %-10s %s\n",
                $finding->type,
                mb_substr($finding->name, 0, 33),
                $finding->category !== '' ? $finding->category : '?',
                $finding->confidence,
                $finding->evidenceUrl,
            ));
        }
    }

    private function printAlerts(): void
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return;
        }

        $report = $plugin->getFindings()->getComplianceReport();

        if ($report['preConsent'] !== []) {
            $this->stderr(sprintf(
                "\n! %d cookie(s) were set before consent was given.\n",
                count($report['preConsent']),
            ), Console::FG_RED);
        }

        if ($report['unblocked'] !== []) {
            $this->stderr(sprintf(
                "! %d third-party resource(s) load without any data-cookiekit markup.\n",
                count($report['unblocked']),
            ), Console::FG_RED);
        }

        if ($report['miscategorised'] !== []) {
            $this->stdout(sprintf(
                "~ %d resource(s) are blocked under the wrong category.\n",
                count($report['miscategorised']),
            ), Console::FG_YELLOW);
        }

        if ($report['containers'] !== []) {
            $this->stdout(
                "~ A tag container was found. CookieKit cannot see which tags are configured inside it.\n",
                Console::FG_YELLOW,
            );
        }
    }

    /**
     * @return int|null|false null for "all sites", false when the handle is wrong
     */
    private function resolveSiteId(): int|null|false
    {
        if ($this->site === null) {
            return null;
        }

        $site = Craft::$app->getSites()->getSiteByHandle($this->site);

        if ($site === null) {
            $this->stderr("Unknown site handle: {$this->site}\n", Console::FG_RED);

            return false;
        }

        return $site->id;
    }
}
