<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

/**
 * C13 (owner: lane P). Storage stop before the database limit or a full disk. XVID-SCALE-BRIEF 3.2 C13, D-I15, D-I6, G24.
 *
 *   status(): array  keys:
 *     'db_mb'        ?int   database size now (SUM(data_length+index_length) of the schema), null = unknown
 *     'limit_mb'     ?int   the "My database limit" setting in MB, null = no limit
 *     'free_disk_mb' ?int   free disk of the datadir where the server is local, null = unknown / not local
 *     'tmp_free_mb'  ?int   free space of @@tmpdir where local, null = unknown
 *     'binlog'       array  ['on'=>?bool, 'expire_days'=>?int, 'format'=>?string ROW|MIXED|STATEMENT, 'annotate'=>?bool (MariaDB row annotations / MySQL rows query events)]
 *     'tripped'      bool   a storage error was seen (flag set by trip(), cleared by clear() or 15 minutes after the
 *                           trip when a fresh measurement is not full)
 *     'full'         bool   same value as isFull()
 *     'reason'       string '' | 'db_limit' | 'disk' | 'tripped'
 *     'checked_at'   int    unix time of the measurement, 0 = never
 *   isFull(): bool   size >= 0.9 x limit, or free disk < 2 GB or < 10 %, or the tripped flag.
 *   trip(\Throwable $e): bool  true (and the flag is set) when $e or its getPrevious() chain is a storage error:
 *                    DB 1114 (table full), 1021 (disk full), 1142 (command denied: cPanel revokes INSERT at quota), or
 *                    errno 28 / "No space left" in the message. The caller pauses the job AND the schedule, no retry.
 *
 * Extra (lane P): limitSetting()/setLimit()/limitMb() for the "My database limit (GB)" setting (maccms.php
 * app.db_limit_gb, HP-H-4 default ''), evaluate() (pure rule), clear(), isStorageError(), setProbe() for tests.
 *
 * C18 importTarget() (XVID-NOLIMIT-BRIEF A2): the job-level storage target, the only stop of an import: 0.8 x the
 * database limit, and the free disk minus a reserve for the closing search step (1.2 x vod_ft for the copy + about
 * 1 x vod_ft for the FULLTEXT sort + 2 GB; at least the 10 % disk rule of isFull() + 1 GB, so the clean stop comes
 * before the site-wide pause). An import at the target completes (stop_reason storage_target); isFull() (0.9 / disk /
 * trip) is unchanged (G24) and stays the pause before writing.
 *
 * refreshExact() (round-2 verify fix import-r2 N7): ANALYZE of the tables an import grows, then a forced measurement: the
 * import runner's exact measurement near its storage target (information_schema sizes follow the InnoDB statistics, which lag
 * behind a bulk import by up to ~15 %). MySQL 8.0 sessions read information_schema sizes with information_schema_stats_expiry
 * 0 (else cached up to a day). With log_bin on the free-disk reserve also holds the closing search step's binary log.
 *
 * Measurement cache (1.2.0 verify fix, G1 / G6 / F1 / F2): the measurement (SUM(data_length+index_length) over
 * information_schema, @@log_bin, @@datadir free space) is kept in runtime/scale/storage.json for CACHE_SECONDS and shared
 * by every process. Only the command line, the admin entrance, the import runner and housekeeping (refresh() /
 * allowMeasure()) measure, one process at a time (single flight, non-blocking flock); visitor requests only read the
 * file (any age up to STALE_MAX_SECONDS) and never run the information_schema query. The flag lives in
 * runtime/storage_guard.json (and in memory when the disk cannot take the write).
 */
