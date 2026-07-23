<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker;

use Craft;
use coyshdigital\downloadtracker\models\Settings;
use coyshdigital\downloadtracker\services\Downloads;
use coyshdigital\downloadtracker\services\LinkVaultImport;
use coyshdigital\downloadtracker\services\Reports;
use coyshdigital\downloadtracker\variables\DownloadTrackerVariable;
use coyshdigital\downloadtracker\widgets\TopDownloads;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

/**
 * Download Tracker plugin.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Downloads $downloads
 * @property-read Reports $reports
 * @property-read LinkVaultImport $linkVaultImport
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Plugin extends BasePlugin
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission for viewing download stats and reports.
     */
    public const PERMISSION_VIEW_REPORTS = 'download-tracker:viewReports';

    /**
     * @var string The permission for creating and editing saved reports.
     */
    public const PERMISSION_MANAGE_REPORTS = 'download-tracker:manageReports';

    // Static Properties
    // =========================================================================

    /**
     * @var Plugin|null
     */
    public static ?Plugin $plugin = null;

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.2.0';

    // Private Properties
    // =========================================================================

    /**
     * @var string|null Cached contents of the front-end beacon script.
     */
    private ?string $_beaconJs = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'coyshdigital\\downloadtracker\\console\\controllers';
        }

        $this->_registerVariable();
        $this->_registerCpRoutes();
        $this->_registerPermissions();
        $this->_registerWidgets();
        $this->_registerGarbageCollection();
        $this->_registerFrontEndTracking();

        Craft::info(
            Craft::t('download-tracker', '{name} plugin loaded', ['name' => $this->name]),
            __METHOD__
        );
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser();

        if (!$user->getIsAdmin() && !$user->checkPermission(self::PERMISSION_VIEW_REPORTS)) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['subnav'] = [];

        if ($user->checkPermission(self::PERMISSION_VIEW_REPORTS)) {
            $item['subnav']['downloads'] = [
                'label' => Craft::t('download-tracker', 'Downloads'),
                'url' => 'download-tracker',
            ];
            $item['subnav']['reports'] = [
                'label' => Craft::t('download-tracker', 'Reports'),
                'url' => 'download-tracker/reports',
            ];
        }

        if ($user->getIsAdmin()) {
            // Only while there's something to import from. It's a one-afternoon
            // task on the way off another plugin, so it shouldn't be permanent
            // furniture in the nav of every site that never used Link Vault.
            if ($this->linkVaultImport->isAvailable()) {
                $item['subnav']['import'] = [
                    'label' => Craft::t('download-tracker', 'Import'),
                    'url' => 'download-tracker/import',
                ];
            }

            $item['subnav']['settings'] = [
                'label' => Craft::t('download-tracker', 'Settings'),
                'url' => 'download-tracker/settings',
            ];
        }

        return $item;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect('download-tracker/settings');
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers the `craft.downloadTracker` Twig variable.
     *
     * @return void
     */
    private function _registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('downloadTracker', DownloadTrackerVariable::class);
            }
        );
    }

    /**
     * Registers the plugin's control panel routes.
     *
     * @return void
     */
    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['download-tracker'] = 'download-tracker/downloads/index';
                $event->rules['download-tracker/downloads'] = 'download-tracker/downloads/index';
                // Routed on the counter row's ID rather than its downloadKey: the
                // keys carry a colon, which would collide with the rule syntax and
                // leak their shape into bookmarkable URLs.
                $event->rules['download-tracker/downloads/<countId:\d+>'] = 'download-tracker/downloads/detail';

                $event->rules['download-tracker/reports'] = 'download-tracker/reports/index';
                $event->rules['download-tracker/reports/new'] = 'download-tracker/reports/edit';
                $event->rules['download-tracker/reports/<reportId:\d+>'] = 'download-tracker/reports/edit';
                $event->rules['download-tracker/reports/<reportId:\d+>/run'] = 'download-tracker/reports/run';

                $event->rules['download-tracker/import'] = 'download-tracker/import/index';

                $event->rules['download-tracker/settings'] = 'download-tracker/settings/index';
            }
        );
    }

    /**
     * Registers the plugin's user permissions.
     *
     * @return void
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('download-tracker', 'Download Tracker'),
                    'permissions' => [
                        self::PERMISSION_VIEW_REPORTS => [
                            'label' => Craft::t('download-tracker', 'View download stats and reports'),
                            'nested' => [
                                self::PERMISSION_MANAGE_REPORTS => [
                                    'label' => Craft::t('download-tracker', 'Create and edit saved reports'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Registers the plugin's dashboard widgets.
     *
     * @return void
     */
    private function _registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = TopDownloads::class;
            }
        );
    }

    /**
     * Prunes old per-day rollup rows when Craft runs garbage collection.
     *
     * @return void
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function() {
                Plugin::getInstance()->downloads->pruneDaily($this->getSettings()->dailyRetentionDays);
            }
        );
    }

    /**
     * Injects the zero-touch click-tracking script on front-end pages.
     *
     * The script is inlined into the rendered HTML (rather than registered via
     * the asset pipeline) so it works even on themes that don't render Craft's
     * `head()`/`endBody()` hooks. It's static, so a full-page cache (e.g. Blitz)
     * bakes it into the cached HTML; the runtime beacon it fires hits a
     * non-cached action.
     *
     * @return void
     */
    private function _registerFrontEndTracking(): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                if (!$this->getSettings()->injectTrackingScript) {
                    return;
                }

                $pos = strripos($event->output, '</body>');

                if ($pos === false) {
                    return;
                }

                $script = $this->_trackingScript();

                if ($script === '') {
                    return;
                }

                $event->output = substr($event->output, 0, $pos) . $script . substr($event->output, $pos);
            }
        );
    }

    /**
     * Builds the inline `<script>` that configures and runs the click beacon.
     *
     * @return string
     */
    private function _trackingScript(): string
    {
        $js = $this->_beaconJs();

        if ($js === '') {
            return '';
        }

        $settings = $this->getSettings();
        $config = [
            'endpoint' => UrlHelper::actionUrl('download-tracker/track/hit'),
            'prefixes' => array_values($settings->trackedPathPrefixes),
            'extensions' => $settings->normalizedExtensions(),
            'excludeHosts' => array_values($settings->excludedHosts),
            'trackDownloadAttr' => $settings->trackDownloadAttr,
        ];

        return '<script>window.DownloadTracker=' . Json::encode($config) . ";\n" . $js . '</script>';
    }

    /**
     * Returns the (cached) contents of the beacon script.
     *
     * @return string
     */
    private function _beaconJs(): string
    {
        if ($this->_beaconJs !== null) {
            return $this->_beaconJs;
        }

        $path = Craft::getAlias('@coyshdigital/downloadtracker/web/assets/tracker/dist/download-tracker.js');
        $js = (is_string($path) && is_file($path)) ? (string)file_get_contents($path) : '';

        return $this->_beaconJs = $js;
    }
}
