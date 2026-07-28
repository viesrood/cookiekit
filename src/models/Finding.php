<?php

declare(strict_types=1);

namespace viesrood\cookiekit\models;

use craft\base\Model;
use DateTimeInterface;
use viesrood\cookiekit\Plugin;
use viesrood\cookiekit\records\FindingRecord;

/**
 * A single row in the detection inbox.
 */
class Finding extends Model
{
    public ?int $id = null;

    /**
     * One of the FindingRecord::TYPE_* constants.
     */
    public string $type = FindingRecord::TYPE_COOKIE;

    /**
     * As observed: the cookie name, the storage key, or host+path for a
     * resource.
     */
    public string $name = '';

    /**
     * The form to write into the declaration, which for a family of cookies is
     * the wildcard: `_ga_*` rather than `_ga_G3Y7GKHRGGR`.
     */
    public string $declaredAs = '';

    public ?string $signatureKey = null;

    public string $provider = '';

    /**
     * Empty means nobody recognised it, and the admin has to decide.
     */
    public string $category = '';

    public string $purpose = '';

    public string $duration = '';

    /**
     * Comma-separated set of `header`, `markup`, `inline` and `browser`. A
     * finding accumulates the ways it has been seen rather than appearing once
     * per source.
     */
    public string $sources = '';

    /**
     * `observed` (seen in a header or a real browser) or `inferred` (a script
     * known to set it is loaded). Only observed findings import themselves.
     */
    public string $confidence = FindingRecord::CONFIDENCE_INFERRED;

    public string $evidenceUrl = '';

    public string $evidenceDetail = '';

    /**
     * Paste-ready blocking markup, for the unblocked and miscategorised types.
     */
    public string $snippet = '';

    /**
     * 0 means the finding is not tied to one site.
     */
    public int $siteId = 0;

    /**
     * Which categories were granted when this was observed.
     */
    public string $consentSeen = '';

    public bool $preConsent = false;

    public string $status = FindingRecord::STATUS_NEW;

    /**
     * The declaration row this was imported into, if any.
     */
    public ?int $cookieId = null;

    public int $hits = 1;

    public ?DateTimeInterface $firstSeen = null;

    public ?DateTimeInterface $lastSeen = null;

    public ?string $uid = null;

    public function rules(): array
    {
        return [
            [['type', 'name'], 'required'],
            [['type'], 'in', 'range' => [
                FindingRecord::TYPE_COOKIE,
                FindingRecord::TYPE_STORAGE,
                FindingRecord::TYPE_VENDOR,
                FindingRecord::TYPE_UNBLOCKED,
                FindingRecord::TYPE_MISCATEGORISED,
            ]],
            [['status'], 'in', 'range' => [
                FindingRecord::STATUS_NEW,
                FindingRecord::STATUS_IMPORTED,
                FindingRecord::STATUS_IGNORED,
            ]],
            [['confidence'], 'in', 'range' => [
                FindingRecord::CONFIDENCE_OBSERVED,
                FindingRecord::CONFIDENCE_INFERRED,
            ]],
            // An empty category is meaningful here: it says "unrecognised".
            [['category'], 'in', 'range' => array_merge([''], Plugin::CATEGORIES)],
            [['name', 'declaredAs', 'provider', 'duration'], 'string', 'max' => 255],
            [['signatureKey'], 'string', 'max' => 64],
            [['evidenceUrl', 'evidenceDetail'], 'string', 'max' => 500],
            [['purpose', 'snippet'], 'string'],
            [['siteId', 'hits'], 'integer'],
            [['preConsent'], 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    public function getSourceList(): array
    {
        return array_values(array_filter(explode(',', $this->sources)));
    }

    /**
     * @return list<string>
     */
    public function getConsentList(): array
    {
        return array_values(array_filter(explode(',', $this->consentSeen)));
    }

    public function getIsObserved(): bool
    {
        return $this->confidence === FindingRecord::CONFIDENCE_OBSERVED;
    }

    /**
     * Whether this finding says something about the declaration at all. Vendor,
     * unblocked and miscategorised rows are compliance signals, not cookies.
     */
    public function getIsDeclarable(): bool
    {
        return in_array($this->type, [FindingRecord::TYPE_COOKIE, FindingRecord::TYPE_STORAGE], true);
    }

    /**
     * The name to write into the declaration.
     */
    public function getDeclarationName(): string
    {
        return $this->declaredAs !== '' ? $this->declaredAs : $this->name;
    }
}
