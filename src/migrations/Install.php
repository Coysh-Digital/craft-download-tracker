<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\migrations;

use craft\db\Migration;

/**
 * Install migration.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Install extends Migration
{
    // Constants
    // =========================================================================

    /**
     * @var string The per-file counter table.
     */
    public const COUNTS = '{{%downloadtracker_counts}}';

    /**
     * @var string The per-file, per-day rollup table.
     */
    public const DAILY = '{{%downloadtracker_daily}}';

    /**
     * @var string The saved-reports table.
     */
    public const REPORTS = '{{%downloadtracker_reports}}';

    /**
     * @var string The one-row-per-source import bookkeeping table.
     */
    public const IMPORTS = '{{%downloadtracker_imports}}';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->_createTables();
        $this->_createIndexes();
        $this->_addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::DAILY);
        $this->dropTableIfExists(self::COUNTS);
        $this->dropTableIfExists(self::REPORTS);
        $this->dropTableIfExists(self::IMPORTS);

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the plugin's tables.
     *
     * @return void
     */
    private function _createTables(): void
    {
        $this->createTable(self::COUNTS, [
            // Big PK: `INSERT … ON DUPLICATE KEY UPDATE` consumes an auto-increment
            // value per download event, not per file, so this grows fast.
            'id' => $this->bigPrimaryKey(),
            'downloadKey' => $this->string()->notNull(),
            'assetId' => $this->integer(),
            'sourceType' => $this->string(16)->notNull(),
            'source' => $this->text(),
            'filename' => $this->string()->notNull(),
            // The total, people and crawlers alike.
            'count' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
            // The portion of `count` that came from crawlers.
            'crawlerCount' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
            'lastDownloaded' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(self::DAILY, [
            'id' => $this->bigPrimaryKey(),
            'downloadKey' => $this->string()->notNull(),
            'date' => $this->date()->notNull(),
            'count' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'crawlerCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(self::REPORTS, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'criteria' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Bookkeeping for one-way imports from other download plugins. One row
        // per source, holding the high-water mark that makes a re-run a no-op
        // and an incremental top-up cheap.
        $this->createTable(self::IMPORTS, [
            'id' => $this->primaryKey(),
            'source' => $this->string(32)->notNull(),
            'lastRowId' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
            'rowsImported' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
            'filesTouched' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'rowsSkipped' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
            'dateFinished' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    /**
     * Creates indexes on the plugin's tables.
     *
     * @return void
     */
    private function _createIndexes(): void
    {
        // The counter identity: one row per file.
        $this->createIndex(null, self::COUNTS, ['downloadKey'], true);
        $this->createIndex(null, self::COUNTS, ['assetId']);
        // Supports "top downloads" ordering.
        $this->createIndex(null, self::COUNTS, ['count']);

        // The rollup identity: one row per file per day.
        $this->createIndex(null, self::DAILY, ['downloadKey', 'date'], true);
        // Supports date-range reports and retention pruning.
        $this->createIndex(null, self::DAILY, ['date']);

        // One row per import source.
        $this->createIndex(null, self::IMPORTS, ['source'], true);
    }

    /**
     * Adds foreign keys to the plugin's tables.
     *
     * @return void
     */
    private function _addForeignKeys(): void
    {
        // Keep the counter row if its asset is deleted; assetId simply nulls out.
        $this->addForeignKey(null, self::COUNTS, ['assetId'], '{{%elements}}', ['id'], 'SET NULL');
    }
}
