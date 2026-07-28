<?php

declare(strict_types=1);

namespace viesrood\cookiekit\controllers;

use Craft;
use craft\web\Controller;
use viesrood\cookiekit\helpers\VisibleCategories;
use viesrood\cookiekit\models\Cookie;
use viesrood\cookiekit\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP CRUD for the cookie declaration.
 */
class CookiesController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('cookiekit:manageCookies');

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $cookiesByCategory = $plugin->getCookies()->getCookiesByCategory();

        return $this->renderTemplate('cookiekit/cp/cookies/index', [
            'cookiesByCategory' => $cookiesByCategory,
            'categories' => Plugin::CATEGORIES,
            // So the screen can say which categories visitors never get to see,
            // instead of leaving "where did my marketing switch go" a mystery.
            'visibleCategories' => VisibleCategories::resolve(
                $cookiesByCategory,
                $plugin->getSettings()->hideEmptyCategories,
            ),
        ]);
    }

    public function actionEdit(?int $cookieId = null, ?Cookie $cookie = null): Response
    {
        if ($cookie === null) {
            if ($cookieId !== null) {
                $cookie = Plugin::getInstance()->getCookies()->getCookieById($cookieId);
                if ($cookie === null) {
                    throw new NotFoundHttpException('Cookie not found');
                }
            } else {
                $cookie = new Cookie();
            }
        }

        return $this->renderTemplate('cookiekit/cp/cookies/edit', [
            'cookie' => $cookie,
            'categories' => Plugin::CATEGORIES,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = $this->request;
        $cookie = new Cookie([
            'id' => $request->getBodyParam('cookieId') !== null && $request->getBodyParam('cookieId') !== ''
                ? (int)$request->getBodyParam('cookieId')
                : null,
            'category' => (string)$request->getBodyParam('category', 'necessary'),
            'name' => trim((string)$request->getBodyParam('name', '')),
            'provider' => trim((string)$request->getBodyParam('provider', '')),
            'purpose' => trim((string)$request->getBodyParam('purpose', '')),
            'duration' => trim((string)$request->getBodyParam('duration', '')),
            'sortOrder' => (int)$request->getBodyParam('sortOrder', 0),
        ]);

        if (!Plugin::getInstance()->getCookies()->saveCookie($cookie)) {
            $this->setFailFlash(Craft::t('cookiekit', 'Couldn’t save cookie.'));

            return $this->renderTemplate('cookiekit/cp/cookies/edit', [
                'cookie' => $cookie,
                'categories' => Plugin::CATEGORIES,
            ]);
        }

        $this->setSuccessFlash(Craft::t('cookiekit', 'Cookie saved.'));

        return $this->redirect('cookiekit');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int)$this->request->getRequiredBodyParam('cookieId');
        Plugin::getInstance()->getCookies()->deleteCookieById($id);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        $this->setSuccessFlash(Craft::t('cookiekit', 'Cookie deleted.'));

        return $this->redirect('cookiekit');
    }
}
