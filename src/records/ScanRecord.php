<?php

declare(strict_types=1);

namespace viesrood\cookiekit\records;

use craft\db\ActiveRecord;

/**
 * One scan run.
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property int $siteId
 * @property int $urlsScanned
 * @property int $urlsFailed
 * @property int $findingsNew
 * @property int $findingsTotal
 * @property string|null $importBatch
 * @property string $errorMessage
 * @property string|null $dateFinished
 */
class ScanRecord extends ActiveRecord
{
    public const TABLE = '{{%cookiekit_scans}}';

    public const TYPE_CRAWL = 'crawl';
    public const TYPE_BROWSER = 'browser';

    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
