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
 * Report record - a saved set of report criteria.
 *
 * @property int $id
 * @property string $name
 * @property string|null $criteria JSON-encoded filter/sort criteria.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class ReportRecord extends ActiveRecord
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%downloadtracker_reports}}';
    }
}
