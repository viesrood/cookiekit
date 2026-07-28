<?php

declare(strict_types=1);

namespace viesrood\cookiekit\controllers;

use Craft;
use craft\web\Controller;
use viesrood\cookiekit\helpers\RateLimit;
use viesrood\cookiekit\Plugin;
use yii\web\Response;

/**
 * Anonymous endpoint for a daily banner-view counter.
 */
class AnalyticsController extends Controller
{
    protected array|bool|int $allowAnonymous = ['track'];

    public function actionTrack(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // Anonymous and it writes to the database, so it needs a ceiling.
        if (!RateLimit::hit('analytics', 60)) {
            return $this->asJson(['recorded' => false]);
        }

        $plugin = Plugin::getInstance();
        if ($plugin === null || !$plugin->getSettings()->analyticsEnabled) {
            return $this->asJson(['recorded' => false]);
        }

        $event = (string)$this->request->getBodyParam('event', '');
        if ($event !== 'bannerViews') {
            return $this->asJson(['recorded' => false])->setStatusCode(400);
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $recorded = $plugin->getAnalytics()->record(
            $event,
            $siteId,
            [],
            filter_var($this->request->getBodyParam('gpc', false), FILTER_VALIDATE_BOOL),
        );

        return $this->asJson(['recorded' => $recorded]);
    }
}
