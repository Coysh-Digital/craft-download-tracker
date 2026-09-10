# Crawlers

Search engines, AI crawlers, link unfurlers and uptime monitors all download
files. Left alone, they quietly inflate your numbers, so that a figure you'd read
as "people who downloaded this" is really people plus a pile of bots. Download
Tracker can tell most crawlers apart from real people and handle them the way you
choose.

## How crawlers are handled

The **Crawler downloads** setting decides what happens when a download is
detected as coming from a crawler:

- **Count separately** (the default). The crawler download is counted toward the
  file's total **and** toward a crawler total of its own. Your human figure is
  then the total minus the crawler total. This keeps bots visible without letting
  them pollute your human numbers.
- **Don't count.** The file is served and nothing is recorded.
- **Block with a 403.** The managed download route refuses the request outright
  and streams nothing. This only applies to [managed links](/managed-links): the
  click beacon has no file to withhold, so it simply doesn't count the hit.

### Why "count separately" is the safe default

Blocking trusts the crawler detection completely. A User-Agent that's wrongly
read as a crawler means a real person meets a 403 and the download just looks
broken to them. Counting separately is safer because a miscount is something you
can see and correct after the fact, whereas a refused download isn't. Start with
the default, and only move to blocking if you have a specific reason and you're
confident in the detection.

## Prefetch and prerender

Browser prefetch and prerender requests are **always served and never counted**,
whatever you set **Crawler downloads** to. A prefetch is a real browser getting
ready for a real click: it's neither a crawler to turn away nor a download to
count. If it later turns into an actual click, that click is what gets counted.

## How detection works

The plugin knows the well-known search, AI, social and monitoring crawlers by
name, and catches most of the rest by their User-Agent. On top of that it ships
a distilled list of around a thousand extra crawler tokens, so lesser-known bots
are recognised without any setup. For anything still specific to your own server
logs, add a token to **Extra crawler user agents**.

### The distilled crawler list

The extra list is drawn from three community-maintained sources -
[Kikobeats/top-crawler-agents](https://github.com/Kikobeats/top-crawler-agents),
[monperrus/crawler-user-agents](https://github.com/monperrus/crawler-user-agents)
and [matomo-org/device-detector](https://github.com/matomo-org/device-detector) -
distilled down to just the distinctive tokens. Everything the plugin already
catches (any User-Agent containing `bot`, `crawl`, `spider` and the like),
generic words, and browser or in-app tokens that could match real people are all
dropped, so the list stays small and never turns a person into a crawler.

A baseline list ships with the plugin. Admins can update it from **Download
Tracker → Crawlers**, which shows how many tokens are in the list and when it was
last refreshed, and offers a **Refresh & apply now** button that re-downloads the
three sources, re-distils them, and applies the result immediately. The same runs
headless as `craft download-tracker/crawlers/refresh`, for a scheduled task.

The distilled tokens are always **counted separately**, never used to block - a
list this broad is more likely to mis-match than the hand-picked names, and a
miscount is safe to correct after the fact where a refused download isn't.

Tokens are matched case-insensitively as plain substrings, not patterns. If your
logs show a bot identifying itself as something containing `acmebot`, add
`acmebot`:

```
acmebot
examplecrawler
```

Substrings are used rather than regular expressions on purpose: a pattern would
have to run on every download, and a substring check is both cheaper and harder
to get wrong.

## What isn't recorded

Download Tracker deliberately does **not** record *which* crawler made a
download. Doing so would mean a row per file per crawler per day, which is exactly
the kind of row growth this plugin exists to avoid. You get the split between
people and crawlers, not a per-bot breakdown.

## Reading the split

The people/crawler split is available both in the control panel and in Twig:

```twig
{{ craft.downloadTracker.total(asset) }}        {# people and crawlers together #}
{{ craft.downloadTracker.userTotal(asset) }}    {# people only #}
{{ craft.downloadTracker.crawlerTotal(asset) }} {# crawlers only #}
```

The per-day history carries the same split, so a chart can show people and
crawlers side by side. See the [Twig API](/twig-api) and [Reporting](/reporting).

::: tip
The crawler split only fills in while **Count separately** is on. Under **Don't
count** or **Block**, crawler downloads aren't recorded, so there's no crawler
figure to read.
:::
