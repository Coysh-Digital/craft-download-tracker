# Twig API

Everything the plugin exposes to templates hangs off `craft.downloadTracker`.
Use it to read a file's totals, its day-by-day history, and to build counted
download links.

## Reading the numbers

```twig
{{ craft.downloadTracker.total(asset) }}         {# all downloads, people and crawlers #}
{{ craft.downloadTracker.userTotal(asset) }}     {# people only #}
{{ craft.downloadTracker.crawlerTotal(asset) }}  {# crawlers only #}
{{ craft.downloadTracker.daily(asset, 30) }}     {# day-by-day history #}
{{ craft.downloadTracker.record(asset) }}        {# the counter record, incl. last download #}
{{ craft.downloadTracker.top(10) }}              {# the ten most-downloaded files #}
{{ craft.downloadTracker.url(asset) }}           {# a signed, counting download link #}
```

Except for `url()`, which takes an Asset, every method that reads a file accepts
an **Asset**, an **asset ID**, or a **URL or path string**. So all of these work:

```twig
{{ craft.downloadTracker.total(asset) }}
{{ craft.downloadTracker.total(123) }}
{{ craft.downloadTracker.total('/assets/files/brochure.pdf') }}
```

## Methods

### total

```twig
craft.downloadTracker.total(file)
```

The running download total for a file: people and crawlers alike. Returns an
integer, `0` if the file has never been counted.

- `file` - an Asset, an asset ID, or a URL/path string.

### userTotal

```twig
craft.downloadTracker.userTotal(file)
```

The human download total: everything bar the crawlers. This is `total` minus the
crawler figure. Returns an integer.

### crawlerTotal

```twig
craft.downloadTracker.crawlerTotal(file)
```

The crawler download total for a file. Returns an integer. This only fills in
while **Crawler downloads** is set to count separately. See [Crawlers](/crawlers).

### daily

```twig
craft.downloadTracker.daily(file, days = 30)
```

A file's day-by-day history for the last `days` days, one entry per day, oldest
first. Days that saw no downloads come back as zeroes, so you can chart the
result without minding the gaps.

- `file` - an Asset, an asset ID, or a URL/path string.
- `days` - how many days back to return. Defaults to `30`.

Each entry has `date`, `count`, `userCount` and `crawlerCount`:

```twig
{% for day in craft.downloadTracker.daily(asset, 30) %}
  {{ day.date }}: {{ day.count }} ({{ day.userCount }} people, {{ day.crawlerCount }} crawlers)
{% endfor %}
```

How far back this can reach depends on your **Daily rollup retention** setting,
since older per-day rows are pruned. See [Settings](/settings).

### record

```twig
craft.downloadTracker.record(file)
```

The underlying counter record for a file, or `null` if the file has never been
counted. Useful when you want more than a single number, such as the
last-downloaded date:

```twig
{% set record = craft.downloadTracker.record(asset) %}
{% if record %}
  Downloaded {{ record.count }} times, last on {{ record.lastDownloaded|date('j M Y') }}.
{% endif %}
```

### top

```twig
craft.downloadTracker.top(limit = 10, criteria = {})
```

The most-downloaded files, most first. Returns a list of rows. Handy for a
"most popular downloads" block.

- `limit` - how many files to return. Defaults to `10`.
- `criteria` - optional extra criteria to narrow the result.

```twig
{% for row in craft.downloadTracker.top(5) %}
  {{ loop.index }}. {{ row.filename }} - {{ row.count }} downloads
{% endfor %}
```

### url

```twig
craft.downloadTracker.url(asset, params = {})
```

A signed, counting download link for an asset. Unlike the reading methods, this
takes an **Asset**. Use it for gated, private, forced, or off-server downloads
you want counted server-side. See [Managed download links](/managed-links).

- `asset` - the Asset to link to.
- `params` - optional extra query parameters to append to the URL.

```twig
<a href="{{ craft.downloadTracker.url(entry.brochure.one()) }}">
  Download the brochure
</a>
```

## Caching caveat

Avoid printing a live download count directly into a statically cached page. The
number is baked into the cached HTML and freezes until the cache is regenerated.
Show live counts in the control panel, or fetch them with a small client-side
request. Managed link URLs from `url()` are fine to render into cached pages, as
long as you mind the [private-file warning](/managed-links#private-files-and-access).
