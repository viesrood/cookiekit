<?php

declare(strict_types=1);

namespace viesrood\cookiekit\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateInterval;
use DateTimeImmutable;
use viesrood\cookiekit\helpers\LanguageOption;
use viesrood\cookiekit\Plugin;
use viesrood\cookiekit\records\ConsentRecord;

/**
 * Stores and prunes consent receipts.
 *
 * A receipt proves *that* consent was given and for *which* categories, which
 * is what art. 7(1) GDPR asks you to be able to show. It keeps no IP address
 * and no user agent.
 *
 * It is not anonymous, and calling it that would be convenient rather than
 * true. The consent id is a UUID that lives in the visitor's own cookie for as
 * long as their choice is valid, and every event carries it, so the receipts of
 * one person can be tied together for the life of that cookie. That is
 * pseudonymous data, and it is the part that makes a receipt usable as proof at
 * all: without it you could show that somebody consented, not that this
 * visitor did.
 */
class ConsentsService extends Component
{
    /**
     * Logs a consent choice.
     *
     * @param string[] $categories
     */
    public function logConsent(
        string $consentId,
        array $categories,
        int $revision,
        ?int $siteId = null,
        string $action = 'custom',
        ?string $snapshotHash = null,
        bool $gpc = false,
        bool $gpcOverride = false,
        ?int $durationDays = null,
        string $locale = '',
    ): bool {
        $settings = Plugin::getInstance()?->getSettings();
        if ($settings === null || !$settings->logConsents) {
            return false;
        }

        if (self::sanitiseConsentId($consentId) === null) {
            // The banner script always sends a UUID. Anything else is a broken
            // client or someone poking at the endpoint, and a receipt nobody
            // can tie to a visitor is worse than no receipt: it dilutes the
            // very evidence this table exists to provide.
            Craft::warning('CookieKit refused a consent receipt with a malformed id.', __METHOD__);

            return false;
        }

        $categories = array_values(array_intersect(Plugin::CATEGORIES, $categories));
        if (!in_array('necessary', $categories, true)) {
            $categories[] = 'necessary';
        }

        $record = new ConsentRecord();
        // Both of these arrive on a public endpoint and both end up in a CSV an
        // administrator opens in a spreadsheet. Truncating was never validation:
        // `=cmd|'/C calc'!A0` fits in 36 characters. The export neutralises
        // formulas as well, but a receipt that is not a UUID is not a receipt.
        $record->consentId = (string)self::sanitiseConsentId($consentId);
        $record->revision = $revision;
        $record->categories = implode(',', $categories);
        $record->siteId = $siteId;
        $record->action = in_array($action, ['acceptAll', 'denyAll', 'custom', 'changed', 'withdrawn'], true)
            ? $action
            : 'custom';
        $record->snapshotId = $snapshotHash !== null
            ? Plugin::getInstance()?->getSnapshots()->getIdByHash($snapshotHash)
            : null;
        $record->gpc = $gpc;
        $record->gpcOverride = $gpcOverride;
        $record->durationDays = $durationDays ?? $settings->cookieDuration;
        $record->locale = LanguageOption::normalize($locale) ?? '';

        $saved = $record->save();
        if ($saved && $siteId !== null) {
            Plugin::getInstance()?->getAnalytics()->record($record->action, $siteId, $categories, $gpc);
        }

        return $saved;
    }

    /**
     * The receipt id, or null when it is not the UUID the banner script sends.
     */
    public static function sanitiseConsentId(string $consentId): ?string
    {
        $consentId = trim($consentId);

        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $consentId,
        ) === 1 ? strtolower($consentId) : null;
    }

    /**
     * Deletes receipts older than the configured retention period.
     */
    public function pruneExpired(): int
    {
        $settings = Plugin::getInstance()?->getSettings();
        if ($settings === null) {
            return 0;
        }

        $threshold = (new DateTimeImmutable())
            ->sub(new DateInterval(sprintf('P%dM', $settings->logRetentionMonths)));

        return ConsentRecord::deleteAll([
            '<', 'dateCreated', Db::prepareDateForDb($threshold),
        ]);
    }

    /**
     * Latest receipts for the CP overview.
     *
     * @return ConsentRecord[]
     */
    public function getRecentConsents(
        int $limit = 100,
        ?int $siteId = null,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $query = ConsentRecord::find()->orderBy(['dateCreated' => SORT_DESC])->limit($limit);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }
        if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $query->andWhere(['>=', 'dateCreated', $from . ' 00:00:00']);
        }
        if ($to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $query->andWhere(['<=', 'dateCreated', $to . ' 23:59:59']);
        }

        /** @var ConsentRecord[] $records */
        $records = $query->all();

        return $records;
    }

    public function getTotalCount(): int
    {
        return (int)ConsentRecord::find()->count();
    }

    /**
     * How many receipts include each category.
     *
     * @return array<string, int>
     */
    public function getCategoryCounts(): array
    {
        $counts = array_fill_keys(Plugin::CATEGORIES, 0);

        foreach (ConsentRecord::find()->select(['categories'])->column() as $value) {
            foreach (explode(',', (string)$value) as $category) {
                if (isset($counts[$category])) {
                    $counts[$category]++;
                }
            }
        }

        return $counts;
    }

}
