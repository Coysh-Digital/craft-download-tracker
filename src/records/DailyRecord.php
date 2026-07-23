<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\records;

use craft\db\ActiveRecord;

/**
 * Daily record - a file's download count for a single day.
 *
 * @property int $id
 * @property string $downloadKey The file's canonical identity.
 * @property string $date The rollup day (Y-m-d).
 * @property int $count The number of downloads on that day, people and crawlers alike.
 * @property int $crawlerCount The portion of `count` that came from crawlers.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class DailyRecord extends ActiveRecord
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%downloadtracker_daily}}';
    }
}
