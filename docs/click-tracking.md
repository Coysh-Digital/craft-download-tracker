# Automatic click tracking

Automatic click tracking is the zero-touch way to count downloads. With it on,
the plugin injects a small script on your front-end pages. When a visitor clicks
a link that looks like a download, the script pings the plugin to count it, then
lets the browser download the file exactly as it normally would.

You don't change any templates or links to make this work. It's on by default,
controlled by the **Auto-track download links** setting.

## What counts as a download

The script watches for clicks on links, and treats a link as a download when any
of these is true:

- The link's path starts with one of your **Tracked path prefixes**, for example
  `/assets/files/`.
- The link points at a file with one of your **Tracked extensions**, for example
  `pdf`, `zip` or `csv`.
- The link carries a `download` attribute, and **Track download-attribute links**
  is on.

If none of those match, the click is left alone and nothing is counted.

### Tracked path prefixes

This is the most reliable signal, and worth setting for most sites. Add the URL
paths your downloads live under. A link is tracked when its path begins with one
of them.

```
/assets/files/
/downloads/
```

### Tracked extensions

A sensible default list ships with the plugin:

```
pdf, doc, docx, xls, xlsx, ppt, pptx, zip, csv, txt, rtf,
odt, ods, odp, mp3, wav, epub, mobi
```

Extensions are matched lower-case and without the dot. Add or remove types to
suit your site.

### Download-attribute links

An HTML `download` attribute is a strong hint that a link is meant to be saved
rather than navigated to. With **Track download-attribute links** on, any link
carrying that attribute is counted, whatever its path or extension.

```html
<a href="/generated/report.bin" download>Download the report</a>
```

## Excluding links

To stop a specific link being counted, add `data-dt-ignore` to it:

```html
<a href="/assets/files/brochure.pdf" data-dt-ignore>
  Preview (don't count)
</a>
```

To ignore whole hostnames, for example an image CDN whose links aren't really
downloads, add them to **Excluded hosts**. Links to those hosts are never
counted.

## Non-asset files

By default the beacon only counts links that resolve to a Craft asset. This is
deliberate: it means a public beacon can only ever touch counters for real
assets, which keeps the counter table bounded and safe from being spammed with
made-up paths.

If you also want to count links that don't resolve to an asset, such as an
arbitrary same-site path under a tracked prefix or a remote URL, turn on **Track
non-asset files**. Those are then counted keyed to their path or URL instead of
an asset.

## A note on cached counts

Because static caching is a first-class concern here, avoid printing a live
download count directly into a statically cached page. The number would freeze
until the cache is regenerated. Show counts in the control panel, or load them
with a small client-side request. See the [Twig API](/twig-api) for more.

## When to reach for managed links instead

Click tracking runs in the browser, which is perfect for ordinary public links.
When you need a download counted reliably on the server, or the file is gated,
private, off-server, or should force a "Save as...", use a
[managed download link](/managed-links) instead. The two routes share one
counter, so mixing them never double-counts a file.
