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
 * C14 (lane S, XVID-NOLIMIT-BRIEF B1): page-boundary map of the unfiltered newest-first lists, so every page of a list
 * sorted by vod_time costs what page 1 costs (one map row + at most B + per index entries + per primary-key reads).
 *
 * Tables (lane H, scale.sql / ScaleSchema::ensureTables()): {pre}list_pagemap (lk, seq, sort_v, vod_id) and
 * {pre}list_pagemap_state (lk, total, top_v, top_id, dirty_from, built_at, stale_ops, walk_seq, walk_v, walk_id).
 * Keys (lk): 'a' (all published), 't<id>' (one category), 'p<id>' (a parent: type_id_1 = P plus type_id = P), 'adm' (the
 * admin list: every not-recycled row), each optionally suffixed ':h<crc32>' for a visitor group with hidden categories
 * (built when first asked for: seek() records the wish, refresh() builds it).
 * Ranks count from the OLDEST row (0 = oldest), ordered by (vod_time, vod_id); entry seq k holds the row at rank k x B.
 * New rows on top never move a rank, so the map only grows at the top between rebuilds.
 *
 *   seek($lk, $page, $per, $total[, $asc, $hidden]): ?array  ids of that page, newest first ($asc: oldest first);
 *                    [] past the last page; null = no usable map (the caller serves today's deferred join)
 *   refresh($budgetSec): array      housekeeping / CLI: takes the dirty marks, appends new top rows, resumable rewalks
 *                                   (from the entry below the lowest change; a full one at stale_ops > 0.5 %, a total
 *                                   that differs from ScaleCounts by > 0.1 % (count newer than the key's last change) or
 *                                   by > 1 % (any count, once per count snapshot), and weekly). State 'more' = work left.
 *   dirty($minSortV, $typeIds[, $removed]): void   the mark is written next to the map (round-4 R4-4): dirty_from =
 *                                   LEAST(dirty_from, $minSortV) / stale_ops + $removed in the state rows of the keys it
 *                                   touches, so a lost or new runtime folder (database restored without it, a new server on
 *                                   the same database, a runtime wipe) cannot leave the maps behind their lists; one line in
 *                                   runtime/scale/pagemap.dirty only when that write fails. $minSortV 0 = everything;
 *                                   $removed > 0 = that many rows left the lists below the top (counted in stale_ops: pages
 *                                   shift by that many rows until the next full rewalk)
 *   state(): array                  ready / pct / built_at / keys / rows / mb (lane E's Speed page, tools/upgrade.php)
 * Marks and walks never overwrite each other: dirty_from and stale_ops are changed only by atomic statements (LEAST / add
 * by a mark, a compare-and-set to 0 when a walk takes a mark, a subtraction of what a full walk accounted for); the walk
 * saves only its own columns (total, top, built_at, walk position).
 *
 * Connection: inside ThinkPHP the framework's PDO (visitor statement limit applies); housekeeping / CLI / tests pass their
 * own with useConnection(). Identifiers come only from fixed names, the validated prefix and validated index names;
 * every value is an int.
 */
final class ListPageMap
{
    /** rows between two map entries */
    const B = 1000;
    /** pages up to this keep today's query (index from the top); deeper pages seek from the map */
    const AFTER_PAGE = 50;
    /** index entries per walk query */
    const CHUNK = 50000;
    /** full rewalk when stale_ops passes this share of the key's rows, or the total drifts from ScaleCounts by this share */
    const STALE_SHARE = 0.005;
    const DRIFT_SHARE = 0.001;
    const DRIFT_MIN = 10;
    /**
     * Round-4 R4-4: a total that differs from the ScaleCounts snapshot by more than this share starts a full rewalk even
     * when the count is older than the key's last change (rows placed below the top whose marks were lost: the map's total
     * stays behind the list), at most once per count snapshot (a count taken before a big import of new top rows must not
     * start a rewalk on every run until the next recount).
     */
    const DRIFT_BIG_SHARE = 0.01;
    /** Compare-and-set tries when a walk takes a key's mark (a concurrent mark lowered it in between). */
    const TAKE_TRIES = 4;
    /** a full rewalk at least weekly */
    const FULL_EVERY = 604800;
    /** a seek may read at most this many rows above its entry (a map far behind its list serves nothing) */
    const SEEK_MAX_SKIP = 6000;
    /** hidden-category keys kept at most */
    const WANT_MAX = 64;
    const T_MAP = 'list_pagemap';
    const T_STATE = 'list_pagemap_state';

    private static $pdo = null;
    private static $prefix = null;
    private static $runtime = null;
    private static $states = [];
    private static $meta = null;
    private static $wanted = [];
    /**
     * Test hook (tests/list_pagemap.php, round-4 R4-4): callable(string $event, string $lk) run by refresh() after a walk
     * took a key's mark ('taken') and after each saved walk chunk ('chunk'), so a test can write a mark while a walk runs.
     * Null on a site.
     */
    public static $testHook = null;

    // ------------------------------------------------------------------ connection

    public static function useConnection(\PDO $pdo, string $prefix): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{0,64}$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid table prefix');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::$pdo = $pdo;
        self::$prefix = $prefix;
        self::reset();
    }

    /** The site's runtime/ folder (pagemap.json, pagemap.dirty, pagemap.want live in runtime/scale/). */
    public static function useRuntime(string $dir): void
    {
        self::$runtime = rtrim(str_replace('\\', '/', $dir), '/');
        self::$meta = null;
    }

    public static function reset(): void
    {
        self::$states = [];
        self::$meta = null;
        self::$wanted = [];
    }

    private static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            if (!class_exists('\think\Db')) {
                throw new \RuntimeException('ListPageMap: no database connection');
            }
            \think\Db::query('SELECT 1');
            $pdo = \think\Db::connect()->getPdo();
            $prefix = (string)\think\Config::get('database.prefix');
            if (!$pdo instanceof \PDO || !preg_match('/^[A-Za-z0-9_]{0,64}$/D', $prefix)) {
                throw new \RuntimeException('ListPageMap: no database connection');
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

    private static function q(string $sql, array $bind = [], int $mode = \PDO::FETCH_ASSOC): array
    {
        $pdo = self::pdo();
        if ($bind) {
            $st = $pdo->prepare($sql);
            $st->execute($bind);
        } else {
            $st = $pdo->query($sql);
        }
        $rows = $st->fetchAll($mode);
        $st->closeCursor();
        return $rows;
    }

    private static function x(string $sql, array $bind = []): int
    {
        $pdo = self::pdo();
        if (!$bind) {
            return (int)$pdo->exec($sql);
        }
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        return $st->rowCount();
    }

    private static function dir(): string
    {
        if (self::$runtime !== null) {
            $d = self::$runtime . '/scale/';
        } elseif (defined('RUNTIME_PATH')) {
            $d = rtrim(str_replace('\\', '/', RUNTIME_PATH), '/') . '/scale/';
        } elseif (class_exists(__NAMESPACE__ . '\\ScaleTier')) {
            return ScaleTier::runtime('scale');
        } else {
            $d = rtrim(str_replace('\\', '/', dirname(__DIR__, 3)), '/') . '/runtime/scale/';
        }
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
        return $d;
    }

    // ------------------------------------------------------------------ keys

    /** Pure: the base key ('a' | 't<id>' | 'p<id>' | 'adm') of a list, or '' when it has no map. */
    public static function baseKey(int $typeEq, int $parent, bool $admin = false): string
    {
        if ($admin) {
            return $typeEq === 0 && $parent === 0 ? 'adm' : '';
        }
        if ($parent > 0) {
            return 'p' . $parent;
        }
        return $typeEq > 0 ? 't' . $typeEq : 'a';
    }

    /**
     * Pure: the key of a visitor list with these hidden categories ($children = the parent's child ids for 'p' keys):
     * only the hidden ids inside the list matter ('t' keys never get a suffix: a hidden category's own list is empty).
     * Returns [key, relevant hidden ids].
     */
    public static function keyFor(string $base, array $hidden, array $children = []): array
    {
        $hidden = array_values(array_unique(array_filter(array_map('intval', $hidden), function ($v) { return $v > 0 && $v <= 65535; })));
        if (!$hidden || $base === '' || $base === 'adm' || $base[0] === 't') {
            return [$base, []];
        }
        if ($base[0] === 'p') {
            $in = array_flip(array_merge([(int)substr($base, 1)], array_map('intval', $children)));
            $hidden = array_values(array_filter($hidden, function ($v) use ($in) { return isset($in[$v]); }));
            if (!$hidden) {
                return [$base, []];
            }
        }
        sort($hidden);
        return [$base . ':h' . sprintf('%u', crc32(implode(',', $hidden))), $hidden];
    }

    private static function validKey(string $lk): bool
    {
        return (bool)preg_match('/^(a|adm|t[1-9][0-9]{0,4}|p[1-9][0-9]{0,4})(:h[0-9]{1,10})?$/D', $lk);
    }

    /** Index names found by refresh(): type (type_id, status, recycle, time), type1 (type_id_1 ...), recycle (recycle, time). */
    private static function meta(): array
    {
        if (self::$meta === null) {
            $f = self::dir() . 'pagemap.json';
            $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
            self::$meta = is_array($d) ? $d : [];
        }
        return self::$meta;
    }

    private static function saveMeta(array $m): void
    {
        self::$meta = $m;
        $f = self::dir() . 'pagemap.json';
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($m, JSON_UNESCAPED_SLASHES)) !== false && !@rename($tmp, $f)) {
            @unlink($f);
            @rename($tmp, $f);
        }
    }

    /**
     * Query shape of a key: base condition, ranges [column, value, index kind] read in (vod_time, vod_id) order and merged,
     * hidden ids (NOT IN). Null for an unknown key.
     */
    private static function def(string $lk, array $hidden = []): ?array
    {
        if (!self::validKey($lk)) {
            return null;
        }
        $base = explode(':', $lk, 2)[0];
        if (strpos($lk, ':h') !== false && !$hidden) {
            $hidden = array_map('intval', (array)(self::meta()['keys'][$lk]['hidden'] ?? []));
            if (!$hidden) {
                return null;
            }
        }
        $d = ['where' => 'vod_status = 1 AND vod_recycle_time = 0', 'ranges' => [], 'not' => array_values(array_map('intval', $hidden))];
        if ($base === 'a') {
            // round-2 verify fix R2-4b (N13 all list): (vod_status, vod_recycle_time, vod_time) when the site has it (lane H's
            // idx_status_list, found by its columns): the seek reads the index only instead of checking vod_status in 600 rows
            $d['ranges'][] = ['', 0, isset(self::meta()['idx']['status']) ? 'status' : 'recycle'];
        } elseif ($base === 'adm') {
            $d['where'] = 'vod_recycle_time = 0';
            $d['ranges'][] = ['', 0, 'recycle'];
        } elseif ($base[0] === 't') {
            $d['ranges'][] = ['type_id', (int)substr($base, 1), 'type'];
        } else {
            $d['ranges'][] = ['type_id_1', (int)substr($base, 1), 'type1'];
            $d['ranges'][] = ['type_id', (int)substr($base, 1), 'type'];
        }
        return $d;
    }

    /** SQL of one range from the cursor ($inclusive: the cursor row itself is included), $limit rows. */
    private static function rangeSql(array $def, array $range, ?array $cursor, bool $inclusive, int $limit): string
    {
        $idx = (string)(self::meta()['idx'][$range[2]] ?? '');
        $sql = 'SELECT vod_time, vod_id FROM ' . self::t('vod') . ($idx !== '' && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $idx) ? ' FORCE INDEX (`' . $idx . '`)' : '')
            . ' WHERE ' . $def['where'];
        if ($range[0] !== '') {
            $sql .= ' AND ' . $range[0] . ' = ' . (int)$range[1];
        }
        if ($def['not']) {
            $sql .= ' AND type_id NOT IN (' . implode(',', array_map('intval', $def['not'])) . ')';
        }
        if ($cursor !== null) {
            $v = max(0, (int)$cursor[0]);
            $sql .= ' AND vod_time >= ' . $v . ' AND (vod_time > ' . $v . ' OR vod_id ' . ($inclusive ? '>=' : '>') . ' ' . max(0, (int)$cursor[1]) . ')';
        }
        return $sql . ' ORDER BY vod_time ASC, vod_id ASC LIMIT ' . max(1, $limit);
    }

    /**
     * The next $limit rows of the key in rank order from the cursor, as [[vod_time, vod_id], ...]; $more = rows may follow.
     */
    private static function nextRows(array $def, ?array $cursor, bool $inclusive, int $limit, &$more): array
    {
        $lists = [];
        $more = false;
        foreach ($def['ranges'] as $r) {
            $rows = self::q(self::rangeSql($def, $r, $cursor, $inclusive, $limit), [], \PDO::FETCH_NUM);
            if (count($rows) >= $limit) {
                $more = true;
            }
            $lists[] = $rows;
        }
        if (count($lists) === 1) {
            return array_map(function ($r) { return [(int)$r[0], (int)$r[1]]; }, $lists[0]);
        }
        // merge two ordered lists (a parent with rows of its own); a row in both ranges counts once
        $out = [];
        $seen = [];
        $i = $j = 0;
        $a = $lists[0];
        $b = $lists[1];
        while (count($out) < $limit && ($i < count($a) || $j < count($b))) {
            if ($j >= count($b) || ($i < count($a) && ((int)$a[$i][0] < (int)$b[$j][0] || ((int)$a[$i][0] === (int)$b[$j][0] && (int)$a[$i][1] <= (int)$b[$j][1])))) {
                $r = $a[$i++];
            } else {
                $r = $b[$j++];
            }
            if (!isset($seen[(int)$r[1]])) {
                $seen[(int)$r[1]] = true;
                $out[] = [(int)$r[0], (int)$r[1]];
            }
        }
        if ($i < count($a) || $j < count($b)) {
            $more = true;
        }
        return $out;
    }

    // ------------------------------------------------------------------ visitor side

    /** State row of a key (memoized per request); null when missing. */
    private static function stateRow(string $lk): ?array
    {
        if (!array_key_exists($lk, self::$states)) {
            $r = self::q('SELECT * FROM ' . self::t(self::T_STATE) . ' WHERE lk = :k', [':k' => $lk]);
            self::$states[$lk] = $r ? array_map('intval', array_diff_key($r[0], ['lk' => 1])) : null;
        }
        return self::$states[$lk];
    }

    /** Rows of a built key (the admin list's exact total: hidden videos included), or null when not built / unknown. */
    public static function total(string $lk): ?int
    {
        try {
            $st = self::stateRow($lk);
            return $st !== null && (int)$st['built_at'] > 0 ? (int)$st['total'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Pure: bottom ranks [lo, hi] of a page (newest first: page 1 = the top rows; $asc: page 1 = the oldest rows); null when
     * the page lies past the list.
     */
    public static function pageRanks(int $page, int $per, int $total, bool $asc = false): ?array
    {
        $page = max(1, $page);
        $per = max(1, $per);
        if ($total <= 0) {
            return null;
        }
        if ($asc) {
            $lo = ($page - 1) * $per;
            return $lo >= $total ? null : [$lo, min($total - 1, $lo + $per - 1)];
        }
        $hi = $total - ($page - 1) * $per - 1;
        return $hi < 0 ? null : [max(0, $total - $page * $per), $hi];
    }

    /**
     * Ids of a page through the map: the entry at or below the page's lowest rank, then one ordered read of the list index
     * from that entry (<= B + per rows); newest first unless $asc. $total = the list total (ScaleCounts, <= 5 min old; 0 =
     * the map's own total). [] = past the last page; null = no usable map (missing key, map far behind, error).
     */
    public static function seek(string $lk, int $page, int $per, int $total, bool $asc = false, array $hidden = []): ?array
    {
        try {
            $st = self::stateRow($lk);
            if ($st === null || ((int)$st['built_at'] === 0 && (int)$st['total'] === 0)) {
                if ($st === null && strpos($lk, ':h') !== false && $hidden) {
                    self::want($lk, $hidden);
                }
                return null;
            }
            $def = self::def($lk, $hidden);
            if ($def === null) {
                return null;
            }
            $T = $total > 0 ? $total : (int)$st['total'];
            $ranks = self::pageRanks($page, $per, $T, $asc);
            if ($ranks === null) {
                return [];
            }
            list($lo, $hi) = $ranks;
            $e = self::q('SELECT seq, sort_v, vod_id FROM ' . self::t(self::T_MAP) . ' WHERE lk = :k AND seq <= :s ORDER BY seq DESC LIMIT 1',
                [':k' => $lk, ':s' => intdiv($lo, self::B)]);
            if (!$e) {
                return null;
            }
            $skip = $lo - (int)$e[0]['seq'] * self::B;
            if ($skip > self::SEEK_MAX_SKIP) {
                return null;
            }
            $n = $hi - $lo + 1;
            $rows = self::nextRows($def, [(int)$e[0]['sort_v'], (int)$e[0]['vod_id']], true, $skip + $n, $more);
            $ids = array_map(function ($r) { return $r[1]; }, array_slice($rows, $skip, $n));
            if (count($ids) < $n) {
                // round-4 R4-4: the page lies inside the list ($T = the live total) but the index holds fewer rows above the
                // entry than the map promised (rows left the list, or the map lags its list): never a short or empty page
                // below the live last page; the caller's own query (deferred join / OFFSET) answers it
                return null;
            }
            return $asc ? $ids : array_reverse($ids);
        } catch (\Throwable $e) {
            if (class_exists(__NAMESPACE__ . '\\DbTimeout') && DbTimeout::is($e)) {
                throw $e; // the caller's visitor error path (stale copy / busy page), never a second query
            }
            error_log('[ListPageMap] seek: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 160));
            return null;
        }
    }

    /** A visitor asked for a hidden-category key that is not built: one line in runtime/scale/pagemap.want. */
    private static function want(string $lk, array $hidden): void
    {
        if (isset(self::$wanted[$lk])) {
            return;
        }
        self::$wanted[$lk] = true;
        $f = self::dir() . 'pagemap.want';
        if (is_file($f) && @filesize($f) > 65536) {
            return;
        }
        @file_put_contents($f, json_encode(['k' => $lk, 'h' => array_values(array_map('intval', $hidden))]) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Dirty mark (C14; Vod::searchTouch, the importer's batch end, VodDeletion, VodCleanup). $minSortV = the lowest vod_time
     * that changed (0 = everything), $typeIds = the categories of the changed rows ([] = all lists), $removed > 0 = that many
     * rows left the lists (only counted, pages shift until the next full rewalk).
     * Round-4 R4-4: written where the map lives, one UPDATE of the state rows of the keys the mark touches (markRows): a
     * mark kept only in runtime/scale/pagemap.dirty was lost with the runtime folder (a database restored without it, a new
     * server or box on the same database, a runtime wipe), and the maps then stayed behind their lists (deep pages shifted,
     * pages before the last empty). The runtime line is the fallback when that write fails (no connection, no state table
     * yet, the server refused it); refresh() folds such lines as before. Keys without a state row are not built yet: their
     * first walk reads every row anyway.
     */
    public static function dirty(int $minSortV, array $typeIds, int $removed = 0): void
    {
        $t = array_values(array_unique(array_filter(array_map('intval', $typeIds), function ($v) { return $v > 0 && $v <= 65535; })));
        // more than 200 categories in one mark: every key (the runtime line used to keep only the first 200 of them)
        $mark = ['v' => max(0, $minSortV), 't' => count($t) > 200 ? [] : $t, 'r' => max(0, $removed)];
        try {
            if (self::markRows(self::keysOf($mark['t'], $mark['t'] ? self::typesForMarks() : []), $mark['r'] > 0 ? 0 : max(1, $mark['v']), $mark['r'])) {
                return;
            }
        } catch (\Throwable $e) {
            error_log('[ListPageMap] dirty mark in the database: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 120) . ' (runtime line instead)');
        }
        try {
            @file_put_contents(self::dir() . 'pagemap.dirty', json_encode($mark) . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            error_log('[ListPageMap] dirty: ' . get_class($e));
        }
    }

    /**
     * Round-4 R4-4: one mark on the state rows of these base keys (null = every key; hidden-category keys ':h...' follow
     * their base): $fromV > 0 -> dirty_from = LEAST(dirty_from, $fromV) (0 = clean becomes $fromV); $removed > 0 ->
     * stale_ops + $removed. Atomic statements only, so a walk running at the same time never loses the mark (saveState()
     * does not write these two columns; takeMark() clears dirty_from by compare-and-set). True when the statement ran.
     */
    private static function markRows(?array $keys, int $fromV, int $removed): bool
    {
        if ($fromV <= 0 && $removed <= 0) {
            return true;
        }
        $where = '';
        $bind = [];
        if ($keys !== null) {
            $keys = array_values(array_filter($keys, function ($k) { return is_string($k) && self::validKey($k); }));
            if (!$keys) {
                return true;
            }
            $in = [];
            foreach ($keys as $i => $k) {
                $in[] = ':k' . $i;
                $bind[':k' . $i] = $k;
            }
            $where = " WHERE SUBSTRING_INDEX(lk, ':', 1) IN (" . implode(',', $in) . ')';
        }
        $set = [];
        if ($fromV > 0) {
            $set[] = 'dirty_from = IF(dirty_from = 0, :v1, LEAST(dirty_from, :v2))';
            $bind[':v1'] = $fromV;
            $bind[':v2'] = $fromV;
        }
        if ($removed > 0) {
            $set[] = 'stale_ops = LEAST(4294967295, stale_ops + :r)';
            $bind[':r'] = $removed;
        }
        self::x('UPDATE ' . self::t(self::T_STATE) . ' SET ' . implode(', ', $set) . $where, $bind);
        foreach (array_keys(self::$states) as $lk) {
            if ($keys === null || in_array(explode(':', $lk, 2)[0], $keys, true)) {
                unset(self::$states[$lk]); // the request's memo of this row is stale now
            }
        }
        return true;
    }

    /** Category tree for a mark ([type_id => parent id], memo 60 s per process: an import marks every batch). */
    private static function typesForMarks(): array
    {
        static $memo = null;
        static $at = 0.0;
        if ($memo === null || microtime(true) - $at > 60.0) {
            $memo = self::types();
            $at = microtime(true);
        }
        return $memo;
    }

    /**
     * Round-4 R4-4: a walk takes the mark of key $lk: dirty_from = 0 by compare-and-set on the value it read ($seen). A
     * mark that lowered it in between makes the set fail: the lower value is read and taken instead (TAKE_TRIES times;
     * then the walk starts from the newest value read and the mark stays for the next run: safe, at worst one more
     * rewalk). A mark that did not lower it (a change above $seen) is covered by the rewalk from $seen, which starts after
     * this statement and reads every row above it. Returns the value the walk starts from.
     */
    private static function takeMark(string $lk, int $seen): int
    {
        for ($i = 0; $i < self::TAKE_TRIES; $i++) {
            if (self::x('UPDATE ' . self::t(self::T_STATE) . ' SET dirty_from = 0 WHERE lk = :k AND dirty_from = :d', [':k' => $lk, ':d' => $seen]) > 0) {
                return $seen;
            }
            $r = self::q('SELECT dirty_from FROM ' . self::t(self::T_STATE) . ' WHERE lk = :k', [':k' => $lk]);
            $now = $r ? (int)$r[0]['dirty_from'] : 0;
            if ($now === 0 || $now === $seen) {
                return $seen; // nothing to take (the row is clean, or the value did not change: no affected row reported)
            }
            $seen = min($seen, $now);
        }
        return $seen;
    }

    // ------------------------------------------------------------------ maintenance

    /** Lock name of the single-flight refresh. */
    private static function lockName(): string
    {
        $db = (string)self::q('SELECT DATABASE() d')[0]['d'];
        $n = $db . '_' . self::$prefix . 'pagemap';
        return strlen($n) > 60 ? 'pagemap_' . md5($n) : $n;
    }

    /** Category tree of the video module: [type_id => parent id]. */
    private static function types(): array
    {
        $out = [];
        try {
            foreach (self::q('SELECT type_id, type_pid FROM ' . self::t('type') . ' WHERE type_mid = 1') as $r) {
                $out[(int)$r['type_id']] = (int)$r['type_pid'];
            }
        } catch (\Throwable $e) {
            foreach (self::q('SELECT DISTINCT type_id, type_id_1 FROM ' . self::t('vod') . ' WHERE vod_recycle_time = 0 LIMIT 5000') as $r) {
                $out[(int)$r['type_id']] = (int)$r['type_id_1'];
            }
        }
        return $out;
    }

    /** The D-L1 list indexes of this vod table (information_schema; names validated). */
    private static function findIndexes(): array
    {
        $want = ['type' => 'type_id,vod_status,vod_recycle_time,vod_time', 'type1' => 'type_id_1,vod_status,vod_recycle_time,vod_time', 'recycle' => 'vod_recycle_time,vod_time',
            'status' => 'vod_status,vod_recycle_time,vod_time'];
        $cols = [];
        foreach (self::q('SELECT INDEX_NAME n, COLUMN_NAME c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [':t' => self::$prefix . 'vod']) as $r) {
            $cols[(string)$r['n']][] = (string)$r['c'];
        }
        $out = [];
        foreach ($want as $kind => $prefixCols) {
            foreach ($cols as $name => $c) {
                if ($name !== 'PRIMARY' && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) && strpos(implode(',', $c) . ',', $prefixCols . ',') === 0) {
                    $out[$kind] = $name;
                    break;
                }
            }
        }
        return $out;
    }

    /** Pure: keys a dirty mark touches (type ids -> 't', parent 'p', own 'p'; always 'a' and 'adm'); null = every key. */
    public static function keysOf(array $typeIds, array $types): ?array
    {
        if (!$typeIds) {
            return null;
        }
        $k = ['a' => true, 'adm' => true];
        foreach ($typeIds as $t) {
            $t = (int)$t;
            $k['t' . $t] = true;
            if (!empty($types[$t])) {
                $k['p' . (int)$types[$t]] = true;
            }
            $k['p' . $t] = true;
        }
        return array_keys($k);
    }

    /** Fold runtime/scale/pagemap.dirty and pagemap.want into the state rows and the meta file. */
    private static function fold(array $types, array &$meta, array &$states): array
    {
        $out = ['marks' => 0, 'wanted' => 0];
        $dir = self::dir();
        foreach (['dirty', 'want'] as $kind) {
            $f = $dir . 'pagemap.' . $kind;
            if (!is_file($f)) {
                continue;
            }
            $tmp = $f . '.' . getmypid() . '.fold';
            if (!@rename($f, $tmp)) {
                continue;
            }
            $lines = @file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            @unlink($tmp);
            $lines = is_array($lines) ? $lines : [];
            if ($kind === 'want') {
                foreach ($lines as $l) {
                    $w = json_decode($l, true);
                    if (!is_array($w) || !isset($w['k'], $w['h']) || !is_string($w['k']) || !self::validKey($w['k']) || strpos($w['k'], ':h') === false) {
                        continue;
                    }
                    $h = array_values(array_filter(array_map('intval', (array)$w['h'])));
                    sort($h);
                    $base = explode(':', $w['k'], 2)[0];
                    if (!$h || $base . ':h' . sprintf('%u', crc32(implode(',', $h))) !== $w['k']) {
                        continue;
                    }
                    if (!isset($meta['keys'][$w['k']]) && count((array)($meta['keys'] ?? [])) < self::WANT_MAX) {
                        $meta['keys'][$w['k']] = ['hidden' => $h, 'at' => time()];
                        $out['wanted']++;
                    }
                }
                continue;
            }
            $all = count($lines) > 10000;
            foreach ($all ? [] : $lines as $l) {
                $m = json_decode($l, true);
                if (!is_array($m)) {
                    continue;
                }
                $out['marks']++;
                $keys = self::keysOf((array)($m['t'] ?? []), $types);
                foreach ($states as $lk => &$st) {
                    if ($keys !== null && !in_array(explode(':', $lk, 2)[0], $keys, true)) {
                        continue;
                    }
                    if ((int)($m['r'] ?? 0) > 0) {
                        $st['stale_ops'] += (int)$m['r'];
                        $st['_mark_r'] = (int)($st['_mark_r'] ?? 0) + (int)$m['r'];
                    } else {
                        $v = max(1, (int)($m['v'] ?? 0));
                        $st['dirty_from'] = $st['dirty_from'] > 0 ? min($st['dirty_from'], $v) : $v;
                        $st['_mark_d'] = isset($st['_mark_d']) ? min((int)$st['_mark_d'], $v) : $v;
                    }
                    $st['_changed'] = true;
                    $meta['done'][$lk] = time();
                }
                unset($st);
            }
            if ($all) {
                foreach ($states as &$st) {
                    $st['dirty_from'] = 1;
                    $st['_mark_d'] = 1;
                    $st['_changed'] = true;
                }
                unset($st);
                $out['marks'] = count($lines);
            }
        }
        return $out;
    }

    /**
     * The walk's own columns of one key. Round-4 R4-4: dirty_from and stale_ops are never written here (a mark may arrive
     * while the walk runs: dirty(), the importer's batch end, VodDeletion, VodCleanup, an admin write); markKey() /
     * markRows() add marks, takeMark() and rebaseStale() take them.
     */
    private static function saveState(string $lk, array $st): void
    {
        self::x('UPDATE ' . self::t(self::T_STATE) . ' SET total = :t, top_v = :tv, top_id = :ti, built_at = :b, walk_seq = :ws, walk_v = :wv, walk_id = :wi WHERE lk = :k',
            [':t' => (int)$st['total'], ':tv' => (int)$st['top_v'], ':ti' => (int)$st['top_id'], ':b' => (int)$st['built_at'],
             ':ws' => (int)$st['walk_seq'], ':wv' => (int)$st['walk_v'], ':wi' => (int)$st['walk_id'], ':k' => $lk]);
        unset(self::$states[$lk]);
    }

    /**
     * Round-4 R4-4: the marks refresh() found itself for one key (runtime lines folded, a full rewalk due): dirty_from =
     * LEAST(dirty_from, $fromV) when $fromV > 0, stale_ops + $removed; atomic like markRows().
     */
    private static function markKey(string $lk, int $fromV, int $removed): void
    {
        if ($fromV <= 0 && $removed <= 0) {
            return;
        }
        self::x('UPDATE ' . self::t(self::T_STATE) . ' SET dirty_from = IF(:d0 = 0, dirty_from, IF(dirty_from = 0, :d1, LEAST(dirty_from, :d2))),'
            . ' stale_ops = LEAST(4294967295, stale_ops + :r) WHERE lk = :k', [':d0' => max(0, $fromV), ':d1' => max(0, $fromV), ':d2' => max(0, $fromV),
            ':r' => max(0, $removed), ':k' => $lk]);
        unset(self::$states[$lk]);
    }

    /**
     * Round-4 R4-4: a full rewalk starts: the removals it accounts for ($seen = stale_ops as this run knows them) leave the
     * counter; removals counted after this point stay (stale_ops - $seen, never below 0).
     */
    private static function rebaseStale(string $lk, int $seen): void
    {
        if ($seen > 0) {
            self::x('UPDATE ' . self::t(self::T_STATE) . ' SET stale_ops = GREATEST(0, CAST(stale_ops AS SIGNED) - :s) WHERE lk = :k', [':s' => $seen, ':k' => $lk]);
            unset(self::$states[$lk]);
        }
    }

    /** Published counts per key from scale_count (lane H; fresh after a recount): 'a' => vod_all, 't<id>' / 'p<id>' => type_all_<id>. */
    private static function counts(): array
    {
        try {
            $out = [];
            $own = [];
            foreach (self::q("SELECT k, v FROM " . self::t('scale_count') . " WHERE k IN ('vod_all', 'counted_at') OR k LIKE 'type\\_all\\_%' OR k LIKE 'type\\_own\\_%'") as $r) {
                $k = (string)$r['k'];
                if ($k === 'vod_all') {
                    $out['a'] = (int)$r['v'];
                } elseif ($k === 'counted_at') {
                    $out['_at'] = (int)$r['v'];
                } elseif (preg_match('/^type_all_([0-9]+)$/D', $k, $m)) {
                    $out['p' . $m[1]] = (int)$r['v'];
                    $out['t' . $m[1]] = $out['t' . $m[1]] ?? (int)$r['v'];
                } elseif (preg_match('/^type_own_([0-9]+)$/D', $k, $m)) {
                    $own['t' . $m[1]] = (int)$r['v'];
                }
            }
            return $own + $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Maintenance (C14): fold dirty marks and wished hidden-category keys, then per key (rewalks first) a resumable walk:
     * a rewalk from the entry below the lowest change, a full rewalk when due, else the new rows above the top. Single
     * flight (GET_LOCK). Returns state 'done' | 'more' (budget spent, work left) | 'busy' | 'error', rows walked, entries
     * written (added), keys, pct.
     */
    public static function refresh(float $budgetSec = 10.0): array
    {
        $t0 = microtime(true);
        $deadline = $t0 + max(0.2, $budgetSec);
        $out = ['state' => 'done', 'rows' => 0, 'added' => 0, 'keys' => 0, 'walked' => 0, 'more' => false];
        try {
            $lock = self::lockName();
            if ((int)self::q('SELECT GET_LOCK(:n, 0) l', [':n' => $lock])[0]['l'] !== 1) {
                return ['state' => 'busy'] + $out;
            }
        } catch (\Throwable $e) {
            return ['state' => 'error', 'error' => get_class($e) . ' ' . substr($e->getMessage(), 0, 120)] + $out;
        }
        try {
            $meta = self::meta();
            $meta['idx'] = self::findIndexes();
            $types = self::types();
            // keys: all, admin, every category, every parent, wished hidden-category keys
            $keys = ['a' => true, 'adm' => true];
            $parents = [];
            foreach ($types as $id => $pid) {
                $keys['t' . $id] = true;
                if ($pid > 0) {
                    $parents[$pid] = true;
                }
            }
            foreach (array_keys($parents) as $p) {
                if (isset($types[$p])) {
                    $keys['p' . $p] = true;
                }
            }
            $states = [];
            foreach (self::q('SELECT * FROM ' . self::t(self::T_STATE)) as $r) {
                $states[(string)$r['lk']] = array_map('intval', array_diff_key($r, ['lk' => 1]));
            }
            $f = self::fold($types, $meta, $states);
            $out += $f;
            foreach (array_keys((array)($meta['keys'] ?? [])) as $hk) {
                if (isset($keys[explode(':', $hk, 2)[0]])) {
                    $keys[$hk] = true;
                } else {
                    unset($meta['keys'][$hk]);
                }
            }
            // categories gone: their keys leave the map
            foreach (array_keys($states) as $lk) {
                if (!isset($keys[$lk])) {
                    self::x('DELETE FROM ' . self::t(self::T_MAP) . ' WHERE lk = :k', [':k' => $lk]);
                    self::x('DELETE FROM ' . self::t(self::T_STATE) . ' WHERE lk = :k', [':k' => $lk]);
                    unset($states[$lk]);
                }
            }
            $now = time();
            foreach (array_keys($keys) as $lk) {
                if (!isset($states[$lk])) {
                    self::x('INSERT IGNORE INTO ' . self::t(self::T_STATE) . ' (lk, dirty_from) VALUES (:k, 1)', [':k' => $lk]);
                    $states[$lk] = ['total' => 0, 'top_v' => 0, 'top_id' => 0, 'dirty_from' => 1, 'built_at' => 0, 'stale_ops' => 0, 'walk_seq' => 0, 'walk_v' => 0, 'walk_id' => 0];
                }
            }
            // full rewalk triggers: stale share, weekly, total drift against the precomputed counts
            $counts = self::counts();
            foreach ($states as $lk => &$st) {
                if ($st['walk_seq'] > 0 || $st['dirty_from'] === 1) {
                    continue;
                }
                $drift = false;
                if (isset($counts[$lk], $counts['_at']) && $st['dirty_from'] === 0 && $st['built_at'] > 0) {
                    $diff = abs($counts[$lk] - $st['total']);
                    $base = max($counts[$lk], $st['total']);
                    // the count must be newer than the key's last change (a walk that added rows, a folded mark): an import
                    // between the walk and the recount would otherwise look like drift
                    $fresh = $counts['_at'] > (int)($meta['done'][$lk] ?? $now) + 5;
                    $drift = $fresh && $diff > max(self::DRIFT_MIN, self::DRIFT_SHARE * $base);
                    // round-4 R4-4: a big difference starts the rewalk whatever the count's age (rows placed below the top
                    // whose marks were lost: tools/upgrade.php recounted before its refresh stamped the keys, and the maps
                    // stayed 203,135 rows behind on engines-r4's e80), once per count snapshot
                    if (!$drift && $diff > max(self::DRIFT_MIN, self::DRIFT_BIG_SHARE * $base) && (int)($meta['drift_at'][$lk] ?? 0) !== (int)$counts['_at']) {
                        $drift = true;
                        $meta['drift_at'][$lk] = (int)$counts['_at'];
                        $out['drift'] = ($out['drift'] ?? 0) + 1;
                    }
                }
                if (($st['total'] > 0 && $st['stale_ops'] > self::STALE_SHARE * $st['total']) || $st['built_at'] < $now - self::FULL_EVERY || $drift) {
                    $st['dirty_from'] = 1;
                    $st['_mark_d'] = 1;
                    $st['_changed'] = true;
                }
            }
            unset($st);
            foreach ($states as $lk => $st) {
                if (!empty($st['_changed'])) {
                    // round-4 R4-4: what this run found (folded runtime lines, a full rewalk due) goes to the state row as a
                    // mark, never as a plain overwrite of dirty_from / stale_ops
                    self::markKey($lk, (int)($st['_mark_d'] ?? 0), (int)($st['_mark_r'] ?? 0));
                }
            }
            if (isset($meta['drift_at']) && is_array($meta['drift_at'])) {
                $meta['drift_at'] = array_intersect_key($meta['drift_at'], $states);
            }
            $out['keys'] = count($states);
            // order: running walks, then dirty keys (smaller first: they finish), then appends
            uksort($states, function ($a, $b) use ($states) {
                $ra = $states[$a]['walk_seq'] > 0 ? 0 : ($states[$a]['dirty_from'] > 0 ? 1 : 2);
                $rb = $states[$b]['walk_seq'] > 0 ? 0 : ($states[$b]['dirty_from'] > 0 ? 1 : 2);
                return $ra !== $rb ? $ra <=> $rb : ($states[$a]['total'] <=> $states[$b]['total'] ?: strcmp($a, $b));
            });
            foreach ($states as $lk => $st) {
                if (microtime(true) >= $deadline) {
                    $out['more'] = true;
                    break;
                }
                $def = self::def($lk, (array)($meta['keys'][$lk]['hidden'] ?? []));
                if ($def === null) {
                    continue;
                }
                if ($st['walk_seq'] === 0) {
                    $full = false;
                    if ($st['dirty_from'] > 0) {
                        // round-4 R4-4: the walk takes the key's mark (compare-and-set; a lower concurrent mark is taken
                        // instead); a mark above the newest mapped row is covered by the append below
                        $st['dirty_from'] = self::takeMark($lk, $st['dirty_from']);
                        $out['taken'] = ($out['taken'] ?? 0) + 1;
                        if (self::$testHook !== null) {
                            call_user_func(self::$testHook, 'taken', $lk);
                        }
                    }
                    if ($st['dirty_from'] > 0 && ($st['dirty_from'] <= $st['top_v'] || $st['total'] === 0 || $st['dirty_from'] === 1)) {
                        $e = $st['dirty_from'] === 1 ? [] : self::q('SELECT seq, sort_v, vod_id FROM ' . self::t(self::T_MAP) . ' WHERE lk = :k AND sort_v < :v ORDER BY seq DESC LIMIT 1',
                            [':k' => $lk, ':v' => $st['dirty_from']]);
                        if ($e && (int)$e[0]['seq'] > 0) {
                            $st['walk_seq'] = (int)$e[0]['seq'] * self::B + 1;
                            $st['walk_v'] = (int)$e[0]['sort_v'];
                            $st['walk_id'] = max(0, (int)$e[0]['vod_id'] - 1); // (v, id - 1) exclusive = the entry row inclusive
                        } else {
                            $full = true;
                            $st['walk_seq'] = 1;
                            $st['walk_v'] = 0;
                            $st['walk_id'] = 0;
                            // round-4 R4-4: the removals counted so far are what this full walk accounts for
                            self::rebaseStale($lk, (int)$st['stale_ops']);
                            $st['stale_ops'] = 0;
                        }
                    } else {
                        // append: the rows above the newest mapped row
                        $st['walk_seq'] = $st['total'] + 1;
                        $st['walk_v'] = $st['top_v'];
                        $st['walk_id'] = $st['top_id'];
                    }
                    $st['dirty_from'] = 0;
                    $meta['walk'][$lk] = ['full' => $full, 'at' => $now];
                }
                $before = $st['total'];
                $rows0 = $out['rows'];
                $done = self::walk($lk, $def, $st, $deadline, $out, !empty($meta['walk'][$lk]['full']));
                if ($out['rows'] > $rows0 || $st['total'] !== $before) {
                    $meta['done'][$lk] = time();
                }
                if ($done) {
                    unset($meta['walk'][$lk]);
                } else {
                    $out['more'] = true;
                }
            }
            self::saveMeta($meta);
            if ($out['more']) {
                $out['state'] = 'more';
            }
        } catch (\Throwable $e) {
            $out['state'] = 'error';
            $out['error'] = get_class($e) . ' ' . substr($e->getMessage(), 0, 160);
        } finally {
            try {
                self::q('SELECT RELEASE_LOCK(:n) r', [':n' => $lock]);
            } catch (\Throwable $e) {
            }
            self::$states = [];
        }
        $out['seconds'] = round(microtime(true) - $t0, 3);
        $s = self::state();
        $out['pct'] = $s['pct'] ?? null;
        return $out;
    }

    /**
     * One key's walk from (walk_v, walk_id) exclusive with the next rank walk_seq - 1, CHUNK rows per query, entries every
     * B ranks (REPLACE), saved after each chunk. True when the walk reached the newest row (total / top set, entries past
     * the total removed; a full walk also sets built_at and clears stale_ops).
     */
    private static function walk(string $lk, array $def, array &$st, float $deadline, array &$out, bool $full): bool
    {
        while (true) {
            $rank = $st['walk_seq'] - 1;
            $rows = self::nextRows($def, [$st['walk_v'], $st['walk_id']], false, self::CHUNK, $more);
            $entries = [];
            foreach ($rows as $r) {
                if ($rank % self::B === 0) {
                    $entries[] = '(' . self::pdo()->quote($lk) . ',' . intdiv($rank, self::B) . ',' . (int)$r[0] . ',' . (int)$r[1] . ')';
                }
                $rank++;
            }
            foreach (array_chunk($entries, 500) as $chunk) {
                self::x('REPLACE INTO ' . self::t(self::T_MAP) . ' (lk, seq, sort_v, vod_id) VALUES ' . implode(',', $chunk));
            }
            if ($rows) {
                $last = $rows[count($rows) - 1];
                $st['walk_v'] = $last[0];
                $st['walk_id'] = $last[1];
            }
            $st['walk_seq'] = $rank + 1;
            $out['rows'] += count($rows);
            $out['added'] += count($entries);
            if (!$more) {
                $st['total'] = $rank;
                $st['top_v'] = $rank > 0 ? $st['walk_v'] : 0;
                $st['top_id'] = $rank > 0 ? $st['walk_id'] : 0;
                $st['walk_seq'] = $st['walk_v'] = $st['walk_id'] = 0;
                if ($full) {
                    $st['built_at'] = time();
                    $st['stale_ops'] = 0; // this run's view only: the column was rebased when the full walk started
                } elseif ($st['built_at'] === 0) {
                    $st['built_at'] = time();
                }
                $maxSeq = $rank > 0 ? intdiv($rank - 1, self::B) : -1;
                self::x('DELETE FROM ' . self::t(self::T_MAP) . ' WHERE lk = :k AND seq > :s', [':k' => $lk, ':s' => $maxSeq]);
                self::saveState($lk, $st);
                $out['walked']++;
                return true;
            }
            self::saveState($lk, $st);
            if (self::$testHook !== null) {
                call_user_func(self::$testHook, 'chunk', $lk);
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
        }
    }

    /**
     * C14 state for the Speed page and tools/upgrade.php: ready (every key built and no full walk running), pct (keys
     * built / keys), built_at (oldest full build), keys, rows (entries), walking (keys with a walk running), mb.
     */
    public static function state(): array
    {
        try {
            $r = self::q('SELECT COUNT(*) n, COALESCE(SUM(built_at > 0), 0) b, COALESCE(SUM(walk_seq > 0 OR dirty_from > 0), 0) w, COALESCE(MIN(NULLIF(built_at, 0)), 0) m, COALESCE(SUM(total), 0) t FROM '
                . self::t(self::T_STATE));
            $n = (int)$r[0]['n'];
            $b = (int)$r[0]['b'];
            $rows = (int)self::q('SELECT COUNT(*) c FROM ' . self::t(self::T_MAP))[0]['c'];
            return ['ready' => $n > 0 && $b === $n, 'pct' => $n > 0 ? round(100 * $b / $n, 1) : 0.0, 'built_at' => (int)$r[0]['m'], 'keys' => $n,
                'rows' => $rows, 'walking' => (int)$r[0]['w'], 'mapped' => (int)$r[0]['t'], 'mb' => round($rows * 40 / 1048576, 2),
                'state' => $n === 0 ? 'missing' : ($b === $n ? 'ready' : 'building')];
        } catch (\Throwable $e) {
            return ['ready' => false, 'pct' => 0.0, 'state' => 'missing', 'error' => get_class($e)];
        }
    }
}
