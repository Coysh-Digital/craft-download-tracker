/**
 * Download Tracker - zero-touch click beacon.
 *
 * Auto-detects download links and reports a "hit" to the plugin's track action
 * before the browser follows the link. Because it fires an action request, it
 * works on pages served from a full-page static cache (e.g. Blitz).
 *
 * Configured server-side via `window.DownloadTracker`:
 *   { endpoint, prefixes[], extensions[], excludeHosts[], trackDownloadAttr }
 */
(function () {
  'use strict';

  var config = window.DownloadTracker;
  if (!config || !config.endpoint) {
    return;
  }

  var prefixes = config.prefixes || [];
  var extensions = config.extensions || [];
  var excludeHosts = (config.excludeHosts || []).map(function (h) {
    return String(h).toLowerCase();
  });
  var trackDownloadAttr = config.trackDownloadAttr !== false;

  // Guard against double-counting an accidental double-click on the same link.
  var lastHref = null;
  var lastTime = 0;

  function extensionOf(pathname) {
    var name = pathname.split('/').pop() || '';
    var dot = name.lastIndexOf('.');
    return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
  }

  function startsWithPrefix(pathname) {
    var path = pathname.toLowerCase();
    for (var i = 0; i < prefixes.length; i++) {
      var prefix = String(prefixes[i]).toLowerCase();
      if (prefix && path.indexOf(prefix) === 0) {
        return true;
      }
    }
    return false;
  }

  function decide(anchor) {
    // Never track the plugin's own served-download route - it counts itself.
    if (anchor.pathname.indexOf('/actions/download-tracker/') !== -1) {
      return null;
    }
    if (anchor.closest('[data-dt-ignore]')) {
      return null;
    }
    if (excludeHosts.indexOf(anchor.hostname.toLowerCase()) !== -1) {
      return null;
    }

    var hasDownloadAttr = anchor.hasAttribute('download');

    if (hasDownloadAttr && trackDownloadAttr) {
      return { dl: true };
    }
    if (startsWithPrefix(anchor.pathname)) {
      return { dl: hasDownloadAttr };
    }
    if (extensions.indexOf(extensionOf(anchor.pathname)) !== -1) {
      return { dl: hasDownloadAttr };
    }
    return null;
  }

  function send(anchor, decision) {
    var url =
      config.endpoint +
      (config.endpoint.indexOf('?') === -1 ? '?' : '&') +
      'url=' +
      encodeURIComponent(anchor.href) +
      (decision.dl ? '&dl=1' : '');

    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(url);
      } else {
        fetch(url, { method: 'GET', keepalive: true, credentials: 'same-origin' });
      }
    } catch (e) {
      /* never let tracking interfere with the download */
    }
  }

  document.addEventListener(
    'click',
    function (event) {
      var anchor = event.target.closest && event.target.closest('a[href]');
      if (!anchor) {
        return;
      }

      var decision = decide(anchor);
      if (!decision) {
        return;
      }

      var now = Date.now();
      if (anchor.href === lastHref && now - lastTime < 1500) {
        return;
      }
      lastHref = anchor.href;
      lastTime = now;

      send(anchor, decision);
    },
    true // capture phase, so it runs even if other handlers stop propagation
  );
})();
