<?php

declare(strict_types=1);

namespace viesrood\cookiekit\models;

use craft\base\Model;
use DateTimeInterface;
use viesrood\cookiekit\records\ScanRecord;

/**
 * Metadata of one scan run, so the CP can say what was last done and when.
 */
class ScanRun extends Model
{
    public ?int $id = null;

    /**
     * `crawl` for the server-side scan, `browser` for an imported Playwright run.
     */
    public string $type = ScanRecord::TYPE_CRAWL;

    public string $status = ScanRecord::STATUS_RUNNING;

    public int $siteId = 0;

    public int $urlsScanned = 0;

    public int $urlsFailed = 0;

    public int $findingsNew = 0;

    public int $findingsTotal = 0;

    /**
     * Set when this run imported straight into the declaration, so the whole
     * batch can be taken back out again.
     */
    public ?string $importBatch = null;

    public string $errorMessage = '';

    public ?DateTimeInterface $dateCreated = null;

    public ?DateTimeInterface $dateFinished = null;

    public ?string $uid = null;

    public function rules(): array
    {
        return [
            [['type', 'status'], 'required'],
            [['type'], 'in', 'range' => [ScanRecord::TYPE_CRAWL, ScanRecord::TYPE_BROWSER]],
            [['status'], 'in', 'range' => [
                ScanRecord::STATUS_RUNNING,
                ScanRecord::STATUS_DONE,
                ScanRecord::STATUS_FAILED,
            ]],
            [['siteId', 'urlsScanned', 'urlsFailed', 'findingsNew', 'findingsTotal'], 'integer'],
            [['errorMessage'], 'string', 'max' => 500],
            [['importBatch'], 'string', 'max' => 36],
        ];
    }
}
