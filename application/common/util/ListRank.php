<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

/**
 * C15 (lane S, XVID-NOLIMIT-BRIEF B2): rank files for the lists sorted by something else than vod_time, so every page of
 * by=hits / hits_day / hits_week / hits_month / score / up / time_add costs one fseek + fread and `per` primary-key reads
 * (fixes HO-S-8 / G5b: by=hits page 10 of a category was 1.1-3.1 s at 7.1 M).
 *
 * Files: runtime/scale/rank/<sort>/<lk>.u32 = packed unsigned 32-bit vod ids (little endian), lk = 'a' (all published),
 * 't<id>' (one category), 'p<id>' (a parent with its children); <sort>/state.json = built_at, rows, keys. Written to a
 * temporary name and renamed.
 *  - hits, hits_day, hits_week, hits_month, score, up: rank 0 = the first row of the list (ORDER BY vod_<sort> DESC,
 *    vod_id DESC), rebuilt by housekeeping (nightly; hits_day hourly) from ONE server-side sort streamed unbuffered.
 *  - time_add: bottom-anchored (rank 0 = the oldest vod_time_add), so new rows are appended (append(), every housekeeping
 *    run) and old ranks never move; rebuilt weekly.
 * A page reads ids by position; unpublished / deleted ids are dropped by the caller's primary-key read (Vod::rowsByIds).
 *
 *   build($sort): array      full build (housekeeping on the command line or after the response, tools/upgrade.php)
 *   append($sort): array     time_add only: rows above the newest appended (vod_time_add, vod_id)
 *   page($sort, $lk, $page, $per[, $asc]): ?array  ids of the page in list order; [] past the end; null = no usable file
 *                            (missing, or older than STALE_HITS for the hits family / no append in STALE_APPEND)
 *   state(): array           ready / pct / built_at / sorts / mb (lane E, tools/upgrade.php)
 */
final class ListRank
{
    const SORTS = ['hits', 'hits_day', 'hits_week', 'hits_month', 'score', 'up', 'time_add'];
    /** a hits-family file older than this is not used (26 h: one nightly build may be missed) */
    const STALE_HITS = 93600;
    /** time_add: no append for this long, or no full build for STALE_FULL, and the file is not used */
    const STALE_APPEND = 93600;
    const STALE_FULL = 691200;
    /** ids buffered per key before a write */
    const FLUSH = 4096;
    /** rows per append call */
    const APPEND_MAX = 200000;

    private static $pdo = null;
    private static $prefix = null;
    private static $runtime = null;
    private static $stateMemo = [];

