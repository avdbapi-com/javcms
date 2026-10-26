<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\common\util;

/**
 * C4 (owner: lane H). Counts computed in the background (scale_count table). XVID-SCALE-BRIEF 3.2 C4, D-L3, L7, L12.
 *
 * Every getter returns null when no count is known yet (table missing, never counted, database error). Callers MUST treat
 * null as "unknown" and keep today's behaviour (lane T: no hide_empty hiding; lane S: bounded count), never as 0.
 *
 *   type(int $typeId): ?int       published videos in that category (a parent counts its children), 0 when counted and empty
 *   all(): ?int                   published videos in the whole site
 *   share(array $typeIds): ?float share 0.0-1.0 of all published videos that lie in these categories (a parent and its
 *                                 children in one list are not counted twice)
 *   markDirty(string $why): void  request a recount ('import', 'delete', 'type', 'save', 'bulk', ...): one small file write,
 *                                 never a database query; the recount runs in housekeeping (at most every 5 min, 15 min
 *                                 while a bulk job runs, plus once per night)
 *   freshness(): int              unix time of the last complete recount, 0 = never counted
 *
 * Also (lane H internals, used by Extend::dataCount(), mac_data_count(), housekeeping and tools):
 *   dataCount(): array            the array Extend::dataCount() returned before 1.2.0 (same keys), read from the table;
 *                                 [] when unknown. Visitors never run GROUP BY / COUNT / MIN on the video table.
 *   due(): bool, runIfDue(): ?array, recount(PDO, prefix): array  the counts job (single flight, own connection without
 *                                 statement limit, the D-L1 index forced only when ScaleSchema confirmed it). With a
 *                                 deadline (runIfDue(pdo, prefix, deadline), web housekeeping under a PHP time limit) the
 *                                 video counts are resumable slices (vodSlices(), runtime/scale/counts_partial.json) and the
 *                                 totals are written only when the last slice is done; the command line counts in one go.
 * Keys written: vod_all, vod_today, vod_min, type_all_<id>, type_today_<id>, type_own_<id> (rows of the type itself),
 * art_all, art_today, art_min, topic_all, topic_today, topic_min (and tpoic_min, the historical spelling of dataCount),
 * actor_*, role_*, website_* (all/today/min), type_mod_<id> (newest change per category, sitemap lastmod, D-L9),
 * year_<yyyy> (per-year counts; year_hidden=1 when they could not be computed
 * within 20 s), counted_at, count_ms. tier_pin is never touched by the recount.
 */
final class ScaleCounts
{
    public const MIN_GAP = 300;        // recount at most every 5 minutes when dirty
    public const BULK_GAP = 900;       // 15 minutes while a bulk import runs
    public const NIGHT_HOUR = 3;       // nightly recount after 03:00 local time
    public const YEAR_LIMIT_S = 20;    // per-year counts must finish within 20 s, else hidden
    public const PK_CHUNK = 20000;     // ids per statement of a budgeted recount without the D-L1 index
    public const PARTIAL_FILE = 'counts_partial.json';
    public const PARTIAL_MAX_AGE = 21600; // a resumable recount older than 6 h starts over

    private static $snap = false;      // false = not read in this request; null = unknown; array = k => v

    public static function type(int $typeId): ?int
    {
        $s = self::snapshot();
        if ($s === null || $typeId < 1) {
            return null;
        }
        return (int)($s['type_all_' . $typeId] ?? 0);
    }

    public static function all(): ?int
    {
        $s = self::snapshot();
        return $s === null ? null : (int)($s['vod_all'] ?? 0);
    }

    public static function share(array $typeIds): ?float
    {
        $s = self::snapshot();
        if ($s === null) {
            return null;
        }
        $all = (int)($s['vod_all'] ?? 0);
        if ($all <= 0) {
            return 0.0;
        }
        $ids = [];
        foreach ($typeIds as $id) {
            if ((int)$id > 0) { $ids[(int)$id] = true; }
        }
        $sum = 0;
        foreach (array_keys($ids) as $id) {
            $parent = (int)($s['type_parent_' . $id] ?? 0);
            if ($parent > 0 && isset($ids[$parent])) {
                continue; // the parent's total already holds this child
            }
            $sum += (int)($s['type_all_' . $id] ?? 0);
        }
        return max(0.0, min(1.0, $sum / $all));
    }

