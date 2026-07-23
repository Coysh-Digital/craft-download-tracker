<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\widgets;

use Craft;
use coyshdigital\downloadtracker\Plugin;
use craft\base\Widget;
use craft\helpers\DateTimeHelper;

/**
 * A dashboard widget listing the most-downloaded files.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class TopDownloads extends Widget
{
    // Public Properties
    // =========================================================================

    /**
     * @var int How many files to list.
     */
    public int $limit = 5;

    /**
     * @var int Only count downloads from the last N days (0 = all time).
     */
    public int $days = 0;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('download-tracker', 'Top Downloads');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return Craft::getAlias('@coyshdigital/downloadtracker/icon.svg');
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        return parent::isSelectable()
            && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW_REPORTS);
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getTitle(): ?string
    {
        if ($this->days > 0) {
            return Craft::t('download-tracker', 'Top Downloads ({days}d)', ['days' => $this->days]);
        }

        return Craft::t('download-tracker', 'Top Downloads');
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        $criteria = [];

        if ($this->days > 0) {
            $criteria['dateFrom'] = DateTimeHelper::currentUTCDateTime()
                ->modify("-$this->days days")
                ->format('Y-m-d');
        }

        $rows = Plugin::getInstance()->downloads->topDownloads($this->limit, $criteria);

        return Craft::$app->getView()->renderTemplate('download-tracker/_widgets/top-downloads', [
            'rows' => $rows,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('download-tracker/_widgets/top-downloads-settings', [
            'widget' => $this,
        ]);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit', 'days'], 'integer', 'min' => 0];
        $rules[] = [['limit'], 'required'];

        return $rules;
    }
}
