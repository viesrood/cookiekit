<?php

declare(strict_types=1);

namespace viesrood\cookiekit\controllers;

use Craft;
use craft\web\Controller;
use viesrood\cookiekit\helpers\RateLimit;
use viesrood\cookiekit\Plugin;
use yii\web\Response;

/**
 * Front-end endpoint that stores consent receipts.
 */
class ConsentController extends Controller
{
    protected array|bool|int $allowAnonymous = ['save'];

    /**
     * POST /actions/cookiekit/consent/save
     *
     * Body: consentId (uuid), categories (array of category handles),
     * revision (int).
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // Anonymous, and every accepted request is a permanent row. A loop here
        // does not just fill the disk, it drowns the genuine receipts in forged
        // ones, which is the opposite of what an audit trail is for.
        if (!RateLimit::hit('consent', 30)) {
            return $this->asJson(['logged' => false]);
        }

        $settings = Plugin::getInstance()?->getSettings();
        if ($settings === null || !$settings->logConsents) {
            return $this->asJson(['logged' => false]);
        }

        $request = $this->request;
        $consentId = (string)$request->getBodyParam('consentId', '');
        $categories = $request->getBodyParam('categories', []);
        $revision = (int)$request->getBodyParam('revision', $settings->revision);
        $action = (string)$request->getBodyParam('action', 'custom');
        $snapshotHash = (string)$request->getBodyParam('snapshotHash', '');
        $gpc = filter_var($request->getBodyParam('gpc', false), FILTER_VALIDATE_BOOL);
        $gpcOverride = filter_var($request->getBodyParam('gpcOverride', false), FILTER_VALIDATE_BOOL);
        $locale = (string)$request->getBodyParam('locale', '');

        if ($consentId === '' || !is_array($categories)) {
            return $this->asJson(['logged' => false])->setStatusCode(400);
        }

        $categories = array_map('strval', $categories);

        $siteId = null;
        try {
            $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        } catch (\Throwable) {
            // Site context is optional in the receipt.
        }

        $logged = Plugin::getInstance()->getConsents()->logConsent(
            $consentId,
            $categories,
            $revision,
            $siteId,
            $action,
            $snapshotHash !== '' ? $snapshotHash : null,
            $gpc,
            $gpcOverride,
            $settings->cookieDuration,
            $locale,
        );

        return $this->asJson(['logged' => $logged]);
    }
}