    public static function markDirty(string $why): void
    {
        $why = preg_replace('/[^a-z0-9_]/', '', strtolower($why)) ?: 'x';
        $file = ScaleTier::runtime('scale') . 'counts.dirty';
        $line = time() . ' ' . substr($why, 0, 16) . "\n";
        // small append; a file over 4 KB is reset to the last line (only the newest reasons matter)
        if (is_file($file) && (int)filesize($file) > 4096) {
            @file_put_contents($file, $line, LOCK_EX);
        } else {
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        }
    }

    public static function freshness(): int
    {
        $s = self::snapshot();
        return $s === null ? 0 : (int)($s['counted_at'] ?? 0);
    }

    /** The dataCount() array (same keys as before 1.2.0) from the precomputed table; [] when unknown. */
    public static function dataCount(): array
    {
        $s = self::snapshot();
        if ($s === null) {
            return [];
        }
        $out = [];
        foreach ($s as $k => $v) {
            if (strpos($k, 'type_own_') === 0 || strpos($k, 'type_parent_') === 0 || strpos($k, 'type_mod_') === 0 || $k === 'tier_pin') {
                continue;
            }
            $out[$k] = (int)$v;
        }
        return $out;
    }

    /** k => v of scale_count, read once per request; null = unknown. */
    public static function snapshot(): ?array
    {
        if (self::$snap !== false) {
            return self::$snap;
        }
        self::$snap = null;
        $q = ScaleTier::queryFn(null, null);
        if ($q === null) {
            return null;
        }
        try {
            $rows = $q[0]('SELECT k, v FROM ' . $q[1] . 'scale_count');
            $out = [];
            foreach ($rows as $row) {
                $out[(string)$row['k']] = (int)$row['v'];
            }
            // never counted (only the tier pin or nothing): unknown
            self::$snap = isset($out['counted_at']) ? $out : null;
        } catch (\Throwable $e) {
            self::$snap = null; // table missing or database busy: numbers hidden, never computed here
        }
        return self::$snap;
    }

    /**
     * Signature of the set of video categories that hold videos (written by the recount). Part of the public page-cache key
     * (HP-H-2), so pages with menus refresh as soon as a category becomes empty or non-empty, also when the recount ran in
     * the command-line housekeeping where Type::bumpListRevision() is not available (HO-T-2).
     */
    public static function menuSignature(): string
    {
        $f = ScaleTier::runtime('scale') . 'menu_sig.txt';
        $s = is_file($f) ? trim((string)file_get_contents($f)) : '';
        return preg_match('/^[0-9a-f]{8}$/', $s) ? $s : '';
    }

    /**
     * [type_id => newest change] of categories (videos, articles, performers; a parent includes its children), written by
     * the recount as type_mod_<id>. null = never counted (the sitemap then prints no category lastmod). D-L9.
     */
    public static function typeModified(): ?array
    {
        $s = self::snapshot();
        if ($s === null) {
            return null;
        }
        $out = [];
        foreach ($s as $k => $v) {
            if (strpos($k, 'type_mod_') === 0) {
                $out[(int)substr($k, 9)] = (int)$v;
            }
        }
        return $out;
    }

    /** Test hook: forget the per-request snapshot (or inject one). */
    public static function reset($snapshot = false): void
    {
        self::$snap = $snapshot;
    }

    /** Housekeeping: is a recount due? (dirty and the gap passed, or never counted, or the nightly run is due) */
    public static function due(?int $now = null): bool
    {
        $now = $now ?? time();
        $state = ScaleTier::readJson(ScaleTier::runtime('scale') . 'counts.json') ?: [];
        $last = (int)($state['counted_at'] ?? 0);
        if ($last === 0) {
            return true;
        }
        $dirty = self::dirtySince($last, $now);
        if ($dirty !== null) {
            $gap = $dirty['bulk'] ? self::BULK_GAP : self::MIN_GAP;
            if ($now - $last >= $gap) {
                return true;
            }
        }
        // nightly: once per local day after NIGHT_HOUR
        $todayNight = strtotime(date('Y-m-d', $now) . ' ' . sprintf('%02d:00:00', self::NIGHT_HOUR));
        return $now >= $todayNight && $last < $todayNight;
    }

