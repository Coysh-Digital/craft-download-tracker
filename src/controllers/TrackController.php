<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\controllers;

use Craft;
use coyshdigital\downloadtracker\helpers\RequestSignals;
use coyshdigital\downloadtracker\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * The public tracking beacon. Hit by the front-end click listener to count a
 * download without serving the file.
 *
 * This is an action request, which Blitz never caches, so it always runs even
 * when the page it fired from was served from the static cache.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class TrackController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     *
     * The beacon only increments a bounded, server-validated counter - there's
     * no per-user state to protect - so CSRF validation is unnecessary and would
     * only add friction to a fire-and-forget `navigator.sendBeacon()` call.
     */
    public $enableCsrfValidation = false;

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected int|bool|array $allowAnonymous = ['hit'];

    // Public Methods
    // =========================================================================

    /**
     * Records one download and returns an empty `204` response.
     *
     * @return Response
     */
    public function actionHit(): Response
    {
        $request = Craft::$app->getRequest();
        $settings = Plugin::getInstance()->getSettings();

        $signal = RequestSignals::classifyCurrentRequest($settings->normalizedCrawlerUserAgents());

        // The beacon serves nothing, so there's nothing to refuse: a crawler that
        // would be blocked on the served route just gets the same empty 204 as
        // everyone else, and counts for nothing.
        if (!$settings->shouldCount($signal)) {
            return $this->_noContent();
        }

        $isCrawler = $signal === RequestSignals::SIGNAL_CRAWLER;

        $id = $request->getParam('id');
        $url = $request->getParam('url');
        $path = $request->getParam('path');
        $downloadFlag = (bool)$request->getParam('dl');

        $file = null;
        if (is_scalar($id) && ctype_digit((string)$id)) {
            $file = (int)$id;
        } elseif (is_string($url) && $url !== '') {
            $file = $url;
        } elseif (is_string($path) && $path !== '') {
            $file = $path;
        }

        if ($file !== null) {
            try {
                Plugin::getInstance()->downloads->increment($file, [], $downloadFlag, $isCrawler);
            } catch (\Throwable $e) {
                // Never let a transient DB hiccup turn a fire-and-forget beacon
                // into a 500; just log it and return no content.
                Craft::error('Download tracking failed: ' . $e->getMessage(), __METHOD__);
            }
        }

        return $this->_noContent();
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds an empty `204 No Content` response.
     *
     * @return Response
     */
    private function _noContent(): Response
    {
        $response = Craft::$app->getResponse();
        $response->setStatusCode(204);
        $response->format = Response::FORMAT_RAW;
        $response->content = '';

        return $response;
    }
}
