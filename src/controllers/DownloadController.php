<?php
/**
 * Download Tracker plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\controllers;

use Craft;
use coyshdigital\downloadtracker\models\Settings;
use coyshdigital\downloadtracker\Plugin;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The optional served-download route. Counts the download, then streams the file
 * or redirects to it - for gated, private, remote, or forced downloads.
 *
 * The link is a signed action URL, so it can't be tampered into serving an
 * arbitrary asset, and (being an action request) it's never statically cached.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class DownloadController extends Controller
{
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected int|bool|array $allowAnonymous = ['index'];

    // Public Methods
    // =========================================================================

    /**
     * Counts and serves a download.
     *
     * @return Response
     * @throws BadRequestHttpException if the token is missing or invalid.
     * @throws NotFoundHttpException if the asset no longer exists.
     * @throws \yii\web\ForbiddenHttpException if login is required and absent.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $token = (string)Craft::$app->getRequest()->getParam('token', '');
        $assetId = $plugin->downloads->unsignAsset($token);

        if ($assetId === null) {
            throw new BadRequestHttpException('Invalid download token.');
        }

        if ($settings->requireLoginToServe) {
            $this->requireLogin();
        }

        $asset = Craft::$app->getAssets()->getAssetById($assetId);

        if ($asset === null) {
            throw new NotFoundHttpException('File not found.');
        }

        // Count first, so the download is recorded even if streaming is aborted.
        $plugin->downloads->increment($asset);

        $url = $asset->getUrl();

        // A 302 can't set Content-Disposition, so forcing a download always
        // streams, regardless of serve mode.
        $shouldRedirect = !$settings->forceDownload && $url !== null && match ($settings->serveMode) {
            Settings::SERVE_MODE_STREAM => false,
            default => true,
        };

        if ($shouldRedirect && $url !== null) {
            return $this->redirect($url);
        }

        return $this->_streamAsset($asset, $settings->forceDownload);
    }

    // Private Methods
    // =========================================================================

    /**
     * Streams an asset's bytes through PHP, covering both local and remote
     * (S3, etc.) volumes via Craft's filesystem abstraction.
     *
     * @param \craft\elements\Asset $asset
     * @param bool $forceDownload
     * @return Response
     */
    private function _streamAsset(\craft\elements\Asset $asset, bool $forceDownload): Response
    {
        $options = ['inline' => !$forceDownload];
        $mimeType = $asset->getMimeType();

        if ($mimeType !== null) {
            $options['mimeType'] = $mimeType;
        }

        return Craft::$app->getResponse()->sendStreamAsFile(
            $asset->getStream(),
            $asset->getFilename(),
            $options,
        );
    }
}
