# How it works

Download Tracker keeps **one counter row per file** and increments it every time
that file is downloaded. There's no row per download, so the table stays small
no matter how busy the site gets. Alongside the running total, it keeps a compact
**per-day rollup** (one row per file per day) so you can see trends over time.

Downloads reach that counter through one of two routes, and both update the same
counter, so a file is never double-counted.

## The two routes

### 1. Automatic click tracking (the default)

With **Auto-track download links** switched on, the plugin adds a tiny script to
your front-end pages. When a visitor clicks something that looks like a download,
the script quietly pings the plugin and then lets the browser download the file
exactly as it normally would.

You don't have to change any of your templates or links. This is the route most
sites use for most downloads. Full detail, including how the script decides what
counts as a download, is in [Automatic click tracking](/click-tracking).

### 2. Managed download links (optional)

When you want a download counted reliably on the server, for example a
members-only PDF, a file on private or off-server storage, or a link that should
force a "Save as...", you route it through the plugin with a signed link:

```twig
<a href="{{ craft.downloadTracker.url(entry.brochure.one()) }}">
  Download the brochure
</a>
```

The link carries a signed token, counts the download when clicked, and then
either redirects to the file or streams it through Craft. Full detail is in
[Managed download links](/managed-links).

## Why counting happens off to the side

Modern Craft sites often run full-page static caching such as
[Blitz](https://putyourlightson.com/plugins/blitz). A statically cached page is
served straight from disk or the edge and never touches PHP, so any plugin that
tries to "count on page load" simply never runs for those visitors.

Both of Download Tracker's routes avoid this. The click tracker counts on a
separate background request (a beacon) that static caches always let through, and
the managed link is its own request to the plugin. Either way, the counting
never depends on your page hitting PHP.

## People and crawlers

Search engines, AI crawlers, link unfurlers and uptime monitors all download
files, and left alone they quietly inflate your numbers. Download Tracker can
tell most of them apart from real people and, depending on your setting, count
them separately, ignore them, or block them. See [Crawlers](/crawlers).

## What a "file" is

A file is identified by its canonical identity. Where the download resolves to a
Craft asset, the counter is keyed to that asset, so the count follows the asset
even if its URL changes. Where it doesn't resolve to an asset (an arbitrary
same-site path, or a remote URL), it can still be counted by its path or URL, if
you turn on **Track non-asset files**. Keeping that off means a public beacon can
only ever touch counters for real assets, which keeps the counter table bounded.

## Reading the numbers

Once downloads are counting, you can read them:

- In your templates, with the [Twig API](/twig-api).
- In the control panel, on the Downloads screen and per-file detail pages,
  covered in [Reporting](/reporting).
