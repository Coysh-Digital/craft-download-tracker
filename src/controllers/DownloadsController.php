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
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The control-panel Downloads list + CSV export.
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
        if (!in_array($orderBy, ['count', 'lastDownloaded', 'filename'], true)) {
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
}
