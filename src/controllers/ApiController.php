<?php

declare(strict_types=1);

namespace coyshdigital\downloadtracker\controllers;

use coyshdigital\downloadtracker\Plugin;
use Craft;
use craft\helpers\App;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Read-only, HMAC-signed reporting API.
 *
 * Lets an external reporting tool pull aggregate download stats over a signed
 * request: an HMAC over method, path, timestamp, nonce and a hash of the
 * (empty) body, with a short timestamp window and a one-shot nonce. Everything
 * returned is aggregate-only — counts and file names, no per-download rows and
 * nothing that identifies a visitor.
 */
class ApiController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    /**
     * A bound on the file list returned, so a report stays small.
     */
    private const LIMIT = 10;

    /**
     * Authenticate every request by signature, timestamp window and nonce.
     *
     * @param \yii\base\Action $action
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();
        $secret = (string) App::parseEnv($settings->reportingConnectionCode);
        $tolerance = $settings->reportingTolerance ?: 300;

        if ($secret === '') {
            throw new ForbiddenHttpException('Reporting API not configured.');
        }

        $timestamp = (string) $this->request->getHeaders()->get('X-CR-Timestamp');
        $nonce = (string) $this->request->getHeaders()->get('X-CR-Nonce');
        $signature = (string) $this->request->getHeaders()->get('X-CR-Signature');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            throw new ForbiddenHttpException('Missing signature.');
        }

        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new ForbiddenHttpException('Request timestamp out of range.');
        }

        $cache = Craft::$app->getCache();
        $nonceKey = 'dt_report_nonce_' . md5($nonce);
        if ($cache?->get($nonceKey)) {
            throw new ForbiddenHttpException('Nonce already used.');
        }

        $path = '/' . ltrim($this->request->getFullPath(), '/');
        $expected = $this->sign('GET', $path, $timestamp, $nonce, '', $secret);

        if (!hash_equals($expected, $signature)) {
            throw new ForbiddenHttpException('Invalid signature.');
        }

        $cache?->set($nonceKey, 1, $tolerance * 2);

        return true;
    }

    /**
     * Compute a request signature over method, path, timestamp, nonce and a
     * hash of the body. The consuming client signs requests the same way.
     */
    private function sign(string $method, string $path, string $timestamp, string $nonce, string $body, string $secret): string
    {
        $payload = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]);

        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * A verify handshake, so a client can confirm the connection.
     */
    public function actionVerify(): Response
    {
        return $this->asJson([
            'ok' => true,
            'connector' => 'download-tracker',
            'version' => Plugin::getInstance()->version,
            'craft_version' => Craft::$app->getVersion(),
        ]);
    }

    /**
     * The period report: total downloads, a daily total series and the most
     * downloaded files.
     */
    public function actionReport(): Response
    {
        $from = (string) ($this->request->getQueryParam('from') ?: date('Y-m-01'));
        $to = (string) ($this->request->getQueryParam('to') ?: date('Y-m-t'));

        $report = Plugin::getInstance()->downloads->reportForPeriod($from, $to, self::LIMIT);

        return $this->asJson([
            'provider' => 'Download Tracker',
            'metrics' => [
                'downloads' => $report['total'],
                'files' => $report['files'],
            ],
            'timeseries' => $report['series'],
            'top_files' => $report['top'],
        ]);
    }
}
