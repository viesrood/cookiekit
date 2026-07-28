<?php

declare(strict_types=1);

namespace viesrood\cookiekit;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\User as CraftUser;
use craft\web\View;
use Throwable;
use viesrood\cookiekit\models\Settings;
use viesrood\cookiekit\services\ConsentsService;
use viesrood\cookiekit\services\ConsentSnapshotsService;
use viesrood\cookiekit\services\AnalyticsService;
use viesrood\cookiekit\services\CookiesService;
use viesrood\cookiekit\services\DetectorService;
use viesrood\cookiekit\services\FindingsService;
use viesrood\cookiekit\services\ScanService;
use viesrood\cookiekit\services\SignatureService;
use viesrood\cookiekit\services\HtmlBlocker;
use viesrood\cookiekit\variables\CookieKitVariable;
use yii\base\Event;

/**
 * CookieKit plugin.
 *
 * GDPR/AVG-compliant cookie consent for Craft CMS 5: a themable consent
 * banner with per-category opt-in, a cookie declaration managed in the
 * control panel, blocked-script activation after consent, consent logging
 * without network or device data, and optional Google Consent Mode v2 signals.
 *
 * @property-read CookiesService $cookies
 * @property-read ConsentsService $consents
 * @property-read SignatureService $signatures
 * @property-read DetectorService $detector
 * @property-read FindingsService $findings
 * @property-read ScanService $scan
 * @property-read ConsentSnapshotsService $snapshots
 * @property-read AnalyticsService $analytics
 */
class Plugin extends BasePlugin
{
    /**
     * Name of the first-party cookie that stores the visitor's choice.
     */
    public const CONSENT_COOKIE = 'cookiekit_consent';

    /**
     * The consent categories, in display order. "necessary" is always granted.
     */
    public const CATEGORIES = ['necessary', 'preferences', 'statistics', 'marketing'];

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    /**
     * @return array{components: array<string, mixed>}
     */
    public static function config(): array
    {
        return [
            'components' => [
                'cookies' => CookiesService::class,
                'consents' => ConsentsService::class,
                'signatures' => SignatureService::class,
                'detector' => DetectorService::class,
                'findings' => FindingsService::class,
                'scan' => ScanService::class,
                'snapshots' => ConsentSnapshotsService::class,
                'analytics' => AnalyticsService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerPermissions();
        $this->registerCpUrlRules();
        $this->registerVariable();
        $this->registerGarbageCollection();
        $this->registerAutomaticBlocking();

        $request = Craft::$app->getRequest();
        if (
            !$request->getIsConsoleRequest()
            && $request->getIsSiteRequest()
            && $this->getSettings()->autoInject
        ) {
            $this->registerAutoInject();
        }

        Craft::info('CookieKit plugin loaded', __METHOD__);
    }

    public function getCookies(): CookiesService
    {
        /** @var CookiesService $service */
        $service = $this->get('cookies');

        return $service;
    }

    public function getConsents(): ConsentsService
    {
        /** @var ConsentsService $service */
        $service = $this->get('consents');

        return $service;
    }

    public function getSignatures(): SignatureService
    {
        /** @var SignatureService $service */
        $service = $this->get('signatures');

        return $service;
    }

    public function getDetector(): DetectorService
    {
        /** @var DetectorService $service */
        $service = $this->get('detector');

        return $service;
    }

    public function getFindings(): FindingsService
    {
        /** @var FindingsService $service */
        $service = $this->get('findings');

        return $service;
    }

    public function getScan(): ScanService
    {
        /** @var ScanService $service */
        $service = $this->get('scan');

        return $service;
    }

    public function getSnapshots(): ConsentSnapshotsService
    {
        /** @var ConsentSnapshotsService $service */
        $service = $this->get('snapshots');

        return $service;
    }

    public function getAnalytics(): AnalyticsService
    {
        /** @var AnalyticsService $service */
        $service = $this->get('analytics');

        return $service;
    }

    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        return $settings;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('cookiekit', 'CookieKit');

        $user = Craft::$app->getUser();
        if (!$user instanceof CraftUser) {
            return null;
        }

        $subnav = [];
        if ($user->checkPermission('cookiekit:manageCookies')) {
            $subnav['dashboard'] = ['label' => Craft::t('cookiekit', 'Dashboard'), 'url' => 'cookiekit'];
            $subnav['cookies'] = ['label' => Craft::t('cookiekit', 'Cookies'), 'url' => 'cookiekit/cookies'];

            if ($this->getSettings()->scanEnabled) {
                $subnav['scan'] = ['label' => Craft::t('cookiekit', 'Detection'), 'url' => 'cookiekit/scan'];
            }
        }
        if ($user->checkPermission('cookiekit:viewConsents')) {
            $subnav['consents'] = ['label' => Craft::t('cookiekit', 'Consent log'), 'url' => 'cookiekit/consents'];
        }
        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('cookiekit', 'Settings'), 'url' => 'cookiekit/settings'];
        }
        if ($subnav === []) {
            return null;
        }