final class StorageGuard
{
    public const CODES = [1114, 1021, 1142];
    public const LIMIT_RATIO = 0.9;
    public const FREE_MIN_MB = 2048;
    public const FREE_MIN_RATIO = 0.10;
    public const CACHE_SECONDS = 60;
    public const TRIP_HOLD_SECONDS = 900;
    /** Visitor requests keep using a stored measurement up to this age (housekeeping / admin / CLI refresh it). */
    public const STALE_MAX_SECONDS = 86400;
    /**
     * Kept for callers of 1.2.0 builds before the verify fix. '' / 'auto' no longer mean 1 GB: an unknown limit is no
     * db_limit stop (the disk rule and the storage-error trip stay), and the import page asks for the value.
     */
    public const UNKNOWN_LIMIT_MB = 1024;
    /** A2 storage target: share of the database limit, reserve factors of the search table (copy 1.2 + sort 1.0). */
    public const TARGET_RATIO = 0.8;
    public const RESERVE_FT_FACTOR = 2.2;
    public const RESERVE_MARGIN_MB = 1024;
    /**
     * import-r2 (binlog): with log_bin on, the closing search step writes the search table once more into the binary log (ROW
     * events of the rebuild copy) and the import keeps writing about 7 KB of binary log per video between two checks: the
     * free-disk reserve grows by RESERVE_BINLOG_FT_FACTOR x vod_ft + RESERVE_BINLOG_MB.
     */
    public const RESERVE_BINLOG_FT_FACTOR = 1.0;
    public const RESERVE_BINLOG_MB = 512;
    /** import-r2 N7: tables an import grows (refreshExact() analyzes them before an exact measurement). */
    public const GROWING_TABLES = ['vod', 'avdb_import_map', 'avdb_import_item', 'vod_ft', 'avdb_translation'];

    private static $cache = null;
    private static $memoryFlag = null;
    private static $probe = null;
    private static $measureAllowed = null;
    /** Tests: number of real measurements in this process. */
    public static $measured = 0;

    /** Tests: $probe() returns the measurement array (db_mb, limit_mb, free_disk_mb, total_disk_mb, tmp_free_mb, binlog). */
    public static function setProbe(?callable $probe = null)
    {
        self::$probe = $probe; self::$cache = null;
    }

    /**
     * Let this process measure when the stored measurement is older than CACHE_SECONDS (import runner, housekeeping
     * after the response was sent). null = default rule: command line and admin entrance only.
     */
    public static function allowMeasure(?bool $allow = true): void
    {
        self::$measureAllowed = $allow;
    }

    /** Measure now when the stored value is older than CACHE_SECONDS ($force: always), store it, return status(). */
    public static function refresh(bool $force = false): array
    {
        $prev = self::$measureAllowed;
        self::$measureAllowed = true;
        try {
            if ($force) { self::$cache = self::measureShared(time(), true); }
            return self::status();
        } finally { self::$measureAllowed = $prev; }
    }

    /**
     * import-r2 N7: an exact measurement for the import's storage target. information_schema sizes follow the InnoDB statistics,
     * which lag behind a bulk import (626,699 imported videos: 819 MB read, 938.8 MB after ANALYZE TABLE; the stop at the
     * target left the database at 0.92 x the limit and the next start was refused). ANALYZE NO_WRITE_TO_BINLOG TABLE of the
     * tables an import grows refreshes their statistics (InnoDB samples a few pages per index; the index sizes come from the
     * segment headers), then the shared measurement is renewed. The import runner calls it only near its target (at most
     * every 5 to 60 seconds). A refused ANALYZE (privilege, missing table) leaves the plain measurement. Returns status().
     */
    public static function refreshExact(array $tables = self::GROWING_TABLES): array
    {
        if (!self::$probe) {
            $pre = function_exists('config') ? (string)config('database.prefix') : '';
            $names = [];
            foreach ($tables as $t) { if (is_string($t) && preg_match('/^[a-z0-9_]{1,48}$/D', $t) && preg_match('/^[A-Za-z0-9_]{0,32}$/D', $pre)) { $names[] = '`' . $pre . $t . '`'; } }
            if ($names) {
                try { \think\Db::query('ANALYZE NO_WRITE_TO_BINLOG TABLE ' . implode(', ', $names)); } catch (\Throwable $e) { /* the plain measurement below */ }
            }
        }
        self::$exactCount++;
        return self::refresh(true);
    }

    /** Tests: number of refreshExact() calls in this process. */
    public static $exactCount = 0;

    private static function mayMeasure(): bool
    {
        if (self::$measureAllowed !== null) { return self::$measureAllowed; }
        return PHP_SAPI === 'cli' || (defined('ENTRANCE') && ENTRANCE === 'admin');
    }

