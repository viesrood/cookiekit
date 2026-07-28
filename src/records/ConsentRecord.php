<?php

declare(strict_types=1);

namespace viesrood\cookiekit\records;

use craft\db\ActiveRecord;

/**
 * A consent receipt. Pseudonymous, not anonymous: see ConsentsService.
 *
 * @property int $id
 * @property string $consentId
 * @property int $revision
 * @property string $categories
 * @property int|null $siteId
 * @property string $action
 * @property int|null $snapshotId
 * @property bool $gpc
 * @property bool $gpcOverride
 * @property int $durationDays
 * @property string $locale
 */
class ConsentRecord extends ActiveRecord
{
    public const TABLE = '{{%cookiekit_consents}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
