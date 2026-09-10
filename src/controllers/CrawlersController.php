<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\controllers;

use Craft;
use coyshdigital\downloadtracker\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * The control-panel crawler-list utility: shows the distilled third-party token
 * list's status and refreshes it on demand from the upstream sources.
 *
 * @author Coysh Digital
 * @since 1.5.0
 */
class CrawlersController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * The utility fetches from the internet and writes to the runtime path, so
     * it's admin-only - the same bar as the plugin's settings.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    /**
     * Shows the crawler-list status and the refresh control.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('download-tracker/crawlers/index', [
            'status' => Plugin::getInstance()->crawlers->status(),
            'sourceUrls' => [
                'Kikobeats/top-crawler-agents',
                'monperrus/crawler-user-agents',
                'matomo-org/device-detector',
            ],
        ]);
    }

    /**
     * Refreshes the distilled list from the upstream sources.
     *
     * @return Response|null
     */
    public function actionRefresh(): ?Response
    {
        $this->requirePostRequest();

        try {
            $result = Plugin::getInstance()->crawlers->refresh();

            $failed = array_filter($result['sources'], static fn(array $s): bool => !$s['ok']);

            if ($failed !== []) {
                $this->setFailFlash(Craft::t(
                    'download-tracker',
                    'Refreshed with {ok} of {total} sources reachable - {count} tokens in the list now.',
                    [
                        'ok' => count($result['sources']) - count($failed),
                        'total' => count($result['sources']),
                        'count' => $result['tokens'],
                    ],
                ));
            } else {
                $this->setSuccessFlash(Craft::t(
                    'download-tracker',
                    'Crawler list refreshed: {count} tokens ({added} added, {removed} removed).',
                    [
                        'count' => $result['tokens'],
                        'added' => $result['added'],
                        'removed' => $result['removed'],
                    ],
                ));
            }
        } catch (\Throwable $e) {
            Craft::error('Crawler list refresh failed: ' . $e->getMessage(), __METHOD__);
            $this->setFailFlash(Craft::t(
                'download-tracker',
                'Could not refresh the crawler list: {error}',
                ['error' => $e->getMessage()],
            ));
        }

        return $this->redirectToPostedUrl();
    }
}
