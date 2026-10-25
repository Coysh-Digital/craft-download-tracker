<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\helpers;

use Craft;

/**
 * Classifies an inbound request as a person, a crawler, or a browser prefetch,
 * from its headers alone - and tells a download request apart from the other
 * requests a download produces along the way.
 *
 * The classification is deliberately a pure function of the headers, kept out of
 * the Downloads service: the service is also reached from the console and from
 * Twig, where there is no request to inspect.
 *
 * @author Coysh Digital
 * @since 1.1.0
 */
final class RequestSignals
{
    // Constants
    // =========================================================================

    /**
     * @var string A real person, in a real browser.
     */
    public const SIGNAL_HUMAN = 'human';

    /**
     * @var string A crawler, unfurler, monitor, or scripted client that announces
     * itself. Firm enough to refuse a download over, under the 'block' mode.
     */
    public const SIGNAL_CRAWLER = 'crawler';

    /**
     * @var string Probably automated, on a signal too soft to refuse a download
     * over: a match from the distilled third-party token list, or a User-Agent
     * that claims to be a browser which always sends Fetch Metadata headers,
     * arriving without them. Counted as a crawler, never blocked.
     *
     * @since 1.6.0
     */
    public const SIGNAL_SUSPECT = 'suspect';

    /**
     * @var string A browser speculatively fetching ahead of a possible click.
     */
    public const SIGNAL_PREFETCH = 'prefetch';

    /**
     * @var string[] Crawlers that announce themselves by name, lower-case, matched
     * as substrings of the User-Agent.
     *
     * The list exists for detection accuracy only - which crawler it was is never
     * stored, because a per-crawler breakdown would mean a row per crawler per
     * file per day, which is the row growth this plugin exists to avoid.
     */
    private const NAMED_CRAWLERS = [
        // Search engines
        'googlebot', 'google-inspectiontool', 'storebot-google', 'googleother',
        'bingbot', 'duckduckbot', 'yandexbot', 'baiduspider', 'applebot',
        'slurp', 'petalbot', 'seznambot', 'sogou', 'exabot', 'qwantify',
        'neevabot',
        // AI training and retrieval
        'google-extended', 'gptbot', 'oai-searchbot', 'chatgpt-user',
        'claudebot', 'claude-web', 'claude-searchbot', 'claude-user',
        'anthropic-ai', 'perplexitybot', 'perplexity-user', 'ccbot',
        'bytespider', 'amazonbot', 'meta-externalagent', 'meta-externalfetcher',
        'meta-webindexer', 'facebookbot', 'applebot-extended', 'diffbot',
        'omgili', 'omgilibot',
        'timpibot', 'youbot', 'cohere-ai', 'cohere-training-data-crawler',
        'imagesiftbot', 'ai2bot', 'firecrawl', 'mistralai-user',
        // Link unfurlers and social previews
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'slackbot',
        'slack-imgproxy', 'discordbot', 'telegrambot', 'whatsapp',
        'pinterestbot', 'redditbot', 'mastodon', 'embedly', 'skypeuripreview',
        'vkshare', 'tumblr', 'flipboard', 'nuzzel', 'outbrain', 'quora link preview',
        // SEO and monitoring
        'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'rogerbot',
        'screaming frog', 'sitebulb', 'dataforseobot', 'blexbot', 'serpstatbot',
        'uptimerobot', 'pingdom', 'statuscake', 'site24x7', 'newrelicpinger',
        'gtmetrix', 'lighthouse', 'chrome-lighthouse', 'pagespeed',
        // Archives and research
        'ia_archiver', 'archive.org_bot', 'wayback', 'commoncrawl',
        // Scripted clients and headless browsers
        'curl/', 'wget/', 'python-requests', 'python-urllib', 'aiohttp',
        'go-http-client', 'java/', 'okhttp', 'axios/', 'node-fetch', 'got (',
        'guzzlehttp', 'libwww-perl', 'php-curl', 'ruby', 'httpie',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
        'postmanruntime', 'insomnia',
    ];

    /**
     * @var string Catch-all for the long tail of agents that self-identify but
     * aren't named above.
     */
    private const GENERIC_PATTERN = '/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|headless/i';

