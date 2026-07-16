<?php
/**
 * Download Tracker plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\models;

use coyshdigital\downloadtracker\helpers\RequestSignals;
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

    /**
     * @var string Crawler mode: refuse crawler downloads with a 403, count nothing.
     */
    public const CRAWLER_MODE_BLOCK = 'block';

    /**
     * @var string Crawler mode: serve, and count into a separate crawler total.
     */
    public const CRAWLER_MODE_SEPARATE = 'separate';

    /**
     * @var string Crawler mode: serve, but don't count at all.
     */
    public const CRAWLER_MODE_IGNORE = 'ignore';

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
     * @var string How crawler downloads are handled: 'separate' (counted toward
     * the total and toward a crawler total of their own), 'ignore' (not counted),
     * or 'block' (refused with a 403 on the served-download route).
     *
     * Browser prefetch and prerender requests are always served and never counted,
     * whatever this is set to: a prefetch is a real browser preparing for a real
     * click, so it's neither a crawler nor a download.
     */
    public string $crawlerMode = self::CRAWLER_MODE_SEPARATE;

    /**
     * @var string[] Extra User-Agent tokens to treat as crawlers, on top of the
     * built-in list. Matched case-insensitively, as substrings rather than
     * patterns - a regexp here would run on every download.
     */
    public array $crawlerUserAgents = [];

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
     *
     * Maps the 1.0.x `ignorePrefetchAndBots` boolean onto `crawlerMode`, so an
     * existing install keeps behaving exactly as it did after upgrading. Yii
     * discards unknown attributes without a word, so without this the old setting
     * would simply vanish and the site would quietly start counting crawlers.
     *
     * Craft merges project config with config/download-tracker.php and feeds the
     * result through here, so this covers both.
     *
     * @param array<string, mixed>|mixed $values
     * @param bool $safeOnly
     * @return void
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (is_array($values) && array_key_exists('ignorePrefetchAndBots', $values)) {
            $legacy = $values['ignorePrefetchAndBots'];
            unset($values['ignorePrefetchAndBots']);

            // An explicit crawlerMode always wins over the setting it replaced.
            if (!array_key_exists('crawlerMode', $values)) {
                $values['crawlerMode'] = $legacy
                    ? self::CRAWLER_MODE_IGNORE
                    : self::CRAWLER_MODE_SEPARATE;
            }
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['injectTrackingScript', 'trackDownloadAttr', 'forceDownload', 'requireLoginToServe', 'trackUnresolvedFiles'], 'boolean'];
        $rules[] = [['trackedPathPrefixes', 'trackedExtensions', 'excludedHosts', 'crawlerUserAgents'], 'safe'];
        $rules[] = [['serveMode'], 'in', 'range' => [self::SERVE_MODE_AUTO, self::SERVE_MODE_REDIRECT, self::SERVE_MODE_STREAM]];
        $rules[] = [['crawlerMode'], 'in', 'range' => [self::CRAWLER_MODE_BLOCK, self::CRAWLER_MODE_SEPARATE, self::CRAWLER_MODE_IGNORE]];
        $rules[] = [['dailyRetentionDays', 'signedUrlTtl'], 'integer', 'min' => 0];
        $rules[] = [['serveMode', 'crawlerMode', 'dailyRetentionDays'], 'required'];

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

    /**
     * Normalizes the extra crawler tokens to a lower-case, deduplicated list.
     *
     * @return string[]
     */
    public function normalizedCrawlerUserAgents(): array
    {
        $tokens = [];

        foreach ($this->crawlerUserAgents as $token) {
            $token = strtolower(trim((string)$token));

            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Returns whether a request carrying the given signal should be refused
     * outright rather than served.
     *
     * @param string $signal A RequestSignals::SIGNAL_* constant.
     * @return bool
     */
    public function shouldBlock(string $signal): bool
    {
        return $signal === RequestSignals::SIGNAL_CRAWLER
            && $this->crawlerMode === self::CRAWLER_MODE_BLOCK;
    }

    /**
     * Returns whether a request carrying the given signal should be counted.
     *
     * @param string $signal A RequestSignals::SIGNAL_* constant.
     * @return bool
     */
    public function shouldCount(string $signal): bool
    {
        return match ($signal) {
            // A prefetch isn't a download, whatever the crawler mode says.
            RequestSignals::SIGNAL_PREFETCH => false,
            RequestSignals::SIGNAL_CRAWLER => $this->crawlerMode === self::CRAWLER_MODE_SEPARATE,
            default => true,
        };
    }

    /**
     * Returns whether crawler figures are worth showing in the control panel.
     *
     * @return bool
     */
    public function tracksCrawlersSeparately(): bool
    {
        return $this->crawlerMode === self::CRAWLER_MODE_SEPARATE;
    }
}
