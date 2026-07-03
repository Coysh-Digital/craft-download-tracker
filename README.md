# Download Tracker for Craft CMS

Count how many times each of your files gets downloaded — without bloating your
database.

Most download-tracking tools write a new database row (or even a whole element)
for **every single download**. On a busy site that table balloons into millions
of rows and starts to hurt. Download Tracker takes the opposite approach: it
keeps **one running counter per file** and increments it atomically. A file
downloaded a million times is still a single row.

It's also built for modern, cache-first Craft sites. If you run full-page static
caching such as [Blitz](https://putyourlightson.com/plugins/blitz), you'll know
that a cached page never touches PHP — so anything that tries to "count on page
load" simply never fires. Download Tracker sidesteps this entirely by counting
on a lightweight background request that static caches always let through.

## Features

- **One counter per file.** Atomic `count + 1` updates mean no per-download row
  explosion and no race conditions, even under heavy concurrent traffic.
- **Daily breakdown.** A compact per-day rollup (one row per file per day) lets
  you see trends over time, and old rows are pruned automatically.
- **Works with static caching.** Counting happens on a background request, so it
  keeps working on pages served straight from a Blitz (or similar) cache.
- **Zero-touch setup.** Turn it on and it starts counting your existing download
  links automatically — no template changes required.
- **Optional managed download links** for gated, private, remote, or
  force-“Save as…” downloads, using a signed, tamper-proof URL.
- **Reporting built in.** A searchable, sortable Downloads screen in the control
  panel, CSV export, saved reports, and a “Top Downloads” dashboard widget.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later

## Installation

Install with Composer and then enable the plugin:

```bash
composer require coysh-digital/craft-download-tracker
php craft plugin/install download-tracker
```

Or install it from the **Plugin Store** in your control panel.

## How it works

Downloads are counted through one of two routes, and both update the same
counter — so a file is never double-counted.

### 1. Automatic click tracking (the default)

With **Auto-track download links** switched on, the plugin adds a tiny script to
your front-end pages. When a visitor clicks something that looks like a download
— a link under one of your configured paths, a link to a tracked file type, or
any link with a `download` attribute — the script quietly pings the plugin and
then lets the browser download the file exactly as it normally would.

You don't have to change any of your templates or links. To exclude a specific
link, add `data-dt-ignore` to it.

### 2. Managed download links (optional)

When you want a download counted reliably on the server — for example a
members-only PDF, a file on private/off-server storage, or a link that should
force a “Save as…” — route it through the plugin:

```twig
<a href="{{ craft.downloadTracker.url(entry.brochure.one()) }}">
  Download the brochure
</a>
```

The link carries a signed token, counts the download when clicked, and then
either redirects to the file (for public assets) or streams it through Craft
(for private or off-server assets).

> **A note on private files:** a download link is a bearer link — anyone who has
> the URL can use it. If you place a managed link to a *private* asset on a
> *publicly cached* page, that URL gets baked into the cached HTML and becomes a
> permanent public link. For genuinely private files, turn on **Require login**,
> and/or set a **Signed URL lifetime** and only render the link on pages that
> aren't statically cached (such as logged-in account pages).

## Reading the numbers in Twig

```twig
{{ craft.downloadTracker.total(asset) }}   {# total downloads (a number) #}
{{ craft.downloadTracker.record(asset) }}  {# the counter record, incl. last download #}
{{ craft.downloadTracker.top(10) }}        {# the ten most-downloaded files #}
{{ craft.downloadTracker.url(asset) }}     {# a signed, counting download link #}
```

`total()`, `record()` and `url()` all accept an Asset, an asset ID, or a
URL/path string.

> **Tip:** avoid printing a live download count directly into a statically
> cached page — the number would freeze until the cache is regenerated. Show
> counts in the control panel, or load them with a small client-side request.

## Settings

Everything is configurable in **Settings → Download Tracker**, or in a
`config/download-tracker.php` file (which takes precedence, and is handy for
per-environment values):

| Setting | What it does |
| --- | --- |
| Auto-track download links | Injects the click-tracking script on the front end. |
| Tracked path prefixes | URL paths whose links should be counted, e.g. `/assets/files/`. |
| Tracked extensions | File types to treat as downloads (`pdf`, `zip`, `csv`, …). |
| Track download-attribute links | Also count any link with a `download` attribute. |
| Excluded hosts | Hostnames to ignore, e.g. an image CDN. |
| Track non-asset files | Also count links that don't resolve to a Craft asset. Off by default. |
| Ignore prefetch & bots | Skip browser prefetch/prerender and obvious crawlers. |
| Serve mode | How managed links deliver files: redirect, stream, or auto. |
| Force download | Serve managed downloads as an attachment (“Save as…”). |
| Require login | Only serve managed downloads to logged-in users. |
| Signed URL lifetime | How long a managed download link stays valid (0 = forever). |
| Daily rollup retention | How many days of per-day history to keep. |

## Support

Found a bug or have a request? Please open an issue on
[GitHub](https://github.com/Coysh-Digital/craft-download-tracker/issues).

## License

This plugin is licensed under the [Craft License](LICENSE.md).

Made by [Coysh Digital](https://coysh.digital).
