# Release Notes for Download Tracker

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
