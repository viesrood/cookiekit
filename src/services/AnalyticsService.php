<?php

declare(strict_types=1);

namespace viesrood\cookiekit\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use yii\db\Expression;
use viesrood\cookiekit\Plugin;
use viesrood\cookiekit\records\AnalyticsRecord;

/**
 * Privacy-minimal daily event aggregates.
 *
 * No identifier, URL, IP address or user agent is stored. These are event
 * counts, deliberately not presented as unique visitors.
 */
class AnalyticsService extends Component
{
    public const EVENTS = [
        'bannerViews',
        'acceptAll',
        'denyAll',
        'custom',
        'changed',
        'withdrawn',
        'gpcSeen',
    ];

    /**
     * @param list<string> $categories
     */
    public function record(string $event, int $siteId, array $categories = [], bool $gpc = false): bool
    {
        $settings = Plugin::getInstance()?->getSettings();
        if ($settings === null || !$settings->analyticsEnabled || !in_array($event, self::EVENTS, true)) {
            return false;
        }

        $insert = array_fill_keys([
            'bannerViews', 'acceptAll', 'denyAll', 'custom', 'changed',
            'withdrawn', 'gpcSeen', 'grantPreferences', 'grantStatistics',
            'grantMarketing',
        ], 0);
        $insert['siteId'] = $siteId;
        $insert['day'] = date('Y-m-d');
        $insert[$event] = 1;
        $now = Db::prepareDateForDb(new DateTime());
        $insert['dateCreated'] = $now;
        $insert['dateUpdated'] = $now;
        $insert['uid'] = StringHelper::UUID();

        foreach (['preferences', 'statistics', 'marketing'] as $category) {
            if (in_array($category, $categories, true)) {
                $insert['grant' . ucfirst($category)] = 1;
            }
        }
        if ($gpc && $event !== 'gpcSeen') {
            $insert['gpcSeen'] = 1;
        }

        $updates = [];
        foreach ($insert as $column => $value) {
            if (in_array($column, ['siteId', 'day'], true) || $value !== 1) {
                continue;
            }
            $updates[$column] = new Expression("[[$column]] + 1");
        }
        $updates['dateUpdated'] = $now;

        Craft::$app->getDb()->createCommand()
            ->upsert(AnalyticsRecord::TABLE, $insert, $updates)
            ->execute();

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSeries(int $days = 30, ?int $siteId = null): array
    {
        $query = (new Query())
            ->from(AnalyticsRecord::TABLE)
            ->where(['>=', 'day', date('Y-m-d', strtotime("-$days days"))])
            ->orderBy(['day' => SORT_ASC]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $query->all();

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    public function getTotals(int $days = 30, ?int $siteId = null): array
    {
        $rows = $this->getSeries($days, $siteId);
        $columns = array_merge(self::EVENTS, ['grantPreferences', 'grantStatistics', 'grantMarketing']);
        $totals = array_fill_keys($columns, 0);

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $totals[$column] += (int)($row[$column] ?? 0);
            }
        }

        return $totals;
    }

    /**
     * Drops day buckets older than the consent log's retention window.
     *
     * These rows hold nothing that identifies anyone, but a table that only
     * ever grows is still a table nobody is looking after, and the second
     * resolution of `dateUpdated` on a quiet site is more than the counters
     * themselves reveal.
     *
     * Called from garbage collection.
     */
    public function pruneExpired(): int
    {
        $settings = Plugin::getInstance()?->getSettings();

        if ($settings === null) {
            return 0;
        }

        $threshold = (new DateTimeImmutable())
            ->sub(new DateInterval(sprintf('P%dM', max(1, $settings->logRetentionMonths))));

        return AnalyticsRecord::deleteAll([
            '<', 'day', $threshold->format('Y-m-d'),
        ]);
    }
}
