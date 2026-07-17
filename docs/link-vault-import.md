# Moving from Link Vault

If you're coming from
[Link Vault](https://github.com/masugadesign/link-vault-craft-cms), your download
history can come with you. Link Vault keeps a row per download; this plugin keeps
a count per file, and the importer folds one into the other.

## Before you start

**Import before you uninstall Link Vault.** The importer reads Link Vault's
tables directly, so the history is gone once they are. Link Vault itself isn't
modified or removed by the import, so you can import, check the numbers look
right, and only then uninstall it.

**Check your retention setting first.** Day-by-day history is only imported as
far back as your **Daily rollup retention** allows (365 days by default). Beyond
that window, downloads still count toward each file's running total, but the
per-day detail behind them isn't kept. If you want the complete day-by-day
history, set retention to `0` before importing.

::: warning This is a one-way door
Once Link Vault is uninstalled, its tables are gone and the older per-day detail
is unrecoverable. Raising the retention setting afterwards won't bring it back.
Decide on retention, then import, then uninstall.
:::

## Running the import

Make sure both plugins are installed, then go to **Download Tracker → Import**.
The page is admin-only, and it only exists while Link Vault is still installed.

It shows what it will do before you commit to anything: how many downloads it
will import, how many files they belong to, and what it will skip. When you run
it, it runs in the background as a queue job, so progress shows in the control
panel's queue indicator and a large history won't time out.

### From the console

For a very large history, or if you'd rather script it, use the console:

```bash
php craft download-tracker/import/link-vault --dryRun   # report, change nothing
php craft download-tracker/import/link-vault            # do it
```

`--dryRun` prints the same summary the Import page shows, without writing
anything.

## It's safe to run more than once

The import resumes from where it stopped rather than counting anything twice. So
you can import now, leave Link Vault running a little longer to keep catching
downloads, and run the import again to pick up the stragglers before you
uninstall. Re-running never double-counts what's already been imported.

## What comes over

Kept:

- Each file's **running total**.
- Each file's **last-downloaded date**.
- Each file's **day-by-day history**, as far back as your retention setting
  allows.

Downloads that Link Vault logged against an asset stay attached to that asset, so
imported history and future tracking share one counter. There's no separate
"imported" bucket: it all lands in the same per-file counter the plugin uses from
then on.

## What doesn't come over

A count per file simply has nowhere to put some of what a row per download can
hold. These are dropped:

- **Who** downloaded what, and from which IP.
- Custom fields you added to Link Vault, ZIP names, and the specific element each
  download was logged against.
- **The people/crawler split.** Link Vault never recorded a User-Agent, so this
  can't be reconstructed. Every imported download counts as a person, and your
  crawler numbers start from the day you switch over.
- **Leech attempts**, which were blocked requests rather than real downloads.
- Downloads that were deleted in Link Vault, which stay deleted.

## Still on Craft 4?

The Link Vault import is exactly why the
[`craft-4-support` branch](https://github.com/Coysh-Digital/craft-download-tracker/tree/craft-4-support)
exists. Link Vault's schema is identical on its Craft 4 and Craft 5 releases, so
the importer works the same on both. If you're still on Craft 4, you can install
Download Tracker there, import your history, and drop Link Vault **before**
upgrading to Craft 5, rather than carrying Link Vault through the upgrade.

## After importing

Once the numbers look right and you've imported any final stragglers, uninstall
Link Vault as normal. From that point Download Tracker carries the totals
forward, counting new downloads through its own [click tracking](/click-tracking)
and [managed links](/managed-links).