        $item['subnav'] = $subnav;
        $first = reset($subnav);
        $item['url'] = $first['url'];

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Sends the generic plugin settings URL to the plugin's own screen, so the
     * section stays open instead of collapsing into Settings.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('cookiekit/settings'));
    }

    /**
     * The settings form, namespaced the way Craft namespaces it, so the fields
     * arrive as `settings[…]` and `plugins/save-plugin-settings` can read them.
     */
    public function renderSettingsHtml(bool $readOnly = false): string
    {
        $view = Craft::$app->getView();

        return (string)$view->namespaceInputs(function() use ($readOnly): string {
            $html = (string)$this->settingsHtml();

            return $readOnly ? (string)Html::disableInputs(static fn(): string => $html) : $html;
        }, 'settings');
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('cookiekit/cp/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['cookiekit'] = 'cookiekit/dashboard/index';
                $event->rules['cookiekit/cookies'] = 'cookiekit/cookies/index';
                $event->rules['cookiekit/cookies/new'] = 'cookiekit/cookies/edit';
                $event->rules['cookiekit/cookies/<cookieId:\d+>'] = 'cookiekit/cookies/edit';
                $event->rules['cookiekit/consents'] = 'cookiekit/log/index';
                $event->rules['cookiekit/scan'] = 'cookiekit/scan/index';
                $event->rules['cookiekit/settings'] = 'cookiekit/settings/index';
                $event->rules['cookiekit/export/consents'] = 'cookiekit/export/consents';
                $event->rules['cookiekit/export/analytics'] = 'cookiekit/export/analytics';
            }
        );
    }

    private function registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function (Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('cookiekit', CookieKitVariable::class);
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('cookiekit', 'CookieKit'),
                    'permissions' => [
                        'cookiekit:manageCookies' => [
                            'label' => Craft::t('cookiekit', 'Manage the cookie declaration'),
                        ],
                        'cookiekit:viewConsents' => [
                            'label' => Craft::t('cookiekit', 'View the consent log'),
                        ],
                        'cookiekit:exportData' => [
                            'label' => Craft::t('cookiekit', 'Export consent and analytics data'),
                        ],
                        'cookiekit:purgeData' => [
                            'label' => Craft::t('cookiekit', 'Permanently delete consent and analytics data'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Prune expired consent log entries during Craft's garbage collection.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            static function (): void {
                $plugin = Plugin::getInstance();

                if ($plugin === null) {
                    return;
                }

                // Each step is isolated on purpose. An exception here does not
                // just skip the rest of our own cleanup: it aborts the whole
                // Gc::EVENT_RUN loop, taking every garbage collector
                // registered after ours with it. One call to a method that
                // does not exist is enough to do that.
                $steps = [
                    'consents' => static fn(): int => $plugin->getConsents()->pruneExpired(),
                    'analytics' => static fn(): int => $plugin->getAnalytics()->pruneExpired(),
                    'findings' => static fn(): int => $plugin->getFindings()
                        ->pruneStale($plugin->getSettings()->findingRetentionDays),
                    // After the consent rows, so a snapshot whose last receipt
                    // was just pruned is collected in the same run.
                    'snapshots' => static fn(): int => $plugin->getSnapshots()->pruneOrphans(),
                ];

                foreach ($steps as $name => $step) {
                    try {
                        $step();
                    } catch (Throwable $exception) {
                        Craft::error(
                            sprintf('CookieKit could not prune %s: %s', $name, $exception->getMessage()),
                            __METHOD__,
                        );
                    }
                }
            }
        );
    }

    /**
     * Render the banner automatically at the end of the <body> on site requests.
     */
    private function registerAutoInject(): void
    {
        Event::on(
            View::class,
            View::EVENT_END_BODY,
            static function (): void {
                $view = Craft::$app->getView();
                if (!$view instanceof View) {
                    return;
                }
                echo (new CookieKitVariable())->render();
            }
        );
    }

    /**
     * Block only full site page templates, after Craft and plugins have
     * finished composing the response. CP pages and action responses are never
     * rewritten.
     */
    private function registerAutomaticBlocking(): void
    {
        $mode = $this->getSettings()->autoBlockMode;

        if ($mode !== 'enforce' && $mode !== 'report') {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            function (TemplateEvent $event) use ($mode): void {
                if ($event->templateMode !== View::TEMPLATE_MODE_SITE) {
                    return;
                }

                // This event also fires for templates that emit XML, JSON or
                // plain text through {% header %}. Feeding a feed to an HTML
                // rewriter is how a sitemap turns into garbage, so ask the
                // response what it is rather than guessing from the body.
                $response = Craft::$app->getResponse();
                $contentType = $response->getHeaders()->get('content-type');

                if (is_string($contentType) && stripos($contentType, 'html') === false) {
                    return;
                }

                if ($mode === 'report') {
                    $this->reportBlockable($event->output);

                    return;
                }

                $blocker = new HtmlBlocker($this->getSignatures()->getMatcher());
                $event->output = $blocker->rewrite($event->output);
            },
        );
    }

    /**
     * Report mode: look at the page, change nothing, and put what enforcing
     * would have acted on into the Detection inbox.
     *
     * It exists so "start with reporting, then enforce" is real advice rather
     * than a setting that behaves exactly like off.
     *
     * The value over the crawler is coverage: this sees the pages visitors
     * actually request, including ones no sitemap or entry query would reach.
     * The cost is that it runs on live traffic, so it is throttled hard: one
     * look per URL per hour, and the throttle is checked before the page is
     * parsed, not after.
     */
    private function reportBlockable(string $html): void
    {
        try {
            $request = Craft::$app->getRequest();
            $site = Craft::$app->getSites()->getCurrentSite();
            $path = $request->getFullPath();
            $key = 'cookiekit:report:' . $site->id . ':' . sha1($path);
            $cache = Craft::$app->getCache();

            if ($cache->get($key) !== false) {
                return;
            }

            $cache->set($key, true, 3600);

            $url = rtrim((string)$site->getBaseUrl(), '/') . '/' . ltrim($path, '/');
            $items = $this->getDetector()->detectFromHtml($html, $url, $site->id);

            // Only what automatic blocking would have touched. The inferred
            // cookie findings that come with a vendor are the crawler's job;
            // repeating them from live traffic would bury the point.
            $blockable = array_values(array_filter(
                $items,
                static fn(array $item): bool => in_array($item['type'], ['unblocked', 'miscategorised'], true),
            ));

            if ($blockable !== []) {
                $this->getFindings()->recordFindings($blockable);
            }
        } catch (Throwable $exception) {
            // Reporting must never be the reason a page fails to render.
            Craft::error('CookieKit could not report blockable resources: ' . $exception->getMessage(), __METHOD__);
        }
    }
}
