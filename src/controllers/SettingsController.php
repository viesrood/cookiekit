<?php

declare(strict_types=1);

namespace viesrood\cookiekit\controllers;

use Craft;
use craft\web\Controller;
use viesrood\cookiekit\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The plugin's own settings screen.
 *
 * Craft's generic plugin settings live under `settings/plugins/…`, which is a
 * different control panel section, so opening them collapses CookieKit's own
 * navigation. Serving the same form from inside `cookiekit/…` keeps the section
 * open and the subnav item highlighted.
 */
class SettingsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireAdmin(false);

        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new ForbiddenHttpException('CookieKit is not available.');
        }

        // Admin changes can be locked in production, and then the settings are
        // read-only rather than absent, exactly as Craft's own screen does it.
        $readOnly = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        return $this->renderTemplate('cookiekit/cp/settings-page', [
            'settingsHtml' => $plugin->renderSettingsHtml($readOnly),
            'readOnly' => $readOnly,
        ]);
    }
}
