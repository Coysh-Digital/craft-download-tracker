<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\tests\unit;

use coyshdigital\downloadtracker\helpers\RequestSignals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure request classifier: who sent a request, whether it's the
 * download itself, and how a client is fingerprinted.
 *
 * @author Coysh Digital
 * @since 1.6.0
 */
final class RequestSignalsTest extends TestCase
{
    // Constants
    // =========================================================================

    private const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    private const EDGE_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.2210.91';
    private const FIREFOX_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0';
    private const SAFARI_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15';
    private const IOS_SAFARI_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';
    private const IOS_CHROME_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1';
    private const ANDROID_WEBVIEW_UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.6099.144 Mobile Safari/537.36';

    /**
     * @var array<string, string> What a real browser sends on a navigation.
     */
    private const NAVIGATION_HEADERS = [
        'sec-fetch-mode' => 'navigate',
        'sec-fetch-site' => 'same-origin',
        'sec-fetch-dest' => 'document',
        'sec-fetch-user' => '?1',
    ];

    /**
     * @var array<string, string> What a real browser sends from `sendBeacon()`.
     */
    private const BEACON_HEADERS = [
        'sec-fetch-mode' => 'no-cors',
        'sec-fetch-site' => 'same-origin',
        'sec-fetch-dest' => 'empty',
    ];

    // Who sent it
    // =========================================================================

