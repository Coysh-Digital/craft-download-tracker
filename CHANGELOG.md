# Release Notes for Download Tracker

## 1.2.0 - 2026-07-17

### Added
- **Import from Link Vault**, for sites moving off Masuga's Link Vault plugin.
  It folds Link Vault's row-per-download log into this plugin's per-file
  counters, keeping the running totals, the day-by-day history and each file's
  last-downloaded date. Run it from **Download Tracker → Import** (admins only,
  and only shown while Link Vault is still installed) or with
  `php craft download-tracker/import/link-vault`.
- The Import page shows what will happen before you commit to anything: how many
  downloads it will import, how many files they belong to, and what it will skip.
  `--dryRun` does the same on the command line.
- The import runs as a queue job, so progress shows in the control panel's queue
  indicator and a large history won't time out. It's safe to re-run: it resumes
  from where it stopped rather than counting anything twice, so you can import,
  leave Link Vault running a while longer, and top up before uninstalling.

### Fixed
- The per-file detail page's date range collapsed to a single day, so the chart,
  the day-by-day table and the "in this range" totals only ever showed one day's
  downloads - the day the range started - instead of the whole window. The same
  bug made `craft.downloadTracker.daily()` return one day rather than the range
  asked for. Both affect 1.1.0, and are most visible on a file with a long
  history, since a site with only recent downloads showed an empty range instead.

### Notes
- Run the import **before** uninstalling Link Vault - it reads Link Vault's
  tables directly, and can't recover the history once they're gone.
- A count per file can't hold everything a row per download could. Imports keep
  the totals and daily history, and drop the per-user, per-IP and custom-field
  detail. Blocked leech attempts and downloads deleted in Link Vault are skipped.
  Link Vault never recorded a user agent, so imported downloads can't be split
  into people and crawlers, and all count as people.

## 1.1.0 - 2026-07-16

### Added
- **Crawler downloads** setting, controlling what happens when a search engine,
  AI crawler, link unfurler or monitoring tool downloads a file:
  - **Count separately** (new default) - counted toward the total, and toward a
    crawler total of their own, so you can read your human numbers off the split.
  - **Don't count** - the file is served, nothing is recorded.
  - **Block with a 403** - the served-download route refuses outright and streams
    nothing. Only the served route can block; the beacon has no file to withhold.
- Crawler detection covers the well-known search, AI, social and monitoring
  crawlers by name, with a catch-all for the long tail, and an **Extra crawler
  user agents** setting for anything else you see in your own logs.
- **Per-file detail page**: click a file in the Downloads list for its totals,
  a day-by-day chart and table over the last 30, 90 or 365 days, and a CSV
  export of that history.
- Twig API: `craft.downloadTracker.userTotal()`, `.crawlerTotal()`, and
  `.daily(file, days)` for a file's day-by-day history.
- `beforeTrackDownload` events now carry `isCrawler`, so a handler can decide
  about crawler hits itself without touching the setting.

### Changed
- **`count` is now the total: people and crawlers together.** Under the new
  default, crawler downloads are included in it, and the human figure is
  `count - crawlerCount`. Every existing list, report, widget and Twig call
  keeps working unchanged, but a site that previously discarded bot hits will
  see its totals rise once crawlers start counting. Installs that had
  `ignorePrefetchAndBots` on keep their existing behaviour automatically - see
  below - so this only affects new installs and anyone who opts in.
- The served-download route now recognises prefetch and crawler requests, which
  it previously ignored entirely: it counted every request carrying a valid
  token, whatever sent it. A browser prefetch of a download link is therefore no
  longer counted twice (once when speculatively fetched, once when clicked).
- Browser prefetch and prerender requests are always served and never counted,
  whatever **Crawler downloads** is set to. A prefetch is a real browser getting
  ready for a real click - neither a crawler to turn away nor a download to
  count.
- `lastDownloaded` tracks when `count` last moved, so a counted crawler hit
  updates it too.
- The Downloads CSV export gained **User downloads** and **Crawler downloads**
  columns. They're appended after the existing ones, so anything reading that
  file by column position is unaffected.

### Deprecated
- `ignorePrefetchAndBots`, replaced by `crawlerMode`. It's still honoured, from
  both project config and `config/download-tracker.php`: `true` maps to
  `crawlerMode: 'ignore'` and `false` to `'separate'`, so upgrading doesn't
  change how an existing site counts. An explicit `crawlerMode` always wins.
  Support for the old setting will be removed in 2.0.

## 1.0.4 - 2026-07-08

### Fixed
- The served-download route now returns a plain 404 when a request arrives with
  a missing or invalid token, instead of throwing a `BadRequestHttpException`.
  The route is anonymous and its URL is easy to guess, so bots and stale links
  probe it constantly with no token at all. Each of those probes was raising an
  application exception, which filled up error trackers with noise that wasn't
  a fault in the site. Nothing about the token check has changed, and no file
  was ever served without a valid token. The rejection reason is still written
  to the Craft logs, so a genuinely broken signed link can still be traced.

## 1.0.3 - 2026-07-08

### Changed
- Now we're in the plugin store, I thought it was time for a decent logo.
  Redrew the plugin icon as a proper coloured badge (a download arrow over
  rising count bars) so it stands on its own next to the other listings, and
  updated the control-panel mask icon to match.

## 1.0.2 - 2026-07-03

### Fixed
- Settings page is now viewable (read-only) for admins in environments where
  `allowAdminChanges` is off, instead of returning a 403. Saving is still
  blocked in those environments.
- The **Tracked extensions**, **Tracked path prefixes**, and **Excluded hosts**
  textareas no longer render their values run together on a single line - each
  value now appears on its own line again.

### Changed
- Redrew the control-panel icon with a more balanced arrow-and-tray glyph so it
  no longer looks squished in the sidebar.

## 1.0.1 - 2026-07-03

### Changed
- Adopted the standard Coysh Digital commercial license.
- Refreshed the plugin icon and updated the README.

## 1.0.0 - 2026-07-03

### Added
- Initial release.
- Atomic per-file download **counter** (one row per file) plus a bounded
  per-day rollup - no element-per-download or row-per-download bloat.
- **Zero-touch JS beacon** that auto-detects download links and counts them
  without any template changes. Works with full-page static caches (e.g. Blitz)
  because counting happens on a non-cached `/actions/...` request.
- Optional **served-download route** (`craft.downloadTracker.url(asset)`) that
  streams or redirects to the file after counting - for gated, private, remote,
  or forced ("Save as…") downloads, with a signed, tamper-proof link.
- Twig API: `craft.downloadTracker.url()`, `.total()`, `.record()`, `.top()`.
- Control panel: a sortable/searchable **Downloads** list with CSV export,
  **saved reports**, and a **Top Downloads** dashboard widget.
- Automatic garbage collection of the daily rollup beyond a configurable
  retention window.