    /**
     * @var string[] The headers a browser uses to flag a speculative fetch.
     */
    private const PREFETCH_HEADERS = ['sec-purpose', 'purpose', 'x-moz', 'x-purpose'];

    /**
     * @var string[] The Fetch Metadata request headers. A browser that supports
     * them sends at least `Sec-Fetch-Mode` and `Sec-Fetch-Site` on every request
     * to a secure origin, whatever kind of request it is.
     */
    private const FETCH_METADATA_HEADERS = ['sec-fetch-mode', 'sec-fetch-site', 'sec-fetch-dest'];

    /**
     * @var int The first Chromium release that sends every Fetch Metadata header
     * (76 added Mode and Site, 80 added Dest). Covers Chrome, Edge, Opera,
     * Brave, Samsung Internet and Android WebView, which all carry `Chrome/N`.
     */
    private const MIN_CHROME_WITH_FETCH_METADATA = 80;

    /**
     * @var int The first Firefox release that sends Fetch Metadata.
     */
    private const MIN_FIREFOX_WITH_FETCH_METADATA = 90;

    /**
     * @var int The first Safari (and iOS, where every browser is WebKit) major
     * version held to send Fetch Metadata. Support shipped in 16.4; 17 is used so
     * a point-release miscount can't turn a real 16.x user into a suspect.
     */
    private const MIN_WEBKIT_WITH_FETCH_METADATA = 17;

    /**
     * @var string[] HTTP methods that fetch a file's bytes. Anything else (HEAD
     * from a link checker, OPTIONS from a preflight) is not a download.
     */
    private const DOWNLOAD_METHODS = ['GET', 'POST'];

    // Public Methods
    // =========================================================================

