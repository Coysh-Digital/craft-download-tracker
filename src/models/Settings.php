<?php
/**
 * Download Tracker plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\models;

use craft\base\Model;

/**
 * Download Tracker settings model.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Settings extends Model
{
    // Constants
    // =========================================================================

    /**
     * @var string Serve mode: redirect public assets, stream private ones.
     */
    public const SERVE_MODE_AUTO = 'auto';

    /**
     * @var string Serve mode: always 302-redirect to the asset URL.
     */
    public const SERVE_MODE_REDIRECT = 'redirect';

    /**
     * @var string Serve mode: always stream the file through PHP.
     */
    public const SERVE_MODE_STREAM = 'stream';

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether to inject the click-tracking script on front-end pages.
     */
    public bool $injectTrackingScript = true;

    /**
     * @var string[] URL path prefixes whose links the beacon should track.
     */
    public array $trackedPathPrefixes = [];

    /**
     * @var string[] File extensions (lower-case, no dot) treated as downloads.
     */
    public array $trackedExtensions = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip', 'csv', 'txt', 'rtf', 'odt', 'ods', 'odp',
        'mp3', 'wav', 'epub', 'mobi',
    ];

    /**
     * @var bool Whether to track any link carrying a `download` attribute.
     */
    public bool $trackDownloadAttr = true;

    /**
     * @var string[] Hosts to ignore (e.g. an image CDN).
     */
    public array $excludedHosts = [];

    /**
     * @var string How the served-download route delivers files.
     */
    public string $serveMode = self::SERVE_MODE_AUTO;

    /**
     * @var bool Whether to force a "Save as…" download on the served route.
     */
    public bool $forceDownload = false;

    /**
     * @var bool Whether to ignore prefetch/prerender and obvious bot requests.
     */
    public bool $ignorePrefetchAndBots = true;

    /**
     * @var bool Whether the served-download route requires a logged-in user.
     */
    public bool $requireLoginToServe = false;

    /**
     * @var bool Whether to also track links that don't resolve to a Craft asset
     * (arbitrary same-site paths under a tracked prefix, or remote URLs). Off by
     * default so a public beacon can only ever touch counters for real assets -
     * this keeps the counter table bounded.
     */
    public bool $trackUnresolvedFiles = false;

    /**
     * @var int Lifetime, in seconds, of a signed served-download URL (0 = never
     * expires). Leave at 0 when links are embedded in statically-cached pages;
     * set a value when links are only rendered on uncached (e.g. gated) pages.
     */
    public int $signedUrlTtl = 0;

    /**
     * @var int How many days of per-day rollup rows are kept before pruning.
     */
    public int $dailyRetentionDays = 365;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['injectTrackingScript', 'trackDownloadAttr', 'forceDownload', 'ignorePrefetchAndBots', 'requireLoginToServe', 'trackUnresolvedFiles'], 'boolean'];
        $rules[] = [['trackedPathPrefixes', 'trackedExtensions', 'excludedHosts'], 'safe'];
        $rules[] = [['serveMode'], 'in', 'range' => [self::SERVE_MODE_AUTO, self::SERVE_MODE_REDIRECT, self::SERVE_MODE_STREAM]];
        $rules[] = [['dailyRetentionDays', 'signedUrlTtl'], 'integer', 'min' => 0];
        $rules[] = [['serveMode', 'dailyRetentionDays'], 'required'];

        return $rules;
    }

    /**
     * Normalizes the tracked extensions to a lower-case, dot-free list.
     *
     * @return string[]
     */
    public function normalizedExtensions(): array
    {
        $extensions = [];
        foreach ($this->trackedExtensions as $extension) {
            $extension = strtolower(trim((string)$extension, " \t\n\r\0\x0B."));
            if ($extension !== '') {
                $extensions[] = $extension;
            }
        }

        return array_values(array_unique($extensions));
    }
}
