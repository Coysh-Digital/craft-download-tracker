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
 * Adds the import bookkeeping table.
 *
 * Imports fold another plugin's per-event log into our counters with additive
 * upserts, which are correct under batching and resumption but would happily
 * double-count a second run. The high-water mark this table holds is what makes
 * a re-run a no-op, and an incremental top-up cheap.
 *
 * @author Coysh Digital
 * @since 1.2.0
 */
class m260717_090000_add_imports_table extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Install::IMPORTS)) {
            return true;
        }

        $this->createTable(Install::IMPORTS, [
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

        $this->createIndex(null, Install::IMPORTS, ['source'], true);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Install::IMPORTS);

        return true;
    }
}
