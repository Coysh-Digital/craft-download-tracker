---
layout: home

hero:
  name: Download Tracker
  text: Download counting for Craft CMS
  tagline: Count how many times each of your files gets downloaded, without bloating your database.
  actions:
    - theme: brand
      text: Get started
      link: /installation
    - theme: alt
      text: How it works
      link: /how-it-works

features:
  - title: One counter per file
    details: Atomic count + 1 updates mean no per-download row explosion and no race conditions, even under heavy concurrent traffic.
  - title: Works with static caching
    details: Counting happens on a background request, so it keeps working on pages served straight from a Blitz (or similar) cache.
  - title: Zero-touch setup
    details: Turn it on and it starts counting your existing download links automatically, with no template changes required.
  - title: People and crawlers, told apart
    details: Count crawler downloads separately from human ones, ignore them, or block them outright with a 403.
---

## What it is

Download Tracker counts how many times each of your files gets downloaded,
without the database cost that usually comes with it.

A lot of the download-tracking tools out there write a new database row, or even
a whole element, for **every single download**. On a busy site that table
balloons into millions of rows and starts to hurt. On a statically cached site
it can also mean the cache is refreshed constantly. Download Tracker takes the
opposite approach: it keeps **one running counter per file** and increments it
atomically. A file downloaded a million times is still a single row.

It's also built for modern, cache-first Craft sites. If you run full-page static
caching such as [Blitz](https://putyourlightson.com/plugins/blitz), you'll know
that a cached page never touches PHP, so anything that tries to "count on page
load" simply never fires. Download Tracker sidesteps this entirely by counting
on a lightweight background request that static caches always let through.

## What you get

- **One counter per file.** No per-download row explosion, no race conditions.
- **Daily breakdown.** A compact per-day rollup lets you see trends over time,
  and old rows are pruned automatically. Every file has a detail screen with its
  day-by-day chart, table and CSV export.
- **People and crawlers, told apart.** Count crawler downloads separately from
  human ones, ignore them, or block them with a 403.
- **Works with static caching.** Counting happens on a background request.
- **Zero-touch setup.** It starts counting your existing download links with no
  template changes.
- **Optional managed download links** for gated, private, remote, or
  force-"Save as..." downloads, using a signed, tamper-proof URL.
- **Reporting built in.** A searchable, sortable Downloads screen in the control
  panel, CSV export, saved reports, and a "Top Downloads" dashboard widget.
- **Import from Link Vault.** Bring your download history across before you
  uninstall it. See [Moving from Link Vault](/link-vault-import).

## Where to next

- New here? Start with [Installation](/installation), then [How it works](/how-it-works).
- Want counts without touching templates? See [Automatic click tracking](/click-tracking).
- Need a counted link for a gated or private file? See [Managed download links](/managed-links).
- Reading numbers in your templates? See the [Twig API](/twig-api).

::: tip Still on Craft 4?
From 1.4.0, one release runs on Craft 4 and Craft 5 alike — there's no separate
branch or version line to pick between. Everything in these docs applies to both.
:::
