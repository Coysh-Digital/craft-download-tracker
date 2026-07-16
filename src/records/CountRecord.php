<?php
/**
 * Download Tracker plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\records;

use craft\db\ActiveRecord;

/**
 * Count record - the running download total for a single file.
 *
 * @property int $id
 * @property string $downloadKey The canonical identity (`asset:123`, `path:…`, `url:…`).
 * @property int|null $assetId The related asset, when the file is a Craft asset.
 * @property string $sourceType One of `asset`, `path`, `url`.
 * @property string|null $source The raw asset reference, path, or URL, for display.
 * @property string $filename The file's display name.
 * @property int $count The running download total, people and crawlers alike.
 * @property int $crawlerCount The portion of `count` that came from crawlers.
 * @property string|null $lastDownloaded When the file was last downloaded.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class CountRecord extends ActiveRecord
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%downloadtracker_counts}}';
    }
}