    #[DataProvider('namedCrawlers')]
    public function testNamedCrawlersAreFirmSignals(string $userAgent): void
    {
        self::assertSame(RequestSignals::SIGNAL_CRAWLER, RequestSignals::classify($userAgent));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namedCrawlers(): iterable
    {
        yield 'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        yield 'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.0; +https://openai.com/gptbot'];
        yield 'Bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'];
        yield 'facebookexternalhit' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'];
        yield 'Slack unfurler' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)'];
        yield 'curl' => ['curl/8.4.0'];
        yield 'python-requests' => ['python-requests/2.31.0'];
        yield 'headless Chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36'];
        yield 'UptimeRobot' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'];
        yield 'generic bot token' => ['Mozilla/5.0 (compatible; AcmeBot/1.0)'];
        yield 'generic crawl token' => ['SomeCrawler/3.2'];
        yield 'empty User-Agent' => [''];
        yield 'whitespace User-Agent' => ['   '];
    }

    #[DataProvider('realBrowsers')]
    public function testRealBrowsersAreHuman(string $userAgent): void
    {
        self::assertSame(RequestSignals::SIGNAL_HUMAN, RequestSignals::classify($userAgent, self::NAVIGATION_HEADERS, [], [], true));
        self::assertSame(RequestSignals::SIGNAL_HUMAN, RequestSignals::classify($userAgent, self::BEACON_HEADERS, [], [], true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function realBrowsers(): iterable
    {
        yield 'Chrome' => [self::CHROME_UA];
        yield 'Edge' => [self::EDGE_UA];
        yield 'Firefox' => [self::FIREFOX_UA];
        yield 'Safari' => [self::SAFARI_UA];
        yield 'iOS Safari' => [self::IOS_SAFARI_UA];
        yield 'iOS Chrome' => [self::IOS_CHROME_UA];
        yield 'Android WebView' => [self::ANDROID_WEBVIEW_UA];
    }

    public function testExtraTokensAreFirmSignals(): void
    {
        $userAgent = 'Mozilla/5.0 (compatible; AcmeFetcher/2.0)';

        self::assertSame(RequestSignals::SIGNAL_HUMAN, RequestSignals::classify($userAgent));
        self::assertSame(RequestSignals::SIGNAL_CRAWLER, RequestSignals::classify($userAgent, [], ['acmefetcher']));
        self::assertSame(RequestSignals::SIGNAL_CRAWLER, RequestSignals::classify($userAgent, [], [' AcmeFetcher ']), 'Tokens are trimmed and matched case-insensitively.');
    }

    public function testSoftTokensAreSuspectNotCrawler(): void
    {
        $userAgent = 'Mozilla/5.0 (compatible; AcmeFetcher/2.0)';

        self::assertSame(RequestSignals::SIGNAL_SUSPECT, RequestSignals::classify($userAgent, [], [], ['acmefetcher']));
    }

    public function testFirmSignalOutranksSoftOne(): void
    {
        // A User-Agent on both lists is a firm match: the hand-picked token wins.
        $userAgent = 'Mozilla/5.0 (compatible; AcmeFetcher/2.0)';

        self::assertSame(RequestSignals::SIGNAL_CRAWLER, RequestSignals::classify($userAgent, [], ['acmefetcher'], ['acmefetcher']));

        // And a generic giveaway is firm even when only the soft list names it.
        self::assertSame(RequestSignals::SIGNAL_CRAWLER, RequestSignals::classify('AcmeBot/1.0', [], [], ['acmebot']));
    }

    public function testEmptyTokensAreIgnored(): void
    {
        self::assertSame(RequestSignals::SIGNAL_HUMAN, RequestSignals::classify(self::CHROME_UA, self::NAVIGATION_HEADERS, ['', '  '], ['', "\n"], true));
    }

    #[DataProvider('prefetchHeaders')]
    public function testPrefetchOutranksEverything(array $headers): void
    {
        // Even a self-announcing crawler reads as a prefetch when it says so;
        // and a browser with no Fetch Metadata does too.
        self::assertSame(RequestSignals::SIGNAL_PREFETCH, RequestSignals::classify('curl/8.4.0', $headers));
        self::assertSame(RequestSignals::SIGNAL_PREFETCH, RequestSignals::classify(self::CHROME_UA, $headers, [], [], true));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function prefetchHeaders(): iterable
    {
        yield 'Sec-Purpose: prefetch' => [['sec-purpose' => 'prefetch']];
        yield 'Sec-Purpose: prefetch;prerender' => [['sec-purpose' => 'prefetch;prerender']];
        yield 'Purpose: prefetch' => [['purpose' => 'prefetch']];
        yield 'X-Moz: prefetch' => [['x-moz' => 'prefetch']];
        yield 'X-Purpose: prefetch' => [['x-purpose' => 'prefetch']];
        yield 'mixed case' => [['purpose' => 'Prefetch']];
    }

    public function testUnrelatedPurposeIsNotPrefetch(): void
    {
        self::assertSame(RequestSignals::SIGNAL_HUMAN, RequestSignals::classify(self::CHROME_UA, ['purpose' => 'preview'] + self::NAVIGATION_HEADERS, [], [], true));
    }

    // Browsers that can't be what they claim
    // =========================================================================

    #[DataProvider('browsersThatSendFetchMetadata')]
    public function testModernBrowserWithoutFetchMetadataIsSuspect(string $userAgent): void
    {
        self::assertSame(RequestSignals::SIGNAL_SUSPECT, RequestSignals::classify($userAgent, [], [], [], true));
        self::assertSame(RequestSignals::SIGNAL_SUSPECT, RequestSignals::classify($userAgent, ['sec-fetch-mode' => '', 'sec-fetch-site' => '  '], [], [], true), 'Empty header values are the same as absent ones.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function browsersThatSendFetchMetadata(): iterable
    {
        yield 'Chrome 120' => [self::CHROME_UA];
        yield 'Edge 120' => [self::EDGE_UA];
        yield 'Chrome 80 (first to send Sec-Fetch-Dest)' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.149 Safari/537.36'];
        yield 'Firefox 121' => [self::FIREFOX_UA];
        yield 'Firefox 90 (first to send)' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:90.0) Gecko/20100101 Firefox/90.0'];
        yield 'Safari 17' => [self::SAFARI_UA];
        yield 'iOS 17 Safari' => [self::IOS_SAFARI_UA];
        yield 'iOS 17 Chrome (WebKit underneath)' => [self::IOS_CHROME_UA];
        yield 'iPad iOS 17' => ['Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1'];
        yield 'Android WebView on Chromium 120' => [self::ANDROID_WEBVIEW_UA];
        yield 'Samsung Internet on Chromium 115' => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36'];
    }

    #[DataProvider('browsersThatMightNotSendFetchMetadata')]
    public function testOlderOrUnknownBrowserWithoutFetchMetadataStaysHuman(string $userAgent): void
    {
        self::assertSame(RequestSignals::SIGNAL_HUMAN, RequestSignals::classify($userAgent, [], [], [], true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function browsersThatMightNotSendFetchMetadata(): iterable
    {
        yield 'Chrome 79' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36'];
        yield 'Firefox 89' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0'];
        yield 'Safari 16' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.3 Safari/605.1.15'];
        yield 'iOS 16 Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 16_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.3 Mobile/15E148 Safari/604.1'];
        yield 'iOS 16 Chrome (WebKit underneath, whatever CriOS says)' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 16_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1'];
        yield 'iOS in-app WebView with no version token' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 15_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/420.0.0.0]'];
        yield 'Internet Explorer 11' => ['Mozilla/5.0 (Windows NT 10.0; WOW64; Trident/7.0; rv:11.0) like Gecko'];
        yield 'unknown browser' => ['AcmeBrowser/1.0'];
        yield 'bare Mozilla' => ['Mozilla/5.0'];
    }

    public function testFetchMetadataIsNotExpectedOverPlainHttp(): void
    {
        // Browsers don't send Fetch Metadata to insecure origins, so a modern
        // User-Agent without it over HTTP is still a person.
        self::assertSame(RequestSignals::SIGNAL_HUMAN, RequestSignals::classify(self::CHROME_UA, [], [], [], false));
        self::assertSame(RequestSignals::SIGNAL_HUMAN, RequestSignals::classify(self::CHROME_UA));
    }

    public function testAnySingleFetchMetadataHeaderVouchesForTheBrowser(): void
    {
        foreach (['sec-fetch-mode' => 'navigate', 'sec-fetch-site' => 'none', 'sec-fetch-dest' => 'document'] as $name => $value) {
            self::assertSame(RequestSignals::SIGNAL_HUMAN, RequestSignals::classify(self::CHROME_UA, [$name => $value], [], [], true), $name);
        }
    }

    public function testNamedCrawlerWithFetchMetadataIsStillACrawler(): void
    {
        // Real headers don't launder a self-announcing crawler.
        self::assertSame(RequestSignals::SIGNAL_CRAWLER, RequestSignals::classify('Mozilla/5.0 (compatible; Googlebot/2.1)', self::NAVIGATION_HEADERS, [], [], true));
    }

    // Signal helpers
    // =========================================================================

    public function testIsCrawlerCoversBothCrawlerSignals(): void
    {
        self::assertTrue(RequestSignals::isCrawler(RequestSignals::SIGNAL_CRAWLER));
        self::assertTrue(RequestSignals::isCrawler(RequestSignals::SIGNAL_SUSPECT));
        self::assertFalse(RequestSignals::isCrawler(RequestSignals::SIGNAL_HUMAN));
        self::assertFalse(RequestSignals::isCrawler(RequestSignals::SIGNAL_PREFETCH));
    }

    public function testAlreadyDetectedCoversNamedAndGenericTokens(): void
    {
        self::assertTrue(RequestSignals::alreadyDetected('googlebot'));
        self::assertTrue(RequestSignals::alreadyDetected('Mozilla/5.0 (compatible; bingbot/2.0)'));
        self::assertTrue(RequestSignals::alreadyDetected('somecrawler'));
        self::assertTrue(RequestSignals::alreadyDetected(''));
        self::assertFalse(RequestSignals::alreadyDetected('acmefetcher'));
    }

    // Is it the download itself
    // =========================================================================

    #[DataProvider('downloadRequests')]
    public function testDownloadRequestsAreCounted(string $method, string $range): void
    {
        self::assertTrue(RequestSignals::isDownloadRequest($method, $range));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function downloadRequests(): iterable
    {
        yield 'plain GET' => ['GET', ''];
        yield 'lower-case get' => ['get', ''];
        yield 'POST' => ['POST', ''];
        yield 'open-ended range from the start' => ['GET', 'bytes=0-'];
        yield 'bounded range from the start' => ['GET', 'bytes=0-1023'];
        yield 'multi-range opening at the start' => ['GET', 'bytes=0-0, 100-200'];
        yield 'spaces in the range' => ['GET', 'bytes = 0 - 1023'];
        yield 'unreadable range unit is left to count' => ['GET', 'items=5-10'];
        yield 'malformed range is left to count' => ['GET', 'bytes'];
    }

    #[DataProvider('nonDownloadRequests')]
    public function testOtherRequestsAreNotCounted(string $method, string $range): void
    {
        self::assertFalse(RequestSignals::isDownloadRequest($method, $range));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonDownloadRequests(): iterable
    {
        yield 'HEAD' => ['HEAD', ''];
        yield 'OPTIONS' => ['OPTIONS', ''];
        yield 'lower-case head' => ['head', ''];
        yield 'HEAD with a range' => ['HEAD', 'bytes=0-'];
        yield 'PDF viewer paging' => ['GET', 'bytes=65536-131071'];
        yield 'download manager slice' => ['GET', 'bytes=1048576-'];
        yield 'media player seek' => ['GET', 'bytes=500-999'];
        yield 'suffix range' => ['GET', 'bytes=-500'];
        yield 'multi-range not opening at the start' => ['GET', 'bytes=100-200, 0-0'];
        yield 'upper-case unit' => ['GET', 'BYTES=100-'];
    }

    // Fingerprinting
    // =========================================================================

    public function testFingerprintIsStableAndOpaque(): void
    {
        $fingerprint = RequestSignals::fingerprint('203.0.113.7', self::CHROME_UA);

        self::assertSame($fingerprint, RequestSignals::fingerprint('203.0.113.7', self::CHROME_UA));
        self::assertSame($fingerprint, RequestSignals::fingerprint(' 203.0.113.7 ', self::CHROME_UA), 'Whitespace is trimmed.');
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $fingerprint);
        self::assertStringNotContainsString('203.0.113.7', $fingerprint);
    }

    public function testFingerprintTellsClientsApart(): void
    {
        $base = RequestSignals::fingerprint('203.0.113.7', self::CHROME_UA);

        self::assertNotSame($base, RequestSignals::fingerprint('203.0.113.8', self::CHROME_UA), 'Different address.');
        self::assertNotSame($base, RequestSignals::fingerprint('203.0.113.7', self::FIREFOX_UA), 'Different browser.');
        self::assertNotSame($base, RequestSignals::fingerprint('', self::CHROME_UA), 'Unknown address.');
    }
}
