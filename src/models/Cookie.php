<?php

declare(strict_types=1);

namespace viesrood\cookiekit\models;

use Craft;
use craft\base\Model;
use DateTimeInterface;
use viesrood\cookiekit\helpers\CookieNameMatcher;
use viesrood\cookiekit\Plugin;

/**
 * A single cookie in the declaration.
 */
class Cookie extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_DETECTED = 'detected';

    public ?int $id = null;

    /**
     * One of Plugin::CATEGORIES.
     */
    public string $category = 'necessary';

    /**
     * Cookie name as set in the browser, e.g. `_ga`.
     */
    public string $name = '';

    /**
     * Party that sets the cookie, e.g. "Google Analytics".
     */
    public string $provider = '';

    /**
     * Purpose description shown to visitors.
     */
    public string $purpose = '';

    /**
     * Human-readable lifetime, e.g. "2 jaar" or "Sessie".
     */
    public string $duration = '';

    public int $sortOrder = 0;

    /**
     * Whether this row was typed in by hand or written by a scan.
     */
    public string $source = self::SOURCE_MANUAL;

    /**
     * Last time a scan actually saw this cookie. Stays null for a hand-entered
     * row nobody has confirmed yet, which is what drives the "declared but
     * never detected" signal.
     */
    public ?DateTimeInterface $lastDetected = null;

    /**
     * Groups every row written by one import, so it can be taken back out in
     * one go.
     */
    public ?string $importBatch = null;

    public ?string $uid = null;

    public function rules(): array
    {
        return [
            [['name', 'category'], 'required'],
            [['category'], 'in', 'range' => Plugin::CATEGORIES],
            [['name', 'provider', 'duration'], 'string', 'max' => 255],
            [['purpose'], 'string'],
            [['sortOrder'], 'integer'],
            [['source'], 'in', 'range' => [self::SOURCE_MANUAL, self::SOURCE_DETECTED]],
            [['importBatch'], 'string', 'max' => 36],
            [['name'], 'validateName'],
        ];
    }

    /**
     * A declared name of `*` would silently match every cookie there is and
     * swallow the whole declaration.
     */
    public function validateName(string $attribute): void
    {
        $value = $this->$attribute;

        if (is_string($value) && !CookieNameMatcher::isMeaningful($value)) {
            $this->addError($attribute, Craft::t('cookiekit', 'A cookie name needs more than a wildcard.'));
        }
    }

    public function getIsDetected(): bool
    {
        return $this->source === self::SOURCE_DETECTED;
    }
}
