# Managed download links

A managed download link routes a download through the plugin instead of pointing
straight at the file. The link carries a signed token, counts the download when
it's clicked, and then delivers the file. Because the counting happens on the
server, it's reliable in a way that browser-based click tracking can't always be.

Reach for a managed link when you want a download counted reliably on the server,
or when the file is:

- **Gated**, for example a members-only PDF.
- **Private or off-server**, on a volume that isn't publicly readable.
- **Forced**, meaning it should download as a "Save as..." rather than open
  inline.

For ordinary public links, you don't need this. The zero-touch
[click tracker](/click-tracking) already counts them.

## Creating a link

Use the `url()` Twig method, passing an asset:

```twig
<a href="{{ craft.downloadTracker.url(entry.brochure.one()) }}">
  Download the brochure
</a>
```

The returned URL points at the plugin's download action, carries a signed token
identifying the asset, and counts the download when clicked. You can pass extra
query parameters too:

```twig
{{ craft.downloadTracker.url(asset, { v: 2 }) }}
```

See the [Twig API](/twig-api#url) for the full signature.

## How the file is delivered

Once the download is counted, the plugin delivers the file according to your
**Serve mode** setting:

- **Auto** (the default). Public assets are handed off with a 302 redirect to
  their real URL, which is cheap and lets your web server or CDN do the serving.
  Private or off-server assets are streamed through Craft, because there's no
  public URL to redirect to.
- **Redirect.** Always 302-redirect to the asset URL. Fastest, but only suitable
  for assets that have a reachable public URL.
- **Stream.** Always stream the file through PHP. Use this when you want the file
  bytes to pass through Craft, for example so a private file is never exposed by
  a public URL.

### Forcing a "Save as..."

Turn on **Force download** to serve managed downloads as an attachment, so the
browser saves the file rather than trying to open it inline. This applies to the
managed route only.

## Private files and access

A download link is a **bearer link**: anyone who has the URL can use it. Two
settings tighten this up.

### Require login

Turn on **Require login** to serve managed downloads only to logged-in users.
A request without a session is refused rather than served.

### Signed URL lifetime

By default a signed link never expires (**Signed URL lifetime** of `0`). That's
the right choice when links are embedded in statically cached pages, because a
cached page may keep serving the same link for a long time and you don't want it
to go stale.

Set a lifetime (in seconds) when your links are only rendered on uncached pages,
such as a logged-in account page. After that many seconds the link stops working
and has to be regenerated.

::: warning Private files on cached pages
If you place a managed link to a **private** asset on a **publicly cached** page,
that URL gets baked into the cached HTML and becomes a permanent public link to
the file. For genuinely private files, turn on **Require login**, and/or set a
**Signed URL lifetime**, and only render the link on pages that aren't
statically cached.
:::

## Crawlers and managed links

The managed route is the only one that can refuse a download. If your
[crawler](/crawlers) setting is **Block with a 403**, a request detected as a
crawler is refused here and nothing is streamed. The click beacon has no file to
withhold, so blocking only affects managed links. Prefetch and prerender
requests are always served and never counted, whatever the setting.
