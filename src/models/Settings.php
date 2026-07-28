<?php

declare(strict_types=1);

namespace viesrood\cookiekit\models;

use Craft;
use craft\base\Model;
use viesrood\cookiekit\helpers\LanguageOption;

/**
 * CookieKit settings.
 */
class Settings extends Model
{
    /**
     * How long the consent cookie is valid, in days. After this the banner is
     * shown again. EU guidance suggests renewing consent at least every
     * 12 months (the French CNIL even recommends 6).
     */
    public int $cookieDuration = 365;

    /**
     * Consent revision. Bump this whenever the cookie declaration changes
     * materially: every visitor will be asked for consent again.
     */
    public int $revision = 1;

    /**
     * Store consent receipts in the database (accountability, art. 7(1) GDPR).
     *
     * No IP address and no user agent, but the consent id is pseudonymous
     * rather than anonymous. See ConsentsService for what that means.
     */
    public bool $logConsents = true;

    /**
     * How long consent log entries are kept, in months.
     */
    public int $logRetentionMonths = 24;

    /**
     * Emit Google Consent Mode v2 default/update signals.
     */
    public bool $googleConsentMode = false;

    /**
     * Count anonymous banner views and consent actions in daily aggregates.
     * Off by default so upgrading an existing installation never starts new
     * measurement without an administrator choosing it.
     */
    public bool $analyticsEnabled = false;

    /**
     * URL of the cookie/privacy policy page, linked from the banner.
     */
    public string $policyUrl = '';

    /**
     * Inject the banner automatically at the end of <body>. When disabled,
     * add `{{ craft.cookiekit.render() }}` to your layout instead.
     */
    public bool $autoInject = false;

    /**
     * Site template rendered instead of the bundled banner, for example
     * `_cookiekit/banner`. Automatic injection uses it too, so a template of
     * your own no longer means turning that off.
     */
    public string $bannerTemplate = '';

    /**
     * Force the banner into one language, whatever the site language is.
     *
     * Empty means it follows the site, which is what a correctly configured
     * site wants. Fill this in only when the Craft site language cannot change
     * but your visitors read something else.
     *
     * It translates the plugin's own texts and nothing else: cookie purposes,
     * providers and lifetimes come out of the declaration exactly as they were
     * typed.
     */
    public string $bannerLanguage = '';

    /**
     * Register the bundled stylesheet.
     *
     * There is deliberately no matching switch for the script: without it the
     * banner is inert markup and nothing on the page says why. Dropping the
     * script stays a `registerJs: false` escape hatch at the call site.
     */
    public bool $registerBannerCss = true;

    /**
     * Leave a category out of the banner when no cookies are declared for it.
     *
     * A hidden category is not offered at all, so "Accept all" no longer grants
     * it either and scripts blocked under it stay blocked. That is deliberate:
     * a visitor cannot agree to something never shown. It does mean you have to
     * declare a category's cookies before its switch appears.
     *
     * Turn this off if you block scripts under a category whose cookies you
     * have not got round to declaring.
     */
    public bool $hideEmptyCategories = true;

    /**
     * Server-side blocking mode for recognised third-party resources.
     *
     * "report" leaves markup alone but keeps detection available. "enforce"
     * rewrites recognised scripts, frames and pixels before the response is
     * sent. Existing installations safely land on "off".
     */
    public string $autoBlockMode = 'off';

    // ------------------------------------------------------------- detection

    /**
     * Master switch for cookie detection. Off hides the whole CP screen.
     */
    public bool $scanEnabled = true;

    /**
     * Ceiling on how many pages one crawl fetches.
     */
    public int $scanMaxUrls = 50;

    /**
     * How many entries to sample per section and entry type. Entry types are
     * what differ in template, and therefore in which third parties they load.
     */
    public int $scanUrlsPerSection = 5;

    /**
     * Extra URLs to always include, one per line, relative or absolute.
     */
    public string $scanExtraUrls = '';

    /**
     * Append a unique query parameter so a full-page cache (Blitz) is bypassed.
     *
     * On: you get a fresh render, but you are measuring a variant no visitor
     * ever receives. Off: you measure the cached, pre-consent variant, which is
     * legally the interesting one. Both are defensible, so it is a choice.
     */
    public bool $scanCacheBust = true;

    public int $scanTimeout = 10;

    public int $scanConcurrency = 5;

    /**
     * Write findings straight into the declaration when they were actually
     * observed and are recognised. Anything unrecognised always waits in the
     * inbox instead of getting an invented purpose.
     */
    public bool $autoImport = true;

    /**
     * Shared secret for the remote scan endpoints. Set it from an environment
     * variable: `$COOKIEKIT_SCAN_TOKEN`.
     */
    public string $scanToken = '';

    /**
     * How long a finding nobody acted on is kept.
     */
    public int $findingRetentionDays = 90;

    public function rules(): array
    {
        return [
            [['cookieDuration', 'revision', 'logRetentionMonths'], 'required'],
            [['cookieDuration'], 'integer', 'min' => 1, 'max' => 730],
            [['revision'], 'integer', 'min' => 1],
            [['logRetentionMonths'], 'integer', 'min' => 1, 'max' => 120],
            [['logConsents', 'googleConsentMode', 'analyticsEnabled', 'autoInject', 'registerBannerCss', 'hideEmptyCategories'], 'boolean'],
            [['policyUrl', 'bannerTemplate', 'bannerLanguage'], 'string'],
            [['autoBlockMode'], 'in', 'range' => ['off', 'report', 'enforce']],
            [['bannerLanguage'], 'match',
                'pattern' => LanguageOption::PATTERN,
                'skipOnEmpty' => true,
                'message' => Craft::t('cookiekit', 'Use a language tag such as nl or nl-NL.'),
            ],
            [['bannerTemplate'], 'match',
                'pattern' => '#^[\w\-]+(?:/[\w\-]+)*$#',
                'skipOnEmpty' => true,
                'message' => Craft::t('cookiekit', 'Use a template path such as _cookiekit/banner.'),
            ],

            [['scanEnabled', 'scanCacheBust', 'autoImport'], 'boolean'],
            [['scanMaxUrls'], 'integer', 'min' => 1, 'max' => 500],
            [['scanUrlsPerSection'], 'integer', 'min' => 1, 'max' => 50],
            [['scanTimeout'], 'integer', 'min' => 1, 'max' => 60],
            [['scanConcurrency'], 'integer', 'min' => 1, 'max' => 20],
            [['findingRetentionDays'], 'integer', 'min' => 7, 'max' => 730],
            [['scanExtraUrls', 'scanToken'], 'string'],
        ];
    }

    /**
     * The extra URLs as a list, blank lines and stray whitespace removed.
     *
     * @return list<string>
     */
    public function getExtraUrls(): array
    {
        $lines = preg_split('/\R/', $this->scanExtraUrls) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    }

    /**
     * The scan token with any environment variable resolved.
     */
    public function getResolvedScanToken(): string
    {
        return (string)Craft::parseEnv($this->scanToken);
    }

    /**
     * The defaults a render() call falls back to when it passes no options.
     *
     * @return array{template: string, registerCss: bool, language: string}
     */
    public function getBannerDefaults(): array
    {
        return [
            'template' => (string)Craft::parseEnv($this->bannerTemplate),
            'registerCss' => $this->registerBannerCss,
            'language' => (string)Craft::parseEnv($this->bannerLanguage),
        ];
    }
}
