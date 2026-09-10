<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\services;

use Craft;
use coyshdigital\downloadtracker\helpers\CrawlerTokens;
use coyshdigital\downloadtracker\Plugin;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use yii\base\Component;

/**
 * Owns the distilled third-party crawler token list: loading it for
 * classification, and refreshing it from the upstream community sources.
 *
 * A baseline list ships with the plugin (`src/data/crawler-tokens.txt`), so
 * detection is wider out of the box with nothing to configure. An admin can
 * refresh it from the control panel; the refreshed list is written to the
 * runtime path and takes precedence over the baseline. Both are produced by the
 * same pure {@see CrawlerTokens} distiller, so a refresh only ever moves the
 * list forward from the same inputs - it can't drift into a different shape.
 *
 * @author Coysh Digital
 * @since 1.5.0
 */
class Crawlers extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The Kikobeats top-crawler-agents source (JSON array of UAs).
     */
    public const SOURCE_KIKOBEATS = 'https://raw.githubusercontent.com/Kikobeats/top-crawler-agents/master/index.json';

    /**
     * @var string The monperrus crawler-user-agents source (JSON patterns).
     */
    public const SOURCE_MONPERRUS = 'https://raw.githubusercontent.com/monperrus/crawler-user-agents/master/crawler-user-agents.json';

    /**
     * @var string The matomo device-detector bots fixture (YAML user_agents).
     */
    public const SOURCE_MATOMO = 'https://raw.githubusercontent.com/matomo-org/device-detector/master/Tests/fixtures/bots.yml';

    // Private Properties
    // =========================================================================

    /**
     * @var string[]|null Memoised effective token list.
     */
    private ?array $_tokens = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the full set of extra crawler tokens for classification: the
     * admin's own tokens plus the distilled third-party list.
     *
     * @return string[]
     */
    public function classificationTokens(): array
    {
        return array_values(array_unique(array_merge(
            Plugin::getInstance()->getSettings()->normalizedCrawlerUserAgents(),
            $this->tokens(),
        )));
    }

    /**
     * Returns the effective distilled token list - the refreshed one if it
     * exists, otherwise the baseline shipped with the plugin.
     *
     * @return string[]
     */
    public function tokens(): array
    {
        if ($this->_tokens !== null) {
            return $this->_tokens;
        }

        $contents = $this->_read($this->_storageFile()) ?? $this->_read($this->_baselineFile());

        return $this->_tokens = $contents !== null ? CrawlerTokens::fromFile($contents) : [];
    }

    /**
     * Refreshes the distilled list from the three community sources.
     *
     * Downloads what it can, distils, and writes the result to the runtime path
     * only if at least one source came back - a total outage never wipes the
     * working list. Individual source failures are reported but don't abort.
     *
     * @return array{tokens: int, added: int, removed: int, sources: array<int, array{name: string, ok: bool, note: string}>}
     * @throws \RuntimeException if every source failed.
     */
    public function refresh(): array
    {
        $before = $this->tokens();

        $client = Craft::createGuzzleClient(['timeout' => 25, 'connect_timeout' => 10]);
        $bodies = ['kikobeats' => '', 'monperrus' => '', 'matomo' => ''];
        $sources = [];
        $anyOk = false;

        foreach ([
            ['kikobeats', 'Kikobeats top-crawler-agents', self::SOURCE_KIKOBEATS],
            ['monperrus', 'monperrus crawler-user-agents', self::SOURCE_MONPERRUS],
            ['matomo', 'matomo device-detector bots', self::SOURCE_MATOMO],
        ] as [$key, $name, $url]) {
            try {
                $body = (string)$client->get($url)->getBody();
                $bodies[$key] = $body;
                $anyOk = true;
                $sources[] = ['name' => $name, 'ok' => true, 'note' => $this->_formatBytes(strlen($body))];
            } catch (\Throwable $e) {
                $sources[] = ['name' => $name, 'ok' => false, 'note' => $e->getMessage()];
                Craft::warning("Crawler source failed ($url): " . $e->getMessage(), __METHOD__);
            }
        }

        if (!$anyOk) {
            throw new \RuntimeException('None of the crawler sources could be reached.');
        }

        $tokens = CrawlerTokens::distil($bodies['kikobeats'], $bodies['monperrus'], $bodies['matomo']);

        $this->_write($tokens, $sources);
        $this->_tokens = $tokens;

        return [
            'tokens' => count($tokens),
            'added' => count(array_diff($tokens, $before)),
            'removed' => count(array_diff($before, $tokens)),
            'sources' => $sources,
        ];
    }

    /**
     * Returns a summary of the current list for the control panel.
     *
     * @return array{count: int, refreshedAt: \DateTime|null, usingBaseline: bool, sources: array<int, array{name: string, ok: bool, note: string}>}
     */
    public function status(): array
    {
        $meta = $this->_readMeta();

        return [
            'count' => count($this->tokens()),
            'refreshedAt' => $meta['refreshedAt'],
            'usingBaseline' => !is_file($this->_storageFile()),
            'sources' => $meta['sources'],
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Writes the refreshed token list and its metadata to the runtime path.
     *
     * @param string[] $tokens
     * @param array<int, array{name: string, ok: bool, note: string}> $sources
     * @return void
     */
    private function _write(array $tokens, array $sources): void
    {
        $dir = dirname($this->_storageFile());
        FileHelper::createDirectory($dir);

        file_put_contents($this->_storageFile(), CrawlerTokens::toFile($tokens));
        file_put_contents($this->_metaFile(), Json::encode([
            'refreshedAt' => gmdate('c'),
            'count' => count($tokens),
            'sources' => $sources,
        ]));
    }

    /**
     * Reads and normalises the metadata file, if present.
     *
     * @return array{refreshedAt: \DateTime|null, sources: array<int, mixed>}
     */
    private function _readMeta(): array
    {
        $raw = $this->_read($this->_metaFile());
        $data = $raw !== null ? Json::decodeIfJson($raw) : null;

        if (!is_array($data)) {
            return ['refreshedAt' => null, 'sources' => []];
        }

        $refreshedAt = null;
        if (!empty($data['refreshedAt'])) {
            try {
                $refreshedAt = new \DateTime((string)$data['refreshedAt']);
            } catch (\Throwable) {
                $refreshedAt = null;
            }
        }

        return [
            'refreshedAt' => $refreshedAt,
            'sources' => is_array($data['sources'] ?? null) ? $data['sources'] : [],
        ];
    }

    /**
     * Reads a file, returning null if it's absent or unreadable.
     *
     * @param string $path
     * @return string|null
     */
    private function _read(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return ($contents === false || $contents === '') ? null : $contents;
    }

    /**
     * The baseline list shipped with the plugin.
     *
     * @return string
     */
    private function _baselineFile(): string
    {
        return Craft::getAlias('@coyshdigital/downloadtracker/data/crawler-tokens.txt');
    }

    /**
     * The refreshed list written by an admin, under the runtime path.
     *
     * @return string
     */
    private function _storageFile(): string
    {
        return Craft::$app->getPath()->getRuntimePath() . '/download-tracker/crawler-tokens.txt';
    }

    /**
     * The metadata companion to the refreshed list.
     *
     * @return string
     */
    private function _metaFile(): string
    {
        return Craft::$app->getPath()->getRuntimePath() . '/download-tracker/crawler-tokens.meta.json';
    }

    /**
     * Formats a byte count for the sources summary.
     *
     * @param int $bytes
     * @return string
     */
    private function _formatBytes(int $bytes): string
    {
        return $bytes >= 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' B';
    }
}
