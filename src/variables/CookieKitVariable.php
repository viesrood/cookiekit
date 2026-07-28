<?php

declare(strict_types=1);

namespace viesrood\cookiekit\variables;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use Twig\Markup;
use viesrood\cookiekit\helpers\BannerOptions;
use viesrood\cookiekit\helpers\CategoryText;
use viesrood\cookiekit\helpers\LanguageOption;
use viesrood\cookiekit\helpers\VisibleCategories;
use viesrood\cookiekit\Plugin;
use viesrood\cookiekit\web\assets\banner\BannerCssAsset;
use viesrood\cookiekit\web\assets\banner\BannerJsAsset;

/**
 * Twig API: craft.cookiekit
 */
class CookieKitVariable
{
    /**
     * Renders the consent banner (plus preferences panel) and registers the
     * JS/CSS. Add this once, just before </body>:
     *
     *     {{ craft.cookiekit.render() }}
     *
     * Options:
     * - template:       site template replacing the bundled banner
     * - language:       force the banner language, e.g. 'nl' or 'nl-NL'
     * - registerAssets: false skips both the script and the stylesheet
     * - registerCss:    false skips only the stylesheet
     * - registerJs:     false skips only the script
     *
     * Anything left out falls back to the plugin settings, so automatic
     * injection ends up with the same result as a hand-written call.
     *
     * @param array{
     *     template?: string|null,
     *     language?: string|null,
     *     registerAssets?: bool,
     *     registerCss?: bool,
     *     registerJs?: bool,
     * } $options
     */
    public function render(array $options = []): Markup
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $view = Craft::$app->getView();

        if (!$view instanceof View) {
            return new Markup('', 'UTF-8');
        }

        $resolved = BannerOptions::resolve($options, $settings->getBannerDefaults());

        if ($resolved['registerJs']) {
            $view->registerAssetBundle(BannerJsAsset::class);
        }

        if ($resolved['registerCss']) {
            $view->registerAssetBundle(BannerCssAsset::class);
        }

        if ($settings->googleConsentMode) {
            $this->registerConsentModeDefaults($view);
        }

        $cookiesByCategory = $plugin->getCookies()->getCookiesByCategory();

        // One list, used in two places that must never disagree: what the
        // template offers, and what "Accept all" grants. The script reads the
        // checkboxes for "Save preferences" but `config.categories` for
        // "Accept all", so filtering only one of them makes the two buttons
        // produce different consent for a category nobody was shown.
        $visible = VisibleCategories::resolve($cookiesByCategory, $settings->hideEmptyCategories);

        [$template, $mode] = self::resolveTemplate($resolved['template'], 'cookiekit/banner');

        $html = $this->inLanguage(
            $resolved['language'],
            function () use (
                $view,
                $template,
                $mode,
                $plugin,
                $settings,
                $cookiesByCategory,
                $visible,
            ): string {
                // Built inside the language swap on purpose: the exact labels
                // shown to the visitor are also what the proof snapshot stores.
                $labels = CategoryText::labels();
                $descriptions = CategoryText::descriptions();
                $language = Craft::$app->language;
                $siteId = Craft::$app->getSites()->getCurrentSite()->id;
                $declared = [];

                foreach ($cookiesByCategory as $category => $cookies) {
                    $declared[$category] = array_map(
                        static fn($cookie): array => [
                            'name' => $cookie->name,
                            'provider' => $cookie->provider,
                            'purpose' => $cookie->purpose,
                            'duration' => $cookie->duration,
                        ],
                        $cookies,
                    );
                }

                $snapshot = $plugin->getSnapshots()->capture([
                    'revision' => $settings->revision,
                    'siteId' => $siteId,
                    'language' => $language,
                    'durationDays' => $settings->cookieDuration,
                    'policyUrl' => $settings->policyUrl,
                    'categories' => $visible,
                    'categoryLabels' => $labels,
                    'categoryDescriptions' => $descriptions,
                    'cookies' => $declared,
                ], $settings->revision, $siteId, $language);

                $config = [
                    'revision' => $settings->revision,
                    'duration' => $settings->cookieDuration,
                    'categories' => $visible,
                    'logConsents' => $settings->logConsents,
                    'analytics' => $settings->analyticsEnabled,
                    'gcm' => $settings->googleConsentMode,
                    'snapshotHash' => $snapshot['hash'],
                    'locale' => $language,
                    'saveUrl' => UrlHelper::actionUrl('cookiekit/consent/save'),
                    'trackUrl' => UrlHelper::actionUrl('cookiekit/analytics/track'),
                    'csrfUrl' => UrlHelper::actionUrl('users/session-info'),
                ];

                return $view->renderTemplate($template, [
                    'settings' => $settings,
                    'config' => Json::encode($config),
                    'cookiesByCategory' => $cookiesByCategory,
                    'categories' => $visible,
                    'categoryLabels' => $labels,
                    'categoryDescriptions' => $descriptions,
                ], $mode);
            },
        );

