<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\controllers;

use Craft;
use coyshdigital\downloadtracker\Plugin;
use craft\helpers\DateTimeHelper;
use craft\helpers\FileHelper;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The control-panel Downloads list, per-file detail page, and CSV exports.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class DownloadsController extends Controller
{
    // Constants
    // =========================================================================

    /**
     * @var int How many rows are shown per page.
     */
    public const PER_PAGE = 50;

    /**
     * @var int[] The day ranges the detail page offers.
     */
    public const RANGE_OPTIONS = [30, 90, 365];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException if the user lacks the view permission.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW_REPORTS);

        return true;
    }

    /**
     * Lists tracked downloads.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $downloads = Plugin::getInstance()->downloads;

        $criteria = $this->_criteriaFromRequest();
        $page = max(1, (int)$request->getParam('page', 1));

        $total = $downloads->queryTotal($criteria);
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        $criteria['limit'] = self::PER_PAGE;
        $criteria['offset'] = ($page - 1) * self::PER_PAGE;
        $rows = $downloads->query($criteria);

        return $this->renderTemplate('download-tracker/downloads/index', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'search' => $request->getParam('search'),
            'orderBy' => $criteria['orderBy'],
            'sort' => $criteria['sort'],
            'showCrawlers' => Plugin::getInstance()->getSettings()->tracksCrawlersSeparately(),
        ]);
    }

    /**
     * Shows one file's totals and day-by-day history.
     *
     * @param int $countId
     * @return Response
     * @throws NotFoundHttpException if there's no counter row with that ID.
     */
    public function actionDetail(int $countId): Response
    {
        $downloads = Plugin::getInstance()->downloads;
        $settings = Plugin::getInstance()->getSettings();
        $record = $downloads->getCountRecordById($countId);

        if ($record === null) {
            throw new NotFoundHttpException('File not found.');
        }

        $days = $this->_daysFromRequest();
        [$dateFrom, $dateTo] = $this->_range($days);
        $series = $downloads->dailySeries($record->downloadKey, $dateFrom, $dateTo);

        $total = (int)$record->count;
        $crawlerTotal = (int)$record->crawlerCount;
        $counts = array_column($series, 'count');

        return $this->renderTemplate('download-tracker/downloads/_detail', [
            'record' => $record,
            'total' => $total,
            'crawlerTotal' => $crawlerTotal,
            'userTotal' => max(0, $total - $crawlerTotal),
            'series' => $series,
            'rangeTotal' => array_sum($counts),
            'rangeUserTotal' => array_sum(array_column($series, 'userCount')),
            'rangeCrawlerTotal' => array_sum(array_column($series, 'crawlerCount')),
            // Floored at 1 so a range with no downloads can't divide by zero.
            'peak' => max(1, (int)max($counts ?: [0])),
            'days' => $days,
            'rangeOptions' => self::RANGE_OPTIONS,
            'showCrawlers' => $settings->tracksCrawlersSeparately(),
            'retentionDays' => $settings->dailyRetentionDays,
        ]);
    }

    /**
     * Exports one file's day-by-day history as CSV.
     *
     * @return Response
     * @throws NotFoundHttpException if there's no counter row with that ID.
     */
    public function actionExportDaily(): Response
    {
        $downloads = Plugin::getInstance()->downloads;
        $countId = (int)Craft::$app->getRequest()->getParam('countId');
        $record = $downloads->getCountRecordById($countId);

        if ($record === null) {
            throw new NotFoundHttpException('File not found.');
        }

        [$dateFrom, $dateTo] = $this->_range($this->_daysFromRequest());
        $csv = $downloads->exportDailyCsv($record->downloadKey, $dateFrom, $dateTo);

        // The file name reaches this from a tracked link, so it can't go into a
        // Content-Disposition header as it stands.
        $filename = FileHelper::sanitizeFilename(
            pathinfo($record->filename, PATHINFO_FILENAME) . '-daily.csv',
            ['asciiOnly' => true],
        );

        return Craft::$app->getResponse()->sendContentAsFile($csv, $filename, [
            'mimeType' => 'text/csv',
        ]);
    }

    /**
     * Exports the current list as CSV.
     *
     * @return Response
     */
    public function actionExport(): Response
    {
        $csv = Plugin::getInstance()->downloads->exportCsv($this->_criteriaFromRequest());

        return Craft::$app->getResponse()->sendContentAsFile($csv, 'downloads.csv', [
            'mimeType' => 'text/csv',
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds query criteria from the request.
     *
     * @return array<string, mixed>
     */
    private function _criteriaFromRequest(): array
    {
        $request = Craft::$app->getRequest();

        $orderBy = (string)$request->getParam('orderBy', 'count');
        if (!in_array($orderBy, ['count', 'crawlerCount', 'userCount', 'lastDownloaded', 'filename'], true)) {
            $orderBy = 'count';
        }

        $sort = strtolower((string)$request->getParam('sort', 'desc')) === 'asc' ? 'asc' : 'desc';

        return [
            'search' => $request->getParam('search') ?: null,
            'sourceType' => $request->getParam('sourceType') ?: null,
            'orderBy' => $orderBy,
            'sort' => $sort,
        ];
    }

    /**
     * Returns the requested day range, held to one of the offered options.
     *
     * @return int
     */
    private function _daysFromRequest(): int
    {
        $days = (int)Craft::$app->getRequest()->getParam('days', self::RANGE_OPTIONS[0]);

        return in_array($days, self::RANGE_OPTIONS, true) ? $days : self::RANGE_OPTIONS[0];
    }

    /**
     * Returns the `[from, to]` Y-m-d bounds of a day range ending today.
     *
     * @param int $days
     * @return array{string, string}
     */
    private function _range(int $days): array
    {
        $today = DateTimeHelper::currentUTCDateTime();
        // Take the start date off a copy: `modify()` mutates the DateTime and
        // hands back the same instance, so modifying `$today` in place would
        // leave both bounds on the start date and collapse the range to a
        // single day.
        $from = (clone $today)->modify('-' . ($days - 1) . ' days');

        return [
            $from->format('Y-m-d'),
            $today->format('Y-m-d'),
        ];
    }
}
