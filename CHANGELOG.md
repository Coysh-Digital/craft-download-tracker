# Release Notes for Download Tracker

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
