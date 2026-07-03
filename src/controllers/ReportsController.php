<?php
/**
 * Download Tracker plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\controllers;

use Craft;
use coyshdigital\downloadtracker\Plugin;
use coyshdigital\downloadtracker\records\ReportRecord;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The control-panel saved reports.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class ReportsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException if the user lacks the required permission.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Viewing/running needs the view permission; editing needs more.
        if (in_array($action->id, ['edit', 'save', 'delete'], true)) {
            $this->requirePermission(Plugin::PERMISSION_MANAGE_REPORTS);
        } else {
            $this->requirePermission(Plugin::PERMISSION_VIEW_REPORTS);
        }

        return true;
    }

    /**
     * Lists saved reports.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('download-tracker/reports/index', [
            'reports' => Plugin::getInstance()->reports->getAllReports(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_REPORTS),
        ]);
    }

    /**
     * Shows the report edit form.
     *
     * @param int|null $reportId
     * @param ReportRecord|null $report
     * @return Response
     * @throws NotFoundHttpException if editing a report that doesn't exist.
     */
    public function actionEdit(?int $reportId = null, ?ReportRecord $report = null): Response
    {
        $reports = Plugin::getInstance()->reports;

        if ($report === null) {
            if ($reportId !== null) {
                $report = $reports->getReportById($reportId);
                if ($report === null) {
                    throw new NotFoundHttpException('Report not found.');
                }
            } else {
                $report = new ReportRecord();
            }
        }

        $isNew = !$report->id;

        return $this->renderTemplate('download-tracker/reports/_edit', [
            'report' => $report,
            'criteria' => $reports->getCriteria($report),
            'isNew' => $isNew,
            'title' => $isNew
                ? Craft::t('download-tracker', 'New report')
                : $report->name,
        ]);
    }

    /**
     * Saves a report.
     *
     * @return Response|null
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $reports = Plugin::getInstance()->reports;
        $request = Craft::$app->getRequest();

        $id = $request->getBodyParam('reportId');
        $report = $id ? $reports->getReportById((int)$id) : null;
        $report ??= new ReportRecord();

        $report->name = trim((string)$request->getBodyParam('name'));
        $report->criteria = Json::encode($this->_criteriaFromRequest());

        if ($report->name === '') {
            $report->addError('name', Craft::t('download-tracker', 'A name is required.'));
        }

        if ($report->hasErrors() || !$reports->saveReport($report)) {
            Craft::$app->getSession()->setError(Craft::t('download-tracker', 'Couldn’t save report.'));
            Craft::$app->getUrlManager()->setRouteParams(['report' => $report]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('download-tracker', 'Report saved.'));

        return $this->redirectToPostedUrl($report);
    }

    /**
     * Deletes a report.
     *
     * @return Response
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');

        if (Plugin::getInstance()->reports->deleteReportById($id)) {
            Craft::$app->getSession()->setNotice(Craft::t('download-tracker', 'Report deleted.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('download-tracker', 'Couldn’t delete report.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Runs a saved report and shows its results.
     *
     * @param int $reportId
     * @return Response
     * @throws NotFoundHttpException if the report doesn't exist.
     */
    public function actionRun(int $reportId): Response
    {
        $plugin = Plugin::getInstance();
        $report = $plugin->reports->getReportById($reportId);

        if ($report === null) {
            throw new NotFoundHttpException('Report not found.');
        }

        $criteria = $plugin->reports->getCriteria($report);
        $criteria['limit'] = 500;

        return $this->renderTemplate('download-tracker/reports/_run', [
            'report' => $report,
            'rows' => $plugin->downloads->query($criteria),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_REPORTS),
        ]);
    }

    /**
     * Exports a saved report as CSV.
     *
     * @param int $reportId
     * @return Response
     * @throws NotFoundHttpException if the report doesn't exist.
     */
    public function actionExport(int $reportId): Response
    {
        $plugin = Plugin::getInstance();
        $report = $plugin->reports->getReportById($reportId);

        if ($report === null) {
            throw new NotFoundHttpException('Report not found.');
        }

        $csv = $plugin->downloads->exportCsv($plugin->reports->getCriteria($report));
        $filename = preg_replace('/[^a-z0-9\-_]+/i', '-', $report->name) . '.csv';

        return Craft::$app->getResponse()->sendContentAsFile($csv, $filename, [
            'mimeType' => 'text/csv',
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds a report's stored criteria from posted input.
     *
     * @return array<string, mixed>
     */
    private function _criteriaFromRequest(): array
    {
        $request = Craft::$app->getRequest();

        $orderBy = (string)$request->getBodyParam('orderBy', 'count');
        if (!in_array($orderBy, ['count', 'lastDownloaded', 'filename'], true)) {
            $orderBy = 'count';
        }

        $sort = strtolower((string)$request->getBodyParam('sort', 'desc')) === 'asc' ? 'asc' : 'desc';

        return [
            'dateFrom' => $this->_normalizeDate($request->getBodyParam('dateFrom')),
            'dateTo' => $this->_normalizeDate($request->getBodyParam('dateTo')),
            'sourceType' => $request->getBodyParam('sourceType') ?: null,
            'search' => trim((string)$request->getBodyParam('search')) ?: null,
            'minCount' => ($min = trim((string)$request->getBodyParam('minCount'))) !== '' ? (int)$min : null,
            'orderBy' => $orderBy,
            'sort' => $sort,
        ];
    }

    /**
     * Normalizes a posted date to a `Y-m-d` string, or `null` if it's absent or
     * malformed.
     *
     * @param mixed $value
     * @return string|null
     */
    private function _normalizeDate(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return null;
        }

        $date = date_create($value);

        return $date ? $date->format('Y-m-d') : null;
    }
}
