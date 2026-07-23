<?php
/**
 * Download Tracker plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\downloadtracker\services;

use Craft;
use coyshdigital\downloadtracker\Plugin;
use coyshdigital\downloadtracker\records\CountRecord;
use coyshdigital\downloadtracker\records\DailyRecord;
use coyshdigital\downloadtracker\records\ImportRecord;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\db\Expression;

/**
 * Link Vault import service - folds Link Vault's per-event download log into
 * this plugin's atomic counters.
 *
 * Link Vault stores an element per download; we store a count per file. The
 * import is therefore lossy by design, and deliberately so: the whole reason to
 * leave Link Vault is that a row per event is what made it heavy.
 *
 * Two properties carry the design:
 *
 * 1. Aggregation happens in SQL, grouped by file *and* day, so a batch costs one
 *    query no matter how many events it spans.
 * 2. Every write is additive (`count = count + n`). That makes batches
 *    commutative - they merge into each other, into rows we already had, and
 *    into rows a later batch touches - so the job can chunk and resume anywhere
 *    without ordering or barriers. `lastRowId` is what stops a *re-run* from
 *    double-counting.
 *
 * Link Vault's tables are read with raw queries and its classes are never
 * referenced, because the point of running this is to uninstall it afterwards.
 *
 * @author Coysh Digital
 * @since 1.2.0
 */