    /**
     * Returns whether a candidate token is already covered by the built-in
     * detection - either it matches the generic pattern, or one of the named
     * crawler tokens is a substring of it (so any User-Agent carrying the
     * candidate is already caught). Used when distilling third-party crawler
     * lists, to keep only the tokens that add coverage.
     *
     * @param string $token
     * @return bool
     */
    public static function alreadyDetected(string $token): bool
    {
        $token = strtolower(trim($token));

        if ($token === '') {
            return true;
        }

        if (preg_match(self::GENERIC_PATTERN, $token)) {
            return true;
        }

        foreach (self::NAMED_CRAWLERS as $named) {
            if (str_contains($token, $named)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classifies a request from its raw signals.
     *
     * Pure: no Craft, no request object, so it can be exercised directly from
     * `craft tinker` with nothing bootstrapped.
     *
     * The two token lists differ in how far they're trusted. `$extraTokens` are
     * hand-picked (the site's own setting) and yield {@see SIGNAL_CRAWLER}, which
     * the 'block' mode may refuse. `$softTokens` are the broad distilled list,
     * and yield {@see SIGNAL_SUSPECT}, which is only ever counted separately: a
     * list that wide is more likely to mis-match, and a miscount can be corrected
     * where a refused download can't.
     *
     * @param string $userAgent The raw User-Agent header ('' if absent).
     * @param array<string, string> $headers Lower-cased header name => value.
     * @param string[] $extraTokens Firm crawler tokens, matched as substrings.
     * @param string[] $softTokens Soft crawler tokens, matched as substrings.
     * @param bool $expectFetchMetadata Whether a modern browser would have sent
     * Fetch Metadata headers with this request - true only over HTTPS, since
     * browsers omit them on plain HTTP.
     * @return string One of the SIGNAL_* constants.
     */
    public static function classify(
        string $userAgent,
        array $headers = [],
        array $extraTokens = [],
        array $softTokens = [],
        bool $expectFetchMetadata = false,
    ): string {
        // Prefetch outranks everything else: it's a real browser preparing for a
        // real click, so it's neither a download to count nor a crawler to block.
        if (self::_isPrefetch($headers)) {
            return self::SIGNAL_PREFETCH;
        }

        $userAgent = strtolower(trim($userAgent));

        // No User-Agent at all is never a real browser download.
        if ($userAgent === '') {
            return self::SIGNAL_CRAWLER;
        }

        foreach (self::NAMED_CRAWLERS as $token) {
            if (str_contains($userAgent, $token)) {
                return self::SIGNAL_CRAWLER;
            }
        }

        if (self::_containsToken($userAgent, $extraTokens)) {
            return self::SIGNAL_CRAWLER;
        }

        if (preg_match(self::GENERIC_PATTERN, $userAgent)) {
            return self::SIGNAL_CRAWLER;
        }

        if (self::_containsToken($userAgent, $softTokens)) {
            return self::SIGNAL_SUSPECT;
        }

        // A User-Agent that names a browser which always sends Fetch Metadata,
        // arriving without any, can't be that browser: it's a script, scanner or
        // proxy wearing a browser's User-Agent. The headers are forbidden to page
        // scripts and set by the browser itself, so they can't simply be left
        // off by a real one.
        if ($expectFetchMetadata
            && !self::_hasFetchMetadata($headers)
            && self::_claimsFetchMetadataBrowser($userAgent)
        ) {
            return self::SIGNAL_SUSPECT;
        }

        return self::SIGNAL_HUMAN;
    }

    /**
     * Returns whether a signal should be counted on the crawler side of the
     * people/crawler split.
     *
     * @param string $signal One of the SIGNAL_* constants.
     * @return bool
     * @since 1.6.0
     */
    public static function isCrawler(string $signal): bool
    {
        return $signal === self::SIGNAL_CRAWLER || $signal === self::SIGNAL_SUSPECT;
    }

    /**
     * Returns whether a request is the download itself, rather than one of the
     * other requests a single download throws off along the way.
     *
     * A HEAD from a link checker or download manager fetches no bytes. A `Range`
     * request that doesn't start at byte 0 is a continuation of a download
     * already under way: an inline PDF viewer pulling pages on demand, a media
     * player seeking, or a download manager fetching a file over several
     * connections at once. Each of those arrives at the served route as its own
     * HTTP request, and counting them all would count one download many times.
     *
     * Pure, so it can be exercised with nothing bootstrapped.
     *
     * @param string $method The HTTP method.
     * @param string $range The raw `Range` header ('' if absent).
     * @return bool
     * @since 1.6.0
     */
    public static function isDownloadRequest(string $method, string $range = ''): bool
    {
        if (!in_array(strtoupper(trim($method)), self::DOWNLOAD_METHODS, true)) {
            return false;
        }

        $range = strtolower(str_replace(' ', '', trim($range)));

        if ($range === '') {
            return true;
        }

        // Anything that isn't a byte range we can read is left to count: the
        // worst case is one count too many, which is what happened before.
        if (!preg_match('/^bytes=(\d*)-/', $range, $matches)) {
            return true;
        }

        // "bytes=0-", "bytes=0-1023" and "bytes=0-0,100-200" all open the file
        // from the start; "bytes=500-" and the suffix form "bytes=-500" don't.
        return $matches[1] !== '' && (int)$matches[1] === 0;
    }

    /**
     * Builds the fingerprint under which repeated hits on one file from the
     * same client are collapsed into a single download.
     *
     * It's a one-way hash of the address and User-Agent together: enough to
     * tell one client's burst of requests apart from another's for a minute,
     * and never stored anywhere but the cache, so nothing about the visitor
     * persists. Pure, so it can be exercised with nothing bootstrapped.
     *
     * @param string $ip The client address ('' if unknown).
     * @param string $userAgent The raw User-Agent header ('' if absent).
     * @return string
     * @since 1.6.0
     */
    public static function fingerprint(string $ip, string $userAgent): string
    {
        return sha1(trim($ip) . '|' . trim($userAgent));
    }

    /**
     * Classifies the current web request.
     *
     * @param string[] $extraTokens Firm crawler tokens, matched as substrings.
     * @param string[] $softTokens Soft crawler tokens, matched as substrings.
     * @return string One of the SIGNAL_* constants.
     */
    public static function classifyCurrentRequest(array $extraTokens = [], array $softTokens = []): string
    {
        $request = Craft::$app->getRequest();

        // Nothing reaches this from the console, but a console request has no
        // headers to read, so answer for the operator running the command.
        if ($request->getIsConsoleRequest()) {
            return self::SIGNAL_HUMAN;
        }

        $headers = [];

        foreach (array_merge(self::PREFETCH_HEADERS, self::FETCH_METADATA_HEADERS) as $name) {
            $headers[$name] = (string)$request->getHeaders()->get($name);
        }

        return self::classify(
            (string)$request->getHeaders()->get('User-Agent'),
            $headers,
            $extraTokens,
            $softTokens,
            // Browsers only send Fetch Metadata to secure origins, so their
            // absence means nothing on plain HTTP.
            $request->getIsSecureConnection(),
        );
    }

    /**
     * Returns whether the current web request is the download itself, per
     * {@see isDownloadRequest()}.
     *
     * @return bool
     * @since 1.6.0
     */
    public static function isCurrentRequestDownload(): bool
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return true;
        }

        return self::isDownloadRequest(
            $request->getMethod(),
            (string)$request->getHeaders()->get('Range'),
        );
    }

    /**
     * Fingerprints the current web request, per {@see fingerprint()}.
     *
     * @return string|null The fingerprint, or null from the console, where there
     * is no client to fingerprint and nothing to collapse.
     * @since 1.6.0
     */
    public static function fingerprintCurrentRequest(): ?string
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return null;
        }

        return self::fingerprint(
            (string)$request->getUserIP(),
            (string)$request->getHeaders()->get('User-Agent'),
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns whether the headers flag a speculative fetch.
     *
     * @param array<string, string> $headers Lower-cased header name => value.
     * @return bool
     */
    private static function _isPrefetch(array $headers): bool
    {
        $secPurpose = strtolower($headers['sec-purpose'] ?? '');

        if (str_contains($secPurpose, 'prefetch') || str_contains($secPurpose, 'prerender')) {
            return true;
        }

        foreach (['purpose', 'x-moz', 'x-purpose'] as $name) {
            if (strtolower($headers[$name] ?? '') === 'prefetch') {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether any of the tokens is a substring of the User-Agent.
     *
     * @param string $userAgent Lower-cased User-Agent.
     * @param string[] $tokens
     * @return bool
     */
    private static function _containsToken(string $userAgent, array $tokens): bool
    {
        foreach ($tokens as $token) {
            $token = strtolower(trim((string)$token));

            if ($token !== '' && str_contains($userAgent, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether the request carried any Fetch Metadata header.
     *
     * @param array<string, string> $headers Lower-cased header name => value.
     * @return bool
     */
    private static function _hasFetchMetadata(array $headers): bool
    {
        foreach (self::FETCH_METADATA_HEADERS as $name) {
            if (trim($headers[$name] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether the User-Agent claims to be a browser release that always
     * sends Fetch Metadata headers.
     *
     * Deliberately narrow: it only ever answers true for a User-Agent that names
     * a browser and a version we know to send them. An in-app WebView with no
     * version token, an unknown browser, or an old release all answer false and
     * are treated exactly as before, so the check can only ever catch a
     * User-Agent that is lying, never doubt one that isn't.
     *
     * @param string $userAgent Lower-cased User-Agent.
     * @return bool
     */
    private static function _claimsFetchMetadataBrowser(string $userAgent): bool
    {
        // Every browser on iOS is WebKit and takes its Fetch Metadata support
        // from the OS, whatever it calls itself (CriOS, FxiOS, EdgiOS), so the
        // iOS version is the one that counts there.
        if (preg_match('/\b(?:iphone|ipad|ipod)\b.*?\bos (\d+)_/', $userAgent, $m)) {
            return (int)$m[1] >= self::MIN_WEBKIT_WITH_FETCH_METADATA;
        }

        // Chromium-based: Chrome, Edge, Opera, Brave, Samsung Internet, Android
        // WebView. (Chrome on iOS is "crios" and was handled above.)
        if (preg_match('/\bchrome\/(\d+)/', $userAgent, $m)) {
            return (int)$m[1] >= self::MIN_CHROME_WITH_FETCH_METADATA;
        }

        if (preg_match('/\bfirefox\/(\d+)/', $userAgent, $m)) {
            return (int)$m[1] >= self::MIN_FIREFOX_WITH_FETCH_METADATA;
        }

        // Safari proper carries "Version/N.N ... Safari/NNN"; Chromium carries
        // "Safari/537.36" without a Version token, and was handled above.
        if (str_contains($userAgent, 'safari/') && preg_match('/\bversion\/(\d+)/', $userAgent, $m)) {
            return (int)$m[1] >= self::MIN_WEBKIT_WITH_FETCH_METADATA;
        }

        return false;
    }
}
