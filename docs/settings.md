# Settings

Everything is configurable in **Settings → Download Tracker** in the control
panel, or in a `config/download-tracker.php` file. When both exist, the config
file wins, which makes it handy for per-environment values and for keeping
settings in version control.

## Settings reference

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| Auto-track download links | `injectTrackingScript` | `true` | Injects the click-tracking script on the front end. See [Automatic click tracking](/click-tracking). |
| Tracked path prefixes | `trackedPathPrefixes` | `[]` | URL path prefixes whose links the beacon counts, e.g. `/assets/files/`. |
| Tracked extensions | `trackedExtensions` | see below | File types to treat as downloads, lower-case and without the dot. |
| Track download-attribute links | `trackDownloadAttr` | `true` | Also count any link carrying a `download` attribute. |
| Excluded hosts | `excludedHosts` | `[]` | Hostnames to ignore, e.g. an image CDN. |
| Track non-asset files | `trackUnresolvedFiles` | `false` | Also count links that don't resolve to a Craft asset. Off keeps the counter table bounded. |
| Crawler downloads | `crawlerMode` | `separate` | How crawler downloads are handled: `separate`, `ignore`, or `block`. See [Crawlers](/crawlers). |
| Extra crawler user agents | `crawlerUserAgents` | `[]` | Extra User-Agent tokens to treat as crawlers, on top of the built-in list. |
| Serve mode | `serveMode` | `auto` | How managed links deliver files: `auto`, `redirect`, or `stream`. See [Managed links](/managed-links). |
| Force download | `forceDownload` | `false` | Serve managed downloads as an attachment ("Save as..."). |
| Require login | `requireLoginToServe` | `false` | Only serve managed downloads to logged-in users. |
| Signed URL lifetime | `signedUrlTtl` | `0` | How long a managed download link stays valid, in seconds. `0` = never expires. |
| Daily rollup retention | `dailyRetentionDays` | `365` | How many days of per-day history to keep before pruning. |

The default **Tracked extensions** are:

```
pdf, doc, docx, xls, xlsx, ppt, pptx, zip, csv, txt, rtf,
odt, ods, odp, mp3, wav, epub, mobi
```

## The config file

Copy the plugin's `src/config.php` to your project's `config/` directory as
`download-tracker.php`, then uncomment and change the values you want to
override. A minimal example:

```php
<?php

return [
    // Count links under these paths.
    'trackedPathPrefixes' => [
        '/assets/files/',
        '/downloads/',
    ],

    // Keep all per-day history, never prune.
    'dailyRetentionDays' => 0,

    // Managed links expire after an hour (for uncached, gated pages).
    'signedUrlTtl' => 3600,
];
```

Values set in the config file take precedence over what's in the control panel,
and the two are merged, so you only need to list the settings you're overriding.

### Multi-environment values

Because it's a normal Craft config file, you can vary settings by environment
using Craft's multi-environment config style:

```php
<?php

return [
    '*' => [
        'trackedPathPrefixes' => ['/assets/files/'],
    ],
    'dev' => [
        // Don't inject the beacon while developing locally.
        'injectTrackingScript' => false,
    ],
];
```

## Notes on individual settings

### Daily rollup retention

This bounds how far back the per-day history goes. Older per-day rows are pruned
automatically, and you can prune on demand with `php craft
download-tracker/counts/prune`. Running totals are never pruned: only the per-day
detail behind them. Set it to `0` to keep every day forever.

This setting also caps how far back a [Link Vault import](/link-vault-import) can
bring day-by-day history, so set it before importing if you want the full history.

### Signed URL lifetime

Leave this at `0` when managed links are embedded in statically cached pages, so
a cached link doesn't expire while the page is still being served. Set a value
when links are only rendered on uncached pages, such as gated account pages. See
[Managed links](/managed-links#signed-url-lifetime).

### Crawler downloads

The default, `separate`, counts crawlers toward the total and toward a crawler
total of their own, so your human numbers are readable off the split. `block`
should be used with care, since it refuses downloads on managed links. See
[Crawlers](/crawlers) for the full picture.
