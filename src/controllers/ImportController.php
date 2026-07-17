<?php
/**
 * Download Tracker plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\controllers;

use Craft;
use coyshdigital\downloadtracker\jobs\ImportLinkVault;
use coyshdigital\downloadtracker\Plugin;
use craft\helpers\Queue;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Runs the one-way import of Link Vault's download history.
 *
 * The whole controller 404s once Link Vault is gone: this exists for a single
 * afternoon in a site's life, and shouldn't linger as a live route afterwards.
 *
 * @author Coysh Digital
 * @since 1.2.0
 */
class ImportController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException if the user is not an admin.
     * @throws NotFoundHttpException if Link Vault isn't installed.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(false);

        if (!Plugin::getInstance()->linkVaultImport->isAvailable()) {
            throw new NotFoundHttpException('Link Vault is not installed.');
        }

        return true;
    }

    /**
     * Shows what an import would do, and offers to do it.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('download-tracker/import/index', [
            'preview' => Plugin::getInstance()->linkVaultImport->getPreview(),
        ]);
    }

    /**
     * Queues the import.
     *
     * @return Response|null
     */
    public function actionStart(): ?Response
    {
        $this->requirePostRequest();

        $import = Plugin::getInstance()->linkVaultImport;
        $preview = $import->getPreview();

        if ($preview['pending'] === 0) {
            Craft::$app->getSession()->setNotice(Craft::t('download-tracker', 'Nothing left to import.'));

            return $this->redirectToPostedUrl();
        }

        Queue::push(new ImportLinkVault([
            'lowId' => $preview['lastRowId'],
            // Pinned now, so downloads logged while the import runs don't keep
            // moving the finish line. They're picked up by the next run.
            'maxId' => $preview['maxRowId'],
            'total' => $preview['pending'],
        ]), priority: 2048);

        Craft::$app->getSession()->setNotice(Craft::t('download-tracker', 'Import queued.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Clears the high-water mark so the next import starts from the beginning.
     *
     * @return Response|null
     */
    public function actionReset(): ?Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->linkVaultImport->reset();

        Craft::$app->getSession()->setNotice(Craft::t('download-tracker', 'Import progress cleared.'));

        return $this->redirectToPostedUrl();
    }
}