    public static function status(): array
    {
        $now = time();
        if (self::$cache === null || $now - (int)self::$cache['checked_at'] >= self::CACHE_SECONDS) {
            self::$cache = self::measureShared($now, false);
        }
        $m = self::$cache;
        $flag = self::flag();
        if ($flag && $now - (int)($flag['at'] ?? 0) >= self::TRIP_HOLD_SECONDS) {
            $probe = self::evaluate($m, false);
            if (!$probe['full'] && $m['checked_at'] > (int)($flag['at'] ?? 0)) { self::clear(); $flag = null; }
        }
        $e = self::evaluate($m, (bool)$flag);
        return [
            'db_mb' => $m['db_mb'],
            'limit_mb' => $m['limit_mb'],
            'free_disk_mb' => $m['free_disk_mb'],
            'tmp_free_mb' => $m['tmp_free_mb'],
            'binlog' => $m['binlog'],
            'total_disk_mb' => $m['total_disk_mb'] ?? null,
            'ft_mb' => $m['ft_mb'] ?? null,
            'tripped' => (bool)$flag,
            'full' => $e['full'],
            'reason' => $e['reason'],
            'checked_at' => (int)$m['checked_at'],
        ];
    }

    public static function isFull(): bool
    {
        return self::status()['full'];
    }

    /**
     * C18: the storage target of an import (A2). Keys: db_mb (?int: 0.8 x limit, null = no limit), disk_mb (?int: MB
     * the import may still add on disk = free disk - reserve, null = free disk unknown), reserve_mb, db_now_mb,
     * free_disk_mb, limit_mb, reached (bool), reason ('' | db_limit | disk). Unknown values never reach the target.
     * $status: a status() array (tests); default the shared measurement.
     */
    public static function importTarget(?array $status = null): array
    {
        $s = $status ?? self::status();
        $limit = isset($s['limit_mb']) && $s['limit_mb'] !== null ? (int)$s['limit_mb'] : null;
        $db = isset($s['db_mb']) && $s['db_mb'] !== null ? (int)$s['db_mb'] : null;
        $free = isset($s['free_disk_mb']) && $s['free_disk_mb'] !== null ? (int)$s['free_disk_mb'] : null;
        $total = isset($s['total_disk_mb']) && $s['total_disk_mb'] !== null ? (int)$s['total_disk_mb'] : null;
        $ft = max(0, (int)($s['ft_mb'] ?? 0));
        $reserve = (int)ceil(self::RESERVE_FT_FACTOR * $ft) + self::FREE_MIN_MB;
        if ($total !== null && $total > 0) { $reserve = max($reserve, (int)ceil(self::FREE_MIN_RATIO * $total) + self::RESERVE_MARGIN_MB); }
        // import-r2: binary logs on the same disk take the closing search step's copy once more and keep growing between two checks
        if (!empty($s['binlog']['on'])) { $reserve += (int)ceil(self::RESERVE_BINLOG_FT_FACTOR * $ft) + self::RESERVE_BINLOG_MB; }
        $dbTarget = $limit !== null && $limit > 0 ? (int)floor(self::TARGET_RATIO * $limit) : null;
        $disk = $free !== null ? $free - $reserve : null;
        $reason = '';
        if ($dbTarget !== null && $db !== null && $db >= $dbTarget) { $reason = 'db_limit'; }
        elseif ($disk !== null && $disk <= 0) { $reason = 'disk'; }
        return ['db_mb' => $dbTarget, 'disk_mb' => $disk, 'reserve_mb' => $reserve, 'db_now_mb' => $db, 'free_disk_mb' => $free, 'limit_mb' => $limit, 'reached' => $reason !== '', 'reason' => $reason];
    }

    /**
     * Pure rule (D-I15): full when db >= 0.9 x limit, free disk < 2 GB or < 10 % of the disk, or tripped.
     * Unknown values (null) never make it full.
     */
    public static function evaluate(array $m, bool $tripped): array
    {
        if ($tripped) { return ['full' => true, 'reason' => 'tripped']; }
        $db = $m['db_mb'] ?? null; $limit = $m['limit_mb'] ?? null;
        if ($db !== null && $limit !== null && $limit > 0 && $db >= self::LIMIT_RATIO * $limit) { return ['full' => true, 'reason' => 'db_limit']; }
        $free = $m['free_disk_mb'] ?? null; $total = $m['total_disk_mb'] ?? null;
        if ($free !== null && ($free < self::FREE_MIN_MB || ($total !== null && $total > 0 && $free < self::FREE_MIN_RATIO * $total))) { return ['full' => true, 'reason' => 'disk']; }
        return ['full' => false, 'reason' => ''];
    }

