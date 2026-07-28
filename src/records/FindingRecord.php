<?php

declare(strict_types=1);

namespace viesrood\cookiekit\records;

use craft\db\ActiveRecord;

/**
 * One detected cookie, storage key, vendor or blocking problem.
 *
 * @property int $id
 * @property string $type
 * @property string $name
 * @property string $declaredAs
 * @property string|null $signatureKey
 * @property string $provider
 * @property string $category
 * @property string|null $purpose
 * @property string $duration
 * @property string $sources
 * @property string $confidence
 * @property string $evidenceUrl
 * @property string $evidenceDetail
 * @property string|null $snippet
 * @property int $siteId
 * @property string $consentSeen
 * @property bool $preConsent
 * @property string $status
 * @property int|null $cookieId
 * @property int $hits
 * @property string $firstSeen
 * @property string $lastSeen
 */
class FindingRecord extends ActiveRecord
{
    public const TABLE = '{{%cookiekit_findings}}';

    public const TYPE_COOKIE = 'cookie';
    public const TYPE_STORAGE = 'storage';
    public const TYPE_VENDOR = 'vendor';
    public const TYPE_UNBLOCKED = 'unblocked';
    public const TYPE_MISCATEGORISED = 'miscategorised';

    public const STATUS_NEW = 'new';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_IGNORED = 'ignored';

    public const CONFIDENCE_OBSERVED = 'observed';
    public const CONFIDENCE_INFERRED = 'inferred';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
