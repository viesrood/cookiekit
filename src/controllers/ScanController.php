<?php

declare(strict_types=1);

namespace viesrood\cookiekit\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use Throwable;
use viesrood\cookiekit\jobs\ScanJob;
use viesrood\cookiekit\Plugin;
use viesrood\cookiekit\records\FindingRecord;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Endpoints for the browser scanner.
 *
 * Two actions are reachable without a CP session so the scanner can talk to a
 * site it is not logged in to, and both are gated on a shared secret that has
 * to be set deliberately. An unset token means the endpoints are closed, not
 * that everything is allowed: a plugin that opens a write path by default is a
 * plugin that eventually writes someone else's data.
 */
class ScanController extends Controller
{
    protected array|int|bool $allowAnonymous = ['urls', 'import'];

    /**
     * Only so many attempts per minute per address, so the token cannot simply
     * be guessed at speed.
     */
    private const MAX_ATTEMPTS_PER_MINUTE = 10;

    /**
     * The detection screen.
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('cookiekit:manageCookies');

        $plugin = $this->plugin();

        return $this->renderTemplate('cookiekit/cp/scan/index', [
            'report' => $plugin->getFindings()->getComplianceReport(),
            'counts' => $plugin->getFindings()->getStatusCounts(),
            'open' => $plugin->getFindings()->getFindings(['status' => FindingRecord::STATUS_NEW]),
            'ignored' => $plugin->getFindings()->getFindings(['status' => FindingRecord::STATUS_IGNORED]),
            'imported' => $plugin->getFindings()->getFindings(['status' => FindingRecord::STATUS_IMPORTED]),
            'lastScan' => $plugin->getScan()->getLastScan(),
            'categories' => Plugin::CATEGORIES,
            'settings' => $plugin->getSettings(),
            'siteUrl' => rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/'),
        ]);
    }

    /**
     * Queues a crawl. The control panel then follows Craft's own job progress
     * rather than holding a request open for a minute.
     */
    public function actionRun(): Response
    {
        $this->requirePermission('cookiekit:manageCookies');
        $this->requirePostRequest();

        $siteId = Craft::$app->getRequest()->getBodyParam('siteId');

        Craft::$app->getQueue()->push(new ScanJob([
            'siteId' => is_numeric($siteId) ? (int)$siteId : null,
        ]));

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        $this->setSuccessFlash(Craft::t('cookiekit', 'Scan started.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Adds the selected findings to the declaration.
     */
    public function actionAddToDeclaration(): Response
    {
        $this->requirePermission('cookiekit:manageCookies');
        $this->requirePostRequest();

        $ids = $this->selectedIds();
        /** @var array<int, string> $categories */
        $categories = (array)Craft::$app->getRequest()->getBodyParam('categories', []);

        $result = $this->plugin()->getFindings()->importFindings($ids, $categories);

        foreach ($result['errors'] as $error) {
            Craft::$app->getSession()->setError($error);
        }

        if ($result['imported'] > 0) {
            $this->setSuccessFlash(Craft::t('cookiekit', '{count} cookie(s) added to the declaration.', [
                'count' => $result['imported'],
            ]));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionIgnore(): Response
    {
        $this->requirePermission('cookiekit:manageCookies');
        $this->requirePostRequest();

        $this->plugin()->getFindings()->ignoreFindings($this->selectedIds());
        $this->setSuccessFlash(Craft::t('cookiekit', 'Findings ignored.'));

        return $this->redirectToPostedUrl();
    }

    public function actionReset(): Response
    {
        $this->requirePermission('cookiekit:manageCookies');
        $this->requirePostRequest();

        $this->plugin()->getFindings()->resetFindings($this->selectedIds());
        $this->setSuccessFlash(Craft::t('cookiekit', 'Findings reopened.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Takes one automatic import back out of the declaration.
     */
    public function actionRevert(): Response
    {
        $this->requirePermission('cookiekit:manageCookies');
        $this->requirePostRequest();

        $batch = (string)Craft::$app->getRequest()->getRequiredBodyParam('batch');
        $result = $this->plugin()->getFindings()->revertBatch($batch);

        foreach ($result['kept'] as $name) {
            Craft::$app->getSession()->setNotice(Craft::t('cookiekit', '{name} was edited after the import and has been left alone.', [
                'name' => $name,
            ]));
        }

        $this->setSuccessFlash(Craft::t('cookiekit', '{count} cookie(s) removed from the declaration.', [
            'count' => $result['removed'],
        ]));

        return $this->redirectToPostedUrl();
    }

    /**
     * The pages a scan would visit. The sampling rules stay on this side, so
     * the scanner never has to reimplement them.
     */
    public function actionUrls(): Response
    {
        $this->requireScanToken();

        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return $this->asJson(['urls' => []]);
        }

        $targets = $plugin->getScan()->discoverUrls();

        return $this->asJson([
            'urls' => array_column($targets, 'url'),
            'count' => count($targets),
        ]);
    }

    /**
     * Takes in a scanner payload and records what it saw.
     */
    public function actionImport(): Response
    {
        $this->requirePostRequest();
        $this->requireScanToken();

        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new ForbiddenHttpException('CookieKit is not available.');
        }

        $payload = Craft::$app->getRequest()->getBodyParams();

        if ($payload === []) {
            $raw = Craft::$app->getRequest()->getRawBody();

            try {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return $this->asJson(['error' => 'Body is not valid JSON.'])->setStatusCode(400);
            }
        }

        if (!is_array($payload)) {
            return $this->asJson(['error' => 'Body is not valid JSON.'])->setStatusCode(400);
        }

        $summary = $plugin->getScan()->importBrowserReport($payload);

        return $this->asJson([
            'ok' => true,
            'pages' => $summary['urlsScanned'],
            'new' => $summary['new'],
            'updated' => $summary['updated'],
            'imported' => $summary['imported'],
            'batch' => $summary['batch'],
        ]);
    }

    /**
     * @return list<int>
     */
    private function selectedIds(): array
    {
        /** @var list<mixed> $raw */
        $raw = (array)Craft::$app->getRequest()->getBodyParam('findingIds', []);

        return array_values(array_map('intval', array_filter($raw, 'is_numeric')));
    }

    private function plugin(): Plugin
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new ForbiddenHttpException('CookieKit is not available.');
        }

        return $plugin;
    }

    /**
     * A logged-in admin who may manage the declaration is let through as well:
     * inside the CP there is already a session saying who they are, and making
     * them dig up the token would only encourage keeping it somewhere careless.
     *
     * @throws ForbiddenHttpException
     */
    private function requireScanToken(): void
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user instanceof User && $user->can('cookiekit:manageCookies')) {
            return;
        }

        $expected = Plugin::getInstance()?->getSettings()->getResolvedScanToken() ?? '';

        if ($expected === '') {
            throw new ForbiddenHttpException(
                'No scan token is configured. Set COOKIEKIT_SCAN_TOKEN and point the plugin setting at it.',
            );
        }

        $this->enforceRateLimit();

        $header = Craft::$app->getRequest()->getHeaders()->get('Authorization', '');
        $provided = is_string($header) && preg_match('/^Bearer\s+(.+)$/i', trim($header), $match) === 1
            ? $match[1]
            : '';

        // Constant time, so a wrong token cannot be narrowed down by measuring
        // how long the rejection takes.
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new ForbiddenHttpException('Invalid scan token.');
        }
    }

    /**
     * @throws ForbiddenHttpException
     */
    private function enforceRateLimit(): void
    {
        $ip = Craft::$app->getRequest()->getUserIP() ?? 'unknown';
        $key = 'cookiekit:scan-auth:' . sha1($ip);
        $cache = Craft::$app->getCache();

        $attempts = (int)$cache->get($key);

        if ($attempts >= self::MAX_ATTEMPTS_PER_MINUTE) {
            throw new ForbiddenHttpException('Too many attempts. Try again in a minute.');
        }

        $cache->set($key, $attempts + 1, 60);
    }
}
