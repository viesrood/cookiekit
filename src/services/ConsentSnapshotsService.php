<?php

declare(strict_types=1);

namespace viesrood\cookiekit\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTimeImmutable;
use viesrood\cookiekit\records\ConsentRecord;
use viesrood\cookiekit\records\ConsentSnapshotRecord;
use yii\db\IntegrityException;

/**
 * Creates immutable, deduplicated proof of the consent text and declaration.
 */
class ConsentSnapshotsService extends Component
{
    /**
     * Whether the snapshot table is there yet, answered once per request.
     *
     * The check used to pass `true` as the refresh flag, which is not a cached
     * lookup at all: it re-runs the introspection query and rewrites the schema
     * cache every time. On the banner path that is an extra query on every page
     * view, forever, to guard against the few seconds between a deploy and
     * `craft up`. The cached answer covers that just as well.
     */
    private ?bool $tableExists = null;

    private function tableExists(): bool
    {
        return $this->tableExists ??= Craft::$app->getDb()->getSchema()
            ->getTableSchema(ConsentSnapshotRecord::TABLE) !== null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{id: int, hash: string}
     */
    public function capture(array $context, int $revision, int $siteId, string $language): array
    {
        $json = Json::encode($context);
        $hash = hash('sha256', $json);

        // Deployments can serve traffic for a moment before `craft up` runs.
        // Keep the banner working; logging will attach snapshots once the
        // migration has created the table.
        if (!$this->tableExists()) {
            return ['id' => 0, 'hash' => $hash];
        }

        $existing = ConsentSnapshotRecord::findOne(['snapshotHash' => $hash]);

        if ($existing instanceof ConsentSnapshotRecord) {
            return ['id' => (int)$existing->id, 'hash' => $hash];
        }

        $record = new ConsentSnapshotRecord();
        $record->snapshotHash = $hash;
        $record->revision = $revision;
        $record->siteId = $siteId;
        $record->language = mb_substr($language, 0, 32);
        $record->context = $json;

        try {
            $record->save(false);
        } catch (IntegrityException) {
            // Two simultaneous uncached page renders can capture the same
            // snapshot. The unique hash makes that race harmless.
            $existing = ConsentSnapshotRecord::findOne(['snapshotHash' => $hash]);
            if (!$existing instanceof ConsentSnapshotRecord) {
                throw new \RuntimeException('The consent snapshot could not be stored.');
            }

            return ['id' => (int)$existing->id, 'hash' => $hash];
        }

        return ['id' => (int)$record->id, 'hash' => $hash];
    }

    public function getIdByHash(string $hash): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return null;
        }

        $id = ConsentSnapshotRecord::find()
            ->select(['id'])
            ->where(['snapshotHash' => $hash])
            ->scalar();

        return $id === false ? null : (int)$id;
    }

    /**
     * Removes snapshots no consent event points at any more.
     *
     * A snapshot is proof of what a visitor was shown, so it has to outlive
     * nothing: as long as one receipt references it, it stays, whatever its
     * age. Once the last receipt referencing it has been pruned or purged, it
     * is proof of nothing and only takes up room. Without this the table grew
     * forever and every purge left orphans behind.
     *
     * Snapshots still in use by the current declaration are kept regardless,
     * because the very next visitor will need the same one.
     */
    public function pruneOrphans(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $referenced = (new Query())
            ->select(['snapshotId'])
            ->from(ConsentRecord::TABLE)
            ->where(['not', ['snapshotId' => null]]);

        return ConsentSnapshotRecord::deleteAll([
            'and',
            ['not', ['id' => $referenced]],
            // A grace window, so a snapshot captured seconds ago is never
            // deleted between rendering the banner and the visitor choosing.
            ['<', 'dateCreated', Db::prepareDateForDb(new DateTimeImmutable('-1 day'))],
        ]);
    }
}