    public static function useConnection(\PDO $pdo, string $prefix): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{0,64}$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid table prefix');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::$pdo = $pdo;
        self::$prefix = $prefix;
        self::$stateMemo = [];
    }

    /** The site's runtime/ folder (files live in runtime/scale/rank/). */
    public static function useRuntime(string $dir): void
    {
        self::$runtime = rtrim(str_replace('\\', '/', $dir), '/');
        self::$stateMemo = [];
    }

    private static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            if (!class_exists('\think\Db')) {
                throw new \RuntimeException('ListRank: no database connection');
            }
            \think\Db::query('SELECT 1');
            $pdo = \think\Db::connect()->getPdo();
            $prefix = (string)\think\Config::get('database.prefix');
            if (!$pdo instanceof \PDO || !preg_match('/^[A-Za-z0-9_]{0,64}$/D', $prefix)) {
                throw new \RuntimeException('ListRank: no database connection');
            }
            self::$pdo = $pdo;
            self::$prefix = $prefix;
        }
        return self::$pdo;
    }

    private static function t(string $name): string
    {
        self::pdo();
        return '`' . self::$prefix . $name . '`';
    }

    /** runtime/scale/rank/ (or rank/<sort>/) with a trailing slash. */
    public static function dir(string $sort = ''): string
    {
        if (self::$runtime !== null) {
            $d = self::$runtime . '/scale/rank/';
        } elseif (defined('RUNTIME_PATH')) {
            $d = rtrim(str_replace('\\', '/', RUNTIME_PATH), '/') . '/scale/rank/';
        } else {
            $d = rtrim(str_replace('\\', '/', dirname(__DIR__, 3)), '/') . '/runtime/scale/rank/';
        }
        if ($sort !== '') {
            $d .= $sort . '/';
        }
        return $d;
    }

    private static function validSort(string $sort): bool
    {
        return in_array($sort, self::SORTS, true);
    }

    private static function validKey(string $lk): bool
    {
        return (bool)preg_match('/^(a|t[1-9][0-9]{0,4}|p[1-9][0-9]{0,4})$/D', $lk);
    }

    private static function readState(string $sort): array
    {
        if (!isset(self::$stateMemo[$sort])) {
            $f = self::dir($sort) . 'state.json';
            $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
            self::$stateMemo[$sort] = is_array($d) ? $d : [];
        }
        return self::$stateMemo[$sort];
    }

    private static function writeState(string $sort, array $st): void
    {
        $f = self::dir($sort) . 'state.json';
        self::replaceFile($f . '.' . getmypid() . '.tmp', $f, json_encode($st, JSON_UNESCAPED_SLASHES));
        self::$stateMemo[$sort] = $st;
    }

    /** Write $bytes to $tmp (when given) and rename it over $file; a few retries while a reader holds the file (Windows). */
    private static function replaceFile(string $tmp, string $file, ?string $bytes = null): bool
    {
        if ($bytes !== null && @file_put_contents($tmp, $bytes) !== strlen($bytes)) {
            @unlink($tmp);
            return false;
        }
        for ($i = 0; $i < 5; $i++) {
            if (@rename($tmp, $file)) {
                return true;
            }
            usleep(20000 * ($i + 1));
        }
        @unlink($tmp);
        return false;
    }

    /** Pure: true when the stored state may serve pages now. */
    public static function fresh(string $sort, array $st, int $now): bool
    {
        if (empty($st['built_at'])) {
            return false;
        }
        if ($sort === 'time_add') {
            return (int)$st['built_at'] > $now - self::STALE_FULL && (int)($st['appended_at'] ?? $st['built_at']) > $now - self::STALE_APPEND;
        }
        return (int)$st['built_at'] > $now - self::STALE_HITS;
    }

    /**
     * Pure: byte offset and id count of a page in a file of $count ids. Hits family (top-anchored): page 1 = ids 0..per-1;
     * time_add (bottom-anchored, oldest first): page 1 = the last per ids (read, then reversed). $asc reverses the list.
     * Returns [first index, n, reverse] or null past the end.
     */
    public static function slice(string $sort, int $count, int $page, int $per, bool $asc = false): ?array
    {
        $page = max(1, $page);
        $per = max(1, $per);
        $bottom = $sort === 'time_add';
        // reading from the top of the file = the list order of a top-anchored file, or asc of a bottom-anchored one
        $fromTop = $bottom ? $asc : !$asc;
        if ($fromTop) {
            $lo = ($page - 1) * $per;
            if ($lo >= $count) {
                return null;
            }
            return [$lo, min($per, $count - $lo), false];
        }
        $hi = $count - ($page - 1) * $per - 1;
        if ($hi < 0) {
            return null;
        }
        $lo = max(0, $count - $page * $per);
        return [$lo, $hi - $lo + 1, true];
    }

    /** Ids of one page (list order). [] past the end; null = no usable file. */
    public static function page(string $sort, string $lk, int $page, int $per, bool $asc = false): ?array
    {
        if (!self::validSort($sort) || !self::validKey($lk)) {
            return null;
        }
        $st = self::readState($sort);
        if (!self::fresh($sort, $st, time())) {
            return null;
        }
        $f = self::dir($sort) . $lk . '.u32';
        if (!is_file($f)) {
            // no file for a key the build saw = an empty list; a key the build never saw = no usable file
            return isset($st['keys']) && is_array($st['keys']) && !isset($st['keys'][$lk]) && !empty($st['complete']) ? [] : null;
        }
        $size = (int)@filesize($f);
        $s = self::slice($sort, intdiv($size, 4), $page, $per, $asc);
        if ($s === null) {
            return [];
        }
        $h = @fopen($f, 'rb');
        if (!$h) {
            return null;
        }
        try {
            if (fseek($h, $s[0] * 4) !== 0) {
                return null;
            }
            $raw = (string)fread($h, $s[1] * 4);
        } finally {
            fclose($h);
        }
        $ids = $raw === '' ? [] : array_values(unpack('V*', substr($raw, 0, intdiv(strlen($raw), 4) * 4)));
        return $s[2] ? array_reverse($ids) : $ids;
    }

    /** [type_id => parent id] of the video categories (parents = ids that have children). */
    private static function types(): array
    {
        $out = [];
        try {
            foreach (self::pdo()->query('SELECT type_id, type_pid FROM ' . self::t('type') . ' WHERE type_mid = 1')->fetchAll(\PDO::FETCH_NUM) as $r) {
                $out[(int)$r[0]] = (int)$r[1];
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    private static function bufferedAttr(): int
    {
        return class_exists('Pdo\\Mysql') ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY : \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
    }

    private static function lockName(string $what): string
    {
        $db = (string)self::pdo()->query('SELECT DATABASE()')->fetchColumn();
        $n = $db . '_' . self::$prefix . 'rank_' . $what;
        return strlen($n) > 60 ? 'rank_' . md5($n) : $n;
    }

    /**
     * Full build of one sort: one server-side ORDER BY streamed unbuffered (no statement limit on the housekeeping / CLI
     * connection), ids written per key in list order (time_add: oldest first) to <lk>.u32.<pid>.tmp, renamed at the end;
     * files of keys that no longer exist are removed. Single flight per sort. Returns state 'done' | 'busy' | 'error',
     * rows, keys, seconds, mb. $source (buildAll): a narrow temporary copy of the published rows to sort instead of the vod
     * table (no full scan per sort).
     */
    public static function build(string $sort, string $source = ''): array
    {
        if (!self::validSort($sort)) {
            return ['state' => 'error', 'error' => 'unknown sort'];
        }
        if ($source !== '' && !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $source)) {
            return ['state' => 'error', 'error' => 'bad source'];
        }
        $t0 = microtime(true);
        $pdo = self::pdo();
        $lock = self::lockName($sort);
        if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 0)')->fetchColumn() !== 1) {
            return ['state' => 'busy'];
        }
        $dir = self::dir($sort);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tag = '.' . getmypid() . '.tmp';
        $types = self::types();
        $parents = [];
        foreach ($types as $id => $pid) {
            if ($pid > 0) {
                $parents[$pid] = true;
            }
        }
        $buf = [];
        $counts = [];
        $flush = function ($lk) use (&$buf, $dir, $tag) {
            if (!empty($buf[$lk])) {
                @file_put_contents($dir . $lk . '.u32' . $tag, pack('V*', ...$buf[$lk]), FILE_APPEND);
                $buf[$lk] = [];
            }
        };
        $add = function ($lk, $id) use (&$buf, &$counts, $flush) {
            $buf[$lk][] = $id;
            $counts[$lk] = ($counts[$lk] ?? 0) + 1;
            if (count($buf[$lk]) >= self::FLUSH) {
                $flush($lk);
            }
        };
        $col = 'vod_' . $sort;
        $dirSql = $sort === 'time_add' ? 'ASC' : 'DESC';
        $last = null;
        $maxId = 0;
        $rows = 0;
        $attr = self::bufferedAttr();
        $prev = null;
        try {
            foreach (glob($dir . '*' . $tag) ?: [] as $old) {
                @unlink($old);
            }
            $prev = $pdo->getAttribute($attr);
            $pdo->setAttribute($attr, false);
            $from = $source !== '' ? '`' . $source . '`' : self::t('vod') . ' WHERE vod_status = 1 AND vod_recycle_time = 0';
            $st = $pdo->query('SELECT vod_id, type_id, type_id_1, ' . $col . ' FROM ' . $from . ' ORDER BY ' . $col . ' ' . $dirSql . ', vod_id ' . $dirSql);
            try {
                while (($r = $st->fetch(\PDO::FETCH_NUM)) !== false) {
                    $id = (int)$r[0];
                    $t = (int)$r[1];
                    $t1 = (int)$r[2];
                    $add('a', $id);
                    if ($t > 0) {
                        $add('t' . $t, $id);
                        if (isset($parents[$t])) {
                            $add('p' . $t, $id);
                        }
                    }
                    if ($t1 > 0 && $t1 !== $t) {
                        $add('p' . $t1, $id);
                    }
                    $rows++;
                    $last = [(int)$r[3], $id];
                    if ($id > $maxId) {
                        $maxId = $id;
                    }
                }
            } finally {
                $st->closeCursor();
                $pdo->setAttribute($attr, $prev);
                $prev = null;
            }
            foreach (array_keys($buf) as $lk) {
                $flush($lk);
            }
            $bytes = 0;
            foreach (array_keys($counts) as $lk) {
                if (!self::replaceFile($dir . $lk . '.u32' . $tag, $dir . $lk . '.u32')) {
                    throw new \RuntimeException('ListRank: could not replace ' . $sort . '/' . $lk . '.u32');
                }
                $bytes += $counts[$lk] * 4;
            }
            foreach (glob($dir . '*.u32') ?: [] as $f) {
                if (!isset($counts[basename($f, '.u32')])) {
                    @unlink($f);
                }
            }
            $now = time();
            $state = ['sort' => $sort, 'built_at' => $now, 'rows' => $rows, 'keys' => $counts, 'complete' => true, 'bytes' => $bytes,
                'seconds' => round(microtime(true) - $t0, 2)];
            if ($sort === 'time_add') {
                $state['appended_at'] = $now;
                $state['last'] = $last ?: [0, 0];
                // round-2 verify fix R2-6: appends continue above the highest vod id included (new rows), never above the
                // last vod_time_add: one future-dated row (406,380 in a test set, up to 2028) anchored the file and every
                // later append found 0 rows
                $state['max_id'] = $maxId;
            }
            self::writeState($sort, $state);
            return ['state' => 'done', 'sort' => $sort, 'rows' => $rows, 'keys' => count($counts), 'seconds' => $state['seconds'], 'mb' => round($bytes / 1048576, 2)];
        } catch (\Throwable $e) {
            foreach (glob($dir . '*' . $tag) ?: [] as $old) {
                @unlink($old);
            }
            return ['state' => 'error', 'sort' => $sort, 'error' => get_class($e) . ' ' . substr($e->getMessage(), 0, 160)];
        } finally {
            if ($prev !== null) {
                try { $pdo->setAttribute($attr, $prev); } catch (\Throwable $e) { }
            }
            try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')')->fetchAll(); } catch (\Throwable $e) { }
        }
    }

    /**
     * Round-2 verify fix R2-6 (N15: the six sorts took 1,178.8 s at 7.9 M on VPS-2, each one a full scan of the video
     * table + filesort): every sort of $sorts from ONE read of the table. The published rows' sort columns go to a narrow
     * temporary table (id, two category ids, seven sort values: ~45 bytes a row instead of the full row), then each sort is
     * one ORDER BY over that copy (build($sort, $source)). Without the privilege for temporary tables (or on any error
     * creating it) the sorts are built one by one as before. Returns ['state' => 'done' | 'partial', 'sorts' => [sort =>
     * build() result], 'copy_seconds', 'seconds', 'shared' => bool].
     */
    public static function buildAll(array $sorts = self::SORTS): array
    {
        $t0 = microtime(true);
        $sorts = array_values(array_filter(array_unique($sorts), function ($s) { return is_string($s) && self::validSort($s); }));
        $out = ['state' => 'done', 'sorts' => [], 'copy_seconds' => 0.0, 'seconds' => 0.0, 'shared' => false];
        if (!$sorts) {
            return $out;
        }
        $pdo = self::pdo();
        $tmp = substr(self::$prefix . 'rank_src_' . getmypid(), 0, 64);
        $made = false;
        if (count($sorts) > 1) {
            try {
                $pdo->exec('DROP TEMPORARY TABLE IF EXISTS `' . $tmp . '`');
                $pdo->exec('CREATE TEMPORARY TABLE `' . $tmp . '` ENGINE=InnoDB SELECT vod_id, type_id, type_id_1, vod_hits, vod_hits_day, vod_hits_week, vod_hits_month,'
                    . ' vod_score, vod_up, vod_time_add FROM ' . self::t('vod') . ' WHERE vod_status = 1 AND vod_recycle_time = 0');
                $made = true;
                $out['copy_seconds'] = round(microtime(true) - $t0, 2);
            } catch (\Throwable $e) {
                $out['copy_error'] = get_class($e) . ' ' . substr($e->getMessage(), 0, 120);
            }
        }
        try {
            foreach ($sorts as $sort) {
                $r = self::build($sort, $made ? $tmp : '');
                $out['sorts'][$sort] = $r;
                if (($r['state'] ?? '') !== 'done') {
                    $out['state'] = 'partial';
                }
            }
        } finally {
            if ($made) {
                try { $pdo->exec('DROP TEMPORARY TABLE IF EXISTS `' . $tmp . '`'); } catch (\Throwable $e) { }
            }
        }
        $out['shared'] = $made;
        $out['seconds'] = round(microtime(true) - $t0, 2);
        return $out;
    }

    /**
     * time_add (bottom-anchored): append the published rows that arrived after the last build / append to the files of their
     * keys, in (vod_time_add, vod_id) order. Round 2 (R2-6): "arrived after" = vod_id above the highest id already in the
     * file (state max_id; a primary-key range), so a row with a future vod_time_add can no longer stop every append; rows
     * whose vod_time_add lies below the file's newest one are placed at the end and re-sorted by the next full build.
     * A state written before max_id existed continues once from (vod_time_add, vod_id) and records max_id. Other sorts:
     * nothing (rebuilt whole). Returns state 'done' | 'not_built' | 'busy' | 'skipped' | 'error', rows appended.
     */
    public static function append(string $sort = 'time_add'): array
    {
        if ($sort !== 'time_add') {
            return ['state' => 'skipped', 'rows' => 0];
        }
        $st = self::readState($sort);
        if (empty($st['built_at']) || !isset($st['last']) || !is_array($st['last'])) {
            return ['state' => 'not_built', 'rows' => 0];
        }
        $pdo = self::pdo();
        $lock = self::lockName($sort);
        if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 0)')->fetchColumn() !== 1) {
            return ['state' => 'busy', 'rows' => 0];
        }
        try {
            self::$stateMemo = [];
            $st = self::readState($sort);
            if (isset($st['max_id'])) {
                $rows = $pdo->query('SELECT vod_id, type_id, type_id_1, vod_time_add FROM ' . self::t('vod') . ' WHERE vod_id > ' . (int)$st['max_id']
                    . ' AND vod_status = 1 AND vod_recycle_time = 0 ORDER BY vod_id ASC LIMIT ' . self::APPEND_MAX)->fetchAll(\PDO::FETCH_NUM);
                usort($rows, function ($a, $b) { return [(int)$a[3], (int)$a[0]] <=> [(int)$b[3], (int)$b[0]]; });
            } else {
                // a state written before max_id: the old keyset once; max_id is recorded below for the next appends
                $v = (int)$st['last'][0];
                $id = (int)$st['last'][1];
                $rows = $pdo->query('SELECT vod_id, type_id, type_id_1, vod_time_add FROM ' . self::t('vod') . ' WHERE vod_status = 1 AND vod_recycle_time = 0 AND vod_time_add >= ' . $v
                    . ' AND (vod_time_add > ' . $v . ' OR vod_id > ' . $id . ') ORDER BY vod_time_add ASC, vod_id ASC LIMIT ' . self::APPEND_MAX)->fetchAll(\PDO::FETCH_NUM);
            }
            $types = $rows ? self::types() : [];
            $parents = [];
            foreach ($types as $tid => $pid) {
                if ($pid > 0) {
                    $parents[$pid] = true;
                }
            }
            $buf = [];
            $maxId = (int)($st['max_id'] ?? 0);
            foreach ($rows as $r) {
                $vid = (int)$r[0];
                $t = (int)$r[1];
                $t1 = (int)$r[2];
                $buf['a'][] = $vid;
                if ($t > 0) {
                    $buf['t' . $t][] = $vid;
                    if (isset($parents[$t])) {
                        $buf['p' . $t][] = $vid;
                    }
                }
                if ($t1 > 0 && $t1 !== $t) {
                    $buf['p' . $t1][] = $vid;
                }
                $maxId = max($maxId, $vid);
            }
            $dir = self::dir($sort);
            foreach ($buf as $lk => $ids) {
                foreach (array_chunk($ids, self::FLUSH) as $c) {
                    if (@file_put_contents($dir . $lk . '.u32', pack('V*', ...$c), FILE_APPEND | LOCK_EX) === false) {
                        throw new \RuntimeException('ListRank: append failed for ' . $lk);
                    }
                }
                $st['keys'][$lk] = (int)($st['keys'][$lk] ?? 0) + count($ids);
            }
            if ($rows) {
                $lastRow = $rows[count($rows) - 1];
                $st['last'] = [(int)$lastRow[3], (int)$lastRow[0]];
                $st['rows'] = (int)($st['rows'] ?? 0) + count($rows);
                $st['max_id'] = $maxId;
            }
            $st['appended_at'] = time();
            self::writeState($sort, $st);
            return ['state' => 'done', 'rows' => count($rows), 'more' => count($rows) >= self::APPEND_MAX];
        } catch (\Throwable $e) {
            return ['state' => 'error', 'error' => get_class($e) . ' ' . substr($e->getMessage(), 0, 160), 'rows' => 0];
        } finally {
            try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')')->fetchAll(); } catch (\Throwable $e) { }
        }
    }

    /** C15 state: ready (every sort built and fresh), pct (sorts usable / sorts), built_at (oldest), sorts, mb. */
    public static function state(): array
    {
        $now = time();
        $rows = [];
        $ok = 0;
        $bytes = 0;
        $oldest = 0;
        self::$stateMemo = [];
        foreach (self::SORTS as $sort) {
            $st = self::readState($sort);
            $fresh = self::fresh($sort, $st, $now);
            $ok += $fresh ? 1 : 0;
            $bytes += (int)($st['bytes'] ?? 0);
            if (!empty($st['built_at'])) {
                $oldest = $oldest === 0 ? (int)$st['built_at'] : min($oldest, (int)$st['built_at']);
            }
            $rows[] = ['sort' => $sort, 'ready' => $fresh, 'pct' => $fresh ? 100 : 0, 'built_at' => (int)($st['built_at'] ?? 0), 'rows' => (int)($st['rows'] ?? 0)];
        }
        return ['ready' => $ok === count(self::SORTS), 'pct' => round(100 * $ok / count(self::SORTS), 1), 'built_at' => $oldest, 'sorts' => $rows,
            'mb' => round($bytes / 1048576, 2), 'state' => $ok === count(self::SORTS) ? 'ready' : ($oldest ? 'partial' : 'missing')];
    }
}
