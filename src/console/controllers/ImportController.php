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
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * One-way imports from other download plugins.
 *
 * The control panel runs the same import through a queue job. This is here for
 * histories big enough that watching it over SSH beats trusting a web queue
 * runner with an hour of work.
 *
 * @author Coysh Digital
 * @since 1.2.0
 */
class ImportController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether to report what would be imported and then stop.
     */
    public bool $dryRun = false;

    /**
     * @var int How many Link Vault row IDs to process per window.
     */
    public int $batchSize = 25000;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun', 'batchSize']);
    }

    /**
     * Imports Link Vault's download history into the counters.
     *
     * Safe to re-run: it resumes from where it stopped rather than counting
     * anything twice. Run it before uninstalling Link Vault - it reads Link
     * Vault's tables, and can't do anything once they're gone.
     *
     * @return int
     */
    public function actionLinkVault(): int
    {
        $import = Plugin::getInstance()->linkVaultImport;

        if (!$import->isAvailable()) {
            $this->stderr("Link Vault isn't installed, so there's nothing to import.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $preview = $import->getPreview();

        $this->stdout("Downloads to import: {$preview['pending']}\n");
        $this->stdout("Files they belong to: up to {$preview['files']}\n");
        $this->stdout("Leech attempts to skip: {$preview['leech']}\n");
        $this->stdout("Deleted entries to skip: {$preview['deleted']}\n");

        // Say this before the work starts, not after: it can't be backfilled
        // once Link Vault is gone.
        if ($preview['dailyCutoff'] === null) {
            $this->stdout("Day-by-day history: all of it (retention is set to keep forever).\n");
        } else {
            $this->stdout(
                "Day-by-day history: only from {$preview['dailyCutoff']} onward, per your"
                    . " {$preview['retentionDays']}-day retention setting. Older downloads still count"
                    . " toward each file's total. Set retention to 0 first to keep the lot - it can't"
                    . " be filled in after Link Vault is uninstalled.\n",
                Console::FG_YELLOW,
            );
        }

        if ($this->dryRun) {
            $this->stdout("Dry run, so nothing was imported.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if ($preview['pending'] === 0) {
            $this->stdout("Nothing left to import.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $lowId = $preview['lastRowId'];
        // Pinned up front so downloads logged mid-import don't move the finish
        // line; a later run picks those up.
        $maxId = $preview['maxRowId'];
        $imported = 0;
        $skipped = 0;

        Console::startProgress(0, $preview['pending']);

        while ($lowId < $maxId) {
            $highId = min($lowId + $this->batchSize, $maxId);
            $result = $import->importBatch($lowId, $highId);

            $lowId = $highId;
            $imported += $result['rows'];
            $skipped += $result['skipped'];

            Console::updateProgress(min($imported, $preview['pending']), $preview['pending']);
        }

        Console::endProgress();
        $import->markFinished();

        $this->stdout("Imported $imported download(s).\n", Console::FG_GREEN);

        if ($skipped > 0) {
            $this->stdout("Skipped $skipped download(s) whose file couldn't be identified.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }
}