    public static function trip(\Throwable $e): bool
    {
        if (!self::isStorageError($e)) { return false; }
        $flag = ['at' => time(), 'reason' => substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $e->getMessage()), 0, 200)];
        self::$memoryFlag = $flag;
        $file = self::flagFile();
        if ($file !== '') { @file_put_contents($file, json_encode($flag), LOCK_EX); }
        return true;
    }

    /** Storage error in $e or its previous chain (depth 8): DB 1114 / 1021 / 1142, errno 28, "No space left". */
    public static function isStorageError(\Throwable $e): bool
    {
        for ($depth = 0; $e !== null && $depth < 8; $depth++, $e = $e->getPrevious()) {
            if (self::oneIsStorageError($e)) { return true; }
        }
        return false;
    }

    /** Clear the tripped flag (admin action, or automatically after free space returns). */
    public static function clear(): void
    {
        self::$memoryFlag = false;
        $file = self::flagFile();
        if ($file !== '' && is_file($file)) { @unlink($file); }
    }

    /** Message for the job / admin: "Database almost full (X of Y GB)". */
    public static function message(?array $status = null): string
    {
        $s = $status ?: self::status();
        if ($s['reason'] === 'db_limit') {
            return ImportProviders::text('storage_db_full', 'Database almost full ({used} of {limit} GB). The import is paused before it writes more.', ['used' => self::gb($s['db_mb']), 'limit' => self::gb($s['limit_mb'])]);
        }
        if ($s['reason'] === 'disk') {
            return ImportProviders::text('storage_disk_full', 'The server disk is almost full ({free} GB free). The import is paused.', ['free' => self::gb($s['free_disk_mb'])]);
        }
        if ($s['reason'] === 'tripped') {
            return ImportProviders::text('storage_tripped', 'The database refused a write because the database or the disk is full. The import, the schedule and the search update are paused until space is freed.');
        }
        return '';
    }

    // ------------------------------------------------------------------ the database-limit setting

    /** Raw setting: '' (never asked), 'auto' ("I don't know"), 'none' (no limit) or a number of GB as a string. */
    public static function limitSetting(): string
    {
        $app = function_exists('config') ? config('maccms.app') : null; $v = is_array($app) ? ($app['db_limit_gb'] ?? '') : '';
        if (!is_scalar($v)) { return ''; }
        $v = strtolower(trim((string)$v));
        if ($v === '' || $v === 'auto' || $v === 'none') { return $v; }
        return self::validGb($v) ? $v : '';
    }

    /**
     * The limit in MB. Number -> that many GB. 'none' -> null. '' (never asked) or 'auto' ("I don't know") -> null:
     * an unknown limit is never a db_limit stop (owner decision 2026-10-15: the storage guard stops on what it knows;
     * an assumed 1 GB paused the edge cache, the search drain and the hit fold of every larger site, flood F1). The disk
     * rule and the storage-error trip (1114 / 1021 / 1142 / errno 28) still stop the import; the import page asks for
     * the value (db_limit_asked = false).
     */
    public static function limitMb(?array $context = null): ?int
    {
        $setting = $context['setting'] ?? self::limitSetting();
        if ($setting === '' || $setting === 'auto' || $setting === 'none') { return null; }
        return (int)round((float)$setting * 1024);
    }

    /** Write the setting (value from E's page through the controller): 'auto', 'none' or GB 0.1 .. 100000. */
    public static function setLimit($value): string
    {
        $v = is_scalar($value) ? strtolower(trim((string)$value)) : '';
        if (!($v === 'auto' || $v === 'none' || self::validGb($v))) { throw new \InvalidArgumentException(ImportProviders::text('limit_invalid', 'Enter the database limit in GB (for example 1 or 2.5), or choose "I don\'t know" or "No limit".')); }
        if ($v !== 'auto' && $v !== 'none') { $v = rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); }
        $config = config('maccms');
        if (!is_array($config)) { throw new \RuntimeException('Site settings are not readable.'); }
        $config['app'] = is_array($config['app'] ?? null) ? $config['app'] : [];
        $config['app']['db_limit_gb'] = $v;
        if (!function_exists('mac_arr2file') || mac_arr2file(APP_PATH . 'extra/maccms.php', $config) === false) { throw new \RuntimeException(ImportProviders::text('limit_not_saved', 'Could not save the setting (application/extra/maccms.php is not writable).')); }
        if (function_exists('opcache_invalidate')) { @opcache_invalidate(APP_PATH . 'extra/maccms.php', true); }
        \think\Config::set('maccms', $config);
        self::$cache = null;
        return $v;
    }

    private static function validGb($v): bool
    {
        return is_string($v) && preg_match('/^[0-9]{1,6}(?:\.[0-9]{1,2})?$/D', $v) && (float)$v >= 0.1 && (float)$v <= 100000;
    }

    // ------------------------------------------------------------------ measurement

    /**
     * Shared measurement: the stored file when it is younger than CACHE_SECONDS; else, when this process may measure
     * and no other process is measuring (non-blocking flock), a new measurement written to the file; else the stored
     * file of any age up to STALE_MAX_SECONDS; else (nothing stored, visitor) "unknown" without a query (unknown values
     * never make the guard full). The limit always comes from the setting (it can change between measurements).
     */
    private static function measureShared(int $now, bool $force): array
    {
        if (self::$probe) { return self::measure() + ['checked_at' => $now]; }
        $file = self::measureFile();
        $stored = $file !== '' ? self::readMeasure($file) : null;
        if (!$force && $stored !== null && $now - $stored['checked_at'] < self::CACHE_SECONDS && $stored['checked_at'] <= $now + 5) {
            return self::withLimit($stored);
        }
        if ($force || self::mayMeasure()) {
            $lock = $file !== '' ? @fopen($file . '.lock', 'c') : false;
            $got = $lock ? @flock($lock, LOCK_EX | LOCK_NB) : ($file === '');
            if ($got) {
                try {
                    // another process may have stored a measurement between the read above and the lock
                    $again = (!$force && $file !== '') ? self::readMeasure($file) : null;
                    if ($again !== null && $now - $again['checked_at'] < self::CACHE_SECONDS) { return self::withLimit($again); }
                    $m = self::measure() + ['checked_at' => time()];
                    if ($file !== '') { self::writeMeasure($file, $m); }
                    return $m;
                } finally {
                    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
                }
            }
            if ($lock) { @fclose($lock); }
        }
        if ($stored !== null && $now - $stored['checked_at'] < self::STALE_MAX_SECONDS) { return self::withLimit($stored); }
        return ['db_mb' => null, 'limit_mb' => self::safeLimit(), 'free_disk_mb' => null, 'total_disk_mb' => null, 'tmp_free_mb' => null, 'ft_mb' => null, 'binlog' => ['on' => null, 'expire_days' => null], 'checked_at' => $now];
    }

    private static function withLimit(array $m): array
    {
        $m['limit_mb'] = self::safeLimit();
        return $m;
    }

    private static function safeLimit(): ?int
    {
        try { return self::limitMb(); } catch (\Throwable $e) { return null; }
    }

    private static function measureFile(): string
    {
        if (!defined('RUNTIME_PATH')) { return ''; }
        $dir = rtrim(RUNTIME_PATH, '/\\') . DIRECTORY_SEPARATOR . 'scale';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { return ''; }
        return $dir . DIRECTORY_SEPARATOR . 'storage.json';
    }

    private static function readMeasure(string $file): ?array
    {
        if (!is_file($file)) { return null; }
        $d = json_decode((string)@file_get_contents($file), true);
        if (!is_array($d) || !isset($d['checked_at']) || !is_int($d['checked_at']) || !array_key_exists('db_mb', $d)) { return null; }
        $int = function ($v) { return is_int($v) ? $v : null; };
        $b = is_array($d['binlog'] ?? null) ? $d['binlog'] : [];
        return [
            'db_mb' => $int($d['db_mb']), 'limit_mb' => null, 'free_disk_mb' => $int($d['free_disk_mb'] ?? null),
            'total_disk_mb' => $int($d['total_disk_mb'] ?? null), 'tmp_free_mb' => $int($d['tmp_free_mb'] ?? null), 'ft_mb' => $int($d['ft_mb'] ?? null),
            'binlog' => ['on' => is_bool($b['on'] ?? null) ? $b['on'] : null, 'expire_days' => $int($b['expire_days'] ?? null),
                'format' => in_array($b['format'] ?? null, ['ROW', 'MIXED', 'STATEMENT'], true) ? $b['format'] : null, 'annotate' => is_bool($b['annotate'] ?? null) ? $b['annotate'] : null],
            'checked_at' => $d['checked_at'],
        ];
    }

    private static function writeMeasure(string $file, array $m): void
    {
        $json = json_encode($m);
        $tmp = $file . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
        if (@file_put_contents($tmp, $json) === false) { return; }
        if (!@rename($tmp, $file)) {
            // Windows: a rename over a file another process holds open can fail; write in place instead
            @file_put_contents($file, $json, LOCK_EX);
            @unlink($tmp);
        }
    }

    private static function measure(): array
    {
        self::$measured++;
        if (self::$probe) {
            $m = (array)call_user_func(self::$probe);
            return [
                'db_mb' => isset($m['db_mb']) ? (int)$m['db_mb'] : null,
                'limit_mb' => array_key_exists('limit_mb', $m) ? ($m['limit_mb'] === null ? null : (int)$m['limit_mb']) : null,
                'free_disk_mb' => isset($m['free_disk_mb']) ? (int)$m['free_disk_mb'] : null,
                'total_disk_mb' => isset($m['total_disk_mb']) ? (int)$m['total_disk_mb'] : null,
                'tmp_free_mb' => isset($m['tmp_free_mb']) ? (int)$m['tmp_free_mb'] : null,
                'ft_mb' => isset($m['ft_mb']) ? (int)$m['ft_mb'] : null,
                'binlog' => ['on' => $m['binlog']['on'] ?? null, 'expire_days' => $m['binlog']['expire_days'] ?? null, 'format' => $m['binlog']['format'] ?? null, 'annotate' => $m['binlog']['annotate'] ?? null],
            ];
        }
        $out = ['db_mb' => null, 'limit_mb' => null, 'free_disk_mb' => null, 'total_disk_mb' => null, 'tmp_free_mb' => null, 'ft_mb' => null, 'binlog' => ['on' => null, 'expire_days' => null]];
        // import-r2 N7: MySQL 8.0 serves information_schema.TABLES sizes from a cache refreshed only every
        // information_schema_stats_expiry seconds (default 86,400): this session reads the current statistics instead (MariaDB and
        // MySQL 5.7 have no such variable; the error is ignored)
        static $hasExpiry = null;
        $expiry = false;
        if ($hasExpiry !== false) {
            try { \think\Db::execute('SET SESSION information_schema_stats_expiry = 0'); $expiry = $hasExpiry = true; } catch (\Throwable $e) { $hasExpiry = false; /* not MySQL 8 */ }
        }
        try {
            // one information_schema query: the database size and the search table (vod_ft) size for the A2 reserve
            $r = \think\Db::query('SELECT COALESCE(SUM(data_length + index_length), 0) AS b, COALESCE(SUM(CASE WHEN table_name = ? THEN data_length + index_length ELSE 0 END), 0) AS f FROM information_schema.TABLES WHERE table_schema = DATABASE()', [(string)config('database.prefix') . 'vod_ft']);
            if (isset($r[0]['b'])) { $out['db_mb'] = (int)round((float)$r[0]['b'] / 1048576); }
            if (isset($r[0]['f'])) { $out['ft_mb'] = (int)round((float)$r[0]['f'] / 1048576); }
        } catch (\Throwable $e) { /* unknown */ }
        if ($expiry) { try { \think\Db::execute('SET SESSION information_schema_stats_expiry = DEFAULT'); } catch (\Throwable $e) { /* keep */ } }
        $out['limit_mb'] = self::safeLimit();
        try {
            $v = \think\Db::query('SELECT @@log_bin AS b');
            $out['binlog']['on'] = isset($v[0]['b']) ? ((string)$v[0]['b'] === '1' || strtoupper((string)$v[0]['b']) === 'ON') : null;
            $days = null;
            try { $s = \think\Db::query('SELECT @@binlog_expire_logs_seconds AS s'); if (isset($s[0]['s'])) { $days = (int)ceil((int)$s[0]['s'] / 86400); } } catch (\Throwable $e) { $days = null; }
            if ($days === null || $days === 0) {
                try { $d = \think\Db::query('SELECT @@expire_logs_days AS d'); if (isset($d[0]['d'])) { $days = (int)ceil((float)$d[0]['d']); } } catch (\Throwable $e) { /* keep */ }
            }
            $out['binlog']['expire_days'] = $days;
            // import-r2 (binlog estimate): the format decides the size per imported video (ROW images, statement text, MariaDB's row
            // annotations: ImportProviders::binlogKbPerVideo)
            if ($out['binlog']['on']) {
                try { $f = \think\Db::query('SELECT @@binlog_format AS f'); $fmt = strtoupper((string)($f[0]['f'] ?? '')); $out['binlog']['format'] = in_array($fmt, ['ROW', 'MIXED', 'STATEMENT'], true) ? $fmt : null; } catch (\Throwable $e) { /* unknown */ }
                $ann = null;
                try { $a = \think\Db::query('SELECT @@binlog_annotate_row_events AS a'); $ann = isset($a[0]['a']) ? in_array(strtoupper((string)$a[0]['a']), ['1', 'ON'], true) : null; }
                catch (\Throwable $e) { try { $a = \think\Db::query('SELECT @@binlog_rows_query_log_events AS a'); $ann = isset($a[0]['a']) ? in_array(strtoupper((string)$a[0]['a']), ['1', 'ON'], true) : null; } catch (\Throwable $e2) { $ann = null; } }
                $out['binlog']['annotate'] = $ann;
            }
        } catch (\Throwable $e) { /* unknown */ }
        if (self::dbIsLocal()) {
            try {
                $d = \think\Db::query('SELECT @@datadir AS d, @@tmpdir AS t');
                $dir = (string)($d[0]['d'] ?? ''); $tmp = (string)($d[0]['t'] ?? '');
                if ($dir !== '' && @is_dir($dir)) {
                    $f = @disk_free_space($dir); $t = @disk_total_space($dir);
                    if ($f !== false) { $out['free_disk_mb'] = (int)floor($f / 1048576); }
                    if ($t !== false) { $out['total_disk_mb'] = (int)floor($t / 1048576); }
                }
                $tmp = explode(PATH_SEPARATOR === ';' ? ';' : ':', $tmp)[0];
                if ($tmp !== '' && @is_dir($tmp)) { $f = @disk_free_space($tmp); if ($f !== false) { $out['tmp_free_mb'] = (int)floor($f / 1048576); } }
            } catch (\Throwable $e) { /* unknown */ }
        }
        return $out;
    }

    /** The database server runs on this machine (localhost, 127.0.0.1, ::1, or a socket). */
    public static function dbIsLocal(): bool
    {
        $host = function_exists('config') ? (string)config('database.hostname') : '';
        return self::isLocalHost($host);
    }

    public static function isLocalHost($host): bool
    {
        $h = strtolower(trim((string)$host));
        return $h === '' || $h === 'localhost' || $h === '127.0.0.1' || $h === '::1' || $h === '[::1]' || strpos($h, '/') === 0 || strpos($h, 'localhost:') === 0 || strpos($h, '127.0.0.1:') === 0;
    }

    private static function flag()
    {
        if (self::$memoryFlag === false) { return null; }
        if (is_array(self::$memoryFlag)) { return self::$memoryFlag; }
        $file = self::flagFile();
        if ($file !== '' && is_file($file)) {
            $data = json_decode((string)@file_get_contents($file), true);
            if (is_array($data) && isset($data['at'])) { return $data; }
        }
        return null;
    }

    private static function flagFile(): string
    {
        return defined('RUNTIME_PATH') ? RUNTIME_PATH . 'storage_guard.json' : '';
    }

    private static function gb($mb): string
    {
        return $mb === null ? '?' : rtrim(rtrim(number_format($mb / 1024, 2, '.', ''), '0'), '.');
    }

    private static function oneIsStorageError(\Throwable $e): bool
    {
        $codes = [];
        if ($e instanceof \PDOException && is_array($e->errorInfo)) {
            $codes[] = $e->errorInfo[1] ?? 0;
        }
        if ($e instanceof \think\exception\PDOException && method_exists($e, 'getData')) {
            $data = $e->getData();
            if (is_array($data) && isset($data['PDO Error Info']['Driver Error Code'])) {
                $codes[] = $data['PDO Error Info']['Driver Error Code'];
            }
        }
        if ($e instanceof \mysqli_sql_exception) {
            $codes[] = $e->getCode();
        }
        $message = (string)$e->getMessage();
        if (preg_match('/SQLSTATE\[[0-9A-Z]{5}\]:[^:]*:\s*(\d{1,5})\b/', $message, $m)) {
            $codes[] = $m[1];
        }
        foreach ($codes as $code) {
            if (is_numeric($code) && in_array((int)$code, self::CODES, true)) {
                return true;
            }
        }
        return (bool)preg_match('/No space left on device|errno:? ?28\b|Errcode: ?28\b|error 28\b/i', $message);
    }
}
