# Developer events

Download Tracker fires an event just before it records a download, so a custom
module or plugin can inspect the download, decide whether it should count, or
react to it. This is the hook to reach for when the built-in
[settings](/settings) don't cover a rule you need.

## `beforeTrackDownload`

The `Downloads` service fires `Downloads::EVENT_BEFORE_TRACK_DOWNLOAD` before a
download is counted. The event is a
`coyshdigital\downloadtracker\events\DownloadEvent`, with these properties:

| Property | Type | What it is |
| --- | --- | --- |
| `downloadKey` | `string` | The file's canonical identity (`asset:123`, `path:...`, `url:...`). |
| `assetId` | `?int` | The related asset ID, when the download resolves to one. |
| `asset` | `?Asset` | The related asset, when resolvable. |
| `sourceType` | `string` | One of `asset`, `path`, `url`. |
| `filename` | `string` | The file's display name. |
| `isCrawler` | `bool` | Whether the hit came from a crawler rather than a person. |
| `isValid` | `bool` | Whether the download should be counted. Set to `false` to skip it. |

Set `isValid` to `false` to stop the download being recorded. Everything else is
read-only context to base that decision on.

## Example: skip downloads of a particular file

```php
use coyshdigital\downloadtracker\events\DownloadEvent;
use coyshdigital\downloadtracker\services\Downloads;
use yii\base\Event;

Event::on(
    Downloads::class,
    Downloads::EVENT_BEFORE_TRACK_DOWNLOAD,
    function (DownloadEvent $event) {
        // Don't count downloads of internal test files.
        if (str_starts_with($event->filename, '_internal-')) {
            $event->isValid = false;
        }
    }
);
```

Put this in the `init()` method of a custom module or plugin.

## Example: make your own crawler decision

Because the event carries `isCrawler`, a handler can decide about crawler hits
itself rather than relying on the **Crawler downloads** setting. For example, to
count crawler hits everywhere except one noisy path:

```php
Event::on(
    Downloads::class,
    Downloads::EVENT_BEFORE_TRACK_DOWNLOAD,
    function (DownloadEvent $event) {
        if ($event->isCrawler && str_contains($event->downloadKey, '/press/')) {
            $event->isValid = false;
        }
    }
);
```

This runs alongside the setting, so it's a way to add a narrow exception without
turning off crawler counting for the whole site. For the built-in options, see
[Crawlers](/crawlers).
