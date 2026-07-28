<?php

declare(strict_types=1);

namespace viesrood\cookiekit\controllers;

use Craft;
use craft\db\Query;
use craft\web\Controller;
use viesrood\cookiekit\helpers\Csv;
use viesrood\cookiekit\helpers\SiteAccess;
use viesrood\cookiekit\records\AnalyticsRecord;
use viesrood\cookiekit\records\ConsentRecord;
use viesrood\cookiekit\records\ConsentSnapshotRecord;
use yii\web\Response;

/**
 * Local CSV exports for accountability and reporting.
 */
class ExportController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('cookiekit:exportData');

        return parent::beforeAction($action);
    }

    public function actionConsents(): Response
    {
        $header = [
            'consentId', 'dateCreated', 'revision', 'action', 'categories',
            'siteId', 'gpc', 'gpcOverride', 'durationDays', 'locale', 'snapshotHash',
        ];

        $query = (new Query())
            ->select([
                'c.consentId', 'c.dateCreated', 'c.revision', 'c.action',
                'c.categories', 'c.siteId', 'c.gpc', 'c.gpcOverride',
                'c.durationDays', 'c.locale', 's.snapshotHash',
            ])
            ->from(['c' => ConsentRecord::TABLE])
            ->leftJoin(['s' => ConsentSnapshotRecord::TABLE], '[[s.id]] = [[c.snapshotId]]')
            ->orderBy(['c.dateCreated' => SORT_DESC]);

        // The same filters the consent log screen offers. Without them the only
        // possible export was "everything", which on a busy site is both the
        // slowest and the least useful answer.
        $siteId = SiteAccess::filterId($this->request->getQueryParam('siteId'));

        if ($siteId !== null) {
            $query->andWhere(['c.siteId' => $siteId]);
        }

        foreach (['from' => '>=', 'to' => '<='] as $param => $operator) {
            $value = $this->request->getQueryParam($param);

            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $query->andWhere([$operator, 'c.dateCreated', $value . ($param === 'from' ? ' 00:00:00' : ' 23:59:59')]);
            }
        }

        return $this->csv('cookiekit-consents-' . date('Y-m-d') . '.csv', $header, $query);
    }

    public function actionAnalytics(): Response
    {
        $header = [
            'siteId', 'day', 'bannerViews', 'acceptAll', 'denyAll', 'custom',
            'changed', 'withdrawn', 'gpcSeen', 'grantPreferences',
            'grantStatistics', 'grantMarketing',
        ];

        $query = (new Query())
            ->select($header)
            ->from(AnalyticsRecord::TABLE)
            ->orderBy(['day' => SORT_DESC, 'siteId' => SORT_ASC]);

        $siteId = SiteAccess::filterId($this->request->getQueryParam('siteId'));

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $this->csv('cookiekit-analytics-' . date('Y-m-d') . '.csv', $header, $query);
    }

    /**
     * Writes the query straight into a temporary stream, in batches.
     *
     * The rows never all exist as PHP arrays at once, and `php://temp` spills
     * to disk past a couple of megabytes, so a long export costs disk rather
     * than memory. The previous version materialised every row, built the whole
     * file in memory and then copied it into a string, which ran out of memory
     * on a busy site with no way to narrow the range.
     *
     * @param list<string> $header
     * @param Query<int, array<string, mixed>> $query
     */
    private function csv(string $filename, array $header, Query $query): Response
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+');

        if ($stream === false) {
            throw new \RuntimeException('Could not create CSV export.');
        }

        // UTF-8 BOM keeps non-English text intact in spreadsheet applications.
        // It also makes Excel the likely application to open this, which is
        // exactly why every cell goes through the formula guard first.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $header, ',', '"', '');

        foreach ($query->batch(500) as $rows) {
            foreach ($rows as $row) {
                $cells = [];

                foreach ($header as $column) {
                    $cells[] = $row[$column] ?? '';
                }

                fputcsv($stream, Csv::row($cells), ',', '"', '');
            }
        }

        rewind($stream);

        return Craft::$app->getResponse()->sendStreamAsFile(
            $stream,
            $filename,
            ['mimeType' => 'text/csv; charset=UTF-8'],
        );
    }
}
