<?php

declare(strict_types=1);

namespace viesrood\cookiekit\records;

use craft\db\ActiveRecord;

/**
 * Immutable description of what a visitor was shown for a consent revision.
 *
 * @property int $id
 * @property string $snapshotHash
 * @property int $revision
 * @property int $siteId
 * @property string $language
 * @property string $context
 */
class ConsentSnapshotRecord extends ActiveRecord
{
    public const TABLE = '{{%cookiekit_consent_snapshots}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
