<?php
/**
 * Download Tracker plugin for Craft CMS 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\services;

use Craft;
use coyshdigital\downloadtracker\events\DownloadEvent;
use coyshdigital\downloadtracker\Plugin;
use coyshdigital\downloadtracker\records\CountRecord;
use coyshdigital\downloadtracker\records\DailyRecord;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\db\Expression;

/**
 * Downloads service - resolves a file's identity and maintains its atomic
 * download counter plus a bounded per-day rollup.
 *
 * The counter is a single row per file (`{{%downloadtracker_counts}}`) updated
 * with an atomic `INSERT … ON DUPLICATE KEY UPDATE`, so concurrent downloads
 * never race and the table never grows per-event - the fix for Link Vault-style
 * element/row-per-download bloat.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Downloads extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string Fired before a download is counted; cancelable.
     */
    public const EVENT_BEFORE_TRACK_DOWNLOAD = 'beforeTrackDownload';

    /**
     * @var string Source type for a Craft asset.
     */
    public const SOURCE_ASSET = 'asset';

    /**
     * @var string Source type for a server file path.
     */
    public const SOURCE_PATH = 'path';

    /**
     * @var string Source type for a URL (local or remote).
     */
    public const SOURCE_URL = 'url';

    // Private Properties
    // =========================================================================

    /**
     * @var string[]|null Cached list of the installation's site hostnames.
     */
    private ?array $_siteHosts = null;

    /**
     * @var array<int, string>|null Cached map of volume ID => public URL path.
     */
    private ?array $_volumeUrlPaths = null;

    // Public Methods
    // =========================================================================

    /**
     * Records one download of the given file.
     *
     * @param Asset|int|string $file An asset, an asset ID, or a URL/path string.
     * @param array<string, mixed> $meta Reserved for future metadata.
     * @param bool $downloadFlag Whether the client flagged this as a forced download.
     * @return bool Whether a download was counted.
     * @throws \yii\db\Exception if the upsert fails.
     */
    public function increment(Asset|int|string $file, array $meta = [], bool $downloadFlag = false): bool
    {
        $identity = $this->resolveIdentity($file, $downloadFlag);

        if ($identity === null) {
            return false;
        }

        $event = new DownloadEvent([
            'downloadKey' => $identity['downloadKey'],
            'assetId' => $identity['assetId'],
            'asset' => $identity['asset'],
            'sourceType' => $identity['sourceType'],
            'filename' => $identity['filename'],
        ]);
        $this->trigger(self::EVENT_BEFORE_TRACK_DOWNLOAD, $event);

        if (!$event->isValid) {
            return false;
        }

        $db = Craft::$app->getDb();
        $nowDate = DateTimeHelper::currentUTCDateTime();
        $now = Db::prepareDateForDb($nowDate);
        $today = $nowDate->format('Y-m-d');

        // The running total: one row per file, incremented atomically.
        $db->createCommand()->upsert(
            CountRecord::tableName(),
            [
                'downloadKey' => $identity['downloadKey'],
                'assetId' => $identity['assetId'],
                'sourceType' => $identity['sourceType'],
                'source' => $identity['source'],
                'filename' => $identity['filename'],
                'count' => 1,
                'lastDownloaded' => $now,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ],
            [
                'count' => new Expression('[[count]] + 1'),
                'lastDownloaded' => $now,
                'dateUpdated' => $now,
                'assetId' => $identity['assetId'],
                'filename' => $identity['filename'],
            ],
        )->execute();

        // The per-day rollup: one row per file per day, incremented atomically.
        $db->createCommand()->upsert(
            DailyRecord::tableName(),
            [
                'downloadKey' => $identity['downloadKey'],
                'date' => $today,
                'count' => 1,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ],
            [
                'count' => new Expression('[[count]] + 1'),
                'dateUpdated' => $now,
            ],
        )->execute();

        return true;
    }

    /**
     * Returns the running total for a file, or 0 if it's never been downloaded.
     *
     * @param Asset|int|string $file
     * @return int
     */
    public function getTotal(Asset|int|string $file): int
    {
        $record = $this->getCountRecord($file);

        return $record ? (int)$record->count : 0;
    }

    /**
     * Returns the counter record for a file, if one exists.
     *
     * @param Asset|int|string $file
     * @return CountRecord|null
     */
    public function getCountRecord(Asset|int|string $file): ?CountRecord
    {
        $identity = $this->resolveIdentity($file);

        if ($identity === null) {
            return null;
        }

        return CountRecord::findOne(['downloadKey' => $identity['downloadKey']]);
    }

    /**
     * Resolves any supported file reference to a canonical tracking identity, or
     * `null` if it isn't trackable.
     *
     * @param Asset|int|string $file
     * @param bool $downloadFlag Whether the client flagged this as a forced download.
     * @return array{downloadKey: string, assetId: int|null, asset: Asset|null, sourceType: string, source: string, filename: string}|null
     */
    public function resolveIdentity(Asset|int|string $file, bool $downloadFlag = false): ?array
    {
        if ($file instanceof Asset) {
            return $this->_assetIdentity($file);
        }

        if (is_int($file) || ctype_digit((string)$file)) {
            $asset = Craft::$app->getAssets()->getAssetById((int)$file);

            return $asset ? $this->_assetIdentity($asset) : null;
        }

        $value = trim($file);

        if ($value === '') {
            return null;
        }

        // Excluded hosts are never tracked, even if they resolve to an asset.
        $host = strtolower((string)parse_url($value, PHP_URL_HOST));
        if ($host !== '' && in_array($host, array_map('strtolower', Plugin::getInstance()->getSettings()->excludedHosts), true)) {
            return null;
        }

        // Prefer unifying with a real asset so beacon hits and served hits share
        // one counter row.
        $asset = $this->_resolveAssetFromUrl($value);

        if ($asset !== null) {
            return $this->_assetIdentity($asset);
        }

        // A public beacon must not be able to mint counter rows for arbitrary,
        // never-pruned keys. Only track non-asset files when explicitly enabled.
        if (!Plugin::getInstance()->getSettings()->trackUnresolvedFiles) {
            return null;
        }

        if (!$this->_isTrackable($value, $downloadFlag)) {
            return null;
        }

        return $this->_stringIdentity($value);
    }

    /**
     * Signs an asset ID into an opaque, tamper-proof token for a download URL.
     * The token carries an issue time so links can optionally be expired.
     *
     * @param int $assetId
     * @return string
     */
    public function signAsset(int $assetId): string
    {
        $payload = $assetId . '|' . DateTimeHelper::currentTimeStamp();

        return bin2hex(Craft::$app->getSecurity()->encryptByKey($payload));
    }

    /**
     * Recovers an asset ID from a signed token, or `null` if it's invalid or
     * (when a lifetime is configured) expired.
     *
     * @param string $token
     * @return int|null
     */
    public function unsignAsset(string $token): ?int
    {
        $token = trim($token);

        if ($token === '' || !ctype_xdigit($token)) {
            return null;
        }

        $ciphertext = @hex2bin($token);

        if ($ciphertext === false) {
            return null;
        }

        try {
            $value = Craft::$app->getSecurity()->decryptByKey($ciphertext);
        } catch (\Throwable) {
            return null;
        }

        // Payload is "assetId|issuedTimestamp".
        [$id, $issued] = array_pad(explode('|', $value, 2), 2, null);

        if (!ctype_digit((string)$id)) {
            return null;
        }

        $ttl = Plugin::getInstance()->getSettings()->signedUrlTtl;

        if ($ttl > 0) {
            if (!ctype_digit((string)$issued) || (DateTimeHelper::currentTimeStamp() - (int)$issued) > $ttl) {
                return null;
            }
        }

        return (int)$id;
    }

    /**
     * Runs a report/list query against the counters.
     *
     * @param array<string, mixed> $criteria Supported keys: search, sourceType,
     *   minCount, dateFrom, dateTo, orderBy, sort, limit, offset.
     * @return array<int, array<string, mixed>>
     */
    public function query(array $criteria = []): array
    {
        return $this->_baseQuery($criteria)->all();
    }

    /**
     * Returns the number of counter rows matching the given criteria (ignoring
     * limit/offset), for pagination.
     *
     * @param array<string, mixed> $criteria
     * @return int
     */
    public function queryTotal(array $criteria = []): int
    {
        $criteria['limit'] = null;
        $criteria['offset'] = null;

        return (int)$this->_baseQuery($criteria)->count();
    }

    /**
     * Returns the most-downloaded files.
     *
     * @param int $limit
     * @param array<string, mixed> $criteria
     * @return array<int, array<string, mixed>>
     */
    public function topDownloads(int $limit = 10, array $criteria = []): array
    {
        return $this->query(array_merge($criteria, [
            'orderBy' => 'count',
            'sort' => 'desc',
            'limit' => $limit,
        ]));
    }

    /**
     * Builds a CSV export of the given report criteria.
     *
     * @param array<string, mixed> $criteria
     * @return string
     */
    public function exportCsv(array $criteria = []): string
    {
        $criteria['limit'] = null;
        $criteria['offset'] = null;
        $rows = $this->query($criteria);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['File', 'Downloads', 'Last downloaded', 'Type', 'Source']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $this->_csvSafe($row['filename']),
                $row['count'],
                $row['lastDownloaded'],
                $row['sourceType'],
                $this->_csvSafe($row['source']),
            ]);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Deletes per-day rollup rows older than the retention window.
     *
     * @param int $retentionDays
     * @return int The number of rows deleted.
     */
    public function pruneDaily(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        $cutoff = DateTimeHelper::currentUTCDateTime()
            ->modify("-$retentionDays days")
            ->format('Y-m-d');

        $db = Craft::$app->getDb();
        $table = DailyRecord::tableName();
        $total = 0;

        // Delete in batches so garbage collection never locks the whole table
        // (portable across MySQL/Postgres, which lack a DELETE … LIMIT in common).
        do {
            $ids = (new Query())
                ->select('id')
                ->from($table)
                ->where(['<', 'date', $cutoff])
                ->limit(1000)
                ->column();

            if ($ids) {
                $total += $db->createCommand()->delete($table, ['id' => $ids])->execute();
            }
        } while (count($ids) === 1000);

        return $total;
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the identity array for an asset.
     *
     * @param Asset $asset
     * @return array{downloadKey: string, assetId: int|null, asset: Asset|null, sourceType: string, source: string, filename: string}
     */
    private function _assetIdentity(Asset $asset): array
    {
        try {
            $url = $asset->getUrl();
        } catch (\Throwable) {
            $url = null;
        }

        return [
            'downloadKey' => 'asset:' . $asset->id,
            'assetId' => (int)$asset->id,
            'asset' => $asset,
            'sourceType' => self::SOURCE_ASSET,
            'source' => $url ?: ('asset:' . $asset->id),
            'filename' => $asset->getFilename(),
        ];
    }

    /**
     * Builds the identity array for a URL or path string.
     *
     * @param string $value
     * @return array{downloadKey: string, assetId: int|null, asset: Asset|null, sourceType: string, source: string, filename: string}
     */
    private function _stringIdentity(string $value): array
    {
        $parts = parse_url($value);
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? $value);
        $filename = basename(rawurldecode($path)) ?: $value;
        $type = ($host !== '') ? self::SOURCE_URL : self::SOURCE_PATH;

        // Key on host+path only, so query strings / cache-busters / fragments
        // don't fragment a single file's counter into many rows.
        $canonical = $host . $path;
        $source = ($host !== '')
            ? (($parts['scheme'] ?? 'https') . '://' . $host . $path)
            : $path;

        return [
            'downloadKey' => $type . ':' . sha1($canonical),
            'assetId' => null,
            'asset' => null,
            'sourceType' => $type,
            'source' => $source,
            'filename' => $filename,
        ];
    }

    /**
     * Neutralises spreadsheet formula injection by prefixing any cell value a
     * spreadsheet app would evaluate as a formula.
     *
     * @param mixed $value
     * @return string
     */
    private function _csvSafe(mixed $value): string
    {
        $value = (string)$value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Best-effort resolution of a local URL/path back to a Craft asset, so a
     * beacon hit and a served-download hit for the same file share one counter.
     *
     * @param string $urlOrPath
     * @return Asset|null
     */
    private function _resolveAssetFromUrl(string $urlOrPath): ?Asset
    {
        $path = (string)parse_url($urlOrPath, PHP_URL_PATH);

        if ($path === '') {
            return null;
        }

        $path = rawurldecode($path);
        $filename = basename($path);

        if ($filename === '') {
            return null;
        }

        foreach ($this->_volumeUrlPaths() as $volumeId => $volPath) {
            if (!str_starts_with($path, $volPath . '/')) {
                continue;
            }

            $subPath = ltrim(substr($path, strlen($volPath)), '/');
            $folderPath = trim(dirname($subPath), '.');
            $folderPath = $folderPath === '' ? '' : ($folderPath . '/');

            $query = Asset::find()
                ->volumeId($volumeId)
                ->filename($filename)
                ->limit(2);

            if ($folderPath !== '') {
                $query->folderPath($folderPath);
            }

            $assets = $query->all();

            if (count($assets) === 1) {
                return $assets[0];
            }

            // Ambiguous within the matched volume - fall back to a path/URL key.
            if (count($assets) > 1) {
                return null;
            }
        }

        return null;
    }

    /**
     * Returns a cached map of volume ID => public URL path, for resolving a
     * download URL back to a volume without re-reading the filesystems per hit.
     *
     * @return array<int, string>
     */
    private function _volumeUrlPaths(): array
    {
        if ($this->_volumeUrlPaths !== null) {
            return $this->_volumeUrlPaths;
        }

        $map = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            try {
                $rootUrl = $volume->getFs()->getRootUrl();
            } catch (\Throwable) {
                continue;
            }

            if (!$rootUrl) {
                continue;
            }

            $volPath = rtrim((string)parse_url($rootUrl, PHP_URL_PATH), '/');

            if ($volPath !== '') {
                $map[$volume->id] = $volPath;
            }
        }

        return $this->_volumeUrlPaths = $map;
    }

    /**
     * Returns whether a URL/path string is safe to track: same-site, and either
     * under a configured prefix, of a tracked extension, or a flagged download.
     *
     * @param string $value
     * @param bool $downloadFlag
     * @return bool
     */
    private function _isTrackable(string $value, bool $downloadFlag): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $host = strtolower((string)parse_url($value, PHP_URL_HOST));

        // Reject anything that isn't same-site (relative URLs have no host).
        if ($host !== '') {
            if (in_array($host, array_map('strtolower', $settings->excludedHosts), true)) {
                return false;
            }
            if (!in_array($host, $this->_siteHosts(), true)) {
                return false;
            }
        }

        $path = strtolower((string)(parse_url($value, PHP_URL_PATH) ?: $value));

        foreach ($settings->trackedPathPrefixes as $prefix) {
            $prefix = strtolower(trim((string)$prefix));
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, $settings->normalizedExtensions(), true)) {
            return true;
        }

        return $downloadFlag && $settings->trackDownloadAttr;
    }

    /**
     * Returns the installation's site hostnames (lower-case), including the
     * current request host.
     *
     * @return string[]
     */
    private function _siteHosts(): array
    {
        if ($this->_siteHosts !== null) {
            return $this->_siteHosts;
        }

        $hosts = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();
            if ($baseUrl) {
                $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
                if ($host !== '') {
                    $hosts[] = $host;
                }
            }
        }

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $hosts[] = strtolower(Craft::$app->getRequest()->getHostName() ?? '');
        }

        return $this->_siteHosts = array_values(array_unique(array_filter($hosts)));
    }

    /**
     * Builds the counter query for the given report/list criteria.
     *
     * @param array<string, mixed> $criteria
     * @return Query
     */
    private function _baseQuery(array $criteria): Query
    {
        $counts = CountRecord::tableName();
        $query = (new Query())->from(['c' => $counts]);

        $dateFrom = $criteria['dateFrom'] ?? null;
        $dateTo = $criteria['dateTo'] ?? null;

        $select = [
            'id' => 'c.id',
            'downloadKey' => 'c.downloadKey',
            'assetId' => 'c.assetId',
            'sourceType' => 'c.sourceType',
            'source' => 'c.source',
            'filename' => 'c.filename',
            'lastDownloaded' => 'c.lastDownloaded',
        ];

        if ($dateFrom || $dateTo) {
            $sub = (new Query())
                ->select(['downloadKey', 'rangeCount' => new Expression('SUM([[count]])')])
                ->from(DailyRecord::tableName());

            if ($dateFrom) {
                $sub->andWhere(['>=', 'date', $dateFrom]);
            }
            if ($dateTo) {
                $sub->andWhere(['<=', 'date', $dateTo]);
            }

            $sub->groupBy('downloadKey');

            $query->innerJoin(['d' => $sub], '[[d.downloadKey]] = [[c.downloadKey]]');
            $select['count'] = 'd.rangeCount';
            $countColumn = 'd.rangeCount';
        } else {
            $select['count'] = 'c.count';
            $countColumn = 'c.count';
        }

        $query->select($select);

        if (!empty($criteria['search'])) {
            $query->andWhere(['like', 'c.filename', $criteria['search']]);
        }
        if (!empty($criteria['sourceType'])) {
            $query->andWhere(['c.sourceType' => $criteria['sourceType']]);
        }
        if (isset($criteria['minCount']) && $criteria['minCount'] !== '' && $criteria['minCount'] !== null) {
            $query->andWhere(['>=', $countColumn, (int)$criteria['minCount']]);
        }

        // Order by the SELECT aliases (count / filename / lastDownloaded).
        $orderColumn = match ($criteria['orderBy'] ?? 'count') {
            'filename' => 'filename',
            'lastDownloaded' => 'lastDownloaded',
            default => 'count',
        };
        $sort = strtolower((string)($criteria['sort'] ?? 'desc')) === 'asc' ? SORT_ASC : SORT_DESC;
        $query->orderBy([$orderColumn => $sort]);

        if (array_key_exists('limit', $criteria)) {
            if ($criteria['limit'] !== null) {
                $query->limit((int)$criteria['limit']);
            }
        } else {
            $query->limit(100);
        }
        if (!empty($criteria['offset'])) {
            $query->offset((int)$criteria['offset']);
        }

        return $query;
    }
}