    /** Run the counts job when due, on an own connection without statement limit. null when not due / busy. */
    public static function runIfDue(?\PDO $pdo = null, ?string $prefix = null, ?float $deadline = null): ?array
    {
        if (!self::due()) {
            return null;
        }
        if ($pdo === null) {
            $pdo = ScaleSchema::connect(true);
            $prefix = ScaleSchema::configPrefix();
            if ($pdo === null) {
                return null;
            }
        }
        return self::recount($pdo, (string)$prefix, $deadline !== null ? ['deadline' => $deadline] : []);
    }

    /**
     * The counts job. Single flight (lock file), writes every key in one transaction, then refreshes the tier.
     * Returns ['ok' => bool, 'state' => 'done'|'busy'|'error', 'keys' => int, 'ms' => int, 'year_hidden' => bool, ...].
     */
    public static function recount(\PDO $pdo, string $prefix, array $opt = []): array
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
            return ['ok' => false, 'state' => 'error', 'error' => 'invalid prefix'];
        }
        $lock = @fopen(ScaleTier::runtime('scale') . 'counts.lock', 'c');
        if (!$lock) {
            return ['ok' => false, 'state' => 'error', 'error' => 'lock file'];
        }
        $locked = ScaleTier::lock($lock, LOCK_EX, 0);
        if ($locked === false) {
            @fclose($lock);
            return ['ok' => false, 'state' => 'busy'];
        }
        $t0 = microtime(true);
        $now = (int)($opt['now'] ?? time());
        $today = strtotime(date('Y-m-d', $now));
        $p = $prefix;
        $sel = function ($sql, array $bind = []) use ($pdo) {
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            return $st->fetchAll(\PDO::FETCH_ASSOC);
        };
        $one = function ($sql, array $bind = []) use ($sel) {
            $r = $sel($sql, $bind);
            return $r ? (int)reset($r[0]) : 0;
        };
        $exists = function ($table) use ($sel) {
            return (bool)$sel('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        };
        try {
            $data = [];
            // category tree (type_mid 1 = videos, 2 = articles)
            $parent = [];
            $vodTypes = [];
            foreach ($sel('SELECT type_id, type_pid, type_mid FROM ' . $p . 'type') as $t) {
                $parent[(int)$t['type_id']] = (int)$t['type_pid'];
                if ((int)$t['type_mid'] === 1) { $vodTypes[] = (int)$t['type_id']; }
            }
            $force = '';
            if (!empty($opt['force_index']) || ScaleSchema::indexesConfirmed()) {
                $name = ScaleSchema::indexName('type_list');
                if ($name !== '') {
                    $force = ' FORCE INDEX (`' . $name . '`)';
                }
            }
            // videos per category: covering D-L1 index when confirmed (type_id, vod_status, vod_recycle_time, vod_time)
            // the same scan also gives the newest vod_time per category (sitemap typeModified, D-L9: type_mod_<id>)
            $own = [];
            $mod = [];
            $ownToday = [];
            $years = null;      // null = the bounded GROUP BY vod_year below; array = counted by the slices
            $deadline = isset($opt['deadline']) ? (float)$opt['deadline'] : null;
            if ($deadline !== null) {
                // web slice (page-view or cron-URL housekeeping under a PHP time limit): resumable slices within the budget
                $part = self::vodSlices($sel, $p, $force, $today, $now, $deadline);
                if ($part['state'] === 'partial') {
                    return ['ok' => true, 'state' => 'partial', 'mode' => $part['mode'], 'cursor' => $part['cursor'],
                        'slices' => $part['slices'], 'ms' => (int)round((microtime(true) - $t0) * 1000)];
                }
                [$own, $mod, $ownToday, $years] = [$part['own'], $part['mod'], $part['today'], $part['years']];
            } else {
                foreach ($sel('SELECT type_id, COUNT(*) AS c, MAX(vod_time) AS m FROM ' . $p . 'vod' . $force . ' WHERE vod_status = 1 AND vod_recycle_time = 0 GROUP BY type_id') as $r) {
                    $own[(int)$r['type_id']] = (int)$r['c'];
                    $mod[(int)$r['type_id']] = (int)$r['m'];
                }
                foreach ($sel('SELECT type_id, COUNT(*) AS c FROM ' . $p . 'vod' . $force . ' WHERE vod_status = 1 AND vod_recycle_time = 0 AND vod_time >= ? GROUP BY type_id', [$today]) as $r) {
                    $ownToday[(int)$r['type_id']] = (int)$r['c'];
                }
            }
            self::fold($data, $own, $ownToday, $parent, 'vod');
            $data['vod_min'] = $one('SELECT vod_id FROM ' . $p . 'vod WHERE vod_recycle_time = 0 ORDER BY vod_id ASC LIMIT 1');
            // articles (same type key space, as dataCount() always did)
            if ($exists($p . 'art')) {
                $artOwn = [];
                foreach ($sel('SELECT type_id, COUNT(*) AS c, MAX(art_time) AS m FROM ' . $p . 'art WHERE art_status = 1 GROUP BY type_id') as $r) {
                    $artOwn[(int)$r['type_id']] = (int)$r['c'];
                    $mod[(int)$r['type_id']] = max($mod[(int)$r['type_id']] ?? 0, (int)$r['m']);
                }
                $artToday = [];
                foreach ($sel('SELECT type_id, COUNT(*) AS c FROM ' . $p . 'art WHERE art_status = 1 AND art_time >= ? GROUP BY type_id', [$today]) as $r) {
                    $artToday[(int)$r['type_id']] = (int)$r['c'];
                }
                self::fold($data, $artOwn, $artToday, $parent, 'art');
                $data['art_min'] = $one('SELECT art_id FROM ' . $p . 'art ORDER BY art_id ASC LIMIT 1');
            }
            foreach (['topic', 'actor', 'role', 'website'] as $m) {
                if (!$exists($p . $m)) {
                    continue;
                }
                $data[$m . '_all'] = $one('SELECT COUNT(*) FROM ' . $p . $m . ' WHERE ' . $m . '_status = 1');
                $data[$m . '_today'] = $one('SELECT COUNT(*) FROM ' . $p . $m . ' WHERE ' . $m . '_status = 1 AND ' . $m . '_time >= ?', [$today]);
                $data[$m . '_min'] = $one('SELECT ' . $m . '_id FROM ' . $p . $m . ' ORDER BY ' . $m . '_id ASC LIMIT 1');
            }
            $data['tpoic_min'] = (int)($data['topic_min'] ?? 0);
            // performers per category (small table): newest change for the sitemap's category lastmod
            if ($exists($p . 'actor')) {
                try {
                    foreach ($sel('SELECT type_id, MAX(actor_time) AS m FROM ' . $p . 'actor WHERE actor_status = 1 GROUP BY type_id') as $r) {
                        $mod[(int)$r['type_id']] = max($mod[(int)$r['type_id']] ?? 0, (int)$r['m']);
                    }
                } catch (\Throwable $e) {
                    // an old actor table without type_id: no performer dates
                }
            }
            // type_mod_<id>: own newest change, a parent also takes its children's (Sitemap::typeModified reads these)
            $typeMod = $mod;
            foreach ($mod as $id => $m) {
                $pid = $parent[$id] ?? 0;
                if ($pid > 0 && $pid !== $id) {
                    $typeMod[$pid] = max($typeMod[$pid] ?? 0, $m);
                }
            }
            foreach ($typeMod as $id => $m) {
                if ($id > 0 && $m > 0) {
                    $data['type_mod_' . $id] = $m;
                }
            }
            // per-year counts: bounded to 20 s on this statement; hidden when they cannot finish
            $yearHidden = false;
            if (is_array($years)) {
                foreach ($years as $y => $c) {
                    if ((int)$y >= 1900 && (int)$y <= 2100) { $data['year_' . (int)$y] = (int)$c; }
                }
            } elseif ($years === 'hidden') {
                $yearHidden = true;
            } else try {
                $yearSql = 'SELECT vod_year AS y, COUNT(*) AS c FROM ' . $p . 'vod WHERE vod_status = 1 AND vod_recycle_time = 0 GROUP BY vod_year';
                $v = DbTimeout::parseVersion(DbTimeout::cachedVersion());
                if ($v['kind'] === 'mariadb' && $v['id'] >= 100101) {
                    $yearSql = 'SET STATEMENT max_statement_time=' . self::YEAR_LIMIT_S . ' FOR ' . $yearSql;
                } elseif ($v['kind'] === 'mysql' && $v['id'] >= 50708) {
                    $yearSql = preg_replace('/^SELECT /', 'SELECT /*+ MAX_EXECUTION_TIME(' . (self::YEAR_LIMIT_S * 1000) . ') */ ', $yearSql);
                }
                $ys = microtime(true);
                foreach ($sel($yearSql) as $r) {
                    $y = (int)$r['y'];
                    if ($y >= 1900 && $y <= 2100) {
                        $data['year_' . $y] = (int)$r['c'];
                    }
                }
                if ((microtime(true) - $ys) > self::YEAR_LIMIT_S) {
                    $yearHidden = true;
                }
            } catch (\Throwable $e) {
                $yearHidden = true;
            }
            if ($yearHidden) {
                foreach (array_keys($data) as $k) {
                    if (strpos($k, 'year_') === 0) { unset($data[$k]); }
                }
            }
            $data['year_hidden'] = $yearHidden ? 1 : 0;
            $ms = (int)round((microtime(true) - $t0) * 1000);
            $data['count_ms'] = $ms;
            $data['counted_at'] = $now;
            // write: one transaction, REPLACE every key, then drop keys of this job that were not refreshed
            $pdo->beginTransaction();
            try {
                $rowsSql = [];
                $bind = [];
                foreach ($data as $k => $v) {
                    $rowsSql[] = '(?, ?, ?)';
                    array_push($bind, substr((string)$k, 0, 64), max(0, (int)$v), $now);
                    if (count($rowsSql) >= 400) {
                        $pdo->prepare('REPLACE INTO ' . $p . 'scale_count (k, v, at) VALUES ' . implode(',', $rowsSql))->execute($bind);
                        $rowsSql = [];
                        $bind = [];
                    }
                }
                if ($rowsSql) {
                    $pdo->prepare('REPLACE INTO ' . $p . 'scale_count (k, v, at) VALUES ' . implode(',', $rowsSql))->execute($bind);
                }
                $pdo->prepare('DELETE FROM ' . $p . "scale_count WHERE at < ? AND k <> 'tier_pin'")->execute([$now]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            // HO-T-2: menus depend on which video categories hold videos (hide_empty, single-parent rule, provider block)
            $nonEmpty = [];
            foreach ($vodTypes as $id) {
                if ((int)($data['type_all_' . $id] ?? 0) > 0) { $nonEmpty[] = $id; }
            }
            sort($nonEmpty);
            $sig = sprintf('%08x', crc32(implode(',', $nonEmpty)));
            if ($sig !== self::menuSignature()) {
                ScaleTier::writeAtomic(ScaleTier::runtime('scale') . 'menu_sig.txt', $sig);
                if (isset($GLOBALS['config']['app']['cache_flag']) && class_exists('\think\Cache', false)
                    && class_exists('\app\common\model\Type') && method_exists('\app\common\model\Type', 'bumpListRevision')) {
                    try { \app\common\model\Type::bumpListRevision(true); } catch (\Throwable $e) {}
                }
            }
            ScaleTier::writeAtomic(ScaleTier::runtime('scale') . 'counts.json', json_encode(['counted_at' => $now, 'ms' => $ms,
                'keys' => count($data), 'year_hidden' => $yearHidden], JSON_UNESCAPED_SLASHES));
            @unlink(ScaleTier::runtime('scale') . self::PARTIAL_FILE);
            self::$snap = false;
            ScaleTier::refreshWith($pdo, $p);
            return ['ok' => true, 'state' => 'done', 'keys' => count($data), 'ms' => $ms, 'year_hidden' => $yearHidden,
                'forced_index' => $force !== '', 'vod_all' => (int)($data['vod_all'] ?? 0)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'state' => 'error', 'error' => substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 300)];
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * Resumable video counts for budgeted runs (verify F12: the one-shot GROUP BY ran past PHP's 50 s limit at 524 k rows
     * on a 128 MB pool / 1 core and never completed). The partial sums and the cursor live in runtime/scale/
     * counts_partial.json; every call continues where the last one stopped and returns 'partial' at the deadline.
     *   mode 'type' (D-L1 index confirmed): one covering-index range per category value found in the index (orphan
     *     type ids included), cursor = last type_id; then the bounded per-year statement (hidden when it cannot finish).
     *   mode 'pk' (no confirmed index, e.g. a 1.1.0 schema before "Finish upgrade"): PRIMARY ranges of PK_CHUNK ids with
     *     GROUP BY type_id, vod_year (years counted in the same pass), cursor = last vod_id.
     * A partial older than PARTIAL_MAX_AGE or from another day starts over. Totals are written only at the end (caller).
     * Returns ['state' => 'partial'|'done', 'mode', 'cursor', 'slices', 'own', 'mod', 'today', 'years' => array|'hidden'].
     */
    private static function vodSlices(callable $sel, string $p, string $force, int $today, int $now, float $deadline): array
    {
        $file = ScaleTier::runtime('scale') . self::PARTIAL_FILE;
        $st = ScaleTier::readJson($file);
        $mode = $force !== '' ? 'type' : 'pk';
        if (!is_array($st) || ($st['v'] ?? 0) !== 1 || ($st['mode'] ?? '') !== $mode || (int)($st['today'] ?? 0) !== $today
            || $now - (int)($st['started'] ?? 0) > self::PARTIAL_MAX_AGE) {
            $st = ['v' => 1, 'mode' => $mode, 'today' => $today, 'started' => $now, 'cursor' => -1, 'phase' => 'vod',
                'max' => 0, 'own' => [], 'mod' => [], 'td' => [], 'years' => [], 'slices' => 0];
            if ($mode === 'pk') {
                $r = $sel('SELECT MAX(vod_id) AS m FROM ' . $p . 'vod');
                $st['max'] = $r ? (int)$r[0]['m'] : 0;
                $st['cursor'] = 0;
            }
        }
        $st['slices'] = (int)$st['slices'] + 1;
        $yearAtStart = $st['phase'] === 'year';
        $add = function (array &$map, $k, int $v) { $map[(string)$k] = (int)($map[(string)$k] ?? 0) + $v; };
        $time = function () use ($deadline) { return microtime(true) < $deadline; };
        if ($st['phase'] === 'vod' && $mode === 'type') {
            $next = 'SELECT type_id FROM ' . $p . 'vod' . $force . ' WHERE type_id > ? ORDER BY type_id LIMIT 1';
            $count = 'SELECT COUNT(*) AS c, MAX(vod_time) AS m, COALESCE(SUM(vod_time >= ?), 0) AS t FROM ' . $p . 'vod' . $force
                . ' WHERE type_id = ? AND vod_status = 1 AND vod_recycle_time = 0';
            while ($time()) {
                $r = $sel($next, [(int)$st['cursor']]);
                if (!$r) { $st['phase'] = 'year'; break; }
                $id = (int)$r[0]['type_id'];
                $c = $sel($count, [$today, $id]);
                if ($c && (int)$c[0]['c'] > 0) {
                    $st['own'][(string)$id] = (int)$c[0]['c'];
                    $st['mod'][(string)$id] = (int)$c[0]['m'];
                    if ((int)$c[0]['t'] > 0) { $st['td'][(string)$id] = (int)$c[0]['t']; }
                }
                $st['cursor'] = $id;
            }
        }
        if ($st['phase'] === 'vod' && $mode === 'pk') {
            $sql = 'SELECT type_id, vod_year, COUNT(*) AS c, MAX(vod_time) AS m, COALESCE(SUM(vod_time >= ?), 0) AS t FROM ' . $p . 'vod'
                . ' WHERE vod_id > ? AND vod_id <= ? AND vod_status = 1 AND vod_recycle_time = 0 GROUP BY type_id, vod_year';
            while ($time()) {
                if ((int)$st['cursor'] >= (int)$st['max']) { $st['phase'] = 'done'; break; }
                $a = (int)$st['cursor'];
                $b = min((int)$st['max'], $a + self::PK_CHUNK);
                foreach ($sel($sql, [$today, $a, $b]) as $r) {
                    $id = (int)$r['type_id'];
                    $add($st['own'], $id, (int)$r['c']);
                    $st['mod'][(string)$id] = max((int)($st['mod'][(string)$id] ?? 0), (int)$r['m']);
                    if ((int)$r['t'] > 0) { $add($st['td'], $id, (int)$r['t']); }
                    $add($st['years'], (int)$r['vod_year'], (int)$r['c']);
                }
                $st['cursor'] = $b;
            }
        }
        if ($st['phase'] === 'year') {
            // the per-year statement gets the full YEAR_LIMIT_S when it can, so it starts a slice of its own unless enough of
            // this slice is left (else the next run does it first); hidden only when it cannot finish within that limit
            $left = (int)floor($deadline - microtime(true)) - 1;
            if ($left >= 3 && ($yearAtStart || $left >= min(self::YEAR_LIMIT_S, 15))) {
                $limit = min(self::YEAR_LIMIT_S, $left);
                $yearSql = 'SELECT vod_year AS y, COUNT(*) AS c FROM ' . $p . 'vod WHERE vod_status = 1 AND vod_recycle_time = 0 GROUP BY vod_year';
                $v = DbTimeout::parseVersion(DbTimeout::cachedVersion());
                if ($v['kind'] === 'mariadb' && $v['id'] >= 100101) {
                    $yearSql = 'SET STATEMENT max_statement_time=' . $limit . ' FOR ' . $yearSql;
                } elseif ($v['kind'] === 'mysql' && $v['id'] >= 50708) {
                    $yearSql = preg_replace('/^SELECT /', 'SELECT /*+ MAX_EXECUTION_TIME(' . ($limit * 1000) . ') */ ', $yearSql);
                }
                $ys = microtime(true);
                try {
                    $years = [];
                    foreach ($sel($yearSql) as $r) { $years[(string)(int)$r['y']] = (int)$r['c']; }
                    $st['years'] = (microtime(true) - $ys) > $limit ? 'hidden' : $years;
                } catch (\Throwable $e) {
                    $st['years'] = 'hidden';
                }
                $st['phase'] = 'done';
            }
        }
        $out = ['state' => $st['phase'] === 'done' ? 'done' : 'partial', 'mode' => $mode, 'cursor' => (int)$st['cursor'],
            'slices' => (int)$st['slices']];
        if ($out['state'] === 'partial') {
            ScaleTier::writeAtomic($file, json_encode($st, JSON_UNESCAPED_SLASHES));
            return $out;
        }
        $int = function ($m) { $o = []; foreach ((array)$m as $k => $v) { $o[(int)$k] = (int)$v; } return $o; };
        return $out + ['own' => $int($st['own']), 'mod' => $int($st['mod']), 'today' => $int($st['td']),
            'years' => is_array($st['years']) ? $int($st['years']) : 'hidden'];
    }

    /** own counts -> type_own_*, type_all_* (parents summed), type_today_*, <flag>_all / <flag>_today, type_parent_*. */
    private static function fold(array &$data, array $own, array $ownToday, array $parent, string $flag): void
    {
        $data[$flag . '_all'] = array_sum($own);
        $data[$flag . '_today'] = array_sum($ownToday);
        foreach ($own as $id => $c) {
            $data['type_own_' . $id] = ($data['type_own_' . $id] ?? 0) + $c;
            $data['type_all_' . $id] = ($data['type_all_' . $id] ?? 0) + $c;
            $pid = $parent[$id] ?? 0;
            if ($pid > 0 && $pid !== $id) {
                $data['type_all_' . $pid] = ($data['type_all_' . $pid] ?? 0) + $c;
                $data['type_parent_' . $id] = $pid;
            }
        }
        foreach ($ownToday as $id => $c) {
            $data['type_today_' . $id] = ($data['type_today_' . $id] ?? 0) + $c;
            $pid = $parent[$id] ?? 0;
            if ($pid > 0 && $pid !== $id) {
                $data['type_today_' . $pid] = ($data['type_today_' . $pid] ?? 0) + $c;
            }
        }
        // categories that exist but hold nothing get an explicit 0 (counted and empty, not unknown)
        foreach ($parent as $id => $pid) {
            if (!isset($data['type_all_' . $id])) { $data['type_all_' . $id] = 0; }
        }
    }

    /** ['bulk' => bool] when a markDirty() arrived after $since, else null. */
    private static function dirtySince(int $since, int $now): ?array
    {
        $dirtyFile = ScaleTier::runtime('scale') . 'counts.dirty';
        $raw = is_file($dirtyFile) ? @file_get_contents($dirtyFile) : false;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $found = false;
        $bulk = false;
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode(' ', $line, 2);
            $t = (int)$parts[0];
            if ($t >= $since) {
                $found = true;
            }
            if (($parts[1] ?? '') === 'bulk' && $t >= $now - self::BULK_GAP) {
                $bulk = true;
            }
        }
        return $found ? ['bulk' => $bulk] : null;
    }
}
