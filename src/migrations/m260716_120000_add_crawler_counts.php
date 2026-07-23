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
 * Splits crawler downloads out of the running totals.
 *
 * `count` keeps meaning the total, so every existing query, report and export
 * carries on working untouched; `crawlerCount` is the portion of it that came
 * from crawlers, making the human total `count - crawlerCount`.
 *
 * @author Coysh Digital
 * @since 1.1.0
 */
class m260716_120000_add_crawler_counts extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Every existing row is backfilled to 0 by the ADD COLUMN itself, which
        // is the truth: before now, a hit was either human or never counted.
        if (!$this->db->columnExists(Install::COUNTS, 'crawlerCount')) {
            $this->addColumn(
                Install::COUNTS,
                'crawlerCount',
                $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->after('count'),
            );
        }

        if (!$this->db->columnExists(Install::DAILY, 'crawlerCount')) {
            $this->addColumn(
                Install::DAILY,
                'crawlerCount',
                $this->integer()->unsigned()->notNull()->defaultValue(0)->after('count'),
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists(Install::COUNTS, 'crawlerCount')) {
            $this->dropColumn(Install::COUNTS, 'crawlerCount');
        }

        if ($this->db->columnExists(Install::DAILY, 'crawlerCount')) {
            $this->dropColumn(Install::DAILY, 'crawlerCount');
        }

        return true;
    }
}
