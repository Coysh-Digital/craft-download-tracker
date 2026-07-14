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
     * @throws NotFoundHttpException if the asset no longer exists.
     * @throws \yii\web\ForbiddenHttpException if login is required and absent.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $token = (string)Craft::$app->getRequest()->getParam('token', '');
        $assetId = $plugin->downloads->unsignAsset($token);

        // The route is anonymous and its URL is guessable, so scanners and stale
        // links hit it with no (or a junk) token as a matter of course. That's a
        // routine bad request, not an application fault: answer 404 rather than
        // throw, so it doesn't surface as an exception. The reason is still
        // logged for anyone debugging a genuinely broken signed link.
        if ($assetId === null) {
            Craft::info(
                'Refused a download request with a missing or invalid token.',
                __METHOD__,
            );

            return $this->_notFound();
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
     * Returns an empty 404, for requests that carry no usable download token.
     *
     * @return Response
     */
    private function _notFound(): Response
    {
        $response = Craft::$app->getResponse();
        $response->setStatusCode(404);
        $response->format = Response::FORMAT_RAW;
        $response->content = '';

        return $response;
    }

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
