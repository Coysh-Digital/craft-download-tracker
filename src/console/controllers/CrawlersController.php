<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\console\controllers;

use coyshdigital\downloadtracker\Plugin;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Refreshes the distilled third-party crawler token list from the command line,
 * for CI or a scheduled task.
 *
 * @author Coysh Digital
 * @since 1.5.0
 */
class CrawlersController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Downloads the community crawler lists, distils them, and writes the result.
     *
     * @return int
     */
    public function actionRefresh(): int
    {
        try {
            $result = Plugin::getInstance()->crawlers->refresh();
        } catch (\Throwable $e) {
            $this->stderr('Refresh failed: ' . $e->getMessage() . PHP_EOL);

            return ExitCode::UNAVAILABLE;
        }

        foreach ($result['sources'] as $source) {
            $this->stdout(sprintf(
                "  %s  %s (%s)\n",
                $source['ok'] ? '✓' : '✗',
                $source['name'],
                $source['note'],
            ));
        }

        $this->stdout(sprintf(
            "Crawler list: %d tokens (%d added, %d removed).\n",
            $result['tokens'],
            $result['added'],
            $result['removed'],
        ));

        return ExitCode::OK;
    }
}
