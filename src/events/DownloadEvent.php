<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\events;

use craft\elements\Asset;
use yii\base\Event;

/**
 * Fired before a download is counted. Set {@see $isValid} to `false` to stop the
 * download being recorded.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class DownloadEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The file's canonical identity (`asset:123`, `path:…`, `url:…`).
     */
    public string $downloadKey;

    /**
     * @var int|null The related asset ID, when resolvable.
     */
    public ?int $assetId = null;

    /**
     * @var Asset|null The related asset, when resolvable.
     */
    public ?Asset $asset = null;

    /**
     * @var string One of `asset`, `path`, `url`.
     */
    public string $sourceType;

    /**
     * @var string The file's display name.
     */
    public string $filename;

    /**
     * @var bool Whether the hit came from a crawler rather than a person.
     */
    public bool $isCrawler = false;

    /**
     * @var bool Whether the download should be counted. Set to `false` to skip.
     */
    public bool $isValid = true;
}
