# Reporting

Everything the plugin counts is browsable in the control panel, so you don't have
to write a template to see your numbers. There's a main Downloads list, a detail
page per file, saved reports, and a dashboard widget.

## The Downloads screen

**Download Tracker → Downloads** is a searchable, sortable list of every file
that's been counted. For each file you see its running total, and, while crawlers
are counted separately, the split between people and crawlers. Search by filename
to find a specific file, and sort by count to see what's most popular.

You can **export to CSV** straight from this screen, which is the quickest way to
pull the current numbers into a spreadsheet.

## Per-file detail

Click a file in the Downloads list to open its detail page. This shows:

- The file's totals, including the people and crawler split.
- A **day-by-day chart** and table over the last **30, 90 or 365 days**, so you
  can see how downloads for that one file have trended.
- A **CSV export** of that day-by-day history.

How far back the day-by-day view can reach depends on your **Daily rollup
retention** setting, since older per-day rows are pruned automatically. See
[Settings](/settings).

## Saved reports

When you find yourself running the same view repeatedly, save it as a report.
Saved reports live under **Download Tracker → Reports**, so a filtered, sorted
view is one click away next time rather than something you rebuild by hand.

## The Top Downloads widget

Add the **Top Downloads** widget to your control panel dashboard for an at-a-
glance list of your most-downloaded files. It's the same data as the
`craft.downloadTracker.top()` [Twig method](/twig-api#top), surfaced on the
dashboard so it's in front of you without opening the plugin.

## CSV exports

Two CSV exports are available:

- **The Downloads list**, exporting the current totals per file.
- **A file's day-by-day history**, exported from its detail page.

Both are plain CSV, ready to open in a spreadsheet or feed into another tool.

## Reading the numbers elsewhere

If you'd rather build your own display, on the front end or in a custom module,
the same numbers are available through the [Twig API](/twig-api). And if you need
to react to a download as it happens, see [Developer events](/events).

## Keeping the history tidy

The per-day rollup is pruned to your **Daily rollup retention** window
automatically. If you want to prune on demand, for example right after lowering
the retention setting, there's a console command:

```bash
php craft download-tracker/counts/prune
```

It removes per-day rows older than the configured retention window. Running
totals are never affected by pruning: only the per-day detail behind them is.