class LinkVaultImport extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string Link Vault's download log table.
     */
    public const LINK_VAULT_TABLE = '{{%linkvault_downloads}}';

    /**
     * @var string The `type` Link Vault gives a real download.
     */
    private const TYPE_DOWNLOAD = 'Download';

    /**
     * @var string The `type` Link Vault gives a blocked hotlink attempt.
     */
    private const TYPE_LEECH = 'Leech Attempt';

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, array|null> Memoised identity per file group, so a file
     *   downloaded on 400 days costs one asset lookup rather than 400.
     */
    private array $_identities = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns whether Link Vault's download table is present to import from.
     *
     * This is what gates the whole feature in the CP: once Link Vault is
     * uninstalled the table goes, and so does every trace of the importer.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return Craft::$app->getDb()->tableExists(self::LINK_VAULT_TABLE);
    }

    /**
     * Returns the import's stored state, creating it on first look.
     *
     * @return ImportRecord
     */
    public function getState(): ImportRecord
    {
        $record = ImportRecord::findOne(['source' => ImportRecord::SOURCE_LINK_VAULT]);

        if ($record === null) {
            $record = new ImportRecord([
                'source' => ImportRecord::SOURCE_LINK_VAULT,
            ]);
            $record->save(false);
        }

        return $record;
    }

    /**
     * Returns a dry run: what an import would do, without doing any of it.
     *
     * @return array{available: bool, lastRowId: int, maxRowId: int, imported: int, pending: int, files: int, leech: int, deleted: int, retentionDays: int, dailyCutoff: string|null, dateFinished: string|null}
     */
    public function getPreview(): array
    {
        $state = $this->getState();
        $lastRowId = (int)$state->lastRowId;
        $retentionDays = Plugin::getInstance()->getSettings()->dailyRetentionDays;

        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'lastRowId' => $lastRowId,
                'maxRowId' => 0,
                'imported' => (int)$state->rowsImported,
                'pending' => 0,
                'files' => 0,
                'leech' => 0,
                'deleted' => 0,
                'retentionDays' => $retentionDays,
                'dailyCutoff' => $this->_dailyCutoff(),
                'dateFinished' => $state->dateFinished,
            ];
        }

        $pendingGroups = (new Query())
            ->select(['d.assetId', 'd.dirName', 'd.fileName', 'd.isUrl'])
            ->distinct()
            ->from(['d' => self::LINK_VAULT_TABLE])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[d.id]]')
            ->where(['d.type' => self::TYPE_DOWNLOAD])
            ->andWhere(['e.dateDeleted' => null])
            ->andWhere(['>', 'd.id', $lastRowId]);

        return [
            'available' => true,
            'lastRowId' => $lastRowId,
            'maxRowId' => $this->getMaxRowId(),
            'imported' => (int)$state->rowsImported,
            // Cast: `count()` hands back a string, which would slip past a
            // strict comparison and make "nothing to do" look like work.
            'pending' => (int)$this->_pendingQuery($lastRowId)->count('*'),
            // Distinct Link Vault file groups. A ceiling on the counter rows
            // this would touch, not an exact count: two groups can resolve to
            // one file (an asset row and a URL row for the same asset).
            'files' => (int)(new Query())->from(['g' => $pendingGroups])->count('*'),
            'leech' => (int)(new Query())
                ->from(['d' => self::LINK_VAULT_TABLE])
                ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[d.id]]')
                ->where(['d.type' => self::TYPE_LEECH])
                ->andWhere(['e.dateDeleted' => null])
                ->andWhere(['>', 'd.id', $lastRowId])
                ->count('*'),
            'deleted' => (int)(new Query())
                ->from(['d' => self::LINK_VAULT_TABLE])
                ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[d.id]]')
                ->where(['not', ['e.dateDeleted' => null]])
                ->andWhere(['>', 'd.id', $lastRowId])
                ->count('*'),
            'retentionDays' => $retentionDays,
            'dailyCutoff' => $this->_dailyCutoff(),
            'dateFinished' => $state->dateFinished,
        ];
    }

    /**
     * Returns the highest row ID in Link Vault's log - the finish line.
     *
     * @return int
     */
    public function getMaxRowId(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        return (int)(new Query())
            ->from(self::LINK_VAULT_TABLE)
            ->max('[[id]]');
    }

    /**
     * Imports one window of Link Vault rows into the counters.
     *
     * The window is an ID range rather than a `LIMIT`/`OFFSET` page: sparse IDs
     * cost nothing, and the window can't shift under concurrent writes the way
     * an offset can.
     *
     * @param int $lowId Exclusive lower bound.
     * @param int $highId Inclusive upper bound.
     * @return array{rows: int, skipped: int} Events imported, and events dropped
     *   because their file couldn't be identified.
     * @throws \Throwable if the batch fails; the transaction rolls back and
     *   `lastRowId` stays put, so the batch is simply retried.
     */
    public function importBatch(int $lowId, int $highId): array
    {
        $groups = $this->_aggregate($lowId, $highId);

        /** @var array<string, array{identity: array, total: int, last: string|null}> $counts */
        $counts = [];
        /** @var array<string, array{key: string, date: string, total: int}> $daily */
        $daily = [];
        $rows = 0;
        $skipped = 0;
        $dailyCutoff = $this->_dailyCutoff();

        // Two Link Vault groups can collapse onto one file, so merge in PHP
        // before touching the DB - fewer round trips, and the totals are right
        // either way because the upserts add rather than set.
        foreach ($groups as $group) {
            $hits = (int)$group['hits'];
            $identity = $this->_identityFor($group);

            if ($identity === null) {
                $skipped += $hits;
                continue;
            }

            $rows += $hits;
            $key = $identity['downloadKey'];
            $lastHit = (string)$group['lastHit'];

            if (!isset($counts[$key])) {
                $counts[$key] = ['identity' => $identity, 'total' => 0, 'last' => null];
            }

            $counts[$key]['total'] += $hits;

            if ($counts[$key]['last'] === null || $lastHit > $counts[$key]['last']) {
                $counts[$key]['last'] = $lastHit;
            }

            // `date` comes back formatted differently per driver, so trim rather
            // than trust it - the same reason `dailySeries()` does.
            $date = substr((string)$group['day'], 0, 10);

            // Rollup rows this old would be deleted by the next garbage
            // collection anyway, so don't write them just to delete them. The
            // file's running total above still includes these hits.
            if ($dailyCutoff !== null && $date < $dailyCutoff) {
                continue;
            }

            $dailyKey = $key . '|' . $date;

            if (!isset($daily[$dailyKey])) {
                $daily[$dailyKey] = ['key' => $key, 'date' => $date, 'total' => 0];
            }

            $daily[$dailyKey]['total'] += $hits;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            foreach ($counts as $row) {
                $this->_upsertCount($row['identity'], $row['total'], $row['last']);
            }

            foreach ($daily as $row) {
                $this->_upsertDaily($row['key'], $row['date'], $row['total']);
            }

            $state = $this->getState();
            $state->lastRowId = $highId;
            $state->rowsImported = (int)$state->rowsImported + $rows;
            $state->rowsSkipped = (int)$state->rowsSkipped + $skipped;
            $state->save(false);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * Marks the import finished and records how many files it ended up touching.
     *
     * @return void
     */
    public function markFinished(): void
    {
        $state = $this->getState();

        if ($this->isAvailable()) {
            $groups = (new Query())
                ->select(['d.assetId', 'd.dirName', 'd.fileName', 'd.isUrl'])
                ->distinct()
                ->from(['d' => self::LINK_VAULT_TABLE])
                ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[d.id]]')
                ->where(['d.type' => self::TYPE_DOWNLOAD])
                ->andWhere(['e.dateDeleted' => null])
                ->andWhere(['<=', 'd.id', (int)$state->lastRowId]);

            $state->filesTouched = (int)(new Query())->from(['g' => $groups])->count('*');
        }

        $state->dateFinished = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $state->save(false);
    }

    /**
     * Forgets that anything was imported, so the next run starts from scratch.
     *
     * This does not remove imported counts - it can't, since imported and native
     * hits share a column by design. It's for the case where the counters were
     * restored from a backup taken before the import.
     *
     * @return void
     */
    public function reset(): void
    {
        $state = $this->getState();
        $state->lastRowId = 0;
        $state->rowsImported = 0;
        $state->rowsSkipped = 0;
        $state->filesTouched = 0;
        $state->dateFinished = null;
        $state->save(false);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the earliest day worth writing a rollup row for, or null when
     * rollups are kept forever.
     *
     * @return string|null
     */
    private function _dailyCutoff(): ?string
    {
        $days = Plugin::getInstance()->getSettings()->dailyRetentionDays;

        if ($days <= 0) {
            return null;
        }

        return DateTimeHelper::currentUTCDateTime()
            ->modify("-$days days")
            ->format('Y-m-d');
    }

    /**
     * Counts the importable rows above a high-water mark.
     *
     * @param int $lastRowId
     * @return Query
     */
    private function _pendingQuery(int $lastRowId): Query
    {
        return (new Query())
            ->from(['d' => self::LINK_VAULT_TABLE])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[d.id]]')
            ->where(['d.type' => self::TYPE_DOWNLOAD])
            ->andWhere(['e.dateDeleted' => null])
            ->andWhere(['>', 'd.id', $lastRowId]);
    }

    /**
     * Rolls a window of Link Vault events up to one row per file per day.
     *
     * Link Vault rows are elements, so they can be soft-deleted; joining
     * `elements` and honouring `dateDeleted` is what stops a "deleted" download
     * from being resurrected by the import. Leech attempts share the table and
     * are excluded here - they were blocked requests, not downloads.
     *
     * @param int $lowId
     * @param int $highId
     * @return array<int, array{assetId: int|null, dirName: string|null, fileName: string|null, isUrl: int|null, day: string, hits: int, lastHit: string}>
     */
    private function _aggregate(int $lowId, int $highId): array
    {
        // `CAST(… AS DATE)` rather than MySQL's `DATE()`, so this runs on
        // Postgres too. Craft stores `dateCreated` in UTC and `increment()`
        // derives the daily date in UTC, so the days already line up.
        $day = new Expression('CAST([[d.dateCreated]] AS DATE)');

        return (new Query())
            ->select([
                'assetId' => '[[d.assetId]]',
                'dirName' => '[[d.dirName]]',
                'fileName' => '[[d.fileName]]',
                'isUrl' => '[[d.isUrl]]',
                'day' => $day,
                'hits' => new Expression('COUNT(*)'),
                'lastHit' => new Expression('MAX([[d.dateCreated]])'),
            ])
            ->from(['d' => self::LINK_VAULT_TABLE])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[d.id]]')
            ->where(['d.type' => self::TYPE_DOWNLOAD])
            ->andWhere(['e.dateDeleted' => null])
            ->andWhere(['>', 'd.id', $lowId])
            ->andWhere(['<=', 'd.id', $highId])
            ->groupBy([
                '[[d.assetId]]',
                '[[d.dirName]]',
                '[[d.fileName]]',
                '[[d.isUrl]]',
                $day,
            ])
            ->all();
    }

    /**
     * Resolves a Link Vault file group to one of our file identities.
     *
     * Asset first, path second, as agreed: `assetId` is the only thing Link
     * Vault records that survives a file being moved or renamed, and keying on
     * it means imported history merges with whatever this plugin counts next.
     *
     * @param array $group
     * @return array|null Null when the file can't be identified at all.
     */
    private function _identityFor(array $group): ?array
    {
        $signature = implode('|', [
            $group['assetId'] ?? '',
            $group['dirName'] ?? '',
            $group['fileName'] ?? '',
            $group['isUrl'] ?? '',
        ]);

        if (array_key_exists($signature, $this->_identities)) {
            return $this->_identities[$signature];
        }

        return $this->_identities[$signature] = $this->_resolve($group);
    }

    /**
     * Does the actual resolution behind `_identityFor()`'s memoisation.
     *
     * @param array $group
     * @return array|null
     */
    private function _resolve(array $group): ?array
    {
        $downloads = Plugin::getInstance()->downloads;
        $assetId = isset($group['assetId']) ? (int)$group['assetId'] : 0;

        if ($assetId > 0) {
            $asset = Craft::$app->getAssets()->getAssetById($assetId);

            // A missing asset means it was deleted after Link Vault logged it.
            // The history is still real, so fall through to the path.
            if ($asset !== null) {
                return $downloads->resolveIdentity($asset);
            }
        }

        $value = $this->_sourceString($group);

        if ($value === '') {
            return null;
        }

        // Honour the excluded-hosts setting: the user has said they don't want
        // this host counted, and backfilling it would quietly contradict that.
        $host = strtolower((string)parse_url($value, PHP_URL_HOST));

        if ($host !== '') {
            $excluded = array_map('strtolower', Plugin::getInstance()->getSettings()->excludedHosts);

            if (in_array($host, $excluded, true)) {
                return null;
            }
        }

        // Try the full resolution first: it unifies a URL back to its asset
        // where it can, so an imported URL row and a future beacon hit on the
        // same file share one counter instead of splitting into two.
        $identity = $downloads->resolveIdentity($value);

        if ($identity !== null) {
            return $identity;
        }

        // Otherwise take the key unconditionally. `resolveIdentity()` may have
        // declined on `trackUnresolvedFiles`, which guards a public beacon
        // against minting junk keys - a concern that doesn't apply to history an
        // admin has explicitly asked us to import.
        return $downloads->stringIdentity($value);
    }

    /**
     * Reassembles the file reference Link Vault split across columns.
     *
     * @param array $group
     * @return string
     */
    private function _sourceString(array $group): string
    {
        $fileName = trim((string)($group['fileName'] ?? ''));

        if ($fileName === '') {
            return '';
        }

        // For URL rows Link Vault puts the whole URL in `fileName` and leaves
        // `dirName` empty.
        if (!empty($group['isUrl'])) {
            return $fileName;
        }

        // `dirName` is an absolute, realpath'd directory with a trailing slash.
        // S3/Google rows may not have one at all, in which case the key falls
        // back to the bare filename - approximate, and documented as such.
        return (string)($group['dirName'] ?? '') . $fileName;
    }

    /**
     * Adds an imported total onto a file's counter row.
     *
     * @param array $identity
     * @param int $total
     * @param string|null $lastHit
     * @return void
     */
    private function _upsertCount(array $identity, int $total, ?string $lastHit): void
    {
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());
        $last = $lastHit !== null ? Db::prepareDateForDb($lastHit) : null;

        // `count` grows, `crawlerCount` does not: Link Vault never recorded a
        // user agent, so nothing here can be attributed to a crawler and the
        // human total (`count - crawlerCount`) absorbs all of it.
        $update = [
            'count' => new Expression('[[count]] + :dtImportCount', [':dtImportCount' => $total]),
            'dateUpdated' => $now,
        ];

        if ($last !== null) {
            // Only move `lastDownloaded` forward. Imported history is old by
            // definition and must never overwrite a more recent live hit.
            // `GREATEST` is avoided: it's NULL-poisoned on MySQL.
            $update['lastDownloaded'] = new Expression(
                'CASE WHEN [[lastDownloaded]] IS NULL OR [[lastDownloaded]] < :dtImportLast'
                    . ' THEN :dtImportLast ELSE [[lastDownloaded]] END',
                [':dtImportLast' => $last],
            );
        }

        // `assetId`, `filename`, `source` and `sourceType` are left alone on
        // conflict: an existing row's own tracking knows the file better than
        // Link Vault's snapshot of it does.
        Craft::$app->getDb()->createCommand()->upsert(
            CountRecord::tableName(),
            [
                'downloadKey' => $identity['downloadKey'],
                'assetId' => $identity['assetId'],
                'sourceType' => $identity['sourceType'],
                'source' => $identity['source'],
                'filename' => $identity['filename'],
                'count' => $total,
                'crawlerCount' => 0,
                'lastDownloaded' => $last,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ],
            $update,
        )->execute();
    }

    /**
     * Adds an imported total onto a file's per-day rollup row.
     *
     * @param string $downloadKey
     * @param string $date
     * @param int $total
     * @return void
     */
    private function _upsertDaily(string $downloadKey, string $date, int $total): void
    {
        $now = Db::prepareDateForDb(DateTimeHelper::currentUTCDateTime());

        Craft::$app->getDb()->createCommand()->upsert(
            DailyRecord::tableName(),
            [
                'downloadKey' => $downloadKey,
                'date' => $date,
                'count' => $total,
                'crawlerCount' => 0,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ],
            [
                'count' => new Expression('[[count]] + :dtImportDaily', [':dtImportDaily' => $total]),
                'dateUpdated' => $now,
            ],
        )->execute();
    }
}
