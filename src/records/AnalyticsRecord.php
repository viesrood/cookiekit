<?php

declare(strict_types=1);

namespace viesrood\cookiekit\records;

use craft\db\ActiveRecord;

/**
 * One anonymous aggregate row per site and calendar day.
 */
class AnalyticsRecord extends ActiveRecord
{
    public const TABLE = '{{%cookiekit_analytics}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
