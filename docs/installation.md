# Installation

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later

::: tip Still on Craft 4?
This is the Craft 5 release. If you're on Craft 4, use the
[`craft-4-support` branch](https://github.com/Coysh-Digital/craft-download-tracker/tree/craft-4-support),
which runs on Craft 4 and Craft 5 alike. It's handy if you want to import your
Link Vault history and drop Link Vault before upgrading to Craft 5, rather than
carrying it through the upgrade.
:::

## Install with Composer

From your project root, require the plugin and then install it:

```bash
composer require coysh-digital/craft-download-tracker
php craft plugin/install download-tracker
```

## Install from the Plugin Store

Or install it from the **Plugin Store** in your control panel: search for
"Download Tracker", then click **Install**.

## After installing

Download Tracker works out of the box. With **Auto-track download links** on
(the default), it starts counting your existing download links straight away, so
there's nothing to change in your templates.

A good first pass through the settings:

1. Open **Settings → Download Tracker**.
2. Under **Tracked path prefixes**, add the URL paths your downloads live under,
   for example `/assets/files/`. This is the most reliable signal for the click
   tracker. See [Automatic click tracking](/click-tracking) for how the beacon
   decides what counts.
3. Check the **Tracked extensions** list covers your file types.
4. Decide how you want [crawlers](/crawlers) handled. The default, counting them
   separately, is the safe choice.

That's enough to start collecting numbers. From there:

- Read them in your templates with the [Twig API](/twig-api).
- Browse and export them in the control panel, covered in [Reporting](/reporting).
- If you want certain links counted reliably on the server (gated, private, or
  forced downloads), set up [managed download links](/managed-links).

## Coming from Link Vault?

If you're migrating from Masuga's Link Vault plugin, install Download Tracker
alongside it and import your history **before** you uninstall Link Vault. The
whole process is covered in [Moving from Link Vault](/link-vault-import).

## Uninstalling

Uninstall from **Settings → Plugins**, or with:

```bash
php craft plugin/uninstall download-tracker
```

Uninstalling removes the plugin's tables, which includes your counters and
day-by-day history. Export anything you want to keep from the Downloads screen
first.
