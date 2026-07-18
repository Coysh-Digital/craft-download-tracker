<?php
/**
 * Download Tracker plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\controllers;

use Craft;
use coyshdigital\downloadtracker\helpers\RequestSignals;
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

        // `dlt`, not `token`: `token` is Craft's reserved route-token param, and
        // on Craft 5.9+ an unrecognised value there 400s before this action runs.
        $token = (string)Craft::$app->getRequest()->getParam('dlt', '');
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

        $signal = RequestSignals::classifyCurrentRequest($settings->normalizedCrawlerUserAgents());

        // Refuse before the login check: there's no sense bouncing a crawler we're
        // about to turn away through a login round-trip first.
        if ($settings->shouldBlock($signal)) {
            Craft::info('Refused a download request from a crawler.', __METHOD__);

            return $this->_forbidden();
        }

        if ($settings->requireLoginToServe) {
            $this->requireLogin();
        }

        $asset = Craft::$app->getAssets()->getAssetById($assetId);

        if ($asset === null) {
            throw new NotFoundHttpException('File not found.');
        }

        // Count first, so the download is recorded even if streaming is aborted.
        // A prefetch is still served - it's a real browser getting ready for a
        // real click - it just isn't counted, so the click that follows counts
        // once rather than twice.
        if ($settings->shouldCount($signal)) {
            $plugin->downloads->increment($asset, [], false, $signal === RequestSignals::SIGNAL_CRAWLER);
        }

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
     * Returns an empty 403, for crawler requests under the 'block' crawler mode.
     *
     * A bare response rather than a ForbiddenHttpException, for the same reason
     * the missing-token case answers 404: a crawler meeting a door that's shut to
     * it is routine, not an application fault, and shouldn't fill an error tracker.
     *
     * @return Response
     */
    private function _forbidden(): Response
    {
        $response = Craft::$app->getResponse();
        $response->setStatusCode(403);
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
