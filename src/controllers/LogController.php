<?php

declare(strict_types=1);

namespace viesrood\cookiekit\controllers;

use craft\web\Controller;
use viesrood\cookiekit\helpers\SiteAccess;
use viesrood\cookiekit\Plugin;
use yii\web\Response;

/**
 * CP overview of the consent log.
 */
class LogController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('cookiekit:viewConsents');

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $consents = Plugin::getInstance()->getConsents();
        // Never take the site id straight from the query string: an editor
        // limited to one site could otherwise read another site's receipts by
        // typing a different id in the address bar.
        $siteId = SiteAccess::filterId($this->request->getQueryParam('siteId'));
        $from = $this->request->getQueryParam('from');
        $to = $this->request->getQueryParam('to');

        return $this->renderTemplate('cookiekit/cp/consents/index', [
            'consents' => $consents->getRecentConsents(
                200,
                $siteId,
                is_string($from) ? $from : null,
                is_string($to) ? $to : null,
            ),
            'total' => $consents->getTotalCount(),
            'categoryCounts' => $consents->getCategoryCounts(),
            'categories' => Plugin::CATEGORIES,
            'settings' => Plugin::getInstance()->getSettings(),
            'sites' => SiteAccess::allowedSites(),
            'filters' => [
                'siteId' => $siteId,
                'from' => is_string($from) ? $from : '',
                'to' => is_string($to) ? $to : '',
            ],
        ]);
    }
}
