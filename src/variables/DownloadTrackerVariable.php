<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\variables;

use coyshdigital\downloadtracker\Plugin;
use coyshdigital\downloadtracker\records\CountRecord;
use craft\elements\Asset;
use craft\helpers\UrlHelper;

/**
 * The `craft.downloadTracker` Twig variable.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class DownloadTrackerVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Returns a signed, counting download URL for an asset.
     *
     * Use this for gated, private, forced ("Save as…"), or remote downloads that
     * you want counted server-side. For ordinary public links, the zero-touch
     * click beacon already tracks them - no need to change the URL.
     *
     * @param Asset $asset
     * @param array<string, mixed> $params Extra query params to append.
     * @return string
     */
    public function url(Asset $asset, array $params = []): string
    {
        // Note: the param is deliberately not named `token` - that's Craft's
        // reserved route-token param (generalConfig->tokenParam). On Craft 5.9+,
        // an unrecognised `?token=` makes Craft throw a 400 before this plugin's
        // controller ever runs, which would break every managed download link.
        $params['dlt'] = Plugin::getInstance()->downloads->signAsset((int)$asset->id);

        return UrlHelper::actionUrl('download-tracker/download', $params);
    }

    /**
     * Returns the running download total for a file: people and crawlers alike.
     *
     * @param Asset|int|string $file An asset, an asset ID, or a URL/path string.
     * @return int
     */
    public function total(Asset|int|string $file): int
    {
        return Plugin::getInstance()->downloads->getTotal($file);
    }

    /**
     * Returns the human download total for a file: everything bar the crawlers.
     *
     * @param Asset|int|string $file
     * @return int
     */
    public function userTotal(Asset|int|string $file): int
    {
        return Plugin::getInstance()->downloads->getUserTotal($file);
    }

    /**
     * Returns the crawler download total for a file.
     *
     * @param Asset|int|string $file
     * @return int
     */
    public function crawlerTotal(Asset|int|string $file): int
    {
        return Plugin::getInstance()->downloads->getCrawlerTotal($file);
    }

    /**
     * Returns a file's day-by-day history for the last N days, one entry per day
     * (days with no downloads come back as zeroes).
     *
     * @param Asset|int|string $file
     * @param int $days
     * @return array<int, array{date: string, count: int, userCount: int, crawlerCount: int}>
     */
    public function daily(Asset|int|string $file, int $days = 30): array
    {
        return Plugin::getInstance()->downloads->getDaily($file, $days);
    }

    /**
     * Returns the counter record for a file, if one exists.
     *
     * @param Asset|int|string $file
     * @return CountRecord|null
     */
    public function record(Asset|int|string $file): ?CountRecord
    {
        return Plugin::getInstance()->downloads->getCountRecord($file);
    }

    /**
     * Returns the most-downloaded files.
     *
     * @param int $limit
     * @param array<string, mixed> $criteria
     * @return array<int, array<string, mixed>>
     */
    public function top(int $limit = 10, array $criteria = []): array
    {
        return Plugin::getInstance()->downloads->topDownloads($limit, $criteria);
    }
}
