<?php

declare(strict_types=1);

namespace viesrood\cookiekit\records;

use craft\db\ActiveRecord;

/**
 * Cookie declaration row.
 *
 * @property int $id
 * @property string $category
 * @property string $name
 * @property string $provider
 * @property string $purpose
 * @property string $duration
 * @property int $sortOrder
 * @property string $source
 * @property string|null $lastDetected
 * @property string|null $importBatch
 */
class CookieRecord extends ActiveRecord
{
    public const TABLE = '{{%cookiekit_cookies}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
