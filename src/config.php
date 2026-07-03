<?php
/**
 * Download Tracker config
 *
 * Copy this file to your project's config/ directory as `download-tracker.php`
 * and uncomment any settings you'd like to override. Values set here take
 * precedence over what's configured in the control panel.
 */

return [
    // Automatically inject the click-tracking script on front-end pages. When on,
    // download links are counted with no template changes.
    'injectTrackingScript' => true,

    // URL path prefixes whose links should be tracked by the beacon. Leave empty
    // to rely on the download attribute + extension allow-list instead.
    'trackedPathPrefixes' => [],

    // File extensions the beacon treats as downloads (lower-case, no dot).
    'trackedExtensions' => [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip', 'csv', 'txt', 'rtf', 'odt', 'ods', 'odp',
        'mp3', 'wav', 'epub', 'mobi',
    ],

    // Also track any link carrying a `download` attribute, regardless of path.
    'trackDownloadAttr' => true,

    // Hosts to ignore (e.g. an image CDN whose links aren't real downloads).
    'excludedHosts' => [],

    // How the served-download route delivers files: 'auto' (redirect public
    // assets, stream private/off-server ones), 'redirect', or 'stream'.
    'serveMode' => 'auto',

    // Force a "Save as…" download rather than opening inline (served route only).
    'forceDownload' => false,

    // Don't count browser prefetch / prerender / obvious bot requests.
    'ignorePrefetchAndBots' => true,

    // Require a logged-in user for the served-download route.
    'requireLoginToServe' => false,

    // Also track links that don't resolve to a Craft asset. Off by default so a
    // public beacon can only ever touch counters for real assets (bounded table).
    'trackUnresolvedFiles' => false,

    // Lifetime (seconds) of a signed served-download URL; 0 = never expires.
    // Keep 0 when links are embedded in statically-cached pages.
    'signedUrlTtl' => 0,

    // How many days of per-day rollup rows are kept before garbage collection.
    'dailyRetentionDays' => 365,
];
