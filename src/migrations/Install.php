<?php

declare(strict_types=1);

namespace viesrood\cookiekit\migrations;

use craft\db\Migration;
use viesrood\cookiekit\records\AnalyticsRecord;
use viesrood\cookiekit\records\ConsentRecord;
use viesrood\cookiekit\records\ConsentSnapshotRecord;
use viesrood\cookiekit\records\CookieRecord;
use viesrood\cookiekit\records\FindingRecord;
use viesrood\cookiekit\records\ScanRecord;

/**
 * CookieKit install migration.
 *
 * Six tables: the declaration, the consent receipts and the immutable
 * snapshots they point at, the daily counters, and the detection inbox with
 * its scan runs.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createDeclarationTable();
        $this->createConsentTables();
        $this->createAnalyticsTable();
        $this->createDetectionTables();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropForeignKeyIfExists(ConsentRecord::TABLE, ['snapshotId']);
        $this->dropForeignKeyIfExists(FindingRecord::TABLE, ['cookieId']);

        $this->dropTableIfExists(FindingRecord::TABLE);
        $this->dropTableIfExists(ScanRecord::TABLE);
        $this->dropTableIfExists(AnalyticsRecord::TABLE);
        $this->dropTableIfExists(ConsentRecord::TABLE);
        $this->dropTableIfExists(ConsentSnapshotRecord::TABLE);
        $this->dropTableIfExists(CookieRecord::TABLE);

        return true;
    }

    /**
     * The cookie declaration: the table a visitor reads, plus where each row
     * came from and when a scan last confirmed it.
     */
    private function createDeclarationTable(): void
    {
        if ($this->db->tableExists(CookieRecord::TABLE)) {
            return;
        }

        $this->createTable(CookieRecord::TABLE, [
            'id' => $this->primaryKey(),
            'category' => $this->string(32)->notNull()->defaultValue('necessary'),
            'name' => $this->string(255)->notNull(),
            'provider' => $this->string(255)->notNull()->defaultValue(''),
            'purpose' => $this->text(),
            'duration' => $this->string(255)->notNull()->defaultValue(''),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'source' => $this->string(16)->notNull()->defaultValue('manual'),
            'lastDetected' => $this->dateTime()->null(),
            'importBatch' => $this->string(36)->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, CookieRecord::TABLE, ['category'], false);
        // One declaration row per cookie name, so a rescan can never end up
        // adding a second `_ga`.
        $this->createIndex(null, CookieRecord::TABLE, ['name'], true);
    }

    /**
     * Receipts, and the snapshots that make them provable.
     *
     * A receipt on its own says a visitor agreed; it does not say to what. The
     * snapshot holds the declaration, policy, language and duration exactly as
     * they were shown, hashed and shared by every receipt that saw the same
     * thing.
     */
    private function createConsentTables(): void
    {
        if (!$this->db->tableExists(ConsentSnapshotRecord::TABLE)) {
            $this->createTable(ConsentSnapshotRecord::TABLE, [
                'id' => $this->primaryKey(),
                'snapshotHash' => $this->char(64)->notNull(),
                'revision' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'language' => $this->string(32)->notNull()->defaultValue(''),
                'context' => $this->text()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, ConsentSnapshotRecord::TABLE, ['snapshotHash'], true);
        }

        if (!$this->db->tableExists(ConsentRecord::TABLE)) {
            $this->createTable(ConsentRecord::TABLE, [
                'id' => $this->primaryKey(),
                // A UUID from the visitor's own cookie. No IP address and no
                // user agent are stored, here or anywhere else.
                'consentId' => $this->string(36)->notNull(),
                'revision' => $this->integer()->notNull()->defaultValue(1),
                'categories' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->null(),
                'action' => $this->string(24)->notNull()->defaultValue('custom'),
                'snapshotId' => $this->integer()->null(),
                'gpc' => $this->boolean()->notNull()->defaultValue(false),
                'gpcOverride' => $this->boolean()->notNull()->defaultValue(false),
                'durationDays' => $this->integer()->notNull()->defaultValue(365),
                'locale' => $this->string(32)->notNull()->defaultValue(''),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, ConsentRecord::TABLE, ['consentId'], false);
            $this->createIndex(null, ConsentRecord::TABLE, ['dateCreated'], false);
        }
    }

    /**
     * One row per site per day. Counters only, so there is nothing here to tie
     * back to a visitor.
     */
    private function createAnalyticsTable(): void
    {
        if ($this->db->tableExists(AnalyticsRecord::TABLE)) {
            return;
        }

        $this->createTable(AnalyticsRecord::TABLE, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'day' => $this->date()->notNull(),
            'bannerViews' => $this->integer()->notNull()->defaultValue(0),
            'acceptAll' => $this->integer()->notNull()->defaultValue(0),
            'denyAll' => $this->integer()->notNull()->defaultValue(0),
            'custom' => $this->integer()->notNull()->defaultValue(0),
            'changed' => $this->integer()->notNull()->defaultValue(0),
            'withdrawn' => $this->integer()->notNull()->defaultValue(0),
            'gpcSeen' => $this->integer()->notNull()->defaultValue(0),
            'grantPreferences' => $this->integer()->notNull()->defaultValue(0),
            'grantStatistics' => $this->integer()->notNull()->defaultValue(0),
            'grantMarketing' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, AnalyticsRecord::TABLE, ['siteId', 'day'], true);
        $this->createIndex(null, AnalyticsRecord::TABLE, ['day'], false);
    }

    /**
     * What the crawler and the page detector found, and the runs that found it.
     */
    private function createDetectionTables(): void
    {
        if (!$this->db->tableExists(FindingRecord::TABLE)) {
            $this->createTable(FindingRecord::TABLE, [
                'id' => $this->primaryKey(),
                'type' => $this->string(16)->notNull(),
                'name' => $this->string(255)->notNull(),
                'declaredAs' => $this->string(255)->notNull()->defaultValue(''),
                'signatureKey' => $this->string(64)->null(),
                'provider' => $this->string(255)->notNull()->defaultValue(''),
                // Empty on purpose when nothing recognised it: an unknown
                // cookie gets no category rather than a plausible guess.
                'category' => $this->string(32)->notNull()->defaultValue(''),
                'purpose' => $this->text(),
                'duration' => $this->string(255)->notNull()->defaultValue(''),
                'sources' => $this->string(64)->notNull()->defaultValue(''),
                'confidence' => $this->string(16)->notNull()->defaultValue(FindingRecord::CONFIDENCE_INFERRED),
                'evidenceUrl' => $this->string(500)->notNull()->defaultValue(''),
                'evidenceDetail' => $this->string(500)->notNull()->defaultValue(''),
                'snippet' => $this->text(),
                // Not nullable: both MySQL and Postgres treat NULLs as distinct
                // in a unique index, which would silently break the dedupe.
                'siteId' => $this->integer()->notNull()->defaultValue(0),
                'consentSeen' => $this->string(128)->notNull()->defaultValue(''),
                'preConsent' => $this->boolean()->notNull()->defaultValue(false),
                'status' => $this->string(16)->notNull()->defaultValue(FindingRecord::STATUS_NEW),
                'cookieId' => $this->integer()->null(),
                'hits' => $this->integer()->notNull()->defaultValue(1),
                'firstSeen' => $this->dateTime()->notNull(),
                'lastSeen' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // One row per thing per site, however many times it is seen.
            $this->createIndex(null, FindingRecord::TABLE, ['type', 'name', 'siteId'], true);
            $this->createIndex(null, FindingRecord::TABLE, ['status'], false);
            $this->createIndex(null, FindingRecord::TABLE, ['signatureKey'], false);
            $this->createIndex(null, FindingRecord::TABLE, ['lastSeen'], false);
        }

        if (!$this->db->tableExists(ScanRecord::TABLE)) {
            $this->createTable(ScanRecord::TABLE, [
                'id' => $this->primaryKey(),
                'type' => $this->string(16)->notNull(),
                'status' => $this->string(16)->notNull()->defaultValue(ScanRecord::STATUS_RUNNING),
                'siteId' => $this->integer()->notNull()->defaultValue(0),
                'urlsScanned' => $this->integer()->notNull()->defaultValue(0),
                'urlsFailed' => $this->integer()->notNull()->defaultValue(0),
                'findingsNew' => $this->integer()->notNull()->defaultValue(0),
                'findingsTotal' => $this->integer()->notNull()->defaultValue(0),
                'importBatch' => $this->string(36)->null(),
                'errorMessage' => $this->string(500)->notNull()->defaultValue(''),
                'dateFinished' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, ScanRecord::TABLE, ['dateCreated'], false);
        }
    }

    /**
     * Both are SET NULL: losing a snapshot or a declaration row must not delete
     * the receipt or the finding that referred to it.
     */
    private function addForeignKeys(): void
    {
        $this->addForeignKey(
            null,
            ConsentRecord::TABLE,
            ['snapshotId'],
            ConsentSnapshotRecord::TABLE,
            ['id'],
            'SET NULL',
        );

        $this->addForeignKey(
            null,
            FindingRecord::TABLE,
            ['cookieId'],
            CookieRecord::TABLE,
            ['id'],
            'SET NULL',
        );
    }
}
