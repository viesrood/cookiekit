<?php

declare(strict_types=1);

namespace viesrood\cookiekit\controllers;

use Craft;
use craft\web\Controller;
use viesrood\cookiekit\helpers\SiteAccess;
use viesrood\cookiekit\Plugin;
use viesrood\cookiekit\records\FindingRecord;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Action-oriented CookieKit health overview.
 */
class DashboardController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('cookiekit:manageCookies');

        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            throw new ForbiddenHttpException('CookieKit is not available.');
        }

        $settings = $plugin->getSettings();
        $cookies = $plugin->getCookies()->getAllCookies();
        // Resolved against what this user may actually see, so a bookmarked
        // link with someone else's site id shows their own data rather than a
        // site they have no business reading.
        $site = SiteAccess::resolve($this->request->getQueryParam('siteId'));

        if ($site === null) {
            throw new ForbiddenHttpException('You do not have access to any sites.');
        }

        $siteId = $site->id;
        $openFindings = $plugin->getFindings()->getFindings([
            'status' => FindingRecord::STATUS_NEW,
            'siteId' => $siteId,
        ]);
        $preConsent = array_filter(
            $openFindings,
            static fn($finding): bool => $finding->preConsent,
        );
        $lastScan = $plugin->getScan()->getLastScan($siteId);

        $incomplete = array_filter(
            $cookies,
            static fn($cookie): bool =>
                trim($cookie->provider) === ''
                || trim($cookie->purpose) === ''
                || trim($cookie->duration) === '',
        );

        $checks = [
            [
                'label' => Craft::t('cookiekit', 'Banner delivery'),
                'ok' => $settings->autoInject,
                'detail' => $settings->autoInject
                    ? Craft::t('cookiekit', 'Automatic injection is enabled.')
                    : Craft::t('cookiekit', 'Automatic injection is off; verify that craft.cookiekit.render() is present in the site layout.'),
                'url' => 'cookiekit/settings',
            ],
            [
                'label' => Craft::t('cookiekit', 'Cookie policy'),
                'ok' => trim($settings->policyUrl) !== '',
                'detail' => trim($settings->policyUrl) !== ''
                    ? $settings->policyUrl
                    : Craft::t('cookiekit', 'Add a policy URL in Settings.'),
                'url' => 'cookiekit/settings',
            ],
            [
                'label' => Craft::t('cookiekit', 'Cookie declaration'),
                'ok' => $cookies !== [] && $incomplete === [],
                'detail' => Craft::t('cookiekit', '{count} declared, {incomplete} incomplete.', [
                    'count' => count($cookies),
                    'incomplete' => count($incomplete),
                ]),
                'url' => 'cookiekit/cookies',
            ],
            [
                'label' => Craft::t('cookiekit', 'Automatic blocking'),
                // Reporting counts as in order: it is the step the settings
                // recommend taking first, and marking it as a problem pushes
                // people straight past it into enforcing.
                'ok' => in_array($settings->autoBlockMode, ['enforce', 'report'], true),
                'detail' => match ($settings->autoBlockMode) {
                    'enforce' => Craft::t('cookiekit', 'Recognised trackers are blocked before the page is sent.'),
                    'report' => Craft::t('cookiekit', 'Reporting only. Visited pages are checked and anything blocking would act on shows up in Detection.'),
                    default => Craft::t('cookiekit', 'Off. Blocking relies entirely on the markup in your templates.'),
                },
                'url' => 'cookiekit/settings',
            ],
            [
                'label' => Craft::t('cookiekit', 'Consent proof'),
                'ok' => $settings->logConsents,
                'detail' => $settings->logConsents
                    ? Craft::t('cookiekit', 'Append-only consent events are enabled.')
                    : Craft::t('cookiekit', 'Enable consent logging in Settings.'),
                'url' => 'cookiekit/settings',
            ],
            [
                'label' => Craft::t('cookiekit', 'Detection'),
                'ok' => $lastScan !== null
                    && $preConsent === []
                    && $openFindings === [],
                'detail' => Craft::t('cookiekit', '{pre} pre-consent issue(s), {open} open finding(s).', [
                    'pre' => count($preConsent),
                    'open' => count($openFindings),
                ]),
                'url' => 'cookiekit/scan',
            ],
        ];

        return $this->renderTemplate('cookiekit/cp/dashboard/index', [
            'settings' => $settings,
            'checks' => $checks,
            'lastScan' => $lastScan,
            'openFindings' => count($openFindings),
            'consentTotal' => $plugin->getConsents()->getTotalCount(),
            'analyticsTotals' => $plugin->getAnalytics()->getTotals(30, $siteId),
            'analyticsSeries' => $plugin->getAnalytics()->getSeries(30, $siteId),
            'sites' => SiteAccess::allowedSites(),
            'selectedSiteId' => $siteId,
        ]);
    }
}
