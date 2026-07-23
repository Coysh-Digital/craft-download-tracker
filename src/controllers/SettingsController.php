<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\controllers;

use Craft;
use coyshdigital\downloadtracker\models\Settings;
use coyshdigital\downloadtracker\Plugin;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Manages the plugin settings.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class SettingsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException if the user is not an admin.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Require an admin, but not that admin changes are allowed - admins may
        // still *view* the settings on environments where allowAdminChanges is
        // off; the page renders read-only and actionSave() blocks any writes.
        $this->requireAdmin(false);

        return true;
    }

    /**
     * Shows the settings form.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $overrides = array_keys(Craft::$app->getConfig()->getConfigFromFile('download-tracker'));

        // A config file still using the setting crawlerMode replaced overrides
        // crawlerMode in practice, so show that field as locked too.
        if (in_array('ignorePrefetchAndBots', $overrides, true)) {
            $overrides[] = 'crawlerMode';
        }

        return $this->renderTemplate('download-tracker/settings/index', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'serveModeOptions' => [
                Settings::SERVE_MODE_AUTO => Craft::t('download-tracker', 'Auto (redirect public, stream private)'),
                Settings::SERVE_MODE_REDIRECT => Craft::t('download-tracker', 'Always redirect'),
                Settings::SERVE_MODE_STREAM => Craft::t('download-tracker', 'Always stream'),
            ],
            'crawlerModeOptions' => [
                Settings::CRAWLER_MODE_SEPARATE => Craft::t('download-tracker', 'Count separately'),
                Settings::CRAWLER_MODE_IGNORE => Craft::t('download-tracker', 'Don’t count'),
                Settings::CRAWLER_MODE_BLOCK => Craft::t('download-tracker', 'Block with a 403'),
            ],
            'overrides' => $overrides,
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    /**
     * Saves the settings.
     *
     * @return Response|null
     * @throws ForbiddenHttpException if admin changes are disabled.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Settings are read-only when admin changes are disabled. Edit them in project config or via config/download-tracker.php.');
        }

        $plugin = Plugin::getInstance();
        $settings = (array)Craft::$app->getRequest()->getBodyParam('settings', []);

        // Textarea/multiline fields arrive as strings; store them as arrays.
        foreach (['trackedPathPrefixes', 'trackedExtensions', 'excludedHosts', 'crawlerUserAgents'] as $key) {
            if (isset($settings[$key]) && is_string($settings[$key])) {
                $settings[$key] = $this->_lines($settings[$key]);
            }
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            Craft::$app->getSession()->setError(Craft::t('download-tracker', 'Couldn’t save settings.'));
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $plugin->getSettings(),
            ]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('download-tracker', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    // Private Methods
    // =========================================================================

    /**
     * Splits a textarea value into a trimmed, non-empty list of lines.
     *
     * @param string $value
     * @return string[]
     */
    private function _lines(string $value): array
    {
        $lines = preg_split('/[\r\n]+/', $value) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn($line) => $line !== ''));
    }
}