        return new Markup($html, 'UTF-8');
    }

    /**
     * Renders the cookie declaration table, for a privacy/cookie policy page:
     *
     *     {{ craft.cookiekit.declaration() }}
     *
     * Registers no assets: the "change your preferences" link it contains is
     * wired up by the script that render() already brought in.
     *
     * @param array{template?: string|null, language?: string|null} $options
     */
    public function declaration(array $options = []): Markup
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $view = Craft::$app->getView();

        if (!$view instanceof View) {
            return new Markup('', 'UTF-8');
        }

        $language = LanguageOption::normalize($options['language'] ?? null)
            ?? LanguageOption::normalize($settings->getBannerDefaults()['language']);

        [$template, $mode] = self::resolveTemplate(
            is_string($options['template'] ?? null) && trim($options['template']) !== ''
                ? $options['template']
                : null,
            'cookiekit/declaration',
        );

        $cookiesByCategory = $plugin->getCookies()->getCookiesByCategory();
        $visible = VisibleCategories::resolve($cookiesByCategory, $settings->hideEmptyCategories);

        $html = $this->inLanguage(
            $language,
            static fn(): string => $view->renderTemplate($template, [
                'settings' => $settings,
                'cookiesByCategory' => $cookiesByCategory,
                'categories' => $visible,
                'categoryLabels' => CategoryText::labels(),
                'categoryDescriptions' => CategoryText::descriptions(),
            ], $mode),
        );

        return new Markup($html, 'UTF-8');
    }

    /**
     * Runs the render with Craft's language forced, and always puts it back.
     *
     * @param callable(): string $render
     */
    private function inLanguage(?string $language, callable $render): string
    {
        $app = Craft::$app;

        if ($language === null || $language === $app->language) {
            return $render();
        }

        // Craft builds `locale`, `formattingLocale` and `formatter` once per
        // request, from whatever the language happens to be at first use, and
        // keeps them for the rest of it. Restoring the language afterwards
        // would not undo that.
        //
        // The bundled banner formats nothing, so in practice those components
        // are already built by the time we get here and this line changes
        // nothing. It is here for the template that does format something: a
        // custom banner printing a date inside the swap would otherwise pin
        // the formatter to the forced language and leave every date further
        // down the page in the wrong locale. One line to make that impossible.
        if (!$app->getRequest()->getIsConsoleRequest()) {
            $app->getFormatter();
        }

        $previous = $app->language;
        $app->language = $language;

        try {
            return $render();
        } finally {
            $app->language = $previous;
        }
    }

    /**
     * A template passed in is always resolved against the site's own templates
     * folder; the bundled fallback lives in the plugin and renders in CP mode.
     *
     * @return array{0: string, 1: string}
     */
    private static function resolveTemplate(?string $template, string $bundled): array
    {
        return $template !== null
            ? [$template, View::TEMPLATE_MODE_SITE]
            : [$bundled, View::TEMPLATE_MODE_CP];
    }

    /**
     * Whether the current visitor granted a category, read server-side from
     * the consent cookie. Usable for Twig-level gating:
     *
     *     {% if craft.cookiekit.hasConsent('marketing') %} ... {% endif %}
     */
    public function hasConsent(string $category): bool
    {
        if ($category === 'necessary') {
            return true;
        }

        $consent = $this->getConsent();
        if ($consent === null) {
            return false;
        }

        return in_array($category, $consent['categories'], true);
    }

    /**
     * The parsed consent cookie, or null when no (valid, current-revision)
     * consent is present.
     *
     * @return array{id: string, revision: int, categories: string[]}|null
     */
    public function getConsent(): ?array
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof \craft\web\Request) {
            return null;
        }

        $raw = $request->getCookies()->getValue(Plugin::CONSENT_COOKIE);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $data = Json::decode(urldecode($raw));
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($data) || !isset($data['v'], $data['c']) || !is_array($data['c'])) {
            return null;
        }

        $settings = Plugin::getInstance()->getSettings();
        if ((int)$data['v'] < $settings->revision) {
            return null;
        }

        return [
            'id' => (string)($data['id'] ?? ''),
            'revision' => (int)$data['v'],
            'categories' => array_values(array_intersect(Plugin::CATEGORIES, array_map('strval', $data['c']))),
        ];
    }

    /**
     * Registers the Google Consent Mode v2 defaults (everything denied) as an
     * inline head script, so the signals are set before gtag.js/GTM load.
     * Keep your gtag snippet after `{{ head() }}` in the layout for the
     * ordering to hold.
     *
     * The per-visitor `consent update` is deliberately NOT emitted here: it
     * happens client-side (cookiekit.js), so the rendered HTML contains no
     * visitor-specific state and stays safe for full-page caches like Blitz.
     */
    private function registerConsentModeDefaults(View $view): void
    {
        $js = "window.dataLayer=window.dataLayer||[];"
            . "(function(){function gtag(){dataLayer.push(arguments);}"
            . "gtag('consent','default',{'ad_storage':'denied','ad_user_data':'denied',"
            . "'ad_personalization':'denied','analytics_storage':'denied',"
            . "'functionality_storage':'denied','personalization_storage':'denied',"
            . "'security_storage':'granted','wait_for_update':500});"
            . "})();";

        $view->registerJs($js, View::POS_HEAD, 'cookiekit-gcm');
    }

    /**
     * All declared cookies, optionally filtered by category.
     *
     * @return \viesrood\cookiekit\models\Cookie[]
     */
    public function cookies(?string $category = null): array
    {
        $cookies = Plugin::getInstance()->getCookies()->getAllCookies();

        if ($category === null) {
            return $cookies;
        }

        return array_values(array_filter(
            $cookies,
            static fn($cookie): bool => $cookie->category === $category,
        ));
    }
}
