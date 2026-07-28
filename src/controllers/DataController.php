<?php

declare(strict_types=1);

namespace viesrood\cookiekit\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use viesrood\cookiekit\Plugin;
use viesrood\cookiekit\records\AnalyticsRecord;
use viesrood\cookiekit\records\ConsentRecord;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Explicit, date-bounded local data cleanup.
 */
class DataController extends Controller
{
    public function actionPurge(): Response
    {
        // Deliberately not the export permission. Being allowed to read the
        // consent log is not the same as being allowed to destroy it, and this
        // deletes the exact evidence art. 7 GDPR asks you to be able to show.
        $this->requirePermission('cookiekit:purgeData');
        $this->requirePostRequest();

        $before = (string)$this->request->getRequiredBodyParam('before');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $before) !== 1) {
            throw new BadRequestHttpException('Choose a valid cutoff date.');
        }

        $consents = ConsentRecord::deleteAll(['<', 'dateCreated', $before . ' 00:00:00']);
        $analytics = AnalyticsRecord::deleteAll(['<', 'day', $before]);

        // Deleting receipts leaves their snapshots pointing at nothing. Clean
        // those up here rather than waiting for garbage collection, so the
        // purge really is the erasure it says it is.
        Plugin::getInstance()?->getSnapshots()->pruneOrphans();

        // The deletion itself leaves no trace, so the log is the only record
        // that it happened and who asked for it.
        $user = Craft::$app->getUser()->getIdentity();

        Craft::warning(sprintf(
            'CookieKit: %s purged %d consent event(s) and %d analytics row(s) from before %s.',
            $user instanceof User ? $user->email : 'an unidentified user',
            $consents,
            $analytics,
            $before,
        ), __METHOD__);

        $this->setSuccessFlash(Craft::t(
            'cookiekit',
            'Removed {consents} consent event(s) and {analytics} analytics row(s) from before {date}.',
            ['consents' => $consents, 'analytics' => $analytics, 'date' => $before],
        ));

        return $this->redirectToPostedUrl();
    }
}
