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
 * Import record - the state of a one-way import from another download plugin.
 *
 * @property int $id
 * @property string $source The plugin imported from (`linkvault`).
 * @property int $lastRowId The highest source row ID imported so far; a re-run starts above it.
 * @property int $rowsImported How many source rows have been folded into the counters.
 * @property int $filesTouched How many distinct files those rows resolved to.
 * @property int $rowsSkipped Source rows deliberately not imported, so the loss is never silent.
 * @property string|null $dateFinished When the import last ran out of rows to read.
 *
 * @author Coysh Digital
 * @since 1.2.0
 */
class ImportRecord extends ActiveRecord
{
    // Constants
    // =========================================================================

    /**
     * @var string The Link Vault import source.
     */
    public const SOURCE_LINK_VAULT = 'linkvault';

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%downloadtracker_imports}}';
    }
}
