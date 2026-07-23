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
 * from its headers alone.
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
     * @var string A crawler, unfurler, monitor, or scripted client.
     */
    public const SIGNAL_CRAWLER = 'crawler';

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
        'googlebot', 'google-inspectiontool', 'storebot-google', 'bingbot',
        'duckduckbot', 'yandexbot', 'baiduspider', 'applebot', 'slurp',
        'petalbot', 'seznambot', 'sogou', 'exabot', 'qwantify', 'neevabot',
        // AI training and retrieval
        'google-extended', 'gptbot', 'oai-searchbot', 'chatgpt-user',
        'claudebot', 'claude-web', 'claude-searchbot', 'claude-user',
        'anthropic-ai', 'perplexitybot', 'perplexity-user', 'ccbot',
        'bytespider', 'amazonbot', 'meta-externalagent', 'meta-externalfetcher',
        'facebookbot', 'applebot-extended', 'diffbot', 'omgili', 'omgilibot',
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

    // Public Methods
    // =========================================================================

    /**
     * Classifies a request from its raw signals.
     *
     * Pure: no Craft, no request object, so it can be exercised directly from
     * `craft tinker` with nothing bootstrapped.
     *
     * @param string $userAgent The raw User-Agent header ('' if absent).
     * @param array<string, string> $headers Lower-cased header name => value.
     * @param string[] $extraTokens Extra crawler tokens, matched as substrings.
     * @return string One of the SIGNAL_* constants.
     */
    public static function classify(string $userAgent, array $headers = [], array $extraTokens = []): string
    {
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

        foreach ($extraTokens as $token) {
            $token = strtolower(trim((string)$token));

            if ($token !== '' && str_contains($userAgent, $token)) {
                return self::SIGNAL_CRAWLER;
            }
        }

        return preg_match(self::GENERIC_PATTERN, $userAgent) ? self::SIGNAL_CRAWLER : self::SIGNAL_HUMAN;
    }

    /**
     * Classifies the current web request.
     *
     * @param string[] $extraTokens Extra crawler tokens, matched as substrings.
     * @return string One of the SIGNAL_* constants.
     */
    public static function classifyCurrentRequest(array $extraTokens = []): string
    {
        $request = Craft::$app->getRequest();

        // Nothing reaches this from the console, but a console request has no
        // headers to read, so answer for the operator running the command.
        if ($request->getIsConsoleRequest()) {
            return self::SIGNAL_HUMAN;
        }

        $headers = [];

        foreach (self::PREFETCH_HEADERS as $name) {
            $headers[$name] = (string)$request->getHeaders()->get($name);
        }

        return self::classify(
            (string)$request->getHeaders()->get('User-Agent'),
            $headers,
            $extraTokens,
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
}
