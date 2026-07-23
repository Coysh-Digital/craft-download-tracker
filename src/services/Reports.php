<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\services;

use coyshdigital\downloadtracker\records\ReportRecord;
use craft\helpers\Json;
use yii\base\Component;

/**
 * Reports service - manages saved report criteria.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Reports extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns all saved reports, ordered by name.
     *
     * @return ReportRecord[]
     */
    public function getAllReports(): array
    {
        /** @var ReportRecord[] $reports */
        $reports = ReportRecord::find()
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return $reports;
    }

    /**
     * Returns a saved report by its ID.
     *
     * @param int $id
     * @return ReportRecord|null
     */
    public function getReportById(int $id): ?ReportRecord
    {
        return ReportRecord::findOne($id);
    }

    /**
     * Decodes a report's stored criteria to an array.
     *
     * @param ReportRecord $report
     * @return array<string, mixed>
     */
    public function getCriteria(ReportRecord $report): array
    {
        $criteria = Json::decodeIfJson((string)$report->criteria);

        return is_array($criteria) ? $criteria : [];
    }

    /**
     * Saves a report.
     *
     * @param ReportRecord $report
     * @return bool
     */
    public function saveReport(ReportRecord $report): bool
    {
        return $report->save();
    }

    /**
     * Deletes a report by its ID.
     *
     * @param int $id
     * @return bool
     */
    public function deleteReportById(int $id): bool
    {
        $report = $this->getReportById($id);

        if ($report === null) {
            return false;
        }

        return (bool)$report->delete();
    }
}
