<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\jobs;

use Craft;
use coyshdigital\downloadtracker\Plugin;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use yii\queue\Queue as BaseQueue;

/**
 * Imports Link Vault's download history into the counters, a window at a time.
 *
 * The job walks Link Vault's log by ID range and re-queues itself while rows
 * remain, rather than grinding through the lot in one execution: a site with
 * years of history would otherwise run past the queue's time-to-reserve and be
 * retried from the top forever. Because the underlying upserts are additive and
 * the high-water mark is committed with each window, stopping anywhere is safe -
 * a killed job resumes at the last committed window and re-runs nothing.
 *
 * Progress is carried across the chain so the CP shows one bar advancing to
 * 100%, not a fresh 0% per job.
 *
 * @author Coysh Digital
 * @since 1.2.0
 */
class ImportLinkVault extends BaseJob
{
    // Constants
    // =========================================================================

    /**
     * @var int How long one execution keeps claiming windows before handing over
     *   to a fresh job. Comfortably inside Craft's default 300s time-to-reserve.
     */
    private const TIME_LIMIT = 30;

    // Public Properties
    // =========================================================================

    /**
     * @var int The highest Link Vault row ID already imported.
     */
    public int $lowId = 0;

    /**
     * @var int The row ID to finish at, fixed when the import starts so rows
     *   logged mid-import don't move the finish line.
     */
    public int $maxId = 0;

    /**
     * @var int Events imported so far, across the whole chain.
     */
    public int $imported = 0;

    /**
     * @var int Events skipped so far, across the whole chain.
     */
    public int $skipped = 0;

    /**
     * @var int Total events to import, for the progress bar.
     */
    public int $total = 0;

    /**
     * @var int How many row IDs each window spans.
     */
    public int $batchSize = 25000;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $import = Plugin::getInstance()->linkVaultImport;

        if (!$import->isAvailable()) {
            return;
        }

        $deadline = time() + self::TIME_LIMIT;

        while ($this->lowId < $this->maxId) {
            $highId = min($this->lowId + $this->batchSize, $this->maxId);
            $result = $import->importBatch($this->lowId, $highId);

            $this->lowId = $highId;
            $this->imported += $result['rows'];
            $this->skipped += $result['skipped'];

            $this->setProgress(
                $queue,
                $this->maxId > 0 ? $this->lowId / $this->maxId : 1,
                Craft::t('download-tracker', '{imported} of about {total} downloads imported', [
                    'imported' => Craft::$app->getFormatter()->asDecimal($this->imported),
                    'total' => Craft::$app->getFormatter()->asDecimal(max($this->total, $this->imported)),
                ]),
            );

            if (time() >= $deadline) {
                break;
            }
        }

        if ($this->lowId < $this->maxId) {
            $this->_queueNext($queue);

            return;
        }

        $import->markFinished();
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('download-tracker', 'Importing Link Vault download history');
    }

    // Private Methods
    // =========================================================================

    /**
     * Hands the remaining windows to a fresh job carrying the running counters.
     *
     * @param BaseQueue $queue
     * @return void
     */
    private function _queueNext(BaseQueue $queue): void
    {
        Queue::push(new self([
            'lowId' => $this->lowId,
            'maxId' => $this->maxId,
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'total' => $this->total,
            'batchSize' => $this->batchSize,
        ]), priority: 2048, queue: $queue);
    }
}
