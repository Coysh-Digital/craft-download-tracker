<?php
/**
 * Download Tracker plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\console\controllers;

use coyshdigital\downloadtracker\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Maintenance commands for the download counters.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class CountsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Prunes per-day rollup rows older than the configured retention window.
     *
     * @return int
     */
    public function actionPrune(): int
    {
        $retentionDays = Plugin::getInstance()->getSettings()->dailyRetentionDays;
        $deleted = Plugin::getInstance()->downloads->pruneDaily($retentionDays);

        $this->stdout("Pruned $deleted daily rollup row(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
