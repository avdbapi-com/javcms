<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\common\model;
use think\Db;
use think\Cache;
use app\common\util\Pinyin;
use app\common\validate\Vod as VodValidate;

class Vod extends Base {
    use VodRecycleTrait {
        recycleData as private recycleDataUntouched;
        restoreData as private restoreDataUntouched;
    }

    /** Ids whose detail cache was invalidated during a recycle / restore run (they go to the search queue afterwards). */
    private $ftCollect = null;

    /** 1.2.0 (D-S6): recycle = the videos leave the search table (touch); hidden at the origin at once (purge, C5). */
    public function recycleData($where)
    {
        return $this->withFtCollect(function () use ($where) { return $this->recycleDataUntouched($where); }, true);
    }

    /** 1.2.0 (D-S6): restore = the videos come back into the search table (touch). */
    public function restoreData($where)
    {
        return $this->withFtCollect(function () use ($where) { return $this->restoreDataUntouched($where); }, false);
    }

    private function withFtCollect(callable $run, bool $purge)
    {
        $this->ftCollect = [];
        try {
            $res = $run();
        } finally {
            $ids = array_keys($this->ftCollect);
            $this->ftCollect = null;
        }
        if ($ids) {
            self::searchTouch($ids, $purge);
        }
        return $res;
    }

    /** Fields that go into a search document or decide whether a video has one (D-S6). */
    const FT_FIELDS = ['vod_name', 'vod_en', 'vod_sub', 'vod_remarks', 'vod_director', 'vod_tag', 'vod_class', 'vod_actor',
        'vod_status', 'vod_recycle_time', 'type_id', 'type_id_1', 'vod_time'];

    /**
     * fp-port-c (FINISH-PORT-REVIEW-b P2-1): runs the row write $write (returns the affected rows or false) and, when it
     * changed a row, queues $ids for the search table in the SAME transaction (FilterVocab::searchQueue). A write that shows
     * or hides videos, or changes what their search documents hold, then never leaves a visible row without a document or a
     * queue entry when the request is cut before its later search step (browser closed, php-cgi or proxy timeout, restart):
     * the item path of the CJK filters reads every queued id. Without ids (or without FilterVocab) it is the plain write.
     * Errors roll the write back and are rethrown (the caller reports a failed save, as for any database error).
     */
    public static function queuedWrite(callable $write, array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) { return $i > 0; })));
        if (!$ids || !class_exists('\app\common\util\FilterVocab') || !method_exists('\app\common\util\FilterVocab', 'searchQueue')) {
            return $write();
        }
        Db::startTrans();
        try {
            $res = $write();
            if ($res !== false && (int)$res > 0) {
                \app\common\util\FilterVocab::searchQueue($ids);
            }
            Db::commit();
            return $res;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * D-S6 write path: queue these ids for the search table (<= 20 ids: drained inline, so an admin edit is searchable at
     * once); mark the precomputed counts dirty (C4); $purge = the videos left the site (hide / recycle / delete): the edge
     * cache never serves them again (C5). Never turns a successful write into an error.
     */
    public static function searchTouch(array $ids, bool $purge = false): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return;
        }
        try {
            if (class_exists('\app\common\util\FtIndex')) {
                if (count($ids) <= 20) {
                    \app\common\util\FtIndex::touchNow($ids);
                } else {
                    \app\common\util\FtIndex::touch($ids);
                }
            }
            if (class_exists('\app\common\util\ScaleCounts')) {
                \app\common\util\ScaleCounts::markDirty('admin');
            }
            if (class_exists('\app\common\util\ListPageMap')) {
                self::pageMapTouch($ids);
            }
            if ($purge && class_exists('\app\common\util\EdgeCache')) {
                \app\common\util\EdgeCache::purge($ids);
            }
        } catch (\Throwable $e) {
            error_log('[Vod] search touch failed: ' . get_class($e));
        }
    }
    /**
     * XVID-NOLIMIT-BRIEF B1 (C14): an admin write moved these rows in or out of the lists (hide, recycle, restore, delete,
     * an edit of vod_time / category). Each one shifts the ranks above it by one row, so it is counted (stale_ops of the
     * keys of their categories; a full rewalk at 0.5 %) instead of rewalking from its old position; a row that moved to
     * the top is picked up by the next append. One primary-key read of at most 2,000 ids (categories); more ids, or ids
     * that are gone, count on every key.
     */
    private static function pageMapTouch(array $ids): void
    {
        $types = [];
        if (count($ids) <= 2000) {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (Db::name('Vod')->where('vod_id', 'in', $chunk)->field('type_id,type_id_1')->select() as $r) {
                    $types[(int)$r['type_id']] = true;
                    if ((int)$r['type_id_1'] > 0) {
                        $types[(int)$r['type_id_1']] = true;
                    }
                }
            }
        }
        \app\common\util\ListPageMap::dirty(0, array_keys($types), count($ids));
    }

    // Setup data table (without prefixes)
    protected $name = 'vod';

    // Define timestamp field names
    protected $createTime = '';
    protected $updateTime = '';

    // Auto complete
    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];

    public function countData($where)
    {
        $where = self::ftFilterWhere($this->mergeRecycleWhere(is_array($where) ? $where : []));
        $where2='';
        if(!empty($where['_string'])){
            $where2 = $where['_string'];
            unset($where['_string']);
        }
        try {
            $total = $this->where($where)->where($where2)->count();
        } catch (\Throwable $error) {
            if (!self::ftRefused($error, $where2)) {
                throw $error;
            }
            $total = Db::name('Vod')->where($where)->where(\app\common\util\FilterMatch::strip($where2))->count();
        }
        return $total;
    }

    // ------------------------------------------------------------------ S-O1 FULLTEXT pre-filters (owner 2026-10-17)

    /** Columns whose LIKE filters get the FilterMatch pre-filter (one-column FULLTEXT indexes ft_vod_*). */
    const FT_FILTER_COLS = ['vod_name', 'vod_tag', 'vod_class', 'vod_actor', 'vod_director'];

    /**
     * Condition to AND to `$col LIKE` over mac_like_arr($rawVal); '' = none. Kept for the call sites of the 1.1 tool that
     * added it on the live site (2026-10-17); the rules are FilterMatch's (reports/FT-FILTERS-BUILD.md).
     */
    public static function ftBooleanPrefilter($col, $rawVal)
    {
        return class_exists('\app\common\util\FilterMatch') ? \app\common\util\FilterMatch::prefilter((string)$col, $rawVal) : '';
    }

    /**
     * S-O1: $where with a FilterMatch pre-filter for every LIKE filter on FT_FILTER_COLS (col => ['like', mac_like_arr(v),
     * 'OR'] or ['like', '%v%']), ANDed to '_string'. The LIKE stays and decides; the MATCH only narrows the rows and never
     * drops one the LIKE finds (single words, short words, stopwords, wildcards, non-ASCII values: no MATCH, see
     * FilterMatch). Unchanged when the where already holds one, or pins rows by key (vod_id / name / code equality): a
     * MATCH in WHERE makes the server read the FULLTEXT index instead of that cheaper key.
     */
    public static function ftFilterWhere(array $where): array
    {
        if (!class_exists('\app\common\util\FilterMatch')) {
            return $where;
        }
        $cur = $where['_string'] ?? '';
        if (!is_string($cur) || \app\common\util\FilterMatch::inSql($cur) || self::ftPinned($where)) {
            return $where;
        }
        $pre = [];
        $visible = null;
        foreach (self::FT_FILTER_COLS as $col) {
            $raw = isset($where[$col]) ? self::ftLikeRaw($where[$col]) : null;
            if ($raw === null) {
                continue;
            }
            $fp = \app\common\util\FilterMatch::prefilter($col, $raw);
            if ($fp === '' && method_exists('\app\common\util\FilterMatch', 'items')
                && ($visible !== null ? $visible : ($visible = self::ftVisibleOnly($where)))) {
                // fp-port-b (FINISH-PORT-REVIEW P2-3): a tag / class / actor value with a CJK / kana / Hangul / Thai character
                // (no word path above) reads its rows through the search table's field tokens of the column items that
                // LIKE-match it (FilterMatch::items: an id list, exact, '(1=0)' when no row holds it); lists of visible rows
                // only, the rows the search table holds. It replaces the round-2 CJK "absent" shortcut, which also answered
                // empty for a value inside a long CJK run or a value list with an empty item (FINISH-PORT-REVIEW P3-1).
                $fp = \app\common\util\FilterMatch::items($col, $raw);
            }
            if ($fp !== '') {
                $pre[] = $fp;
            }
        }
        if ($pre) {
            $where['_string'] = (trim($cur) !== '' ? '(' . $cur . ') AND ' : '') . implode(' AND ', $pre);
        }
        return $where;
    }

    /**
     * fp-port-b: true when $where lists visible rows only (vod_status = 1 AND vod_recycle_time = 0, as every visitor list
     * and the landing count do): the rows the search table holds, so FilterMatch::items() may narrow them.
     */
    private static function ftVisibleOnly(array $where): bool
    {
        $is = function ($c, int $want): bool {
            if (is_array($c)) {
                return count($c) === 2 && isset($c[0], $c[1]) && is_string($c[0]) && in_array(strtolower(trim($c[0])), ['eq', '='], true)
                    && is_scalar($c[1]) && (string)$c[1] === (string)$want;
            }
            return is_scalar($c) && (string)$c === (string)$want;
        };
        return array_key_exists('vod_status', $where) && array_key_exists('vod_recycle_time', $where)
            && $is($where['vod_status'], 1) && $is($where['vod_recycle_time'], 0);
    }

    /** The comma list of a LIKE condition built by mac_like_arr() ('%v1%', '%v2%' ...), null for any other condition. */
    private static function ftLikeRaw($cond): ?string
    {
        if (!is_array($cond) || !isset($cond[0], $cond[1]) || !is_string($cond[0]) || strtolower($cond[0]) !== 'like') {
            return null;
        }
        $vals = [];
        foreach (is_array($cond[1]) ? $cond[1] : [$cond[1]] as $p) {
            if (!is_string($p) || strlen($p) < 2 || $p[0] !== '%' || substr($p, -1) !== '%') {
                return null;
            }
            $vals[] = substr($p, 1, -1);
        }
        return $vals ? implode(',', $vals) : null;
    }

    /** True when $where pins rows by a key (vod_id other than NOT IN, or name / code / douban id equality). */
    private static function ftPinned(array $where): bool
    {
        foreach (['vod_id', 'vod_name', 'vod_sub', 'vod_en', 'vod_douban_id'] as $k) {
            if (!array_key_exists($k, $where)) {
                continue;
            }
            $c = $where[$k];
            $op = is_array($c) ? strtolower(trim((string)($c[0] ?? ''))) : '=';
            if ($k === 'vod_id' ? !in_array($op, ['not in', 'neq', '<>', '!='], true) : in_array($op, ['=', 'eq', 'in'], true)) {
                return true;
            }
        }
        return false;
    }

    /** True when $error is the server refusing a FilterMatch condition of $where2; the capability is forgotten then. */
    private static function ftRefused(\Throwable $error, $where2): bool
    {
        if (!class_exists('\app\common\util\FilterMatch') || !\app\common\util\FilterMatch::inSql($where2)
            || !\app\common\util\FilterMatch::refused($error)) {
            return false;
        }
        \app\common\util\FilterMatch::forget(); // FULLTEXT index dropped since the capability was cached
        return true;
    }

    /**
     * S-O1 page planner: true when the page ids of a list with a FilterMatch condition in $where2 are cheaper through the
     * MATCH than through the walk of the list's sort index. The MATCH reads every row its FULLTEXT index selects (M_ft,
     * FilterMatch::selected: the exact number the list's count noted, or a probe of at most ROWS_PROBE rows); the walk reads about $need x $rangeRows / $matches rows of the list's range until
     * it has collected the page ($need = offset + limit). $matches = the list's LIKE matches when counted (null: unknown,
     * then M_ft, which is at least that many: the walk is never priced too high). An unknown price keeps the walk.
     */
    public static function ftIdsCheaper($where2, int $need, ?int $rangeRows, ?int $matches): bool
    {
        if (!class_exists('\app\common\util\FilterMatch') || !\app\common\util\FilterMatch::inSql($where2)) {
            return false;
        }
        $mFt = \app\common\util\FilterMatch::selected($where2);
        $n = $rangeRows !== null && $rangeRows > 0 ? $rangeRows : self::tableRows();
        if ($mFt === null || $mFt < 0 || $n <= 0 || (defined('\app\common\util\FilterMatch::ROWS_PROBE') && $mFt === \app\common\util\FilterMatch::ROWS_PROBE + 1)) {
            return false; // unknown, or only "more than the probe" (no finished count noted it): keep the walk
        }
        $m = max(1, $matches !== null ? min($matches, $mFt) : $mFt);
        return $mFt < max(1, $need) * $n / $m;
    }

    /** Round-4 fp-port-planner-walk: the walk may examine at least this many rows, or this multiple of its expected rows. */
    const WALK_MIN_ROWS = 2000;
    const WALK_FACTOR = 4;

    /**
     * Round-4 fp-port-planner-walk (FINISH-PORT section 10): rows the sort-index walk of a page may examine when the planner
     * chose it over the MATCH (ftIdsCheaper() false): WALK_FACTOR x its expected rows ($need x range / matches, the price
     * it was chosen at), at least WALK_MIN_ROWS, at most the MATCH's own rows (beyond that the MATCH is cheaper whatever the
     * walk finds). 0 = no bound (no FilterMatch condition, an unknown price, a bound as long as the range itself).
     * The price assumes the matches spread over the list; a value whose rows are all old (525k live copy: tag "Solowork",
     * 30k rows, none recent) walked 450k rows (1.1 s warm, 3-4 s cold) before; bounded, it reads at most the bound plus
     * the MATCH's rows.
     */
    public static function ftWalkBound($where2, int $need, ?int $rangeRows, ?int $matches): int
    {
        if (!class_exists('\app\common\util\FilterMatch') || !\app\common\util\FilterMatch::inSql($where2)) {
            return 0;
        }
        $mFt = \app\common\util\FilterMatch::selected($where2);
        $n = $rangeRows !== null && $rangeRows > 0 ? $rangeRows : self::tableRows();
        if ($mFt === null || $mFt <= 0 || $n <= 0 || (defined('\app\common\util\FilterMatch::ROWS_PROBE') && $mFt === \app\common\util\FilterMatch::ROWS_PROBE + 1)) {
            return 0;
        }
        $m = max(1, $matches !== null ? min($matches, $mFt) : $mFt);
        $expect = (int)min(PHP_INT_MAX / 8, ceil(max(1, $need) * ($n / $m)));
        $k = (int)max(self::WALK_MIN_ROWS, min($mFt, self::WALK_FACTOR * $expect));
        return $k >= $n ? 0 : $k;
    }

    /**
     * Round-4 fp-port-planner-walk: the window of a bounded walk, "vod_time >= T" (newest first) or "vod_time <= T" (oldest
     * first), T = the sort value of the $rows-th row of the list's range in the list order, read from its D-L1 index alone
     * (one range of $rows index entries: the category type_id = X (type_list), a parent's children type_id_1 = P without
     * rows of its own (type1_list), or every published video (status_list)). Null when the range holds fewer rows (the walk
     * is short anyway), the sort is not vod_time, the range has another shape (an IN list, hidden categories, a parent with
     * rows of its own) or ScaleSchema does not name the index.
     */
    /** Test hook (tests/ft_filter_match.php): ['status_list' => index, 'type_list' => index, 'type1_list' => index] instead of ScaleSchema. */
    public static $walkIndexes = null;

    private static function walkIndex(string $which): string
    {
        if (is_array(self::$walkIndexes)) {
            return (string)(self::$walkIndexes[$which] ?? '');
        }
        return class_exists('\app\common\util\ScaleSchema') ? (string)\app\common\util\ScaleSchema::indexName($which) : '';
    }

    private static function walkWindow(array $where, int $parent, string $order, int $rows): ?string
    {
        if ($rows < 2 || !preg_match('/^vod_time (asc|desc)$/D', trim($order), $s)) {
            return null;
        }
        $st = $where['vod_status'] ?? null;
        if (!($st === 1 || $st === '1' || (is_array($st) && strtolower((string)($st[0] ?? '')) === 'eq' && !is_array($st[1] ?? null) && (string)($st[1] ?? '') === '1'))) {
            return null;
        }
        if (!array_key_exists('vod_recycle_time', $where) || is_array($where['vod_recycle_time']) || (string)$where['vod_recycle_time'] !== '0') {
            return null;
        }
        try {
            $q = Db::name('Vod')->field('vod_time');
            if ($parent > 0) {
                if (isset($where['type_id']) || self::parentHasOwnRows($parent)) {
                    return null;
                }
                $idx = self::walkIndex('type1_list');
                $q->where('type_id_1', $parent);
            } elseif (isset($where['type_id'])) {
                $c = $where['type_id'];
                if (!(is_array($c) && strtolower((string)($c[0] ?? '')) === 'eq' && is_scalar($c[1] ?? null))) {
                    return null;
                }
                $idx = self::walkIndex('type_list');
                $q->where('type_id', (int)$c[1]);
            } else {
                $idx = self::walkIndex('status_list');
            }
            if ($idx === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $idx)) {
                return null;
            }
            // select(), not value(): value() replaces the limit with LIMIT 1 (the newest row instead of the $rows-th)
            $hit = $q->force($idx)->where('vod_status', 1)->where('vod_recycle_time', 0)->order('vod_time ' . $s[1])->limit($rows - 1, 1)->select();
            $t = (is_array($hit) || $hit instanceof \ArrayAccess) && isset($hit[0]['vod_time']) ? $hit[0]['vod_time'] : null;
        } catch (\Throwable $e) {
            if (class_exists('\app\common\util\DbTimeout') && \app\common\util\DbTimeout::is($e)) {
                throw $e;
            }
            return null;
        }
        if ($t === null || $t === false || $t === '') {
            return null;
        }
        return 'vod_time ' . ($s[1] === 'desc' ? '>=' : '<=') . ' ' . (int)$t;
    }

    /** Rows of the vod table (ScaleCounts, else the table statistics; cached 10 minutes; no COUNT). */
    public static function tableRows(): int
    {
        try {
            if (class_exists('\app\common\util\ScaleCounts') && ($n = \app\common\util\ScaleCounts::all()) !== null && $n > 0) {
                return (int)$n;
            }
        } catch (\Throwable $e) {
        }
        $key = ($GLOBALS['config']['app']['cache_flag'] ?? 'mac') . '_vod_table_rows_v1';
        $n = Cache::get($key);
        if (!is_int($n)) {
            $r = Db::query('SELECT TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [Db::name('Vod')->getTable()]);
            $n = (int)($r[0]['n'] ?? 0);
            Cache::set($key, $n, 600);
        }
        return (int)$n;
    }

    /**
     * S-O1: runs the count $run; when $where2 holds a FilterMatch condition and $complete($result) says the count ran to its
     * end, the rows the statement read (session counter Rows_read, MariaDB) are noted as the MATCH's row count
     * (FilterMatch::noteRows: the page planner's price, no extra query). Servers without the counter: nothing noted.
     */
    private static function ftCounted($where2, callable $run, callable $complete)
    {
        $before = self::ftRowsRead($where2);
        $res = $run();
        if ($before !== null && $complete($res) && ($after = self::ftRowsRead($where2)) !== null && $after >= $before) {
            \app\common\util\FilterMatch::noteRows($where2, $after - $before);
        }
        return $res;
    }

    /** Session Rows_read (MariaDB) when $where2 holds a FilterMatch condition; null otherwise or when the server has none. */
    private static function ftRowsRead($where2): ?int
    {
        static $has = true;
        if (!$has || !class_exists('\app\common\util\FilterMatch') || !\app\common\util\FilterMatch::inSql($where2)) {
            return null;
        }
        try {
            $r = Db::query("SHOW SESSION STATUS LIKE 'Rows_read'");
        } catch (\Throwable $e) {
            $r = [];
        }
        if (!$r) {
            $has = false; // MySQL: no such counter
            return null;
        }
        $row = array_values($r[0]);
        return (int)($row[1] ?? 0);
    }

    /** "col dir" -> "col dir, vod_id dir" (the tie order of the sort-index walk); other sorts unchanged. */
    private static function ftTieOrder($order)
    {
        return is_string($order) && preg_match('/^(vod_[a-z0-9_]+)\s+(asc|desc)$/iD', trim($order), $s) ? $s[1] . ' ' . $s[2] . ', vod_id ' . $s[2] : $order;
    }

    /** $where2 without its FilterMatch conditions (each replaced by 1=1). */
    private static function ftPlain($where2)
    {
        return class_exists('\app\common\util\FilterMatch') ? \app\common\util\FilterMatch::strip($where2) : $where2;
    }

    /** Published rows of the list's range (category, parent or all) from ScaleCounts; null = unknown (whole table then). */
    private static function ftRangeRows(array $where, int $parent): ?int
    {
        $n = self::scaleTotal($parent, $where, []);
        return $n !== null && $n > 0 ? (int)$n : null;
    }

    public function listData($where,$order,$page=1,$limit=20,$start=0,$field='*',$addition=1,$totalshow=1)
    {
        if(!is_array($where)){
            $where = json_decode($where,true);
        }
        $where = self::ftFilterWhere($this->mergeRecycleWhere(is_array($where) ? $where : []));
        if (class_exists('\app\common\util\FilterMatch') && \app\common\util\FilterMatch::inSql($where['_string'] ?? null)) {
            try {
                return $this->listDataRun($where, $order, $page, $limit, $start, $field, $addition, $totalshow);
            } catch (\Throwable $error) {
                if (!self::ftRefused($error, $where['_string'])) {
                    throw $error;
                }
                $where['_string'] = \app\common\util\FilterMatch::strip($where['_string']);
            }
        }
        return $this->listDataRun($where, $order, $page, $limit, $start, $field, $addition, $totalshow);
    }

    private function listDataRun(array $where,$order,$page,$limit,$start,$field,$addition,$totalshow)
    {
        $responsePage = is_scalar($page) && (int)$page > 0 ? $page : 1;
        $page = max(1, (int)$page);
        $limit = max(1, (int)$limit);
        $start = max(0, (int)$start);
        $total = 0;
        $where2='';
        if(!empty($where['_string'])){
            $where2 = $where['_string'];
            unset($where['_string']);
        }

        if (preg_match('/^vod_duration (asc|desc)$/iD', trim((string)$order), $durationOrder)) { $order = $this->buildDurationOrderExpr(strtolower($durationOrder[1])); }
        $offset = $limit * ($page-1) + $start;
        $limit_str = $offset .",".$limit;
        if($totalshow==1) {
            $total = $this->cachedCount($where, $where2);
        }

        // Nothing exists past the counted total, so out-of-range pages skip the OFFSET scan.
        if ($totalshow == 1 && $offset >= $total) {
            $list = [];
        } elseif (class_exists('\app\common\util\FilterMatch') && \app\common\util\FilterMatch::inSql($where2)) {
            // S-O1: page ids through the MATCH only when that is cheaper than the sort-index walk; with the MATCH they are
            // sorted with the walk's tie order (vod_id in the sort direction), so the page is the plain LIKE's page
            $plain = \app\common\util\FilterMatch::strip($where2);
            if (self::ftIdsCheaper($where2, $offset + $limit, null, $totalshow == 1 ? (int)$total : null)) {
                $ids = Db::name('Vod')->field('vod_id')->where($where)->where($where2)->order(self::ftTieOrder($order))->limit($limit_str)->column('vod_id');
                $list = $this->rowsByIds($ids, $where, $plain, $field);
            } else {
                $list = Db::name('Vod')->field($field)->where($where)->where($plain)->order($order)->limit($limit_str)->select();
            }
        } else {
            $list = Db::name('Vod')->field($field)->where($where)->where($where2)->order($order)->limit($limit_str)->select();
        }

        // triage
        $type_list = model('Type')->getCache('type_list');
        // user groups
        $group_list = model('Group')->getCache('group_list');

        foreach($list as $k=>$v){
            if($addition==1){
	            if(!empty($v['type_id'])) {
	                $list[$k]['type'] = $type_list[$v['type_id']];
                    $list[$k]['type_1'] = $type_list[$list[$k]['type']['type_pid']];
	            }
	            if(!empty($v['group_id'])) {
	                $list[$k]['group'] = $group_list[$v['group_id']];
	            }
            }
        }
        return ['code'=>1,'msg'=>lang('data_list'),'page'=>$responsePage,'pagecount'=>ceil($total/$limit),'limit'=>$limit,'total'=>$total,'list'=>$list];
    }

    /**
     * Front-end list totals don't depend on the page number, so cache them per filter (tagged like the list cache,
     * so catalog writes still clear them) instead of recounting the whole table for every page of a search.
     */
    private function cachedCount(array $where, $where2)
    {
        $key = $this->countCacheKey($where, $where2);
        if ($key !== '' && is_int($hit = Cache::get($key))) {
            return $hit;
        }
        $total = (int)self::ftCounted($where2, function () use ($where, $where2) { return $this->where($where)->where($where2)->count(); },
            function () { return true; });
        if ($key !== '') {
            Cache::tag($this->name)->set($key, $total, (int)$GLOBALS['config']['app']['cache_time']);
        }
        return $total;
    }

    /** Cache key of a front-end list total ('' = do not cache: admin/API, cache_core off, unserializable conditions). */
    private function countCacheKey(array $where, $where2)
    {
        if (defined('ENTRANCE') && ENTRANCE === 'index' && (int)($GLOBALS['config']['app']['cache_core'] ?? 0) === 1) {
            try {
                return $GLOBALS['config']['app']['cache_flag'] . '_vod_count_v2_r' . self::listRevision() . '_' . md5(serialize($where) . '|' . $where2);
            } catch (\Throwable $e) {
                return ''; // conditions holding closures can't be serialized; count without caching
            }
        }
        return '';
    }

    /** Ordered ids kept per search (search-speed): 50 pages of 20. */
    const SEARCH_ID_CAP = 1000;

    /**
     * List + total of a keyword search in ONE pass over the table (search-speed, 2026-10-14). The page used to run a
     * COUNT pass and then the list query; for a rare word that list query walked the whole vod_time index (1.4 s on
     * 524k videos) on top of the 0.9 s COUNT. Here one query returns the ordered ids of the first SEARCH_ID_CAP
     * matches and FOUND_ROWS() the total; ids and total are cached like the list cache (tag 'vod', cache_time), so
     * later pages, the ajax search and the other themes reuse them, and the page's rows are read by primary key.
     * Same rows, same total, same order (ties by vod_id in the direction of the sort, like the index walk). An
     * unsupported case (null) uses listData(). 1.2.0 (D-S5): used only while the search table is not ready; a database
     * error is NOT answered with a second full scan any more (the caller sends the stale copy or the busy page).
     */
    private function searchListData($where, $order, $page, $limit, $start)
    {
        // S-O1: a tag / class / actor / director filter of the same search narrows the one-pass scan through its MATCH
        // (the scan then reads the rows of the FULLTEXT index instead of the table), except inside the newest-rows window
        // of a big catalog, whose primary-key range is the cheaper read
        if (is_array($where) && self::oldPathWindow() === null) {
            $where = self::ftFilterWhere($this->mergeRecycleWhere($where));
            if (class_exists('\app\common\util\FilterMatch') && \app\common\util\FilterMatch::inSql($where['_string'] ?? null)) {
                try {
                    $res = $this->searchListFast($where, $order, $page, $limit, $start);
                    return is_array($res) ? $res : $this->listData($where, $order, $page, $limit, $start, '*', 1, 1);
                } catch (\Throwable $error) {
                    if (!self::ftRefused($error, $where['_string'])) {
                        throw $error;
                    }
                    $where['_string'] = \app\common\util\FilterMatch::strip($where['_string']);
                }
            }
        }
        $res = $this->searchListFast($where, $order, $page, $limit, $start);
        if (is_array($res)) {
            return $res;
        }
        return $this->listData($where, $order, $page, $limit, $start, '*', 1, 1);
    }

    private function searchListFast($where, $order, $page, $limit, $start)
    {
        if (!is_array($where) || !preg_match('/^(vod_[a-z_]+) (asc|desc)$/D', trim((string)$order), $sort)) {
            return null;
        }
        $where = $this->mergeRecycleWhere($where);
        $responsePage = is_scalar($page) && (int)$page > 0 ? $page : 1;
        $page = max(1, (int)$page);
        $limit = max(1, (int)$limit);
        $start = max(0, (int)$start);
        $where2 = '';
        if (!empty($where['_string'])) {
            $where2 = $where['_string'];
            unset($where['_string']);
        }
        $offset = $limit * ($page - 1) + $start;
        $dir = strtoupper($sort[2]);
        // HO-H-8 (a): while the search table is not ready on a big catalog (upgrade / backfill running), today's one-pass
        // search reads only the newest OLD_PATH_WINDOW rows (a primary-key range), so it answers inside the statement limit
        // on SHARED hosting; the page says "matches among the newest N videos". Small catalogs keep today's exact search.
        $win = self::oldPathWindow();
        if ($win !== null) {
            $where2 = ($where2 !== '' ? '(' . $where2 . ') AND ' : '') . '`vod_id` >= ' . (int)$win['id'];
        }
        $countKey = $this->countCacheKey($where, $where2);
        $idsKey = $countKey === '' ? '' : $countKey . '_ids_v1_' . md5($order . '|' . self::SEARCH_ID_CAP);
        $hit = $idsKey === '' ? null : Cache::get($idsKey);
        if (is_array($hit) && isset($hit['total'], $hit['ids']) && is_array($hit['ids'])) {
            $total = (int)$hit['total'];
            $ids = $hit['ids'];
        } else {
            if ($countKey !== '' && is_int(Cache::get($countKey))) {
                return null; // total already counted: the plain list query is the cheap path from here
            }
            $query = Db::name('Vod');
            // "+0": the sort must not walk the column's index (that reads every row through the index for a rare word);
            // a table scan with a top-N sort reads each row once and counts the matches on the way.
            $rows = $query->field($query->raw('SQL_CALC_FOUND_ROWS `vod_id`'))->where($where)->where($where2)
                ->order($query->raw('`' . $sort[1] . '`+0 ' . $dir . ', `vod_id` ' . $dir))->limit(0, self::SEARCH_ID_CAP)->select();
            $found = Db::query('SELECT FOUND_ROWS() AS found_rows');
            if (!isset($found[0]['found_rows'])) {
                return null;
            }
            $total = (int)$found[0]['found_rows'];
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int)$row['vod_id'];
            }
            if ($total < count($ids)) {
                return null;
            }
            if ($idsKey !== '') {
                $ttl = (int)$GLOBALS['config']['app']['cache_time'];
                Cache::tag($this->name)->set($idsKey, ['total' => $total, 'ids' => $ids], $ttl);
                Cache::tag($this->name)->set($countKey, $total, $ttl);
            }
        }

        $list = [];
        if ($offset < $total) {
            $slice = array_slice($ids, $offset, $limit);
            if (count($slice) === min($limit, $total - $offset)) {
                $byId = [];
                $rows = Db::name('Vod')->field('*')->where($where)->where($where2)
                    ->where('vod_id IN (' . implode(',', $slice) . ')')->select();
                foreach ($rows as $row) {
                    $byId[(int)$row['vod_id']] = $row;
                }
                foreach ($slice as $id) {
                    if (isset($byId[$id])) {
                        $list[] = $byId[$id];
                    }
                }
            } else {
                // deeper than the cached ids: the plain list query for this page, with the same vod_id tie-break as the
                // cached ids (SS-V4), so rows with equal sort values keep one order on both sides of the 1,000th match
                $list = Db::name('Vod')->field('*')->where($where)->where($where2)->order($sort[1] . ' ' . $dir . ', vod_id ' . $dir)->limit($offset . ',' . $limit)->select();
            }
        }

        $type_list = model('Type')->getCache('type_list');
        $group_list = model('Group')->getCache('group_list');
        foreach ($list as $k => $v) {
            if (!empty($v['type_id'])) {
                $list[$k]['type'] = $type_list[$v['type_id']];
                $list[$k]['type_1'] = $type_list[$list[$k]['type']['type_pid']];
            }
            if (!empty($v['group_id'])) {
                $list[$k]['group'] = $group_list[$v['group_id']];
            }
        }
        return ['code' => 1, 'msg' => lang('data_list'), 'page' => $responsePage, 'pagecount' => ceil($total / $limit), 'limit' => $limit, 'total' => $total, 'list' => $list]
            + ($win !== null ? ['search_msg' => 'newest:' . (int)$win['rows'], 'total_exact' => false] : []);
    }

    /** HO-H-8 (a): rows today's search path may read while the search table is not ready (newest first, by primary key). */
    const OLD_PATH_WINDOW = 100000;

    /**
     * Lower vod_id bound of today's search path on a catalog above OLD_PATH_WINDOW x 1.2 rows: the id range that holds about
     * the newest OLD_PATH_WINDOW rows (ids read by primary key, row count from ScaleCounts or the table statistics; no
     * COUNT). Null = small catalog (today's exact search over every row).
     */
    private static function oldPathWindow(): ?array
    {
        $n = null;
        try {
            if (class_exists('\app\common\util\ScaleCounts')) {
                $n = \app\common\util\ScaleCounts::all();
            }
        } catch (\Throwable $e) {
            $n = null;
        }
        if ($n === null) {
            $key = ($GLOBALS['config']['app']['cache_flag'] ?? 'mac') . '_vod_table_rows_v1';
            $n = Cache::get($key);
            if (!is_int($n)) {
                $r = Db::query('SELECT TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [Db::name('Vod')->getTable()]);
                $n = (int)($r[0]['n'] ?? 0);
                Cache::set($key, $n, 600);
            }
        }
        if ((int)$n <= self::OLD_PATH_WINDOW * 1.2) {
            return null;
        }
        $ends = self::idEnds();
        if ($ends[1] <= $ends[0]) {
            return null;
        }
        $bound = (int)floor($ends[1] - ($ends[1] - $ends[0] + 1) * self::OLD_PATH_WINDOW / (int)$n);
        return ['id' => max($ends[0], $bound), 'rows' => self::OLD_PATH_WINDOW];
    }

    public function listRepeatData($where,$order,$page=1,$limit=20,$start=0,$field='*',$addition=1)
    {
        if(!is_array($where)){
            $where = json_decode($where,true);
        }
        $where = $this->mergeRecycleWhere(is_array($where) ? $where : []);
        $responsePage = is_scalar($page) && (int)$page > 0 ? $page : 1;
        $page = max(1, (int)$page);
        $limit = max(1, (int)$limit);
        $start = max(0, (int)$start);
        $total = 0;
        if (preg_match('/^vod_duration (asc|desc)$/iD', trim((string)$order), $durationOrder)) { $order = $this->buildDurationOrderExpr(strtolower($durationOrder[1])); }
        $limit_str = ($limit * ($page-1) + $start) .",".$limit;

        $total = $this
            ->join('tmpvod t','t.name1 = vod_name')
            ->where($where)
            ->count();

        $list = Db::name('Vod')
            ->join('tmpvod t','t.name1 = vod_name')
            ->field($field)
            ->where($where)
            ->order($order)
            ->limit($limit_str)
            ->select();

        // triage
        $type_list = model('Type')->getCache('type_list');
        // user groups
        $group_list = model('Group')->getCache('group_list');

        foreach($list as $k=>$v){
            if($addition==1){
                if(!empty($v['type_id'])) {
                    $list[$k]['type'] = $type_list[$v['type_id']];
                    $list[$k]['type_1'] = $type_list[$list[$k]['type']['type_pid']];
                }
                if(!empty($v['group_id'])) {
                    $list[$k]['group'] = $group_list[$v['group_id']];
                }
            }
        }
        return ['code'=>1,'msg'=>lang('data_list'),'page'=>$responsePage,'pagecount'=>ceil($total/$limit),'limit'=>$limit,'total'=>$total,'list'=>$list];
    }

    public function listCacheData($lp)
    {
        if(!is_array($lp)){
            $lp = json_decode($lp,true);
        }

        $order = $lp['order'];
        $by = $lp['by'];
        $type = $lp['type'];
        $ids = $lp['ids'];
        $rel = $lp['rel'];
        $paging = $lp['paging'];
        $pageurl = $lp['pageurl'];
        $level = $lp['level'];
        $area = $lp['area'];
        $lang = $lp['lang'];
        $state = $lp['state'];
        $wd = $lp['wd'];
        $tag = $lp['tag'];
        $class = $lp['class'];
        $letter = $lp['letter'];
        $actor = $lp['actor'];
        $director = $lp['director'];
        $version = $lp['version'];
        $year = $lp['year'];
        $start = mac_legacy_abs_int($lp['start']);
        $num = mac_legacy_abs_int($lp['num']);
        $half = mac_legacy_abs_int($lp['half']);
        $weekday = $lp['weekday'];
        $tv = $lp['tv'];
        $timeadd = $lp['timeadd'];
        $timehits = $lp['timehits'];
        $time = $lp['time'];
        $hitsmonth = $lp['hitsmonth'];
        $hitsweek = $lp['hitsweek'];
        $hitsday = $lp['hitsday'];
        $hits = $lp['hits'];
        $not = $lp['not'];
        $cachetime = $lp['cachetime'];
        $isend = $lp['isend'];
        $plot = $lp['plot'];
        $typenot = $lp['typenot'];
        $name = $lp['name'];

        $page = 1;
        $where=[];
        $totalshow = 0;
        $byFromUrl = false;
        $tid = 0;

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        if(!in_array($paging, ['yes', 'no'])) {
            $paging = 'no';
        }

        $param = mac_param_url();
        if($paging=='yes') {
            $param = self::searchLenCheck($param);
            $totalshow = 1;
            if(!empty($param['id'])){
                // $type = intval ($param [\ 'id\ ']);
            }
            if(!empty($param['level'])){
                $level = $param['level'];
            }
            if(!empty($param['ids'])){
                $ids = $param['ids'];
            }
            if(!empty($param['tid'])) {
                $tid = intval($param['tid']);
            }
            if(!empty($param['year'])){
                if(strlen($param['year'])==4){
                    $year = intval($param['year']);
                }
                elseif(strlen($param['year'])==9){
                    $s=substr($param['year'],0,4);
                    $e=substr($param['year'],5,4);
                    $s1 = intval($s);$s2 = intval($e);
                    if($s1>$s2){
                        $s1 = intval($e);$s2 = intval($s);
                    }

                    $tmp=[];
                    for($i=$s1;$i<=$s2;$i++){
                        $tmp[] = $i;
                    }
                    $year = join(',',$tmp);
                }
            }
            if(!empty($param['area'])){
                $area = $param['area'];
            }
            if(!empty($param['lang'])){
                $lang = $param['lang'];
            }
            if(!empty($param['tag'])){
                $tag = $param['tag'];
            }
            if(!empty($param['class'])){
                $class = $param['class'];
            }
            if(!empty($param['state'])){
                $state = $param['state'];
            }
            if(!empty($param['letter'])){
                $letter = $param['letter'];
            }
            if(!empty($param['version'])){
                $version = $param['version'];
            }
            if(!empty($param['actor'])){
                $actor = $param['actor'];
            }
            if(!empty($param['director'])){
                $director = $param['director'];
            }
            if(!empty($param['wd'])){
                $wd = $param['wd'];
            }
            if(!empty($param['name'])){
                $name = $param['name'];
            }
            if(!empty($param['by'])){
                $by = $param['by'];
                $byFromUrl = true; // D-L6: only the URL whitelist applies to a visitor-supplied sort
            }
            if(!empty($param['order'])){
                $order = $param['order'];
            }
            if(!empty($param['page'])){
                $page = intval($param['page']);
            }
            if(isset($param['isend'])){
                $isend = intval($param['isend']);
            }

            foreach($param as $k=>$v){
                if(empty($v)){
                    unset($param[$k]);
                }
            }
            if(empty($pageurl)){
                $pageurl = 'vod/type';
            }
            $param['page'] = 'PAGELINK';
            // round 5 (search-r5 F-r5-1): the pager pattern names the list filters as REQUESTED (searchLenParam: actor / tag /
            // class / director whole, every other field cut as 1.1), like the canonical, language and sort links of the page
            // (HP-S-R4-1); the list itself keeps filtering with the cut $param above. Built from the cut $param, a cut that
            // ended on a space ("...,Akari ") lost it through mac_param_url()'s trim on the next request, so page 2 listed
            // page 2 of another list ("...,Akari", 109 rows instead of 93) under a third URL form. Empty values are dropped
            // the same way as for $param.
            $linkParam = self::searchLenParam(mac_param_url());
            foreach($linkParam as $k=>$v){
                if(empty($v)){
                    unset($linkParam[$k]);
                }
            }
            $linkParam['page'] = 'PAGELINK';

            if($pageurl=='vod/type' || $pageurl=='vod/show'){
                $type = intval( $GLOBALS['type_id'] );
                $type_list = model('Type')->getCache('type_list');
                $type_info = $type_list[$type];
                $flag='type';
                if($pageurl == 'vod/show'){
                    $flag='show';
                }
                // round-3 verify N10: a keyword search inside the category pages through its own address
                // (/category/<slug>/wd/<word>/?page=N): the numbers, the arrows and the C20 "Older / Oldest" links (built
                // from this pattern by AvdbLanguage::cursorUrl) keep the word; mac_url_type() drops it for vod/type
                $catSearch = is_array($type_info) && !empty($linkParam['wd']) ? self::categorySearchUrl($type_info, $flag, $linkParam, 'PAGELINK') : '';
                $pageurl = $catSearch !== '' ? $catSearch : mac_url_type($type_info,$linkParam,$flag);
            }
            elseif ($pageurl == 'vod/search' && ($canonicalPager = self::searchCanonical(mac_param_url(), 'PAGELINK')) !== '') {
                // L3: the pager links straight to the canonical search URL (query form), never to a form that answers 301
                $pageurl = $canonicalPager;
            }
            else{
                $pageurl = mac_url($pageurl,$linkParam);
            }
        }

        $where['vod_status'] = ['eq',1];
        if(!empty($ids)) {
            if($ids!='all'){
                $where['vod_id'] = ['in',explode(',',$ids)];
            }
        }
        if(!empty($not)){
            $where['vod_id'] = ['not in',explode(',',$not)];
        }
        if(!empty($rel)){
            $tmp = explode(',',$rel);
            if(is_numeric($rel) || mac_array_check_num($tmp)==true  ){
                $where['vod_id'] = ['in',$tmp];
            }
            else{
                $where['vod_rel_vod'] = ['like', mac_like_arr($rel),'OR'];
            }
        }
        if(!empty($level)) {
            if($level=='all'){
                $level = '1,2,3,4,5,6,7,8,9';
            }
            $where['vod_level'] = ['in',explode(',',$level)];
        }
        if(!empty($year)) {
            $where['vod_year'] = ['in',explode(',',$year)];
        }
        // Fix country search
        if(!empty($area)) {
            // $where[\'vod_area\'] = [\'in\',explode(\',\',$area)];
            $where['vod_area'] = ['like',mac_like_arr($area),'OR'];
        }
        if(!empty($lang)) {
            $where['vod_lang'] = ['in',explode(',',$lang)];
        }
        if(!empty($state)) {
            $where['vod_state'] = ['in',explode(',',$state)];
        }
        if(!empty($version)) {
            $where['vod_version'] = ['in',explode(',',$version)];
        }
        if(!empty($weekday)){
            // $where[\'vod_weekday\'] = [\'in\',explode(\',\',$weekday)];
            $where['vod_weekday'] = ['like', mac_like_arr($weekday),'OR'];
        }
        if(!empty($tv)){
            $where['vod_tv'] = ['in',explode(',',$tv)];
        }
        if(!empty($timeadd)){
            $s = intval(strtotime($timeadd));
            $where['vod_time_add'] =['gt',$s];
        }
        if(!empty($timehits)){
            $s = intval(strtotime($timehits));
            $where['vod_time_hits'] =['gt',$s];
        }
        if(!empty($time)){
            $s = intval(strtotime($time));
            $where['vod_time'] =['gt',$s];
        }
        if(!empty($letter)){
            if(substr($letter,0,1)=='0' && substr($letter,2,1)=='9'){
                $letter='0,1,2,3,4,5,6,7,8,9';
            }
            $where['vod_letter'] = ['in',explode(',',$letter)];
        }
        // 1.2.0 (D-L1b): a parent list is "type_id_1 = P" plus "type_id = P" (UNION ALL) only when the parent holds rows of
        // its own; the "type_id IN (parent + children)" and "type_id|type_id_1" OR forms picked the wrong index / filesorted.
        $listParent = 0;
        $plannerTypes = [];
        if(!empty($type)) {
            if($type=='current'){
                $type = intval( $GLOBALS['type_id'] );
            }
            if($type!='all') {
                $tmp_arr = explode(',',$type);
                $type_list = model('Type')->getCache('type_list');
                $type = [];
                foreach($type_list as $k2=>$v2){
                    if(in_array($v2['type_id'].'',$tmp_arr) || in_array($v2['type_pid'].'',$tmp_arr)){
                        $type[]=$v2['type_id'];
                    }
                }
                $type = array_values(array_unique($type));
                $shape = self::typeShape($type, is_array($type_list) ? $type_list : []);
                if ($shape[0] === 'parent') {
                    $listParent = $shape[1];
                } else {
                    $where['type_id'] = $shape[0] === 'one' ? ['eq', $shape[1]] : ['in', implode(',',$type) ];
                }
                $plannerTypes = $shape[0] === 'list' ? array_map('intval', $type) : [$shape[1]];
            }
        }
        if(!empty($typenot)){
            $where['type_id'] = ['not in',$typenot];
            $listParent = 0;
            $plannerTypes = [];
        }
        if(!empty($tid)) {
            $type_list = model('Type')->getCache('type_list');
            $tinfo = is_array($type_list) && isset($type_list[$tid]) ? $type_list[$tid] : null;
            if ($tinfo !== null && (int)$tinfo['type_pid'] === 0 && $listParent === 0) {
                $listParent = (int)$tid; // a top-level category: its children through type_id_1, its own rows through type_id
            } else {
                $where['type_id'] = isset($where['type_id']) ? [$where['type_id'], ['eq', (int)$tid]] : ['eq', (int)$tid];
            }
            $plannerTypes = $plannerTypes ? array_values(array_intersect($plannerTypes, array_merge([(int)$tid], self::childIds((int)$tid)))) : [(int)$tid];
            if (!$plannerTypes) { $plannerTypes = [-1]; }
        }
        if(!in_array($GLOBALS['aid'],[13,14,15]) && !empty($param['id'])){
            // $where[\'vod_id\'] = [\'not in\',$param[\'id\']];
        }

        if(!empty($hitsmonth)){
            $tmp = explode(' ',$hitsmonth);
            if(count($tmp)==1){
                $where['vod_hits_month'] = ['gt', $tmp];
            }
            else{
                $where['vod_hits_month'] = [$tmp[0],$tmp[1]];
            }
        }
        if(!empty($hitsweek)){
            $tmp = explode(' ',$hitsweek);
            if(count($tmp)==1){
                $where['vod_hits_week'] = ['gt', $tmp];
            }
            else{
                $where['vod_hits_week'] = [$tmp[0],$tmp[1]];
            }
        }
        if(!empty($hitsday)){
            $tmp = explode(' ',$hitsday);
            if(count($tmp)==1){
                $where['vod_hits_day'] = ['gt', $tmp];
            }
            else{
                $where['vod_hits_day'] = [$tmp[0],$tmp[1]];
            }
        }
        if(!empty($hits)){
            $tmp = explode(' ',$hits);
            if(count($tmp)==1){
                $where['vod_hits'] = ['gt', $tmp];
            }
            else{
                $where['vod_hits'] = [$tmp[0],$tmp[1]];
            }
        }

        if(in_array($isend,['0','1'])){
            $where['vod_isend'] = $isend;
        }

        // 1.2.0 (D-S5): the search path is decided per request from the search table state and the tier, never from a caught
        // error. A keyword goes through the planner (FtPlan, C2); a tag / actor value (no keyword) of a template block with
        // paging off through its field token only when FilterMatch cannot narrow the exact LIKE (D-L8, fp-port); visitor tag /
        // actor filters are LIKE filters with the FilterMatch pre-filter. Other filters of the same list are applied to the
        // rows of the found ids.
        $ftReq = null;
        if ((!empty($wd) || !empty($tag) || !empty($actor)) && ($ftMode = self::ftSearchMode()) !== 'old') {
            if (!empty($wd) && is_scalar($wd)) {
                $ftReq = ['wd' => (string)$wd];
            } elseif ((!empty($tag) xor !empty($actor)) && $paging != 'yes') {
                // fp-port (FilterMatch v2 in 1.2.0; owner decision 2026-10-17 (2), import-r3 filter-like-parity): a visitor's
                // tag / actor filter (paging on: /search/?tag= / ?actor=, the tag and actor lists, ajax pages) never takes the
                // field token: it stays a LIKE filter with the FilterMatch pre-filter (listDataScaled), whatever state the
                // search index is in, so it lists exactly the plain LIKE's rows. The token cannot be proven equal to the LIKE:
                // tg_ holds the items of vod_tag AND vod_class, a whole item can also be part of a longer one (tag "Big" 240
                // LIKE rows, 2 token rows), comma lists and double-escaped values differ; FilterMatch v2 gives these values an
                // exact index path (vocabulary groups, FilterVocab). Template blocks with paging off (D-L8 related "same
                // actor" blocks, the video's own whole items) use the same exact LIKE path when FilterMatch can narrow it;
                // only a value it cannot narrow (non-ASCII, a dense value, the vocabulary not usable right now) keeps the
                // field token there, so a detail page never scans the table.
                $tokens = self::ftBlockTokens(!empty($tag) ? 'vod_tag' : 'vod_actor', !empty($tag) ? $tag : $actor)
                    ? self::fieldTokens(!empty($tag) ? 'tg' : 'ac', !empty($tag) ? $tag : $actor) : [];
                if ($tokens) {
                    // round 5 (S-1, flood-r5 R5-1): a paging-off block reads the newest num x 4 matches (at most 200) of a token
                    // the term map does not hold, not FtPlan::FT_LIMIT
                    $ftReq = ['tokens' => $tokens, 'window' => min(200, max(1, (int)$num) * 4)];
                    if (!empty($tag)) { $tag = ''; } else { $actor = ''; }
                }
            } elseif ($ftMode === 'ft' && $paging == 'yes' && !empty($tag) && empty($actor) && is_scalar($tag)
                && ($routeTok = self::tagRouteFor((string)$tag, $where, (int)$start, (string)$by, (string)$order, [$name, $class, $director, $wd, $plot])) !== null) {
                // round 5 (owner decision D-1 (c), 2026-10-19 15:00): a visitor's tag list whose value FtPlan::tagRoute() proves
                // LIKE-exact (one ASCII word no longer item / class / fold spelling holds, nothing queued, document order known)
                // pages through the term map: exact total, numbered pages to the last, O(page) reads at any depth. Its rows are
                // read with the exact LIKE all the same (ftListData); a missing row falls back to the LIKE path below.
                $ftReq = ['tokens' => [$routeTok], 'route' => (string)$tag];
                $tag = '';
            }
        }
        $likeWd = $ftReq !== null && isset($ftReq['wd']) ? '' : $wd;
        $avdbTitleSearch = $likeWd === '' || $likeWd === null ? '' : \app\common\util\AvdbLanguage::titleSearch($likeWd);
        if(!empty($likeWd)) {
            $role = 'vod_name';
            if(!empty($GLOBALS['config']['app']['search_vod_rule'])){
                $role .= '|'.$GLOBALS['config']['app']['search_vod_rule'];
            }
            $where[$role] = ['like', '%' . $likeWd . '%'];
        }
        if(!empty($name)) {
            $where['vod_name'] = ['like',mac_like_arr($name),'OR'];
        }
        if(!empty($tag)) {
            $where['vod_tag'] = ['like',mac_like_arr($tag),'OR'];
        }
        if(!empty($class)) {
            $where['vod_class'] = ['like',mac_like_arr($class), 'OR'];
        }
        if(!empty($actor)) {
            $where['vod_actor'] = ['like', mac_like_arr($actor), 'OR'];
        }
        if(!empty($director)) {
            $where['vod_director'] = ['like',mac_like_arr($director),'OR'];
        }
        // search-speed (today's path while the search table is not ready): the same rows as `col LIKE '%wd%'` through
        // cheaper SQL (binary LIKE / exact general_ci classes); null = keep the original LIKE. 1.2.0 (D-S5): no second
        // query with the plain LIKE after an error any more.
        if (!empty($likeWd) && isset($role) && isset($where[$role])) {
            $fastWd = \app\common\util\SearchMatch::condition(Db::name('vod')->getTable(), explode('|', $role), $likeWd);
            if ($avdbTitleSearch !== '') {
                // Retain the canonical search rule and OR the selected/English translated title.
                $canonical = Db::name('vod')->where([$role => ['like', '%' . $likeWd . '%']])->fetchSql(true)->select();
                $condition = $fastWd !== null ? $fastWd : substr($canonical, strpos($canonical, ' WHERE ') + 7);
                unset($where[$role]);
                $stringPrefix = empty($where['_string']) ? '' : '(' . $where['_string'] . ') AND ';
                $where['_string'] = $stringPrefix . '(' . $condition . ' OR ' . $avdbTitleSearch . ')';
            } elseif ($fastWd !== null) {
                unset($where[$role]);
                $where['_string'] = (empty($where['_string']) ? '' : '(' . $where['_string'] . ') AND ') . $fastWd;
            }
        }
        if(in_array($plot,['0','1'])){
            $where['vod_plot'] = $plot;
        }

        // a category list other than one category or one parent family (an IN list, typenot) counts as a filter (D-L4)
        $typeIsList = isset($where['type_id']) && !(is_array($where['type_id']) && ($where['type_id'][0] ?? '') === 'eq');
        $hiddenTypes = [];
        if(defined('ENTRANCE') && ENTRANCE == 'index' && $GLOBALS['config']['app']['popedom_filter'] ==1){
            $type_ids = mac_get_popedom_filter($GLOBALS['user']['group']['group_type']);
            if(!empty($type_ids)){
                $hiddenTypes = array_values(array_filter(array_map('intval', explode(',', $type_ids))));
                if(!empty($where['type_id'])){
                    $where['type_id'] = [ $where['type_id'],['not in', explode(',',$type_ids)] ];
                }
                else{
                    $where['type_id'] = ['not in', explode(',',$type_ids)];
                }
            }
        }

        // sc-S14: a search never returns videos of hidden categories (planner: 'hidden'; today's path: NOT IN)
        // round 5 (D-1 (c)): a routed tag list lists what the plain LIKE tag list lists: no hidden-category filter of its own
        // (the LIKE list has none; a visitor group's popedom filter refuses the route, tagRouteFor)
        $searchHidden = isset($ftReq['route']) ? [] : (($ftReq !== null || !empty($likeWd)) ? self::hiddenTypeIds() : []);
        if (!empty($likeWd) && ($extraHidden = array_values(array_diff($searchHidden, $hiddenTypes)))) {
            $cur = $where['type_id'] ?? null;
            if (empty($cur)) {
                $where['type_id'] = ['not in', $extraHidden];
            } elseif (is_array($cur) && isset($cur[0]) && is_array($cur[0])) {
                $cur[] = ['not in', $extraHidden];
                $where['type_id'] = $cur;
            } else {
                $where['type_id'] = [$cur, ['not in', $extraHidden]];
            }
        }

        // 1.2.0 (D-L6): a sort from the URL must be one of the URL whitelist; "rnd" only from templates, as random id
        // ranges in SQL (the old code built range(MIN, MAX) in PHP: 130 MB at 7 M ids).
        if ($byFromUrl && !in_array($by, self::BY_URL, true)) {
            $by = 'time';
        }
        $use_rand = false;
        if($by=='rnd' && !$byFromUrl){
            $use_rand = true;
            $by = 'time';
        }
        if(!in_array($by, ['id', 'time','time_add','score','hits','hits_day','hits_week','hits_month','up','down','level'])) {
            $by = 'time';
        }
        if(!in_array($order, ['asc', 'desc'])) {
            $order = 'desc';
        }
        $order= 'vod_'.$by .' ' . $order;

        // 1.2.0 (D-L5; XVID-NOLIMIT-BRIEF S1): the tier caps are 0 = none since the owner decision of 2026-10-15; a cap set
        // again (tier value > 0) still applies. Deep pages use the page map, the rank files and cursors instead of OFFSET.
        $tier = self::tierInfo();
        if ($ftReq !== null || (!empty($likeWd) && $totalshow == 1)) {
            $cap = (int)$tier['search_cap'];
        } else {
            $cap = in_array($by, ['time', 'time_add'], true) ? (int)$tier['list_cap'] : (int)$tier['by_cap'];
        }
        // B3 / C3 continuation cursors (query string only; mac_param_url() does not carry them): after=<sort value>.<vod_id>
        // on lists sorted by an integer column, cursor=<FTS_DOC_ID> on keyword / tag / actor searches. Malformed values are
        // ignored (the page is the list's first page; its canonical has no cursor, so it is never stored as a cursor page).
        $afterCur = null;
        $searchCur = 0;
        $searchUp = 0;
        if ($paging == 'yes') {
            $rawAfter = $_GET['after'] ?? null;
            if (is_string($rawAfter) && preg_match('/^([0-9]{1,10})\.([0-9]{1,10})$/D', $rawAfter, $am) && $ftReq === null && empty($likeWd)
                && in_array('vod_' . $by, self::KEYSET_COLS, true)) {
                $afterCur = [(int)$am[1], (int)$am[2]];
                $page = 1;
            }
            // round 2 (N10): cursor= values are signed (cursorDecode); '1' = Oldest results, 'd' = below the doc, 'u' = the
            // oldest-first walk above the doc. A value this site did not issue is ignored here (the front door answers it
            // with a 301 to the first page before any query)
            $rawCur = $_GET['cursor'] ?? null;
            if ($ftReq !== null && is_string($rawCur) && ($dec = self::cursorDecode($rawCur, mac_param_url())) !== null) {
                if ($dec[0] === 'u') {
                    $searchUp = $dec[1];
                } else {
                    $searchCur = $dec[1];
                }
                $page = 1;
            }
        }
        $filteredNoNot = !empty($ids) || !empty($rel) || !empty($level) || !empty($year) || !empty($area) || !empty($lang)
            || !empty($state) || !empty($version) || !empty($weekday) || !empty($tv) || !empty($timeadd) || !empty($timehits)
            || !empty($time) || !empty($letter) || !empty($typenot) || !empty($hitsmonth) || !empty($hitsweek) || !empty($hitsday)
            || !empty($hits) || in_array($isend, ['0', '1'], true) || in_array($plot, ['0', '1'], true) || !empty($name) || !empty($tag)
            || !empty($class) || !empty($actor) || !empty($director) || !empty($likeWd) || $typeIsList;
        $filtered = $filteredNoNot || !empty($not);
        // round-4 HO-T-R3-1 (theme blocks "popular / top rated except this video": not="<ids>" with by=hits / score / up ...):
        // a not= list of at most NOT_PAD_MAX ids on an otherwise unfiltered list keeps the rank file of page 1: it reads
        // NOT_PAD_MAX more ids and the NOT IN of the primary-key read drops them (deepRows), instead of a live ORDER BY over
        // the category (ember-modern watch page 0.209 s at 600 k; 15 call sites in 7 themes)
        $notPad = 0;
        if (!$filteredNoNot && !empty($not) && $page === 1 && in_array($by, self::RANK_SORTS, true)) {
            $notIds = self::smallIdList($not);
            $notPad = $notIds !== null ? count($notIds) : 0;
        }

        // XVID-NOLIMIT-BRIEF B1 / B2: an unfiltered list reads its deep pages from the page map (by=time) and every page of
        // the other sorts from the rank files; a visitor group with hidden categories gets its own map key (built on the
        // first request; the deferred join serves meanwhile) and the live query for the rank sorts.
        $deepOpt = [];
        if ((!$filtered || $notPad > 0) && $ftReq === null && empty($likeWd) && !$use_rand && $afterCur === null && class_exists('\app\common\util\ListPageMap')) {
            // a top-level category without children is one category range ('t'); a real parent its family ('p')
            $children = $listParent > 0 ? self::childIds($listParent) : [];
            $base = $listParent > 0 && !$children ? \app\common\util\ListPageMap::baseKey($listParent, 0)
                : \app\common\util\ListPageMap::baseKey(self::typeEqOf($where), $listParent);
            list($mapKey, $mapHidden) = \app\common\util\ListPageMap::keyFor($base, $hiddenTypes, $children);
            $asc = substr($order, -4) === ' asc';
            if ($by === 'time' && $notPad === 0) {
                $deepOpt['pagemap'] = ['lk' => $mapKey, 'hidden' => $mapHidden, 'asc' => $asc];
            } elseif (in_array($by, self::RANK_SORTS, true) && !$mapHidden && class_exists('\app\common\util\ListRank')) {
                $deepOpt['rank'] = ['sort' => $by, 'lk' => $base, 'asc' => $asc] + ($notPad > 0 ? ['pad' => $notPad] : []);
            }
        }

        // 1.2.0 (D-L12): the cache key carries the list revision (Vod::listRevision, C9) instead of Cache::tag('vod').
        $where_cache = $where;
        $where_cache['_ft'] = $ftReq;
        $where_cache['_parent'] = $listParent;
        $where_cache['_types'] = $plannerTypes;
        $where_cache['_hidden'] = $searchHidden; // hiding / showing a category changes the key at once (nothing in vod_ft changes)
        $where_cache['_after'] = $afterCur;
        $where_cache['_cursor'] = $searchCur;
        $where_cache['_up'] = $searchUp;
        if($use_rand){
            $where_cache['order'] = 'rnd';
        }
        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' .md5(\app\common\util\AvdbLanguage::cacheSuffix().'vod_listcache_scale_v2_r'.self::listRevision().'_'.json_encode($where_cache).'_'.$order.'_'.$page.'_'.$num.'_'.$start.'_'.$pageurl.'_'.$cap);
        $res = $GLOBALS['config']['app']['cache_core']==1 && !$use_rand ? Cache::get($cach_name) : null;
        if(empty($cachetime)){
            $cachetime = $GLOBALS['config']['app']['cache_time'];
        }
        if(empty($res)) {
            // round 2 (HO-H-O4, O-S1 CJK filter pages): a visitor list whose query ran into the statement limit is remembered
            // for LIST_TIMEOUT_TTL seconds (per list revision and request): the same list answers the lighter page at once
            // instead of spending the statement limit of a worker and a database thread again on every hit
            $timeoutKey = defined('ENTRANCE') && ENTRANCE === 'index' && !$use_rand ? $cach_name . '_timeout' : '';
            if ($timeoutKey !== '' && Cache::get($timeoutKey)) {
                self::visitorQueryFailed(new \PDOException('SQLSTATE[70100]: <<Unknown error>>: 1969 Query execution was interrupted (max_statement_time exceeded; remembered for this list)'));
            }
            try {
                if ($ftReq !== null) {
                    $res = $this->ftListData($ftReq, $where, $page, $num, $totalshow, $plannerTypes, $searchHidden, $cap, $listParent, $searchCur, $searchUp);
                    if (isset($ftReq['route']) && !empty($res['_route_broken'])) {
                        // round 5 (D-1 (c)): the proven route met a row the exact LIKE does not list (or the term map was not
                        // usable after all): the value is refused for FtPlan::ROUTE_TTL and this request takes the LIKE path
                        \app\common\util\FtPlan::tagRouteRefuse((string)$ftReq['route']);
                        $where['vod_tag'] = ['like', mac_like_arr((string)$ftReq['route']), 'OR'];
                        $res = $this->listDataScaled($where, $order, $page, $num, $start, '*', 1, [
                            'totalshow' => $totalshow == 1, 'cap' => (int)$tier['list_cap'], 'parent' => $listParent, 'scale_total' => null, 'after' => null,
                        ]);
                    }
                } elseif ($use_rand) {
                    $res = $this->randomList($where, $num, $listParent);
                } elseif (!empty($likeWd) && $totalshow == 1) {
                    // table not ready (upgrade / backfill running): today's one-pass search, under the statement limit and
                    // the gate; a timeout ends in the stale copy or the busy page (visitorQueryFailed), never in a retry.
                    // XVID-NOLIMIT-BRIEF L25 (kept): while the search index is not ready the old path keeps its SEARCH_ID_CAP
                    // ordered ids (50 pages of 20) and says "search index still building" ('building'); a tier cap set again
                    // still applies
                    $idPages = (int)ceil(self::SEARCH_ID_CAP / max(1, (int)$num));
                    $oldCap = $cap > 0 ? min($cap, $idPages) : $idPages;
                    $res = $page > $oldCap ? $this->emptyList($page, $num, 0)
                        : $this->searchListData($where, $order, $page, $num, $start);
                    if ($res['total'] > $oldCap * $num) {
                        $res['total_all'] = $res['total'];
                        $res['total'] = $oldCap * $num;
                        $res['pagecount'] = $oldCap;
                        if (empty($res['search_msg'])) {
                            $res['search_msg'] = 'building';
                        }
                    }
                } else {
                    $scaleTotal = ($totalshow == 1 && !$filtered) ? self::scaleTotal($listParent, $where, $hiddenTypes) : null;
                    $res = $this->listDataScaled($where, $order, $page, $num, $start, '*', 1, [
                        'totalshow' => $totalshow == 1, 'cap' => $cap, 'parent' => $listParent, 'scale_total' => $scaleTotal,
                        'after' => $afterCur,
                    ] + $deepOpt);
                }
            } catch (\Throwable $error) {
                if ($timeoutKey !== '' && class_exists('\app\common\util\DbTimeout') && \app\common\util\DbTimeout::is($error)) {
                    try {
                        Cache::set($timeoutKey, 1, self::LIST_TIMEOUT_TTL);
                    } catch (\Throwable $e) {
                        // not remembered: the next hit runs the query again
                    }
                }
                self::visitorQueryFailed($error);
            }
            if($GLOBALS['config']['app']['cache_core']==1 && !$use_rand) {
                $searchish = $ftReq !== null || !empty($likeWd);
                // round 7 (S-R7-P1): a page 1 whose plain-LIKE read ran out of time (the rows found + "Older results",
                // 'list_partial') is kept a minute only, so a later visit (its rows cached by then) can read the whole page
                Cache::set($cach_name, $res, (($searchish && empty($res['total']) && empty($res['list'])) || !empty($res['list_partial'])) ? min(60, (int)$cachetime) : $cachetime);
            }
        }
        $res['pageurl'] = $pageurl;
        $res['half'] = $half;
        if ($paging == 'yes') {
            // C20 open mode (B3 / C3): the theme's volist loop reuses $__LIST__, so the cursor keys reach the shared pager
            // through $__PAGING__: mac_page_param() merges this and clears it (hand-over HO-S-NL1, lane H, common.php).
            // Round 4 (HO-T-R4-1): the list-note keys travel the same way, so the themes' public/list_note
            // (AvdbLanguage::listNote() reads $__PAGING__ when $__LIST__ is the bare rows) also prints them on a page WITH rows
            // ("Type more letters", "About N results", "N+ videos", the 'partial' line).
            $GLOBALS['mac_list_cursor'] = array_intersect_key(is_array($res) ? $res : [],
                array_flip(['page_cursor_next', 'page_cursor_oldest', 'page_cursor_prev', 'page_cursor_on',
                    'search_msg', 'total_exact', 'total_about', 'total_more', 'total_all']));
        }

        return $res;
    }

    /** Query fields of a search URL, in the order of the page's rel=canonical (TemplateSeo). */
    const SEARCH_FIELDS = ['wd', 'class', 'area', 'lang', 'year', 'letter', 'actor', 'director', 'tag', 'by', 'order', 'state', 'version', 'level'];

    /**
     * search_len of a visitor list's filters: exactly 1.1's mac_search_len_check() (orchestrator decision 2026-10-19 05:30,
     * "per-value search_len for comma lists: prefer the 1.1 result"; import-r4 filter-like-parity-searchlen, search-r4
     * F-r4-1, merge r4 hand-over 1). Every field (wd, tag, class, actor, director, ...) is cut as ONE string at search_len
     * characters, then the list splits the comma values (mac_like_arr), so:
     *  - a comma list longer than search_len keeps its cut last value ("Performer Alpha,Performer Betamax" -> "...,Performer
     *    Betam"; an empty last part matches every row, as in 1.1: "Performer A,Performer B,Example C" -> "...,E");
     *  - a single value longer than search_len is cut, never dropped ("ABC-123 A long sample video title" lists its video,
     *    not the unfiltered catalogue).
     * The page's head and links (All::label_search() / label_type(): title, canonical, language and sort links; the landing
     * facts) cut the same request with the same function, so the list, its count, its title and every link name one value
     * and a linked page lists the same rows. Round 3's per-value rule (whole values only, at most 5) is withdrawn: it listed
     * other rows than 1.1 for long lists and the unfiltered catalogue for a single long value. A cut value is a partial
     * value: FilterMatch v2 gives it its index path (+word* of the cut last word through the vocabulary), the exact LIKE
     * decides.
     */
    public static function searchLenCheck(array $param): array
    {
        return function_exists('mac_search_len_check') ? mac_search_len_check($param) : $param;
    }

    /** The comma-list filters whose values the page's links keep as requested (searchLenParam). */
    const LIST_FILTER_FIELDS = ['actor', 'tag', 'class', 'director'];

    /**
     * The template $param of a list / search page (All::label_search(), All::label_type(): title, canonical, language,
     * sort and pager links): mac_search_len_check() for every field but the list filters actor / tag / class / director,
     * which keep the value as requested (mac_param_url() has trimmed it). The list itself cuts them with searchLenCheck()
     * (1.1's result), so every link of the page leads to a page listing the same rows: a link built from the CUT value
     * did not (search-r4 F-r4-1: the two-name actor page linked its canonical, 17 language links and 4 sort links to
     * "...,Haleylee Ixmel"; round 4: a cut that ends on a space, "...,Koji ", lost that space through mac_param_url()'s
     * trim on the next request, so the canonical 301 listed 62 rows instead of 56).
     */
    public static function searchLenParam(array $param): array
    {
        $keep = [];
        foreach (self::LIST_FILTER_FIELDS as $f) {
            if (array_key_exists($f, $param)) {
                $keep[$f] = $param[$f];
            }
        }
        $param = self::searchLenCheck($param);
        foreach ($keep as $f => $v) {
            $param[$f] = $v;
        }
        return $param;
    }

    /**
     * L3 normalized search word (se-R4a, se-R13a): entities decoded, NFC (when intl is there), lower case, single spaces,
     * cut in bytes to the search length (D-S2, FtText::queryBytes) and to the words that make the first 5 token groups.
     */
    public static function normalizeSearchWord($wd): string
    {
        // entities decoded twice, as the normalizer does (D-S2): "&amp;#110;urse" and "&#110;urse" are both "nurse"
        $wd = html_entity_decode(html_entity_decode(is_scalar($wd) ? (string)$wd : '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('//u', $wd)) {
            $wd = function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($wd, 'UTF-8', 'UTF-8') : '';
        }
        if (class_exists('Normalizer')) {
            $n = \Normalizer::normalize($wd, \Normalizer::FORM_C);
            $wd = is_string($n) ? $n : $wd;
        }
        $wd = trim((string)preg_replace('/[\s\x{00A0}\x{3000}\x{200B}]+/u', ' ', mb_strtolower($wd, 'UTF-8')));
        $wd = trim(\app\common\util\FtText::cutBytes($wd, \app\common\util\FtText::queryBytes()));
        $words = $wd === '' ? [] : explode(' ', $wd);
        $keep = [];
        $have = 0;
        foreach ($words as $w) {
            $try = array_merge($keep, [$w]);
            $now = count(\app\common\util\FtText::query(implode(' ', $try))['groups']);
            if ($now === $have && $have >= \app\common\util\FtText::MAX_GROUPS && \app\common\util\FtText::query($w)['groups']) {
                break; // this word would be dropped by the 5-group cap (D-S2): the canonical URL ends before it
            }
            $keep = $try;
            $have = $now;
        }
        return implode(' ', $keep);
    }

    /**
     * L3 canonical URL (site-relative) of a keyword search: the search path, the known fields in the canonical order with
     * the normalized word, page when > 1 ($page = 'PAGELINK' for the pager), ui_lang when it is an offered language.
     * '' when the request has no keyword (filter-only or tag / actor landing pages keep their own canonical).
     */
    public static function searchCanonical(array $param, $page = null): string
    {
        $wd = self::normalizeSearchWord($param['wd'] ?? '');
        if ($wd === '') {
            return '';
        }
        $parts = parse_url((string)mac_url('vod/search'));
        $path = is_array($parts) && isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        if (substr($path, 0, 1) !== '/' || substr($path, 0, 2) === '//') {
            return '';
        }
        $q = [];
        foreach (self::SEARCH_FIELDS as $f) {
            if ($f === 'wd') {
                $q['wd'] = $wd;
                continue;
            }
            $v = $param[$f] ?? '';
            if (is_scalar($v)) {
                $v = trim(html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($v !== '' && $v !== '0') {
                    $q[$f] = $v;
                }
            }
        }
        if ($page === null) {
            $page = isset($param['page']) && is_scalar($param['page']) && ctype_digit((string)$param['page']) ? (int)$param['page'] : 1;
        }
        if ($page === 'PAGELINK' || (is_int($page) && $page > 1)) {
            $q['page'] = $page;
        }
        $lang = $param['ui_lang'] ?? ($_GET['ui_lang'] ?? '');
        if (is_string($lang) && $lang !== '' && class_exists('\app\common\util\TemplateText')
            && array_key_exists($lang, \app\common\util\TemplateText::languages())) {
            $q['ui_lang'] = $lang;
        }
        // XVID-NOLIMIT-BRIEF C4: a continuation page keeps its cursor (^[1-9][0-9]{0,17}$), last, as the pager's link puts it;
        // never on the pager pattern itself
        if ($page !== 'PAGELINK') {
            $cur = $param['cursor'] ?? ($_GET['cursor'] ?? '');
            if (is_string($cur) && preg_match('/^[1-9][0-9]{0,17}$/D', $cur)) {
                unset($q['page']);
                $q['cursor'] = $cur;
            }
        }
        return $path . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /** D-L6: sorts a visitor may ask for in the URL (rnd only from templates). */
    const BY_URL = ['time', 'time_add', 'hits', 'hits_day', 'hits_week', 'hits_month', 'score', 'up'];
    /** B2: sorts served by the rank files (ListRank::SORTS). */
    const RANK_SORTS = ['hits', 'hits_day', 'hits_week', 'hits_month', 'score', 'up', 'time_add'];
    /** Round-4 HO-T-R3-1: a not= list of at most this many ids keeps the rank file of an unfiltered list's page 1. */
    const NOT_PAD_MAX = 50;

    /**
     * The ids of a template's not= value when it is a comma list of 1..NOT_PAD_MAX positive integers (duplicates counted
     * once), else null (a longer or malformed list stays a filter: the live query).
     */
    public static function smallIdList($value): ?array
    {
        if (!is_scalar($value) || trim((string)$value) === '') {
            return null;
        }
        $out = [];
        foreach (explode(',', (string)$value) as $one) {
            $one = trim($one);
            if ($one === '') {
                continue;
            }
            if (!ctype_digit($one) || strlen($one) > 10 || (int)$one < 1) {
                return null;
            }
            $out[(int)$one] = true;
            if (count($out) > self::NOT_PAD_MAX) {
                return null;
            }
        }
        return $out ? array_keys($out) : null;
    }
    /** D-L4: bounded count of filtered lists ("10,000+" above it). */
    const COUNT_BOUND = 10000;
    /** D-L5: pages beyond this use the deferred join (ids first through the list index, rows by primary key); verify fix
     * F2 / G4: from page 2 (OFFSET over full rows read 949-1,162 pages at page 50 of a 147-934 k category, the deferred
     * join 85-100 at page 100). */
    const DEFER_AFTER_PAGE = 1;

    /**
     * Tier limits (C3). XVID-NOLIMIT-BRIEF S1 / C3 note: a cap of 0 means "no cap" (H1 sets every tier to 0), never "use a
     * default"; ScaleTier missing or failing = no caps (the last page comes from the list total, list_page_last()).
     */
    public static function tierInfo(): array
    {
        $d = ['tier' => 1, 'list_cap' => 0, 'by_cap' => 0, 'search_cap' => 0];
        try {
            if (class_exists('\app\common\util\ScaleTier')) {
                $t = \app\common\util\ScaleTier::get();
                foreach ($d as $k => $v) {
                    if (isset($t[$k])) { $d[$k] = max($k === 'tier' ? 1 : 0, (int)$t[$k]); }
                }
            }
        } catch (\Throwable $e) {
        }
        return $d;
    }

    /** Page cap of a visitor list sorted by $by, or of the search: 0 = none (S1); a tier cap set again still applies. */
    public static function pageCap(string $kind, string $by = 'time'): int
    {
        $t = self::tierInfo();
        if ($kind === 'search') {
            return $t['search_cap'];
        }
        return in_array($by, ['', 'time', 'time_add'], true) ? $t['list_cap'] : $t['by_cap'];
    }

    /** B3: integer sort columns a list can continue on with after=<value>.<vod_id> (vod_score is decimal: numbered pages only). */
    const KEYSET_COLS = ['vod_time', 'vod_id', 'vod_time_add', 'vod_hits', 'vod_hits_day', 'vod_hits_week', 'vod_hits_month', 'vod_up'];
    /** B3: index rows one continuation page may pass (the stop point is that far along the list index). */
    const ROW_BUDGET = 200000;
    /** B2: without a usable rank file, deep pages of a sorted list above this many rows answer busy until the first build. */
    const RANK_LIVE_MAX = 100000;

    /** The single category of a list condition (['eq', X], or ['eq', X] combined with the hidden filter), else 0. */
    private static function typeEqOf(array $where): int
    {
        $c = $where['type_id'] ?? null;
        if (is_array($c) && ($c[0] ?? '') === 'eq' && !is_array($c[1] ?? null)) {
            return (int)$c[1];
        }
        if (is_array($c) && isset($c[0]) && is_array($c[0]) && ($c[0][0] ?? '') === 'eq' && !is_array($c[0][1] ?? null)) {
            return (int)$c[0][1];
        }
        return 0;
    }

    /**
     * D-S5 ladder: 'ft' (table ready, FULLTEXT), 'walk' (table ready without FULLTEXT) or 'old' (today's path: table missing
     * or not filled yet). Read from vod_ft_state, never from an error of a search query.
     */
    public static function ftSearchMode(): string
    {
        try {
            if (!class_exists('\app\common\util\FtIndex') || !\app\common\util\FtIndex::ready()) {
                return 'old';
            }
            return (string)(\app\common\util\FtIndex::state()['mode'] ?? 'ft') === 'walk' ? 'walk' : 'ft';
        } catch (\Throwable $e) {
            return 'old';
        }
    }

    /**
     * Category ids whose videos a visitor search must not return (sc-S14, applied at query time, never written into the
     * search table): hidden categories (type_status 0) and the children of hidden parents, plus the group's popedom filter
     * when it is on. From the category cache: hiding or showing a category writes nothing to vod_ft.
     */
    public static function hiddenTypeIds(): array
    {
        $out = [];
        $list = model('Type')->getCache('type_list');
        $list = is_array($list) ? $list : [];
        foreach ($list as $t) {
            if (isset($t['type_status']) && (int)$t['type_status'] !== 1) {
                $out[(int)$t['type_id']] = true;
            }
        }
        foreach ($list as $t) {
            if (isset($out[(int)($t['type_pid'] ?? 0)])) {
                $out[(int)$t['type_id']] = true;
            }
        }
        if (defined('ENTRANCE') && ENTRANCE === 'index' && (int)($GLOBALS['config']['app']['popedom_filter'] ?? 0) === 1
            && isset($GLOBALS['user']['group']['group_type'])) {
            foreach (explode(',', (string)mac_get_popedom_filter($GLOBALS['user']['group']['group_type'])) as $id) {
                if ((int)$id > 0) {
                    $out[(int)$id] = true;
                }
            }
        }
        unset($out[0]);
        return array_keys($out);
    }

    /**
     * fp-port: true when a template block with paging off (D-L8) should use the field token of this tag / actor value:
     * only when FilterMatch cannot narrow the exact LIKE (no pre-filter: a non-ASCII value, a dense value over the row
     * guard, a comma list with such a value, the vocabulary not usable right now). Otherwise the block runs the exact LIKE
     * with the pre-filter, like every visitor list. $col: vod_tag or vod_actor; $value: the comma list mac_like_arr() gets.
     */
    private static function ftBlockTokens(string $col, $value): bool
    {
        if (!is_scalar($value) || (string)$value === '') {
            return false;
        }
        try {
            // fp-port-b: a CJK whole item gets the exact item path too (FilterMatch::items; the block lists visible rows)
            return !class_exists('\app\common\util\FilterMatch') || (\app\common\util\FilterMatch::prefilter($col, (string)$value) === ''
                && (!method_exists('\app\common\util\FilterMatch', 'items') || \app\common\util\FilterMatch::items($col, (string)$value) === ''));
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Round 5 (owner decision D-1 (c)): the field token a visitor tag list pages through (the term map), or null. The list
     * must be exactly "published videos tagged <value>, newest first": no other filter or category (the term map has no
     * category path), no template offset, by=time desc, no continuation cursor, no hidden category for this visitor, the
     * value as requested (a value cut at search_len is a partial value: LIKE path), the same page cap for lists and
     * searches; FtPlan::tagRoute() proves the value itself (rows and order equal to the plain LIKE's).
     */
    private static function tagRouteFor(string $tag, array $where, int $start, string $by, string $order, array $others): ?string
    {
        if (!defined('ENTRANCE') || ENTRANCE !== 'index' || !class_exists('\app\common\util\FtPlan') || !method_exists('\app\common\util\FtPlan', 'tagRoute')) {
            return null;
        }
        if (array_keys($where) !== ['vod_status'] || $start !== 0 || !in_array($by, ['', 'time'], true) || !in_array($order, ['', 'desc'], true)) {
            return null;
        }
        list($name, $class, $director, $wd, $plot) = array_pad($others, 5, null);
        if (!empty($name) || !empty($class) || !empty($director) || !empty($wd) || in_array($plot, ['0', '1'], true)) {
            return null;
        }
        if (isset($_GET['after']) || isset($_GET['cursor'])) {
            return null;
        }
        // the plain LIKE tag list filters only the visitor group's popedom categories (type_id NOT IN, added below the route
        // decision): such a list keeps the LIKE path; categories hidden by type_status are listed by both paths alike
        if ((int)($GLOBALS['config']['app']['popedom_filter'] ?? 0) === 1 && isset($GLOBALS['user']['group']['group_type'])
            && function_exists('mac_get_popedom_filter') && trim((string)mac_get_popedom_filter($GLOBALS['user']['group']['group_type'])) !== '') {
            return null;
        }
        $req = function_exists('mac_param_url') ? mac_param_url() : [];
        if (!isset($req['tag']) || !is_scalar($req['tag']) || (string)$req['tag'] !== $tag) {
            return null;
        }
        $t = self::tierInfo();
        if ((int)$t['search_cap'] !== (int)$t['list_cap']) {
            return null;
        }
        return \app\common\util\FtPlan::tagRoute($tag);
    }

    /** Field tokens (tg_ / ac_) of a comma list of tag / actor values, at most 5 (D-S8). */
    private static function fieldTokens(string $prefix, $values): array
    {
        $out = [];
        foreach (\app\common\util\FtText::splitList(is_scalar($values) ? html_entity_decode((string)$values, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '') as $v) {
            $t = \app\common\util\FtText::fieldToken($prefix, $v);
            if ($t !== '' && preg_match('/^(tg|ac)_[a-z0-9_]{1,81}$/D', $t)) {
                $out[$t] = true;
            }
            if (count($out) >= 5) {
                break;
            }
        }
        return array_keys($out);
    }

    /** Children ids of a category (type cache). */
    private static function childIds(int $typeId): array
    {
        $out = [];
        $list = model('Type')->getCache('type_list');
        foreach (is_array($list) ? $list : [] as $t) {
            if ((int)($t['type_pid'] ?? 0) === $typeId && $typeId > 0) {
                $out[] = (int)$t['type_id'];
            }
        }
        return $out;
    }

    /**
     * Shape of an expanded category list: ['parent', P] when it is exactly a top-level category plus all of its children,
     * ['one', id] for a single category, ['list', 0] otherwise.
     */
    private static function typeShape(array $ids, array $typeList): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach ($ids as $id) {
            if (isset($typeList[$id]) && (int)$typeList[$id]['type_pid'] === 0) {
                $family = [$id];
                foreach ($typeList as $t) {
                    if ((int)$t['type_pid'] === $id) { $family[] = (int)$t['type_id']; }
                }
                sort($family);
                $sorted = $ids;
                sort($sorted);
                if ($sorted === $family) {
                    return ['parent', $id];
                }
                break;
            }
        }
        return count($ids) === 1 ? ['one', $ids[0]] : ['list', 0];
    }

    /**
     * D-L4: precomputed total (ScaleCounts, C4) of an unfiltered list: all videos, one category, or a parent with its
     * children; hidden categories of the visitor are subtracted. Null = unknown (the caller uses the bounded count).
     */
    private static function scaleTotal(int $parent, array $where, array $hidden): ?int
    {
        if (!class_exists('\app\common\util\ScaleCounts')) {
            return null;
        }
        try {
            $types = model('Type')->getCache('type_list');
            $types = is_array($types) ? $types : [];
            $one = 0;
            if (isset($where['type_id'])) {
                $c = $where['type_id'];
                if (is_array($c) && isset($c[0]) && is_array($c[0]) && ($c[0][0] ?? '') === 'eq') {
                    $one = (int)$c[0][1]; // [eq X] combined with the hidden filter
                } elseif (is_array($c) && ($c[0] ?? '') === 'eq') {
                    $one = (int)$c[1];
                } elseif (!(is_array($c) && ($c[0] ?? '') === 'not in')) {
                    return null;
                }
            }
            $hiddenSet = array_flip($hidden);
            if ($one > 0) {
                return isset($hiddenSet[$one]) ? 0 : \app\common\util\ScaleCounts::type($one);
            }
            $total = $parent > 0 ? \app\common\util\ScaleCounts::type($parent) : \app\common\util\ScaleCounts::all();
            if ($total === null) {
                return null;
            }
            foreach ($hidden as $h) {
                $pid = isset($types[$h]) ? (int)$types[$h]['type_pid'] : -1;
                if ($pid === -1 || isset($hiddenSet[$pid])) {
                    continue; // unknown category, or already subtracted through its hidden parent
                }
                if ($parent > 0 && $h !== $parent && $pid !== $parent) {
                    continue;
                }
                $n = \app\common\util\ScaleCounts::type($h);
                if ($n === null) {
                    return null;
                }
                $total -= $n;
            }
            return max(0, (int)$total);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function emptyList($page, $limit, $total)
    {
        $limit = max(1, (int)$limit);
        return ['code' => 1, 'msg' => lang('data_list'), 'page' => max(1, (int)$page), 'pagecount' => (int)ceil($total / $limit), 'limit' => $limit,
            'total' => (int)$total, 'list' => []];
    }

    /** Category / group data of list rows (as listData does). */
    private function addRowInfo(array $list)
    {
        $type_list = model('Type')->getCache('type_list');
        $group_list = model('Group')->getCache('group_list');
        foreach ($list as $k => $v) {
            if (!empty($v['type_id']) && isset($type_list[$v['type_id']])) {
                $list[$k]['type'] = $type_list[$v['type_id']];
                $list[$k]['type_1'] = $type_list[$list[$k]['type']['type_pid']] ?? [];
            }
            if (!empty($v['group_id']) && isset($group_list[$v['group_id']])) {
                $list[$k]['group'] = $group_list[$v['group_id']];
            }
        }
        return $list;
    }

    /** Rows of these ids (primary key), filtered by $where, in the order of $ids. */
    private function rowsByIds(array $ids, array $where, $where2 = '', $field = '*')
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $byId = [];
        foreach (Db::name('Vod')->field($field)->where($where)->where($where2)->where('vod_id IN (' . implode(',', $ids) . ')')->select() as $row) {
            $byId[(int)$row['vod_id']] = $row;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        return $out;
    }

    /**
     * Search / tag / actor list through the planner (C2, D-S4, D-S8): newest first; the pager counts found ids ('total'),
     * 'total_about' is the planner's total ("about N" on the walk path), 'search_msg' its machine message.
     * XVID-NOLIMIT-BRIEF C2-C4: no page cap (a tier cap set again still applies); single tokens page through the term map
     * (exact total, numbered to the last page); other queries are numbered up to FtPlan::NUMBERED_FIRST pages and continue
     * with cursors: page_cursor_next (the signed cursor the "Older results" page continues below), page_cursor_oldest ('1' =
     * the "Oldest results" page), page_cursor_prev (round 2: the signed up-cursor of the "Newer results" link of an oldest
     * page), page_cursor_on (this is a cursor page: no numbers). $cursor = the decoded cursor= of the request (a doc id, 1 =
     * oldest), $up = the decoded up-cursor (the oldest-first walk continues above that doc).
     */
    private function ftListData(array $req, array $where, $page, $num, $totalshow, array $types, array $hidden, int $cap, int $parent, int $cursor = 0, int $up = 0)
    {
        $page = max(1, (int)$page);
        $num = max(1, min(\app\common\util\FtPlan::PER_MAX, (int)$num));
        $where = $this->mergeRecycleWhere($where);
        $where2 = '';
        if (!empty($where['_string'])) {
            $where2 = $where['_string'];
            unset($where['_string']);
        }
        if ($parent > 0) {
            $types = $types ?: [$parent];
        }
        $opt = ['type_ids' => $types, 'page' => $page, 'per' => $num, 'hidden' => $hidden, 'cursor' => $cursor] + ($up > 0 ? ['up' => $up] : []);
        if ($cap > 0 && $page > $cap) {
            $res = $this->emptyList($page, $num, 0);
            $res['search_msg'] = 'page_range';
            return $res;
        }
        if (isset($req['wd'])) {
            $plan = \app\common\util\FtPlan::search((string)$req['wd'], $opt);
        } elseif (count($req['tokens']) === 1) {
            // round 6 (D-9): a routed tag list pages in (vod_time, vod_id) order whatever documents were written out of it
            $plan = \app\common\util\FtPlan::search('', $opt + ['token' => $req['tokens'][0]] + (!empty($req['window']) ? ['window' => (int)$req['window']] : [])
                + (isset($req['route']) ? ['order_time' => true] : []));
        } else {
            $plan = $this->ftTokenUnion($req['tokens'], $where, $where2, $page, $num, $types, $hidden);
        }
        if (isset($req['route'])) {
            // round 5 (D-1 (c)): a proven tag list reads its page's rows with the exact LIKE all the same; a row it does not find
            // (or another planner path than the term map) tells the caller to answer this request through the LIKE path
            $routeWhere = $where;
            $routeWhere['vod_tag'] = ['like', mac_like_arr((string)$req['route']), 'OR'];
            $ids = array_values(array_filter(array_map('intval', (array)($plan['ids'] ?? []))));
            $rows = $this->rowsByIds($ids, $routeWhere, $where2);
            if (($plan['path'] ?? '') !== 'map' || empty($plan['exact']) || count($rows) !== count($ids)) {
                return ['_route_broken' => 1, 'list' => [], 'total' => 0];
            }
            $plan['rows'] = $rows;
        }
        $list = $this->addRowInfo(isset($plan['rows']) ? $plan['rows'] : $this->rowsByIds($plan['ids'], $where, $where2));
        $found = (int)($plan['found'] ?? count($plan['ids']));
        if ($cursor > 0 || $up > 0) {
            $found = count($plan['ids']); // a cursor page is page 1 of an open pager
            $page = 1;
        } elseif (($plan['path'] ?? '') === 'walk') {
            $found = min($found, \app\common\util\FtPlan::NUMBERED_FIRST * $num);
        }
        if ($cap > 0) {
            $found = min($found, $cap * $num);
        }
        if ($totalshow != 1) {
            $found = count($list);
        }
        $res = ['code' => 1, 'msg' => lang('data_list'), 'page' => $page, 'pagecount' => (int)ceil($found / $num), 'limit' => $num,
            'total' => $found, 'list' => $list, 'total_about' => max($found, (int)$plan['total']), 'total_exact' => !empty($plan['exact']),
            'search_msg' => (string)$plan['msg'], 'search_path' => (string)$plan['path']];
        // round 2 (N10): the continuation values are signed for this search (cursorEncode), so only issued cursors render
        $scope = function_exists('mac_param_url') ? mac_param_url() : [];
        if (!empty($plan['next']) && (int)$plan['next'] > 1 && ($c = self::cursorEncode((int)$plan['next'], 'd', $scope)) !== '') {
            $res['page_cursor_next'] = $c;
            $res['page_cursor_oldest'] = '1';
        }
        if (!empty($plan['prev']) && (int)$plan['prev'] > 1 && ($c = self::cursorEncode((int)$plan['prev'], 'u', $scope)) !== '') {
            $res['page_cursor_prev'] = $c;
        }
        if ($cursor > 0 || $up > 0) {
            $res['page_cursor_on'] = 1;
        }
        return $res;
    }

    /** Round-4 F-r4-2: pages of FtPlan::PER_MAX ids read per token for one union page, at most. */
    const TOKEN_UNION_PAGES = 5;

    /**
     * Several tag / actor field tokens in one list (round-4 search-r4 F-r4-2). Since the fp-port only D-L8 template blocks
     * with paging off take field tokens (values FilterMatch cannot narrow: non-ASCII, dense, vocabulary not usable); a
     * visitor list of several values is a LIKE filter with the FilterMatch pre-filter, whose pages are the plain LIKE's.
     * The union here is in the list's order (vod_time DESC, vod_id DESC = the plain LIKE's newest first). Round 3 sorted it
     * by vod_id (not the list's order) after taking each token's first min(100, page x per) ids, so pages overlapped,
     * skipped and repeated (search-r4: pages 2-5 identical, page 6 = page 4). Now each token contributes its newest
     * page x per ids (doc order, up to TOKEN_UNION_PAGES x PER_MAX), the rows of the union are read once by primary key
     * (the list's other filters apply), sorted by (vod_time, vod_id) descending, and page $page is sliced out: no row on two
     * pages, the newest first. Exact while every token's newest documents are its newest videos (documents are built in
     * vod_time order; a re-timed video gets a new document); the block's best effort beyond. 'found' = union rows read.
     */
    private function ftTokenUnion(array $tokens, array $where, $where2, int $page, int $num, array $types, array $hidden): array
    {
        $page = max(1, $page);
        $num = max(1, $num);
        $want = $page * $num;
        $per = max(1, min(\app\common\util\FtPlan::PER_MAX, $want));
        $cand = [];
        $path = 'none';
        $more = false;
        foreach (array_values(array_unique(array_map('strval', $tokens))) as $t) {
            $got = 0;
            for ($tp = 1; $tp <= self::TOKEN_UNION_PAGES && $got < $want; $tp++) {
                $p = \app\common\util\FtPlan::search('', ['type_ids' => $types, 'page' => $tp, 'per' => $per, 'hidden' => $hidden, 'token' => $t]);
                $path = ($p['path'] ?? 'none') !== 'none' ? (string)$p['path'] : $path;
                $ids = array_values(array_filter(array_map('intval', (array)($p['ids'] ?? []))));
                foreach ($ids as $id) {
                    $cand[$id] = true;
                }
                $got += count($ids);
                if (count($ids) < $per) {
                    break; // this token has no more documents
                }
            }
            if ($got >= $want) {
                $more = true; // the token may hold more rows than were read
            }
        }
        $rows = $cand ? $this->rowsByIds(array_keys($cand), $where, $where2) : [];
        usort($rows, function ($a, $b) {
            return [(int)($b['vod_time'] ?? 0), (int)($b['vod_id'] ?? 0)] <=> [(int)($a['vod_time'] ?? 0), (int)($a['vod_id'] ?? 0)];
        });
        $slice = array_slice($rows, ($page - 1) * $num, $num);
        return ['ids' => array_map(function ($r) { return (int)$r['vod_id']; }, $slice), 'rows' => $slice, 'total' => count($rows),
            'found' => count($rows), 'exact' => !$more, 'path' => $path, 'msg' => ''];
    }

    // ------------------------------------------------------------------ round-2: signed search cursors, whole items

    /** cursor= value = doc x CURSOR_MUL + a 6-digit signature (the ^[1-9][0-9]{0,17}$ shape every layer validates). */
    const CURSOR_MUL = 1000000;

    /**
     * Scope of a search cursor: the normalized search of the URL (word, the SEARCH_FIELDS filters, the category of a
     * category-restricted search); a cursor never moves to another search. Round-3 verify N10: the category of a
     * /category/<slug>/ or /show/<slug>/ search is its type id resolved from the slug ('type'), so a cursor signed for
     * /search/?wd=x is foreign to /category/<slug>/wd/x/ and the other way round.
     */
    public static function cursorScope(array $param): string
    {
        $parts = ['wd' => self::normalizeSearchWord($param['wd'] ?? '')];
        foreach (self::SEARCH_FIELDS as $f) {
            if ($f === 'wd') {
                continue;
            }
            $v = $param[$f] ?? '';
            if (is_scalar($v)) {
                $v = trim(mb_strtolower(html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8'));
                if ($v !== '' && $v !== '0') {
                    $parts[$f] = $v;
                }
            }
        }
        foreach (['id', 'tid'] as $f) {
            if (isset($param[$f]) && is_scalar($param[$f]) && (int)$param[$f] > 0) {
                $parts[$f] = (int)$param[$f];
            }
        }
        if (isset($param['id']) && is_scalar($param['id']) && (string)$param['id'] !== '' && !ctype_digit((string)$param['id'])) {
            $type = self::typeOfParam($param);
            $parts['type'] = $type !== null ? (int)$type['type_id'] : -1;
        }
        return (string)json_encode($parts);
    }

    /**
     * The video category of a list request's id (the type slug of /category/<slug>/ and /show/<slug>/, or a numeric
     * type id) from the category cache; null when unknown. Memoized per request.
     */
    public static function typeOfParam(array $param): ?array
    {
        static $memo = [];
        $id = isset($param['id']) && is_scalar($param['id']) ? html_entity_decode(trim((string)$param['id']), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        if ($id === '') {
            return null;
        }
        if (array_key_exists($id, $memo)) {
            return $memo[$id];
        }
        $found = null;
        try {
            $list = model('Type')->getCache('type_list');
            foreach (is_array($list) ? $list : [] as $t) {
                if ((int)($t['type_mid'] ?? 1) !== 1) {
                    continue;
                }
                if ((ctype_digit($id) && (int)$t['type_id'] === (int)$id) || (string)($t['type_en'] ?? '') === $id) {
                    $found = $t;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $found = null;
        }
        return $memo[$id] = $found;
    }

    /**
     * Round-3 verify N10 (category-restricted searches): the address of a keyword search inside a category (or show) list:
     * the list's own page-1 path (/category/<slug>/, /show/<slug>/) + wd/<normalized word>/ (the query form ?wd= when the
     * word holds "/" or "\": Apache refuses %2F / %5C in a path, or when the path has a suffix), the other search fields
     * as query parameters in the canonical order, page last ($page > 1, or 'PAGELINK' for the pager pattern). Its first
     * page is the 301 target of a cursor the site did not issue and the self-canonical of the search's pages. '' without
     * a word or a category.
     */
    public static function categorySearchUrl(array $type, string $flag, array $param, $page = 1): string
    {
        $wd = self::normalizeSearchWord($param['wd'] ?? '');
        if ($wd === '' || empty($type['type_id']) || !function_exists('mac_url_type')) {
            return '';
        }
        $base = (string)mac_url_type($type, ['page' => 1], $flag === 'show' ? 'show' : 'type');
        $parts = parse_url($base);
        $path = is_array($parts) && isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '';
        if ($path === '' || $path[0] !== '/' || substr($path, 0, 2) === '//') {
            return '';
        }
        $pathForm = substr($path, -1) === '/' && strpbrk($wd, "/\\?#%") === false;
        $q = [];
        if (!$pathForm) {
            $q['wd'] = $wd;
        }
        foreach (self::SEARCH_FIELDS as $f) {
            if ($f === 'wd') {
                continue;
            }
            $v = $param[$f] ?? '';
            if (is_scalar($v)) {
                $v = trim(html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($v !== '' && $v !== '0') {
                    $q[$f] = $v;
                }
            }
        }
        if ($page === 'PAGELINK' || (is_int($page) && $page > 1)) {
            $q['page'] = $page;
        }
        $url = $pathForm ? $path . 'wd/' . rawurlencode($wd) . '/' : $path;
        return $q ? $url . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : $url;
    }

    /** Signing key: derived from this install's private database settings (as ThemePreview), never sent anywhere. */
    private static function cursorKey(): string
    {
        static $k = null;
        if ($k === null) {
            $file = defined('APP_PATH') ? APP_PATH . 'database.php' : '';
            $k = hash('sha256', 'javcms-search-cursor-v1|' . ($file !== '' && is_file($file) ? (string)@file_get_contents($file) : '')
                . '|' . (string)($GLOBALS['config']['app']['cache_flag'] ?? ''));
        }
        return $k;
    }

    private static function cursorSig(string $scope, string $dir, int $doc): int
    {
        return (int)(hexdec(substr(hash_hmac('sha256', $scope . '|' . $dir . '|' . $doc, self::cursorKey()), 0, 8)) % self::CURSOR_MUL);
    }

    /** Signed cursor value for doc $doc, direction 'd' (Older results: below the doc) or 'u' (Newer results of an oldest page). */
    public static function cursorEncode(int $doc, string $dir, array $param): string
    {
        if ($doc < 2 || $doc > 999999999999) {
            return '';
        }
        $dir = $dir === 'u' ? 'u' : 'd';
        return (string)($doc * self::CURSOR_MUL + self::cursorSig(self::cursorScope($param), $dir, $doc));
    }

    /**
     * ['o', 1] for '1' (Oldest results), ['d'|'u', doc] for a value this site signed for this search, null for anything else
     * (bad shape, random, another search's or another site's cursor).
     */
    public static function cursorDecode($raw, array $param): ?array
    {
        if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,17}$/D', $raw)) {
            return null;
        }
        if ($raw === '1') {
            return ['o', 1];
        }
        $v = (int)$raw;
        $doc = intdiv($v, self::CURSOR_MUL);
        if ($doc < 2) {
            return null;
        }
        $sig = (string)($v % self::CURSOR_MUL);
        $scope = self::cursorScope($param);
        foreach (['d', 'u'] as $dir) {
            if (hash_equals((string)self::cursorSig($scope, $dir, $doc), $sig)) {
                return [$dir, $doc];
            }
        }
        return null;
    }

    /**
     * Template lists with by="rnd" (D-L6): up to $num random rows picked by random id positions in SQL
     * ("vod_id >= r ORDER BY vod_id LIMIT 1", r drawn between the lowest and highest id), never an id array in PHP.
     */
    private function randomList(array $where, $num, int $parent)
    {
        $num = max(1, min(100, (int)$num));
        $where = $this->mergeRecycleWhere($where);
        $where2 = '';
        if (!empty($where['_string'])) {
            $where2 = $where['_string'];
            unset($where['_string']);
        }
        $picked = [];
        $typeEq = isset($where['type_id']) && is_array($where['type_id']) && ($where['type_id'][0] ?? '') === 'eq' && !is_array($where['type_id'][1] ?? null)
            ? (int)$where['type_id'][1] : 0;
        if ($parent > 0 || $typeEq > 0) {
            // one category: random vod_time positions on its D-L1 index range (type_id_1 = P / type_id = X, status, recycle,
            // vod_time); every probe is one index seek. Random id positions over the whole table missed a category that lives
            // in one id region and then walked the primary key to its end (step 3: 132 s at 7.1 M for an AVDB category).
            $cols = $parent > 0 ? (self::parentHasOwnRows($parent) ? ['type_id_1', 'type_id'] : ['type_id_1']) : ['type_id'];
            $val = $parent > 0 ? $parent : $typeEq;
            foreach ($cols as $ci => $col) {
                $ends = self::timeEnds($col, $val, $where, $where2);
                if ($ends === null) {
                    continue;
                }
                $tries = (int)ceil($num * 3 / count($cols));
                for ($i = 0; $i < $tries && count($picked) < $num; $i++) {
                    $q = Db::name('Vod')->field('vod_id')->where($where)->where($where2)->where($col, $val)->where('vod_time', '>=', mt_rand($ends[0], $ends[1]));
                    $id = (int)$q->order('vod_time asc')->limit(1)->value('vod_id');
                    if ($id > 0) {
                        $picked[$id] = true;
                    }
                }
            }
            $list = $this->addRowInfo($this->rowsByIds(array_keys($picked), $where, $where2));
            shuffle($list);
            return ['code' => 1, 'msg' => lang('data_list'), 'page' => 1, 'pagecount' => 1, 'limit' => $num, 'total' => count($list), 'list' => $list];
        }
        $ends = self::idEnds();
        if ($ends[1] > 0) {
            for ($i = 0; $i < $num * 3 && count($picked) < $num; $i++) {
                $r = mt_rand($ends[0], $ends[1]);
                $q = Db::name('Vod')->field('vod_id')->where($where)->where($where2)->where('vod_id', '>=', $r);
                if ($parent > 0) {
                    $q->where('type_id_1|type_id', $parent);
                }
                $id = (int)$q->order('vod_id asc')->limit(1)->value('vod_id');
                if ($id > 0) {
                    $picked[$id] = true;
                }
            }
        }
        $list = $this->addRowInfo($this->rowsByIds(array_keys($picked), $where, $where2));
        shuffle($list);
        return ['code' => 1, 'msg' => lang('data_list'), 'page' => 1, 'pagecount' => 1, 'limit' => $num, 'total' => count($list), 'list' => $list];
    }

    /**
     * Oldest and newest vod_time of one category range ($col = $val plus the list filters), read as two index seeks
     * (ORDER BY vod_time LIMIT 1 on the D-L1 index), cached 10 minutes per list revision; null = empty category.
     */
    private static function timeEnds(string $col, int $val, array $where, $where2): ?array
    {
        $key = ($GLOBALS['config']['app']['cache_flag'] ?? 'mac') . '_vod_time_ends_v1_' . md5($col . '|' . $val . '|' . json_encode($where) . '|' . $where2 . '|' . self::listRevision());
        $hit = Cache::get($key);
        if (is_array($hit) && count($hit) === 2) {
            return $hit[1] >= $hit[0] && $hit[1] > 0 ? $hit : null;
        }
        $lo = (int)Db::name('Vod')->where($where)->where($where2)->where($col, $val)->order('vod_time asc')->limit(1)->value('vod_time');
        $hi = (int)Db::name('Vod')->where($where)->where($where2)->where($col, $val)->order('vod_time desc')->limit(1)->value('vod_time');
        Cache::set($key, [$lo, $hi], 600);
        return $hi >= $lo && $hi > 0 ? [$lo, $hi] : null;
    }

    /** Lowest and highest vod id (primary-key reads, cached 10 minutes; no MIN/MAX aggregate in visitor requests). */
    private static function idEnds(): array
    {
        $key = ($GLOBALS['config']['app']['cache_flag'] ?? 'mac') . '_vod_id_ends_v1';
        $hit = Cache::get($key);
        if (is_array($hit) && count($hit) === 2) {
            return $hit;
        }
        $lo = (int)Db::name('Vod')->order('vod_id asc')->limit(1)->value('vod_id');
        $hi = (int)Db::name('Vod')->order('vod_id desc')->limit(1)->value('vod_id');
        Cache::set($key, [$lo, $hi], 600);
        return [$lo, $hi];
    }

    /** True when the parent category holds published rows of its own (cached 10 minutes per list revision). */
    private static function parentHasOwnRows(int $parent): bool
    {
        $key = ($GLOBALS['config']['app']['cache_flag'] ?? 'mac') . '_vod_parent_own_v1_' . $parent . '_' . self::listRevision();
        $hit = Cache::get($key);
        if ($hit === 0 || $hit === 1) {
            return $hit === 1;
        }
        $own = Db::name('Vod')->where('type_id', $parent)->where('vod_status', 1)->where('vod_recycle_time', 0)->limit(1)->value('vod_id') ? 1 : 0;
        Cache::set($key, $own, 600);
        return $own === 1;
    }

    /** FORCE INDEX name for a single-category list sorted by time, only when lane H's ScaleSchema confirmed it (D-L1). */
    private static function forcedIndex(array $where, int $parent, string $order): string
    {
        if (!preg_match('/^vod_time (asc|desc)$/D', $order) || !class_exists('\app\common\util\ScaleSchema')
            || !method_exists('\app\common\util\ScaleSchema', 'indexName')) {
            return '';
        }
        try {
            if ($parent > 0) {
                return (string)\app\common\util\ScaleSchema::indexName('type1_list');
            }
            if (isset($where['type_id']) && is_array($where['type_id']) && ($where['type_id'][0] ?? '') === 'eq') {
                return (string)\app\common\util\ScaleSchema::indexName('type_list');
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    /**
     * S-O1: every id of a list with a FilterMatch condition in its sort order ($col $dir, then vod_id $dir: the order of the
     * sort-index walk), read in one statement through the MATCH (at most COUNT_BOUND + 1 rows); null when the list has more
     * (then the bounded count of that list is cached as COUNT_BOUND + 1, so boundedCount() does not read the rows again).
     * Cached per list revision like the count (front-end lists).
     */
    private function ftAllIds(array $where, $where2, string $col, string $dir, int $parent, &$late = false): ?array
    {
        $late = false;
        $key = $this->countCacheKey($where, $where2 . '|ftall|' . $col . ' ' . $dir . '|p' . $parent . '|r' . self::listRevision());
        $hit = $key !== '' ? Cache::get($key) : null;
        if (is_array($hit)) {
            return $hit;
        }
        if ($hit === -1) {
            return null; // more than COUNT_BOUND (cached): the caller pages as usual without reading the matches again
        }
        if ($hit === -2) {
            $late = true; // round 2: the matches could not be read in COUNT_SEC a short while ago (cached COUNT_GUESS_TTL)
            return null;
        }
        $q = Db::name('Vod')->field('vod_id,' . $col . ' AS ft_sv')->where($where)->where($where2);
        if ($parent > 0) {
            $q->where('type_id_1|type_id', $parent);
        }
        $sql = $q->limit(self::COUNT_BOUND + 1)->buildSql();
        $timedOut = false;
        $rows = self::ftCounted($where2, function () use ($sql, &$timedOut) { return self::boundedQuery('SELECT vod_id, ft_sv FROM ' . $sql . ' ft_all', $timedOut); },
            function ($r) use (&$timedOut) { return !$timedOut && count($r) <= self::COUNT_BOUND; });
        if ($timedOut) {
            // round 2 (O-S1): the MATCH rows could not be read in COUNT_SEC: no total for a while (the caller's open pager)
            $countKey = $this->countCacheKey($where, $where2 . '|p' . $parent . '|r' . self::listRevision());
            if ($countKey !== '') {
                Cache::set($countKey, -1, self::COUNT_GUESS_TTL);
            }
            if ($key !== '') {
                Cache::set($key, -2, self::COUNT_GUESS_TTL);
            }
            $late = true;
            return null;
        }
        if (count($rows) > self::COUNT_BOUND) {
            $countKey = $this->countCacheKey($where, $where2 . '|p' . $parent . '|r' . self::listRevision());
            if ($countKey !== '') {
                Cache::set($countKey, self::COUNT_BOUND + 1, (int)$GLOBALS['config']['app']['cache_time']);
            }
            if ($key !== '') {
                Cache::set($key, -1, (int)$GLOBALS['config']['app']['cache_time']);
            }
            return null;
        }
        $desc = strtolower($dir) === 'desc';
        usort($rows, function ($a, $b) use ($desc) {
            $c = ((float)$a['ft_sv'] <=> (float)$b['ft_sv']) ?: ((int)$a['vod_id'] <=> (int)$b['vod_id']);
            return $desc ? -$c : $c;
        });
        $ids = array_map(function ($r) { return (int)$r['vod_id']; }, $rows);
        if ($key !== '') {
            Cache::set($key, $ids, (int)$GLOBALS['config']['app']['cache_time']);
        }
        return $ids;
    }

    /**
     * Round 2 (search-r2 O-S1, import-r2 filter parity): a visitor list's bounded count (and the read of its MATCH ids)
     * runs under COUNT_SEC. A count that cannot finish in time (a LIKE filter the FULLTEXT index cannot narrow over a
     * large table: single words, CJK values, dense values the row guard sends to the plain LIKE) leaves the list without a
     * total for COUNT_GUESS_TTL seconds: the open-ended pager (openTotalPage) instead of a LIKE count over every row or a
     * statement-limit error that turns the page into the lighter page without its results. Admin and command-line counts
     * keep running to their end; engines without a statement limit count as before.
     */
    const COUNT_SEC = 0.6;
    const COUNT_GUESS_TTL = 600;
    /** Seconds a visitor list that ran into the statement limit answers the lighter page without running again. */
    const LIST_TIMEOUT_TTL = 120;
    /** Page 1 read first: no count when counting COUNT_BOUND + 1 matches would examine more rows than this (estimate). */
    const COUNT_EXAMINE_MAX = 20000;
    /**
     * Round 7 (flood-r7 R7-3): the numbered pages of a text filter skip their bounded count when this request's landing count walk
     * puts the rows that count would examine above this (it could not end in COUNT_SEC: 7.1 M u set, cold, big-ass estimated 108 k
     * rows, its count stopped at its 0.6 s limit after 19,809; big-cock 181 k, stopped after 100,638); below it the count runs as
     * before (amateur 62 k estimated: counted 10,001 matches over 57,266 rows in 0.45 s; blowjob 50 k, anal 67 k)
     */
    const COUNT_SKIP_EXAMINE = 80000;
    /**
     * Round 5 (synth S-3), round 6 (orchestrator D-7, search-r6 R6-2 / R6-3, flood-r6 R5-2): page 1 of a text filter without
     * an index path (a dense value without a FilterMatch pre-filter, a value without a vocabulary group, a MATCH whose size
     * the guard could not price in time) walks its list range in windows of newest rows, WALK_PAGE1_ROWS first, each next
     * one twice as long up to WALK_PAGE1_GROW x WALK_PAGE1_ROWS and never longer than the budget left.
     * Round 6: the budget is the rows THIS request's statements stepped through (session Handler_read_next / _prev /
     * _rnd_next: handlerReads()), never the server-global Innodb_buffer_pool_reads (round 5: the reads of concurrent requests
     * counted against it, so the cards of a cold page depended on the traffic and the walk stopped after 600 pages: class
     * landings of the 7.1 M clustered set 0 cards instead of 20). WALK_PAGE1_EXAMINE rows at most; WALK_PAGE1_ROWS_NOSTAT
     * index rows when the counters cannot be read. A walk that stops before its page reads the value's MATCH rows when
     * FilterMatch can build a condition for it (textMatchIds(): exact, newest first, cached for the later pages and
     * continuations), else it shows what it found with "Older results". A warm or small catalog lists what it listed before.
     */
    const WALK_PAGE1_ROWS = 500;
    const WALK_PAGE1_GROW = 16;
    const WALK_PAGE1_EXAMINE = 60000;
    const WALK_PAGE1_ROWS_NOSTAT = 10000;
    /** Round 6 (flood-r6 R5-2): rows the landing count of such a value walks at most (TemplateSeo: a lower bound beyond). */
    const WALK_COUNT_EXAMINE = 20000;
    /** Round 6: newest matching rows the landing count walk keeps (vod_id, vod_time, the column) for the spelling and page 1. */
    const WALK_KEEP_ROWS = 50;
    /** Round 5 (S-3): index rows one continuation page (after=) of such a text filter may pass (ROW_BUDGET for other lists). */
    const WALK_AFTER_ROWS = 10000;
    /** Round 6 (D-7): most ids textMatchIds() keeps (a longer MATCH answers null: its newest rows need all of them). */
    const MATCH_IDS_MAX = 50000;
    /** Round 6 (D-7): seconds the MATCH read of textMatchIds() may run in a visitor request (admin / command line: no limit). */
    const MATCH_IDS_SEC = 1.5;
    /**
     * JavCMS 1.2.0 round 7 (r9 synthesis S-1 "S-R7-P1", a targeted D-8): a value without a MATCH path (a LIKE wildcard or an
     * apostrophe in it, only words shorter than FilterMatch::MIN_TOKEN_FLOOR, a MATCH group above MATCH_IDS_MAX) whose rows
     * lie below the rows the page-1 walk examines goes on with the plain LIKE below the walk's stop point (timedWalk()):
     * window by window, newest first, under a TIME budget instead of a row budget. TAIL_SEC_MAX = most seconds that read may
     * take; TAIL_RESERVE = database time it leaves to the rest of the page (other blocks of the theme) on top of
     * DbTimeout::RENDER_RESERVE; TAIL_ROWS_MAX = largest window (index rows); TAIL_MIN_SEC = less time than this left: no
     * further window (its statement could not finish).
     */
    const TAIL_SEC_MAX = 2.5;
    const TAIL_RESERVE = 0.5;
    const TAIL_ROWS_MAX = 65536;
    const TAIL_MIN_SEC = 0.15;

    /**
     * Round 6: the landing count walk of a request (windowLikeCount) keyed by column + LIKE pattern: its newest matching rows
     * and where it stopped, so page 1 of the same landing (same list shape) reuses them instead of walking the same windows
     * again (flood-r6 R5-2: one budget for a landing's whole database work).
     */
    private static $walkMemo = [];

    /** Test hook (tests/ft_filter_match.php): [page-1 walk, landing count walk] budgets instead of the constants (null). */
    public static $walkExamine = null;

    /**
     * Test hook (tests/ft_filter_match.php, round 7): seconds timedWalk() may read instead of the request's time left (null =
     * DbTimeout::remaining() minus TAIL_RESERVE, at most TAIL_SEC_MAX; 0 = no time: the walk's rows + "Older results").
     */
    public static $tailSec = null;

    /** Round 6: rows the page-1 walk ( false) or the landing count walk may step through. */
    private static function walkBudget(bool $count = false): int
    {
        if (is_array(self::$walkExamine)) {
            return max(1, (int)(self::$walkExamine[$count ? 1 : 0] ?? 1));
        }
        return $count ? self::WALK_COUNT_EXAMINE : self::WALK_PAGE1_EXAMINE;
    }

    /** Round 6: tests and long-running processes start a fresh request state. */
    public static function resetRequestState(): void
    {
        self::$walkMemo = [];
        self::$statOverhead = null;
    }

    /**
     * Round 6: what one handlerReads() call adds to the session counters itself (MariaDB 11.4: 0; a server that answers
     * SHOW STATUS from a scanned table counts its rows), measured once per request; the walk budgets subtract it per window.
     */
    private static $statOverhead = null;

    private static function statOverhead(): int
    {
        if (self::$statOverhead === null) {
            $a = self::handlerReads();
            $b = $a !== null ? self::handlerReads() : null;
            self::$statOverhead = ($a !== null && $b !== null) ? max(0, $b - $a) : 0;
        }
        return self::$statOverhead;
    }

    /**
     * Round 5 (S-3): the vod_time of the $rows-th row of a list range below $below (null: from the top), newest first, read
     * from the range's D-L1 index alone (the walkWindow() shapes: one category, a parent without rows of its own, or every
     * published video); null when fewer rows remain or the shape / index is not one of those.
     */
    private static function windowBelow(array $where, int $parent, ?int $below, int $rows): ?int
    {
        if ($rows < 1) {
            return null;
        }
        try {
            $q = self::rangeIndexQuery($where, $parent, 'vod_time');
            if ($q === null) {
                return null;
            }
            if ($below !== null) {
                $q->where('vod_time', '<', $below);
            }
            // select(), not value(): value() replaces the limit with LIMIT 1
            $hit = $q->order('vod_time desc')->limit($rows - 1, 1)->select();
            $t = (is_array($hit) || $hit instanceof \ArrayAccess) && isset($hit[0]['vod_time']) ? $hit[0]['vod_time'] : null;
        } catch (\Throwable $e) {
            if (class_exists('\app\common\util\DbTimeout') && \app\common\util\DbTimeout::is($e)) {
                throw $e;
            }
            return null;
        }
        return $t === null || $t === false || $t === '' ? null : (int)$t;
    }

    /**
     * Round 5 (S-3): a query on the D-L1 index of a list range alone (published, not recycled; one category, a parent without
     * rows of its own, or every published video), FORCE INDEX named by ScaleSchema; null for any other range shape.
     */
    private static function rangeIndexQuery(array $where, int $parent, string $field)
    {
        $st = $where['vod_status'] ?? null;
        if (!($st === 1 || $st === '1' || (is_array($st) && strtolower((string)($st[0] ?? '')) === 'eq' && !is_array($st[1] ?? null) && (string)($st[1] ?? '') === '1'))) {
            return null;
        }
        if (!array_key_exists('vod_recycle_time', $where) || is_array($where['vod_recycle_time']) || (string)$where['vod_recycle_time'] !== '0') {
            return null;
        }
        $q = Db::name('Vod')->field($field);
        if ($parent > 0) {
            if (isset($where['type_id']) || self::parentHasOwnRows($parent)) {
                return null;
            }
            $idx = self::walkIndex('type1_list');
            $q->where('type_id_1', $parent);
        } elseif (isset($where['type_id'])) {
            $c = $where['type_id'];
            if (!(is_array($c) && strtolower((string)($c[0] ?? '')) === 'eq' && is_scalar($c[1] ?? null))) {
                return null;
            }
            $idx = self::walkIndex('type_list');
            $q->where('type_id', (int)$c[1]);
        } else {
            $idx = self::walkIndex('status_list');
        }
        if ($idx === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $idx)) {
            return null;
        }
        return $q->force($idx)->where('vod_status', 1)->where('vod_recycle_time', 0);
    }

    /**
     * Round 5 (S-3): rows of the list range at or above ($vod_time, $vod_id) in the newest-first order, counted on its index
     * alone (at most $cap): the rows a newest-first walk examined to reach that row, whatever plan the server chose for it
     * (the session handler counters of a range read with an index condition count other things than a plain walk).
     */
    private static function rowsAbove(array $where, int $parent, int $time, int $id, int $cap): ?int
    {
        try {
            $q = self::rangeIndexQuery($where, $parent, '1');
            if ($q === null) {
                return null;
            }
            $sql = $q->where('vod_time >= ' . $time . ' AND (vod_time > ' . $time . ' OR vod_id >= ' . $id . ')')->limit(max(1, $cap))->buildSql();
            $r = Db::query('SELECT COUNT(*) AS c FROM ' . $sql . ' rows_above');
            return (int)($r[0]['c'] ?? 0);
        } catch (\Throwable $e) {
            if (class_exists('\app\common\util\DbTimeout') && \app\common\util\DbTimeout::is($e)) {
                throw $e;
            }
            return null;
        }
    }

    /**
     * Round 5 (S-3), round 6 (D-7, search-r6 R6-2 / R6-3, flood-r6 R5-2): page 1 (the first $need rows) of a newest-first
     * text-filtered list, read window by window (see WALK_PAGE1_ROWS) while the rows this request's statements stepped
     * through stay under WALK_PAGE1_EXAMINE (session counters; each window never longer than the budget left).
     * ['rows', 'examined' (rows the walk stepped through, null unknown), 'stop' (the vod_time below which the list continues
     * when the walk stopped before $need rows; null = the rows are the list's first $need rows or all of it), 'total' (the
     * exact bounded count when the landing count of this request counted the same list, else null)], or null when the list
     * has another shape (not vod_time desc, a range of one window or less, no D-L1 index): the caller walks as before.
     * Round 6: the newest rows the landing count walk of this request found for the same list (walkMemo) come first, and
     * the walk goes on below the window where that count stopped (one budget for the landing's database work).
     */
    private function boundedFirstPage(array $where, $where2, string $order, int $need, $field, int $parent): ?array
    {
        if (!preg_match('/^vod_time desc$/iD', trim($order))) {
            return null;
        }
        $size = self::WALK_PAGE1_ROWS;
        $rows = [];
        $examined = 0;
        $upper = null;
        $walked = 0;
        $total = null;
        $mk = self::walkMemoKey($where, $where2, $parent);
        $memo = $mk !== null && isset(self::$walkMemo[$mk]) ? self::$walkMemo[$mk] : null;
        if ($memo !== null) {
            $ids = array_slice($memo['ids'], 0, $need);
            $got = $ids ? $this->rowsByIds($ids, $where, self::ftPlain($where2), $field) : [];
            if (count($got) === count($ids)) {
                $total = !empty($memo['exact']) ? (int)$memo['count'] : null;
                if (count($ids) >= $need || ($memo['stop'] === null && (int)$memo['found'] === count($memo['ids']))) {
                    // the count walk read every row above its last window: these are the list's newest rows
                    return ['rows' => $got, 'examined' => $memo['examined'], 'stop' => null, 'total' => $total];
                }
                if ((int)$memo['found'] === count($memo['ids'])) {
                    // it stopped before a page holding every match it found: the walk goes on below its last window
                    $rows = $got;
                    $examined = $memo['examined'];
                    $upper = (int)$memo['stop'];
                    $size = max(self::WALK_PAGE1_ROWS, (int)$memo['size']);
                    $walked = (int)$memo['walked'];
                }
            }
        }
        if ($upper === null) {
            $lo = self::windowBelow($where, $parent, null, $size);
            if ($lo === null) {
                return null; // one window or less: the walk is short anyway
            }
        } else {
            $left = $examined !== null ? self::walkBudget() - (int)$examined : self::WALK_PAGE1_ROWS_NOSTAT - $walked;
            if ($left <= 0) {
                return ['rows' => $rows, 'examined' => $examined, 'stop' => $upper, 'total' => $total, 'size' => $size];
            }
            $size = (int)min($size, max(self::WALK_PAGE1_ROWS, $left));
            $lo = self::windowBelow($where, $parent, $upper, $size); // null: the rest of the range is the last window
        }
        while (true) {
            $cond = $lo !== null ? 'vod_time >= ' . $lo : '';
            if ($upper !== null) {
                $cond = ($cond !== '' ? $cond . ' AND ' : '') . 'vod_time < ' . $upper;
            }
            $w2 = $cond === '' ? $where2 : (($where2 !== '' && $where2 !== null ? '(' . $where2 . ') AND ' : '') . $cond);
            $h0 = self::handlerReads();
            $got = $this->pageRows($where, $w2, $order, 0, $need - count($rows), $field, $parent, false, false);
            $h1 = $h0 !== null ? self::handlerReads() : null;
            $examined = ($examined !== null && $h1 !== null) ? $examined + max(0, $h1 - $h0 - self::statOverhead()) : null;
            foreach ($got as $r) {
                $rows[] = $r;
            }
            $walked += $size;
            if (count($rows) >= $need || $lo === null) {
                // the rows the walk examined up to its last row, from the index (the count decision of the caller prices the
                // bounded count with it, as for the plain walk)
                $last = $rows ? $rows[count($rows) - 1] : null;
                if (is_array($last) || $last instanceof \ArrayAccess) {
                    $above = isset($last['vod_time'], $last['vod_id']) ? self::rowsAbove($where, $parent, (int)$last['vod_time'], (int)$last['vod_id'], $walked) : null;
                    if ($above !== null) {
                        $examined = max((int)$examined, $above);
                    }
                }
                return ['rows' => $rows, 'examined' => $examined, 'stop' => null, 'total' => $total];
            }
            // round 6: the budget left in rows this request stepped through (round 5: the server-global disk reads)
            $left = $examined !== null ? self::walkBudget() - (int)$examined : self::WALK_PAGE1_ROWS_NOSTAT - $walked;
            if ($left <= 0) {
                return ['rows' => $rows, 'examined' => $examined, 'stop' => $lo, 'total' => $total, 'size' => $size];
            }
            $upper = $lo;
            $size = (int)min($size * 2, self::WALK_PAGE1_ROWS * self::WALK_PAGE1_GROW, max(self::WALK_PAGE1_ROWS, $left));
            $lo = self::windowBelow($where, $parent, $upper, $size); // null: the rest of the range is the last window
        }
    }

    /**
     * Round 7 (r9 synthesis S-1 "S-R7-P1"): seconds timedWalk() may read now: the request's database time left
     * (DbTimeout::remaining(), which already keeps DbTimeout::RENDER_RESERVE for the render) minus TAIL_RESERVE for the rest
     * of the page, at most TAIL_SEC_MAX (TAIL_SEC_MAX when the request has no deadline); 0 = no time left.
     */
    private static function tailSeconds(): float
    {
        if (self::$tailSec !== null) {
            return max(0.0, (float)self::$tailSec);
        }
        $left = class_exists('\app\common\util\DbTimeout') && method_exists('\app\common\util\DbTimeout', 'remaining')
            ? \app\common\util\DbTimeout::remaining() : null;
        if ($left === null) {
            return self::TAIL_SEC_MAX;
        }
        return max(0.0, min(self::TAIL_SEC_MAX, (float)$left - self::TAIL_RESERVE));
    }

    /**
     * Round 7: the SELECT of one timedWalk() window: at most $limit rows of the list newest first (vod_time DESC, vod_id DESC:
     * the order of the range's D-L1 index, which every walk window here is cut from: one category, a parent without rows of
     * its own, or every published video; rangeIndexQuery()), '' for any other range shape.
     */
    private static function tailWindowSql(array $where, $w2, int $parent, int $limit, $field): string
    {
        // the window's order and the cursor need vod_time and vod_id in the rows (no duplicate names: a derived table refuses them)
        $f = trim((string)$field);
        if ($f !== '' && $f !== '*') {
            foreach (['vod_id', 'vod_time'] as $need) {
                if (!preg_match('/(^|[\s,`.])' . $need . '`?\s*(,|$)/', $f)) {
                    $f .= ',' . $need;
                }
            }
        }
        $q = self::rangeIndexQuery($where, $parent, $f === '' ? '*' : $f);
        if ($q === null) {
            return '';
        }
        $q->where($where);
        if ($w2 !== '' && $w2 !== null) {
            $q->where($w2);
        }
        return 'SELECT * FROM ' . $q->order('vod_time desc, vod_id desc')->limit(max(1, $limit))->buildSql() . ' tail_window';
    }

    /**
     * Round 7 (r9 synthesis S-1 "S-R7-P1", a targeted D-8 revert to the plain LIKE read of src-final for exactly these
     * values): up to $need further rows of a text-filtered newest-first list whose value has no MATCH path (textMatchIds()
     * answered null), read with the plain LIKE below a point of the list: $below = the vod_time below which nothing was read
     * (the page-1 walk's stop point), or $after = [vod_time, vod_id] = the rows after that row (an "Older results" cursor).
     * Window by window, newest first (each window's lower vod_time from the range's D-L1 index alone, windowBelow(); each
     * next window twice as long, at most TAIL_ROWS_MAX and never longer than the time left lets it read at the speed the
     * last window was read), under a TIME budget (tailSeconds(): the request's database time left minus TAIL_RESERVE, at
     * most TAIL_SEC_MAX). Every window statement runs under the time left (FtIndex::limited), so the read never passes the
     * statement limit; a window that runs out of time keeps the rows of the windows before it. The rows stay exact and in
     * the list's order. Returns ['rows' => at most $need rows, 'cursor' => 'T.id' where the list continues unread (null = its
     * end was reached: these rows are all that is left), 'examined' => rows the windows stepped through (session counters,
     * null unknown)], or null for a range shape without a D-L1 index (the caller answers as before).
     */
    private function timedWalk(array $where, $where2, int $parent, ?int $below, ?array $after, int $need, $field, int $size): ?array
    {
        $start = $after !== null ? [max(0, (int)$after[0]), max(0, (int)$after[1])] : null;
        $cursor = $start !== null ? $start[0] . '.' . $start[1] : ($below !== null ? (int)$below . '.0' : null);
        if ($need < 1 || self::tailWindowSql($where, $where2, $parent, 1, 'vod_id') === '' || !class_exists('\app\common\util\FtIndex')
            || !method_exists('\app\common\util\FtIndex', 'isTimeout')) {
            return $need < 1 ? ['rows' => [], 'cursor' => $cursor, 'examined' => 0] : null;
        }
        $sec = self::tailSeconds();
        if ($sec < self::TAIL_MIN_SEC) {
            return ['rows' => [], 'cursor' => $cursor, 'examined' => 0];
        }
        $until = microtime(true) + $sec;
        $rows = [];
        $examined = 0;
        $size = (int)max(self::WALK_PAGE1_ROWS, min(self::TAIL_ROWS_MAX, $size));
        // windowBelow() counts the rows with vod_time < $upper; an after= cursor starts at its own vod_time (ties below it)
        $upper = $start !== null ? $start[0] + 1 : $below;
        $lo = self::windowBelow($where, $parent, $upper, $size); // null: the rest of the range is the last window
        $first = true;
        while (true) {
            $cond = [];
            if ($lo !== null) {
                $cond[] = 'vod_time >= ' . (int)$lo;
            }
            if ($first && $start !== null) {
                $cond[] = '(vod_time < ' . $start[0] . ' OR (vod_time = ' . $start[0] . ' AND vod_id < ' . $start[1] . '))';
            } elseif ($upper !== null) {
                $cond[] = 'vod_time < ' . (int)$upper;
            }
            $c = implode(' AND ', $cond);
            $w2 = $c === '' ? $where2 : (($where2 !== '' && $where2 !== null ? '(' . $where2 . ') AND ' : '') . $c);
            $left = $until - microtime(true);
            if ($left < self::TAIL_MIN_SEC) {
                return ['rows' => $rows, 'cursor' => $cursor, 'examined' => $examined];
            }
            $t0 = microtime(true);
            $h0 = self::handlerReads();
            try {
                $got = Db::query(\app\common\util\FtIndex::limited(self::tailWindowSql($where, $w2, $parent, $need - count($rows), $field), $left));
            } catch (\Throwable $e) {
                // the request's own deadline (DbTimeout marked it): its lighter page; this window's own time limit (message
                // only: DbTimeout's per-request flag stays unset): the rows of the windows before it, the cursor above it
                if (class_exists('\app\common\util\DbTimeout') && \app\common\util\DbTimeout::seen()) {
                    throw $e;
                }
                if (\app\common\util\FtIndex::isTimeout($e)) {
                    return ['rows' => $rows, 'cursor' => $cursor, 'examined' => $examined];
                }
                throw $e;
            }
            $h1 = $h0 !== null ? self::handlerReads() : null;
            $examined = ($examined !== null && $h1 !== null) ? $examined + max(0, $h1 - $h0 - self::statOverhead()) : null;
            $spent = max(0.001, microtime(true) - $t0);
            $got = is_array($got) ? $got : [];
            usort($got, function ($a, $b) {
                return [(int)$b['vod_time'], (int)$b['vod_id']] <=> [(int)$a['vod_time'], (int)$a['vod_id']];
            });
            foreach ($got as $r) {
                $rows[] = $r;
            }
            if (count($rows) >= $need) {
                $last = $rows[count($rows) - 1];
                return ['rows' => $rows, 'cursor' => (int)$last['vod_time'] . '.' . (int)$last['vod_id'], 'examined' => $examined];
            }
            if ($lo === null) {
                return ['rows' => $rows, 'cursor' => null, 'examined' => $examined]; // the last window: the list's end
            }
            $first = false;
            $upper = $lo;
            $cursor = $upper . '.0'; // every row at or above $lo was read
            // the next window: twice as long, at most TAIL_ROWS_MAX, and no longer than half the time left at this window's speed
            $left = $until - microtime(true);
            $fit = (int)floor($size / $spent * max(0.0, $left) / 2);
            $size = (int)max(self::WALK_PAGE1_ROWS, min($size * 2, self::TAIL_ROWS_MAX, $fit));
            $lo = self::windowBelow($where, $parent, $upper, $size);
        }
    }

    /**
     * Round 5 (S-3), round 6 (S-R6-1, R5-2): bounded LIKE count of a landing value without an index path (TemplateSeo),
     * window by window like boundedFirstPage(), while the rows this request's statements stepped through stay under
     * WALK_COUNT_EXAMINE: ['count' => matches (at most COUNT_BOUND + 1), 'exact' => the count is the bounded LIKE count (it
     * reached the bound or read the whole range), 'rows' => the newest WALK_KEEP_ROWS matching rows read on the way
     * ([vod_id, vod_time, $field] newest first: the stored spelling needs no further read), 'stop' => the vod_time below
     * which nothing was read (null: the whole range)]; 'exact' false = a lower bound. Null when the catalog is one window
     * or less, or the index is unknown (the caller counts as before). The walk is kept for page 1 of the same landing
     * (walkMemo: the list starts with these rows and goes on below 'stop').
     */
    public static function windowLikeCount(string $field, string $like): ?array
    {
        if (!in_array($field, self::FT_FILTER_COLS, true)) {
            return null;
        }
        $where = ['vod_status' => ['eq', 1], 'vod_recycle_time' => 0];
        $size = self::WALK_PAGE1_ROWS;
        $lo = self::windowBelow($where, 0, null, $size);
        if ($lo === null) {
            return null;
        }
        $n = 0;
        $upper = null;
        $walked = 0;
        $examined = 0;
        $keep = [];
        $idx = self::walkIndex('status_list');
        $forced = $idx !== '' && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $idx);
        $window = function () use ($field, $like, $forced, $idx, &$lo, &$upper) {
            $q = Db::name('Vod')->where('vod_status', 1)->where('vod_recycle_time', 0)->where($field, 'like', $like);
            if ($forced) {
                $q->force($idx);
            }
            if ($lo !== null) {
                $q->where('vod_time', '>=', $lo);
            }
            if ($upper !== null) {
                $q->where('vod_time', '<', $upper);
            }
            return $q;
        };
        while (true) {
            $c = 0;
            if (count($keep) < self::WALK_KEEP_ROWS) {
                // the newest matches of this window (the spelling, page 1 of the list); the count below reads the same pages
                // (only the count's rows go into the budget: the second pass over a window costs no disk read)
                foreach ($window()->field('vod_id,vod_time,' . $field)->order('vod_time desc, vod_id desc')->limit(self::WALK_KEEP_ROWS - count($keep))->select() as $r) {
                    $keep[] = ['vod_id' => (int)$r['vod_id'], 'vod_time' => (int)$r['vod_time'], $field => (string)$r[$field]];
                }
            }
            $h0 = self::handlerReads();
            $r = Db::query('SELECT COUNT(*) AS c FROM ' . $window()->field('1')->limit(self::COUNT_BOUND + 1 - $n)->buildSql() . ' window_count');
            $c = (int)($r[0]['c'] ?? 0);
            $h1 = $h0 !== null ? self::handlerReads() : null;
            $examined = ($examined !== null && $h1 !== null) ? $examined + max(0, $h1 - $h0 - self::statOverhead()) : null;
            $n += $c;
            $walked += $size;
            $done = $n > self::COUNT_BOUND || $lo === null;
            $left = $examined !== null ? self::walkBudget(true) - (int)$examined : self::WALK_PAGE1_ROWS_NOSTAT - $walked;
            if ($done || $left <= 0) {
                $stop = $done && $lo === null ? null : $lo;
                if ($n > self::COUNT_BOUND) {
                    $stop = $lo; // the bound was reached inside this window: the rows below it were not all read
                }
                self::$walkMemo[$field . '|' . substr($like, 1, -1)] = ['ids' => array_column($keep, 'vod_id'), 'found' => $n, 'stop' => $stop,
                    'examined' => $examined, 'size' => (int)min($size * 2, self::WALK_PAGE1_ROWS * self::WALK_PAGE1_GROW), 'walked' => $walked,
                    'count' => min($n, self::COUNT_BOUND + 1), 'exact' => $done];
                return ['count' => min($n, self::COUNT_BOUND + 1), 'exact' => $done, 'rows' => $keep, 'stop' => $stop];
            }
            $upper = $lo;
            $size = (int)min($size * 2, self::WALK_PAGE1_ROWS * self::WALK_PAGE1_GROW, max(self::WALK_PAGE1_ROWS, $left));
            $lo = self::windowBelow($where, 0, $upper, $size);
        }
    }

    /**
     * Round 6: walkMemo key of a list that is exactly "published videos whose <tag | actor | class> LIKE one value" (all
     * categories, no other filter: the landing pages), or null.
     */
    private static function walkMemoKey(array $where, $where2, int $parent): ?string
    {
        if ($parent !== 0 || ($where2 !== '' && $where2 !== null)) {
            return null;
        }
        $st = $where['vod_status'] ?? null;
        if (!($st === 1 || $st === '1' || (is_array($st) && strtolower((string)($st[0] ?? '')) === 'eq' && !is_array($st[1] ?? null) && (string)($st[1] ?? '') === '1'))) {
            return null;
        }
        if (!array_key_exists('vod_recycle_time', $where) || is_array($where['vod_recycle_time']) || (string)$where['vod_recycle_time'] !== '0') {
            return null;
        }
        $rest = array_diff_key($where, ['vod_status' => 1, 'vod_recycle_time' => 1]);
        if (count($rest) !== 1) {
            return null;
        }
        $col = (string)key($rest);
        $raw = in_array($col, self::WALK_PAGE1_COLS, true) ? self::ftLikeRaw($rest[$col]) : null;
        return $raw === null || strpos($raw, ',') !== false ? null : $col . '|' . $raw;
    }

    /**
     * Round 6 (D-7): the MATCH conditions FilterMatch can build for the LIKE filters of a list of the textMatchIds() shape
     * (published, not recycled, LIKE filters on FT_FILTER_COLS, optionally one category; no other condition), null for any
     * other shape or when no filter gets one. A MATCH the size guard knows to be dense (a spread sample or a finished count
     * above its row limit) is left out: reading its rows costs more than the walk of the list.
     */
    private static function textMatchConds(array $where, $where2, &$over = false): ?array
    {
        $over = false;
        if (($where2 !== '' && $where2 !== null) || !class_exists('\app\common\util\FilterMatch') || !method_exists('\app\common\util\FilterMatch', 'plan')) {
            return null;
        }
        $st = $where['vod_status'] ?? null;
        if (!($st === 1 || $st === '1' || (is_array($st) && strtolower((string)($st[0] ?? '')) === 'eq' && !is_array($st[1] ?? null) && (string)($st[1] ?? '') === '1'))) {
            return null;
        }
        if (!array_key_exists('vod_recycle_time', $where) || is_array($where['vod_recycle_time']) || (string)$where['vod_recycle_time'] !== '0') {
            return null;
        }
        $conds = [];
        $likes = 0;
        foreach ($where as $k => $c) {
            if ($k === 'vod_status' || $k === 'vod_recycle_time') {
                continue;
            }
            if ($k === 'type_id' && is_array($c) && strtolower((string)($c[0] ?? '')) === 'eq' && is_scalar($c[1] ?? null)) {
                continue;
            }
            $raw = in_array($k, self::FT_FILTER_COLS, true) ? self::ftLikeRaw($c) : null;
            if ($raw === null) {
                return null; // another condition: the walk decides as before
            }
            $likes++;
            $p = \app\common\util\FilterMatch::plan((string)$k, $raw);
            // a MATCH the term statistics put above MATCH_IDS_MAX rows is read only where the caller allows it ($over:
            // textMatchIds($readOver)): the statistics count a word in EVERY field, so they over-estimate one column (round 7,
            // search-r7 R7-1: class 'BBW' 9,856 rows, MATCH(vod_class) '+bbw' 10,104 rows, priced by the all-field df 91,040;
            // search-r6 'Bi Sexual' '+sexual*' 74,540 is really above it: its read stops at MATCH_IDS_MAX + 1 rows or
            // MATCH_IDS_SEC, both remembered)
            if (is_array($p) && ($p['sql'] ?? '') !== '' && empty($p['dense'])) {
                $conds[] = (string)$p['sql'];
                if (isset($p['est']) && $p['est'] !== null && (int)$p['est'] > self::MATCH_IDS_MAX) {
                    $over = true;
                }
            }
        }
        return $likes > 0 && $conds ? $conds : null;
    }

    /** Round 6 (D-7): app-cache key of textMatchIds() ('' = not cached: admin / command line, cache_core off). */
    private static function textMatchKey(array $where, int $parent): string
    {
        if (!defined('ENTRANCE') || ENTRANCE !== 'index' || (int)($GLOBALS['config']['app']['cache_core'] ?? 0) !== 1) {
            return '';
        }
        $norm = $where;
        ksort($norm);
        array_walk_recursive($norm, function (&$v) { $v = is_scalar($v) ? (string)$v : $v; });
        return $GLOBALS['config']['app']['cache_flag'] . '_vod_mids_v1_r' . self::listRevision() . '_' . md5(json_encode($norm) . '|p' . $parent);
    }

    /**
     * Round 6 (orchestrator D-7, search-r6 R6-2 / R6-3): every id of a visitor list of the textMatchConds() shape, read through
     * the MATCH conditions FilterMatch builds for its LIKE values (also when the size guard sent the list to the plain LIKE
     * because its probe ran out of time), in the list's order (vod_time DESC, vod_id DESC): ['ids' => int[], 'total' => int],
     * or null: another shape, no usable condition, more than MATCH_IDS_MAX rows or out of MATCH_IDS_SEC (both remembered
     * COUNT_GUESS_TTL seconds), or ($read false) not read yet. The LIKE filters stay in the statement and decide; every row a
     * LIKE finds holds a MATCH word (FilterMatch), so the ids are exactly the plain LIKE's rows. One statement without
     * ORDER BY (the server stops at the LIMIT), sorted here; cached per list revision like the bounded counts, so the later
     * pages and the continuations ("Older results") of the list page through the same ids. Unlike ftAllIds() (COUNT_BOUND + 1
     * rows, the count's bound) it keeps up to MATCH_IDS_MAX: the newest rows of a value with 30,000 old matches (search-r6
     * 'Solowork') can only be told from all of them.
     */
    public static function textMatchIds(array $where, $where2, int $parent, bool $read = true, bool $readOver = false, float $sec = 0.0): ?array
    {
        // round 7 (search-r7 R7-1): $over = a condition the term statistics put above MATCH_IDS_MAX rows (an all-field upper
        // estimate): its ids are read only when the caller allows it ($readOver: page 1 of a walk that stopped before its page,
        // the alternative being a thin page); a cached answer is used by every caller
        $over = false;
        $conds = self::textMatchConds($where, $where2, $over);
        if ($conds === null) {
            return null;
        }
        $key = self::textMatchKey($where, $parent);
        if ($key !== '') {
            $hit = Cache::get($key);
            if (is_array($hit) && isset($hit['ids']) && is_array($hit['ids'])) {
                return $hit;
            }
            if ($hit === -1 || $hit === -2) {
                return null; // more rows than MATCH_IDS_MAX, or out of time a short while ago
            }
        }
        if (!$read || ($over && !$readOver)) {
            return null;
        }
        $q = Db::name('Vod')->field('vod_id,vod_time')->where($where)->where(implode(' AND ', $conds));
        if ($parent > 0) {
            $q->where('type_id_1|type_id', $parent);
        }
        $sql = 'SELECT vod_id, vod_time FROM ' . $q->limit(self::MATCH_IDS_MAX + 1)->buildSql() . ' match_ids';
        // round 7: page 1 of a walk that stopped before its page may give the read the request's time left ($sec: tailSeconds(),
        // never less than MATCH_IDS_SEC; cold, the 10,104 MATCH rows of class 'BBW' took 1.6-2.0 s here)
        $sec = self::countLimitSec() > 0 ? max(self::MATCH_IDS_SEC, $sec) : 0.0;
        $timedOut = false;
        try {
            $rows = $sec > 0 && class_exists('\app\common\util\FtIndex') && method_exists('\app\common\util\FtIndex', 'isTimeout')
                ? Db::query(\app\common\util\FtIndex::limited($sql, $sec)) : Db::query($sql);
        } catch (\Throwable $e) {
            if (!(class_exists('\app\common\util\FtIndex') && \app\common\util\FtIndex::isTimeout($e))) {
                if (self::ftRefused($e, implode(' AND ', $conds))) {
                    return null; // the FULLTEXT index is gone (capability forgotten): the walk's answer stands
                }
                throw $e;
            }
            $timedOut = true;
            $rows = [];
        }
        if ($timedOut || count($rows) > self::MATCH_IDS_MAX) {
            if ($key !== '') {
                Cache::set($key, $timedOut ? -2 : -1, $timedOut ? self::COUNT_GUESS_TTL : (int)$GLOBALS['config']['app']['cache_time']);
            }
            return null;
        }
        usort($rows, function ($a, $b) {
            return [(int)$b['vod_time'], (int)$b['vod_id']] <=> [(int)$a['vod_time'], (int)$a['vod_id']];
        });
        $res = ['ids' => array_map(function ($r) { return (int)$r['vod_id']; }, $rows),
            'times' => array_map(function ($r) { return (int)$r['vod_time']; }, $rows), 'total' => count($rows)];
        if ($key !== '') {
            Cache::set($key, $res, (int)$GLOBALS['config']['app']['cache_time']);
        }
        return $res;
    }

    /**
     * Round 6 (D-7, R6-2): the ids of a textMatchIds() list after the continuation point (vod_time, vod_id) in the list's
     * order (vod_time DESC, vod_id DESC): the next MATCH rows below T, whatever lies between (no empty "Older" pages).
     */
    private static function matchIdsAfter(array $m, int $time, int $id, int $limit): array
    {
        $lo = 0;
        $hi = count($m['ids']);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            $t = (int)$m['times'][$mid];
            if ($t > $time || ($t === $time && (int)$m['ids'][$mid] >= $id)) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return array_slice($m['ids'], $lo, $limit);
    }

    /** Round 5 (S-3): the landing filters whose page 1 is walked window by window (the crawler's tag / actor / class pages). */
    const WALK_PAGE1_COLS = ['vod_tag', 'vod_actor', 'vod_class'];

    /** Round 5 (S-3): true when $where holds a LIKE filter of WALK_PAGE1_COLS (tag / actor / class). */
    private static function textLikeFilter(array $where): bool
    {
        foreach (self::WALK_PAGE1_COLS as $c) {
            if (isset($where[$c]) && self::ftLikeRaw($where[$c]) !== null) {
                return true;
            }
        }
        return false;
    }


    /**
     * Rows the server has stepped through in this session (Handler_read_next + _prev + _rnd_next: index entries and table
     * rows examined; MariaDB and MySQL), null when the counters cannot be read.
     */
    private static function handlerReads(): ?int
    {
        try {
            $n = 0;
            $seen = 0;
            foreach (Db::query("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_next', 'Handler_read_prev', 'Handler_read_rnd_next')") as $r) {
                $row = array_values($r);
                $n += (int)($row[1] ?? 0);
                $seen++;
            }
            return $seen > 0 ? $n : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Test hook: seconds of the visitor count limit for every caller (null = the rule of countLimitSec()). */
    public static $countSec = null;

    /** Seconds a visitor count may run (0 = no extra limit: admin, command line). */
    private static function countLimitSec(): float
    {
        if (self::$countSec !== null) {
            return max(0.0, (float)self::$countSec);
        }
        if (PHP_SAPI === 'cli' || !defined('ENTRANCE') || ENTRANCE !== 'index') {
            return 0.0;
        }
        $stmt = 0.0;
        try {
            if (class_exists('\app\common\util\ScaleTier')) {
                $stmt = (float)(\app\common\util\ScaleTier::get()['stmt_limit'] ?? 0);
            }
        } catch (\Throwable $e) {
            $stmt = 0.0;
        }
        return $stmt > 0 ? max(0.2, min(self::COUNT_SEC, $stmt / 2)) : self::COUNT_SEC;
    }

    /** Rows of $sql (one SELECT) for a visitor count, under countLimitSec(); [] with $timedOut = true when it ran out of time. */
    private static function boundedQuery(string $sql, &$timedOut = false): array
    {
        $timedOut = false;
        $sec = self::countLimitSec();
        if ($sec <= 0 || !class_exists('\app\common\util\FtIndex') || !method_exists('\app\common\util\FtIndex', 'isTimeout')) {
            return Db::query($sql);
        }
        try {
            return Db::query(\app\common\util\FtIndex::limited($sql, $sec));
        } catch (\Throwable $e) {
            if (!\app\common\util\FtIndex::isTimeout($e)) {
                throw $e;
            }
            $timedOut = true; // message-only test: DbTimeout's per-request flag (LITE page on a later failure) stays unset
            return [];
        }
    }

    /**
     * Round 7 (flood-r7 R7-3): the count of a text-filtered list of the landing shape (walkMemoKey(): published videos whose
     * tag / actor / class LIKE one value) that the landing of the same value already holds, or null: this request's landing
     * count walk when it counted exactly (windowLikeCount(): at most COUNT_BOUND + 1; then kept like a finished bounded count
     * of the list), else TemplateSeo's cached landing facts of a tag / actor value when they counted MORE than COUNT_BOUND
     * rows (COUNT_BOUND + 1, the "10,000+" pager: facts up to a day old stay true for it while rows come and go; a smaller
     * number is only taken from this list revision). -1 = this request's landing count walk stopped on its budget so far below
     * the bound that a bounded count could not end in time either (no count: the open-ended pager). A value with a LIKE
     * wildcard or escape is left to the bounded count (the landing escapes them, the list's LIKE does not).
     */
    private function knownTextCount(array $where, $where2, int $parent): ?int
    {
        $mk = self::walkMemoKey($where, $where2, $parent);
        if ($mk === null) {
            return null;
        }
        // a finished bounded count of this list revision first (boundedCount() would answer it)
        $key = $this->countCacheKey($where, $where2 . '|p' . $parent . '|r' . self::listRevision());
        $hit = $key !== '' ? Cache::get($key) : null;
        if (is_int($hit) && $hit >= 0) {
            return $hit;
        }
        if (isset(self::$walkMemo[$mk])) {
            $mm = self::$walkMemo[$mk];
            if (!empty($mm['exact'])) {
                $n = min((int)$mm['count'], self::COUNT_BOUND + 1);
                $this->rememberCount($where, $where2, $parent, $n);
                return $n;
            }
            // the landing count walk of this request stopped on its budget: a bounded count of COUNT_BOUND + 1 matches would
            // examine about examined x (COUNT_BOUND + 1) / found rows (page 1's rule); above COUNT_SKIP_EXAMINE it could not end in
            // time either (flood-r7: big-ass page 48 counted 19,809 rows to its 0.6 s limit after this walk), so no count: the
            // open-ended pager, remembered like a count that ran out of time
            // (never more rows than the list's range holds: a small catalog counts as before)
            if (isset($mm['examined']) && $mm['examined'] !== null
                && min((float)(self::ftRangeRows($where, $parent) ?? self::tableRows()),
                    (float)$mm['examined'] * (self::COUNT_BOUND + 1) / max(1, (int)$mm['found'])) > self::COUNT_SKIP_EXAMINE) {
                if ($key !== '') {
                    try {
                        Cache::set($key, -1, self::COUNT_GUESS_TTL);
                    } catch (\Throwable $e) {
                        // not kept: the next page decides again
                    }
                }
                return -1;
            }
        }
        list($col, $raw) = explode('|', $mk, 2);
        if (($col !== 'vod_tag' && $col !== 'vod_actor') || strpbrk($raw, "%_\\") !== false
            || !class_exists('\app\common\util\TemplateSeo') || !method_exists('\app\common\util\TemplateSeo', 'landingFactsKnown')) {
            return null;
        }
        $f = \app\common\util\TemplateSeo::landingFactsKnown($col === 'vod_actor' ? 'actor' : 'tag', $raw);
        return is_array($f) && !empty($f['exact']) && (int)($f['count'] ?? 0) > self::COUNT_BOUND ? self::COUNT_BOUND + 1 : null;
    }

    /** Round 7: $n (an exact bounded count of this list, at most COUNT_BOUND + 1) kept the way boundedCount() keeps its own. */
    private function rememberCount(array $where, $where2, int $parent, int $n): void
    {
        $key = $this->countCacheKey($where, $where2 . '|p' . $parent . '|r' . self::listRevision());
        if ($key === '' || $n < 0) {
            return;
        }
        try {
            Cache::set($key, min($n, self::COUNT_BOUND + 1), (int)$GLOBALS['config']['app']['cache_time']);
        } catch (\Throwable $e) {
            // not kept: the next page counts again (bounded)
        }
    }

    /**
     * D-L4: COUNT of at most COUNT_BOUND + 1 rows (cached per list revision for front-end lists). -1 = no total: the
     * visitor count ran out of COUNT_SEC (cached COUNT_GUESS_TTL seconds; the caller pages without a total).
     */
    private function boundedCount(array $where, $where2, int $parent)
    {
        $key = $this->countCacheKey($where, $where2 . '|p' . $parent . '|r' . self::listRevision());
        if ($key !== '' && is_int($hit = Cache::get($key))) {
            return $hit;
        }
        $timedOut = false;
        if ($parent > 0) {
            // D-L1b also for the bounded count of a filtered parent list: the two index ranges (type_id_1 = P, and type_id = P
            // only when the parent holds rows), each bounded; the OR form read whole tables at 7.1 M (3.5 s, step 3 browse)
            $parts = [Db::name('Vod')->field('1')->where($where)->where($where2)->where('type_id_1', $parent)->limit(self::COUNT_BOUND + 1)->buildSql()];
            if (self::parentHasOwnRows($parent)) {
                $parts[] = Db::name('Vod')->field('1')->where($where)->where($where2)->where('type_id', $parent)->limit(self::COUNT_BOUND + 1)->buildSql();
            }
            $r = self::boundedQuery('SELECT LEAST(COUNT(*), ' . (self::COUNT_BOUND + 1) . ') AS c FROM (' . implode(' UNION ALL ', $parts) . ') bounded_count', $timedOut);
        } else {
            $sub = Db::name('Vod')->field('1')->where($where)->where($where2)->limit(self::COUNT_BOUND + 1)->buildSql();
            // S-O1: a count through a MATCH that ends below the bound read every row of the MATCH: noted for the page planner
            $r = self::ftCounted($where2, function () use ($sub, &$timedOut) { return self::boundedQuery('SELECT COUNT(*) AS c FROM ' . $sub . ' bounded_count', $timedOut); },
                function ($r) use (&$timedOut) { return !$timedOut && (int)($r[0]['c'] ?? 0) <= self::COUNT_BOUND; });
        }
        if ($timedOut) {
            if ($key !== '') {
                Cache::set($key, -1, self::COUNT_GUESS_TTL);
            }
            return -1;
        }
        $n = (int)($r[0]['c'] ?? 0);
        if ($key !== '') {
            Cache::set($key, $n, (int)$GLOBALS['config']['app']['cache_time']);
        }
        return $n;
    }

    /**
     * List at scale (D-L1b, D-L4, D-L5; front end, ajax/data and the admin video list): total from $opt['scale_total'] when
     * known (unfiltered list, ScaleCounts), else a bounded count of COUNT_BOUND + 1 rows ('total_more' = "10,000+"); the
     * pager total never passes the page cap ('total_all' keeps the full number); no query past the cap; deferred join
     * beyond page DEFER_AFTER_PAGE; parent lists as type_id_1 = P (+ UNION ALL type_id = P when the parent holds rows).
     * $opt: totalshow bool, cap int (pages, 0 = none), parent int, scale_total ?int.
     */
    public function listDataScaled($where, $order, $page = 1, $limit = 20, $start = 0, $field = '*', $addition = 1, array $opt = [])
    {
        if (!is_array($where)) {
            $where = json_decode($where, true);
        }
        // S-O1: FULLTEXT pre-filters for the tag / class / actor / director / name LIKE filters (count always, page ids when
        // cheaper than the walk: listDataScaledRun / pageRows); a MATCH the server refuses is run again without it
        $where = self::ftFilterWhere($this->mergeRecycleWhere(is_array($where) ? $where : []));
        if (class_exists('\app\common\util\FilterMatch') && \app\common\util\FilterMatch::inSql($where['_string'] ?? null)) {
            try {
                return $this->listDataScaledRun($where, $order, $page, $limit, $start, $field, $addition, $opt);
            } catch (\Throwable $error) {
                if (!self::ftRefused($error, $where['_string'])) {
                    throw $error;
                }
                $where['_string'] = \app\common\util\FilterMatch::strip($where['_string']);
            }
        }
        return $this->listDataScaledRun($where, $order, $page, $limit, $start, $field, $addition, $opt);
    }

    private function listDataScaledRun(array $where, $order, $page, $limit, $start, $field, $addition, array $opt)
    {
        $responsePage = is_scalar($page) && (int)$page > 0 ? $page : 1;
        $page = max(1, (int)$page);
        $limit = max(1, (int)$limit);
        $start = max(0, (int)$start);
        $cap = max(0, (int)($opt['cap'] ?? 0));
        $parent = max(0, (int)($opt['parent'] ?? 0));
        $where2 = '';
        if (!empty($where['_string'])) {
            $where2 = $where['_string'];
            unset($where['_string']);
        }
        if (preg_match('/^vod_duration (asc|desc)$/iD', trim((string)$order), $durationOrder)) {
            $order = $this->buildDurationOrderExpr(strtolower($durationOrder[1]));
        }
        $offset = $limit * ($page - 1) + $start;
        $total = 0;
        $totalAll = 0;
        $more = false;
        $totalshow = !empty($opt['totalshow']);
        // XVID-NOLIMIT-BRIEF B3: a continuation page (after=<sort value>.<vod_id>) of a list sorted by an integer column
        if (!empty($opt['after']) && is_array($opt['after']) && preg_match('/^(vod_[a-z_]+) (asc|desc)$/D', (string)$order, $ks)
            && in_array($ks[1], self::KEYSET_COLS, true)) {
            // S-O1: the continuation read is bounded by ROW_BUDGET rows of the walk; a MATCH would read all its rows instead
            $res = $this->afterPage($where, self::ftPlain($where2), $ks[1], $ks[2], $opt['after'], $limit, $field, $parent,
                !(class_exists('\app\common\util\FilterMatch') && \app\common\util\FilterMatch::inSql($where2)));
            if ($addition == 1) {
                $res['list'] = $this->addRowInfo($res['list']);
            }
            return $res;
        }
        if ($totalshow && !empty($opt['open_total']) && (!isset($opt['scale_total']) || $opt['scale_total'] === null)) {
            // admin filter on a column without an index at T3 (step 3: the bounded count of the "hidden" chip read 500 k rows,
            // 9 s): no count at all; one extra row tells whether a next page exists and the pager is open-ended ("+")
            return $this->openTotalPage($where, $where2, (string)$order, $page, $responsePage, $offset, $limit, $cap, $field, $parent, $addition);
        }
        // S-O1: a list with a FilterMatch condition reads its matches once through the MATCH (ids + sort value, at most
        // COUNT_BOUND + 1 rows: the same rows its bounded count reads) and pages them in PHP in the walk's order (sort value,
        // then vod_id in the sort direction); the ids are cached like the count. Above COUNT_BOUND the usual path follows.
        $allIds = null;
        $allTried = false;
        if ($totalshow && $start === 0 && !(isset($opt['scale_total']) && $opt['scale_total'] !== null)
            && class_exists('\app\common\util\FilterMatch') && \app\common\util\FilterMatch::inSql($where2)
            && preg_match('/^(vod_[a-z0-9_]+) (asc|desc)$/D', (string)$order, $ks)) {
            $allIds = $this->ftAllIds($where, $where2, $ks[1], $ks[2], $parent, $allLate);
            $allTried = true;
            if ($allIds === null && $allLate) {
                // round 2 (O-S1): the MATCH rows could not be read in COUNT_SEC: no total, the open-ended pager
                return $this->openTotalPage($where, $where2, (string)$order, $page, $responsePage, $offset, $limit, $cap, $field, $parent, $addition);
            }
        }
        // round 6 (D-7): a text-filtered list whose MATCH ids an earlier request (or this request's landing count) read in full
        // (textMatchIds(): page 1 of a walk that stopped before its page) pages through them, every page exact, no count.
        // Round 10 (search-r10 FR-1 / FR-2, orchestrator O-26 (a) / (b)): the page-1 walk, the MATCH-id pages, the counts taken
        // from the landing facts and the "Older" link of the open-ended pager belong to big catalogs ($big: scale tier 3, where
        // FilterMatch leaves dense MATCHes to the walk); below tier 3 FilterMatch prices every MATCH with its probes and these
        // lists count and page as the deployed 1.2.0 (src-final) did: the same numbered pager in every run on the live copy,
        // where the round-6 to 9 paths gave the open-ended pager, or pagers that changed between identical requests
        $big = class_exists('\app\common\util\FilterMatch') && method_exists('\app\common\util\FilterMatch', 'bigCatalog')
            && \app\common\util\FilterMatch::bigCatalog();
        $textList = $big && $totalshow && $start === 0 && !$allTried && !(isset($opt['scale_total']) && $opt['scale_total'] !== null)
            && preg_match('/^vod_time desc$/iD', trim((string)$order)) && self::textLikeFilter($where)
            && !(class_exists('\app\common\util\FilterMatch') && \app\common\util\FilterMatch::inSql($where2));
        if ($textList && self::countLimitSec() > 0 && ($mIds = self::textMatchIds($where, $where2, $parent, false)) !== null) {
            return $this->matchIdsPage($mIds, $where, $where2, $page, $responsePage, $offset, $limit, $cap, $field, $addition);
        }
        // round 2 (O-S1): page 1 of a visitor list without a known total and without a MATCH (filters the FULLTEXT index
        // cannot narrow: single words, CJK values, dense values the row guard sends to the plain LIKE) reads its rows first:
        // fewer than a page is the exact total without any count; a full page counts under COUNT_SEC and, when that runs
        // out, keeps these rows with the open-ended pager (no second read of the page)
        if ($totalshow && $start === 0 && $page === 1 && !$allTried && !(isset($opt['scale_total']) && $opt['scale_total'] !== null)
            && self::countLimitSec() > 0 && !($cap > 0 && $page > $cap)
            && !(class_exists('\app\common\util\FilterMatch') && \app\common\util\FilterMatch::inSql($where2))) {
            // round 5 (synth S-3, flood-r5 R5-2: the verified crawler's tag / actor / class landings read 1.3-22.6 k pages
            // cold; /search/actor/Barbara+Ruvincos/ walked 102,800 rows for page 1): a text filter without an index path (a
            // dense value FilterMatch does not narrow, a value without a vocabulary group) walks the list's newest rows in
            // windows of WALK_PAGE1_ROWS (its D-L1 index range: the T of each window from the index alone) while the walk
            // stays cheap. Round 6 (D-7, R6-3): the budget is the rows this request's statements stepped through
            // (WALK_PAGE1_EXAMINE, session counters; round 5: 600 disk reads of the whole server since the walk began). Rows
            // stay exact and in the list's order; a walk that stops before its page reads the value's MATCH rows (the
            // newest matches, exact, textMatchIds()) when FilterMatch can build a condition for it, else it answers what it
            // found, no total, and continues with after=T.0 ("Older results", B3: the rows below the last window read)
            $p1 = $big && self::textLikeFilter($where) ? $this->boundedFirstPage($where, $where2, (string)$order, $limit + 1, $field, $parent) : null;
            // two snapshots before the page (the second minus the first = what one status read adds itself on servers
            // that count it), one after: the rows the page's walk examined
            if ($p1 !== null) {
                $rows = $p1['rows'];
                $examined = $p1['examined'];
            } else {
                $read00 = self::handlerReads();
                $read0 = $read00 !== null ? self::handlerReads() : null;
                $rows = $this->pageRows($where, $where2, (string)$order, 0, $limit + 1, $field, $parent, false, false);
                $read1 = $read0 !== null ? self::handlerReads() : null;
                $examined = $read1 !== null ? max(0, ($read1 - $read0) - max(0, $read0 - $read00)) : null;
            }
            $first = array_slice($rows, 0, $limit);
            $more = false;
            if ($p1 !== null && $p1['stop'] !== null && count($rows) <= $limit) {
                // round 6 (D-7, search-r6 R6-2 / R6-3): the walk stopped before its page: the newest matches through the MATCH
                // rows FilterMatch can build for the value (exact, the ids cached for the later pages and "Older results")
                if ($textList && ($mIds = self::textMatchIds($where, $where2, $parent, true, true, self::tailSeconds())) !== null) {
                    return $this->matchIdsPage($mIds, $where, $where2, $page, $responsePage, $offset, $limit, $cap, $field, $addition);
                }
                // round 7 (r9 synthesis S-1 "S-R7-P1", live copy: 39 of 953 values got an empty or thin page 1 here): no MATCH
                // path for the value (a LIKE wildcard or an apostrophe in it, only words shorter than the index's shortest
                // word, a MATCH group above MATCH_IDS_MAX, or its MATCH read ran out of time): the rest of the page with the
                // plain LIKE below the walk's stop point under the request's time left (timedWalk(); src-final read these
                // values with one plain LIKE): exact rows; the list's end reached = the exact total; only a read that runs
                // out of time keeps the rows found + "Older results" from where it stopped
                $tail = $this->timedWalk($where, $where2, $parent, (int)$p1['stop'], null, $limit + 1 - count($rows), $field,
                    2 * (int)($p1['size'] ?? self::WALK_PAGE1_ROWS));
                $cursor = (int)$p1['stop'] . '.0';
                if ($tail !== null) {
                    foreach ($tail['rows'] as $r) {
                        $rows[] = $r;
                    }
                    $examined = ($examined !== null && $tail['examined'] !== null) ? (int)$examined + (int)$tail['examined'] : null;
                    $cursor = $tail['cursor'];
                }
                $first = array_slice($rows, 0, $limit);
                if ($tail === null || ($cursor !== null && count($rows) <= $limit)) {
                    // out of time (or a range shape timedWalk() does not read): exact rows, no total, continuation below what
                    // was read
                    if ($addition == 1) {
                        $first = $this->addRowInfo($first);
                    }
                    return ['code' => 1, 'msg' => lang('data_list'), 'page' => $responsePage, 'pagecount' => 1, 'limit' => $limit,
                        'total' => count($first), 'list' => $first, 'total_all' => count($first), 'total_more' => true,
                        'page_cursor_next' => $cursor !== null ? $cursor : (int)$p1['stop'] . '.0', 'list_partial' => 1];
                }
                // a full page with more rows below it, or the whole rest of the list read: the count decision below
            }
            $openEnded = false;
            if (count($rows) <= $limit) {
                $totalAll = count($rows);
            } elseif ($p1 !== null && isset($p1['total']) && $p1['total'] !== null) {
                // round 6 (R5-2): the landing count of this request counted the same list (bounded, exact); round 7 (flood-r7
                // R7-3): kept like a finished bounded count, so the numbered pages 2-501 of this list do not count it again
                $totalAll = (int)$p1['total'];
                $this->rememberCount($where, $where2, $parent, $totalAll);
            } elseif ($textList && ($known = $this->knownTextCount($where, $where2, $parent)) !== null) {
                // round 7 (flood-r7 R7-3): the landing facts of the value hold its count already (every page of the list
                // shows the same pager: "10,000+" with numbered pages for the first 10,000 videos, then Older)
                $totalAll = $known;
            } elseif ($examined !== null && min((float)(self::ftRangeRows($where, $parent) ?? self::tableRows()),
                    $examined * (self::COUNT_BOUND + 1) / ($limit + 1)) > self::COUNT_EXAMINE_MAX) {
                // the page's walk examined that many rows for limit + 1 matches: a count of COUNT_BOUND + 1 matches would
                // examine more than COUNT_EXAMINE_MAX rows (never more than the list's range holds; many matches spread
                // thin: dense LIKE values, single words), so no count at all: the open-ended pager (cached like a count
                // that ran out of time)
                $totalAll = -1;
                $countKey = $this->countCacheKey($where, $where2 . '|p' . $parent . '|r' . self::listRevision());
                if ($countKey !== '') {
                    Cache::set($countKey, -1, self::COUNT_GUESS_TTL);
                }
            } else {
                $totalAll = $this->boundedCount($where, $where2, $parent);
            }
            if ($totalAll < 0) {
                $more = true;
                $openEnded = true;
                $totalAll = $limit * 2; // no total: this page + one more page (the open-ended pager of openTotalPage)
            } elseif ($totalAll > self::COUNT_BOUND) {
                $more = true;
            }
            if ($addition == 1) {
                $first = $this->addRowInfo($first);
            }
            $total = $cap > 0 ? min($totalAll, $cap * $limit) : $totalAll;
            $res = ['code' => 1, 'msg' => lang('data_list'), 'page' => $responsePage, 'pagecount' => (int)ceil($total / $limit), 'limit' => $limit,
                'total' => $total, 'list' => $first, 'total_all' => $totalAll, 'total_more' => $more];
            // B3: a bounded list ("10,000+") whose last numbered page is this one continues with after= from its last row;
            // round 7 (search-r7 R7-3, D-6 condition "every video stays reachable through Older"): so does every page of the
            // open-ended pager (no total), page 1 too
            if ($more && $first && (($openEnded && $big) || $page >= (int)ceil($total / $limit)) && preg_match('/^(vod_[a-z_]+) (asc|desc)$/D', (string)$order, $ks)
                && in_array($ks[1], self::KEYSET_COLS, true)) {
                $last = $first[count($first) - 1];
                if (isset($last[$ks[1]], $last['vod_id'])) {
                    $res['page_cursor_next'] = (int)$last[$ks[1]] . '.' . (int)$last['vod_id'];
                }
            }
            return $res;
        }
        if ($totalshow) {
            if ($allIds !== null) {
                $totalAll = count($allIds);
            } elseif ($allTried) {
                $totalAll = self::COUNT_BOUND + 1; // ftAllIds read COUNT_BOUND + 1 matches: no second pass for the count
                $more = true;
            } elseif (isset($opt['scale_total']) && $opt['scale_total'] !== null) {
                $totalAll = max(0, (int)$opt['scale_total']);
            } else {
                // round 7 (flood-r7 R7-3, O-F1 limit + 1 s: every numbered deep page of a dense refused tag ran a new bounded
                // LIKE count at its time limit, 19,809-100,638 rows): the numbered pages of a text-filtered list take the count
                // the landing of the same value holds (this request's landing count, or its cached facts above COUNT_BOUND),
                // else the bounded count (cached per list revision as before)
                $totalAll = $textList ? $this->knownTextCount($where, $where2, $parent) : null;
                if ($totalAll === null) {
                    $totalAll = $this->boundedCount($where, $where2, $parent);
                }
                if ($totalAll < 0) {
                    // round 2 (O-S1, CJK / single-word / dense filter values): the bounded count ran out of COUNT_SEC, so
                    // no total and no LIKE count over every row: the open-ended pager, the page's own rows still shown
                    return $this->openTotalPage($where, $where2, (string)$order, $page, $responsePage, $offset, $limit, $cap, $field, $parent, $addition);
                }
                $more = $totalAll > self::COUNT_BOUND;
            }
            $total = $cap > 0 ? min($totalAll, $cap * $limit) : $totalAll;
        }
        if ($allIds !== null) {
            $list = ($cap > 0 && $page > $cap) || $offset >= $total ? [] : $this->rowsByIds(array_slice($allIds, $offset, $limit), $where, self::ftPlain($where2), $field);
            if ($addition == 1) {
                $list = $this->addRowInfo($list);
            }
            return ['code' => 1, 'msg' => lang('data_list'), 'page' => $responsePage, 'pagecount' => (int)ceil($total / $limit), 'limit' => $limit,
                'total' => $total, 'list' => $list, 'total_all' => $totalAll, 'total_more' => false];
        }
        $list = [];
        if (!($cap > 0 && $page > $cap) && !($totalshow && $offset >= $total)) {
            $deep = $this->deepRows($opt, $where, $where2, $page, $limit, $start, $field, $totalAll);
            $walkRows = 0;
            if ($deep === null) {
                // S-O1 page planner: the MATCH reads its rows, the walk about (offset + limit) x range / matches rows
                $counted = $totalshow && !$more && !(isset($opt['scale_total']) && $opt['scale_total'] !== null) ? $totalAll : null;
                $rangeRows = self::ftRangeRows($where, $parent);
                $ftIds = self::ftIdsCheaper($where2, $offset + $limit, $rangeRows, $counted);
                if (!$ftIds) {
                    // round-4 fp-port-planner-walk: the walk's price assumes the matches spread over the list; a bound
                    // keeps a value whose rows are all old (525k "Solowork": 450k rows examined, 1.1 s warm) from walking
                    $walkRows = self::ftWalkBound($where2, $offset + $limit, $rangeRows, $counted);
                }
            }
            if ($deep !== null) {
                $list = $deep;
            } else {
                $list = $this->pageRows($where, $where2, (string)$order, $offset, $limit, $field, $parent, $page > self::DEFER_AFTER_PAGE, $ftIds, $walkRows);
                if ($list === null) {
                    // the bounded walk could not fill the page: the MATCH rows in the list's order (the walk's tie order)
                    $list = $this->pageRows($where, $where2, (string)$order, $offset, $limit, $field, $parent, $page > self::DEFER_AFTER_PAGE, true);
                }
            }
        }
        if ($addition == 1) {
            $list = $this->addRowInfo($list);
        }
        if (!$totalshow) {
            $total = count($list);
        }
        $res = ['code' => 1, 'msg' => lang('data_list'), 'page' => $responsePage, 'pagecount' => (int)ceil($total / $limit), 'limit' => $limit,
            'total' => $total, 'list' => $list, 'total_all' => ($totalshow ? $totalAll : $total), 'total_more' => $more];
        // B3: the last numbered page of a bounded list ("10,000+") continues with after= from its last row (never a dead end)
        if ($more && $list && $totalshow && $page >= (int)ceil($total / $limit) && preg_match('/^(vod_[a-z_]+) (asc|desc)$/D', (string)$order, $ks)
            && in_array($ks[1], self::KEYSET_COLS, true)) {
            $last = $list[count($list) - 1];
            if (isset($last[$ks[1]], $last['vod_id'])) {
                $res['page_cursor_next'] = (int)$last[$ks[1]] . '.' . (int)$last['vod_id'];
            }
        }
        return $res;
    }

    /**
     * A page without a total (admin filters on unindexed columns at T3; round 2: visitor lists whose bounded count ran out
     * of COUNT_SEC): one extra row tells whether a next page exists, the pager is open-ended ("+": total = the rows up to
     * this page + one more page when there is one, 'total_more' set).
     */
    private function openTotalPage(array $where, $where2, string $order, int $page, $responsePage, int $offset, int $limit, int $cap, $field, int $parent, $addition): array
    {
        $ftIds = self::ftIdsCheaper($where2, $offset + $limit + 1, self::ftRangeRows($where, $parent), null);
        $list = ($cap > 0 && $page > $cap) ? [] : $this->pageRows($where, $where2, $order, $offset, $limit + 1, $field, $parent, $page > self::DEFER_AFTER_PAGE, $ftIds);
        $more = count($list) > $limit;
        $list = array_slice($list, 0, $limit);
        if ($addition == 1) {
            $list = $this->addRowInfo($list);
        }
        // round 7 (search-r7 R7-3, D-6 conditions): the open-ended pager links pages up to the counted pager's last numbered
        // page (ceil((COUNT_BOUND + 1) / limit) = 501 at 20 per page), never one past it (cold, page 501 of a dense tag linked
        // page 502, which answered 404 once the bounded count was known), and every page of a visitor list with more rows
        // continues with after= from its last row ("Older results"), so every video stays reachable. Round 10 (search-r10
        // FR-2, O-26 (b)): on a big catalog (FilterMatch::bigCatalog(), scale tier 3) only; below tier 3 the open-ended pager is
        // the deployed 1.2.0 one (the next page while rows remain, no "Older"): on the live copy these lists then answer the
        // pager of the deployed code in every run (an open-ended page there links its next page, every video is reached page
        // by page, and a count that is known gives the numbered pager with "Older" after its last numbered page)
        $big = class_exists('\app\common\util\FilterMatch') && method_exists('\app\common\util\FilterMatch', 'bigCatalog')
            && \app\common\util\FilterMatch::bigCatalog();
        $openMax = $big ? (int)ceil((self::COUNT_BOUND + 1) / max(1, $limit)) : PHP_INT_MAX;
        $total = $offset + count($list) + ($more && !($cap > 0 && $page >= $cap) && $page < $openMax ? $limit : 0);
        $res = ['code' => 1, 'msg' => lang('data_list'), 'page' => $responsePage, 'pagecount' => (int)ceil($total / $limit), 'limit' => $limit,
            'total' => $total, 'list' => $list, 'total_all' => $total, 'total_more' => $more];
        if ($big && $more && $list && self::countLimitSec() > 0 && preg_match('/^(vod_[a-z_]+) (asc|desc)$/D', trim($order), $ks)
            && in_array($ks[1], self::KEYSET_COLS, true)) {
            $last = $list[count($list) - 1];
            if (isset($last[$ks[1]], $last['vod_id'])) {
                $res['page_cursor_next'] = (int)$last[$ks[1]] . '.' . (int)$last['vod_id'];
            }
        }
        return $res;
    }

    /**
     * Round 6 (D-7): one page of a list from its textMatchIds() ids (every id of the list in its order): the rows of page
     * $page by primary key (the list's own conditions applied again), the exact total, numbered pages to the last one; no
     * count statement and no walk. A page past the last one is empty (the render answers 404 there).
     */
    private function matchIdsPage(array $m, array $where, $where2, int $page, $responsePage, int $offset, int $limit, int $cap, $field, $addition): array
    {
        $totalAll = (int)$m['total'];
        $total = $cap > 0 ? min($totalAll, $cap * $limit) : $totalAll;
        $list = ($cap > 0 && $page > $cap) || $offset >= $total ? [] : $this->rowsByIds(array_slice($m['ids'], $offset, $limit), $where, self::ftPlain($where2), $field);
        if ($addition == 1) {
            $list = $this->addRowInfo($list);
        }
        // a list longer than the tier's numbered pages continues from its last numbered page with after= (B3), whose
        // continuation pages read the same MATCH ids (afterPage)
        $more = $totalAll > $total;
        $res = ['code' => 1, 'msg' => lang('data_list'), 'page' => $responsePage, 'pagecount' => (int)ceil($total / $limit), 'limit' => $limit,
            'total' => $total, 'list' => $list, 'total_all' => $totalAll, 'total_more' => $more, 'total_exact' => true];
        if ($more && $list && $page >= (int)ceil($total / $limit)) {
            $last = $list[count($list) - 1];
            if (isset($last['vod_time'], $last['vod_id'])) {
                $res['page_cursor_next'] = (int)$last['vod_time'] . '.' . (int)$last['vod_id'];
            }
        }
        return $res;
    }

    /**
     * B1 / B2 rows of a deep page: the rank file of a sorted list (every page), the page map of a by=time list (pages above
     * ListPageMap::AFTER_PAGE). Null = not available here (the caller runs today's query); a sorted list above
     * RANK_LIVE_MAX rows without a usable rank file answers busy on its deep pages until the first build (visitors only).
     */
    private function deepRows(array $opt, array $where, $where2, int $page, int $limit, int $start, $field, int $total): ?array
    {
        if ($start !== 0) {
            return null;
        }
        try {
            if (!empty($opt['rank']) && is_array($opt['rank']) && class_exists('\app\common\util\ListRank')) {
                $r = $opt['rank'];
                // round-4 HO-T-R3-1: a small not= list (page 1 only): $pad more ids, the NOT IN of $where drops them in the
                // primary-key read, the page keeps its first $limit rows
                $pad = max(0, min(self::NOT_PAD_MAX, (int)($r['pad'] ?? 0)));
                if ($pad > 0 && $page !== 1) {
                    return null;
                }
                $ids = \app\common\util\ListRank::page((string)$r['sort'], (string)$r['lk'], $page, $limit + $pad, !empty($r['asc']));
                if ($ids !== null) {
                    $rows = $this->rowsByIds($ids, $where, $where2, $field);
                    return $pad > 0 ? array_slice($rows, 0, $limit) : $rows;
                }
                if ($page > \app\common\util\ListPageMap::AFTER_PAGE && $total > self::RANK_LIVE_MAX && defined('ENTRANCE') && ENTRANCE === 'index') {
                    self::deepBusy();
                }
                return null;
            }
            if (!empty($opt['pagemap']) && is_array($opt['pagemap']) && $page > \app\common\util\ListPageMap::AFTER_PAGE) {
                $m = $opt['pagemap'];
                $ids = \app\common\util\ListPageMap::seek((string)$m['lk'], $page, $limit, $total, !empty($m['asc']), (array)($m['hidden'] ?? []));
                return $ids === null ? null : $this->rowsByIds($ids, $where, $where2, $field);
            }
        } catch (\Throwable $e) {
            if ($e instanceof \PDOException || $e instanceof \think\exception\DbException
                || (class_exists('\app\common\util\DbTimeout') && \app\common\util\DbTimeout::is($e))) {
                throw $e;
            }
            error_log('[Vod] deep page: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 160));
        }
        return null;
    }

    /** B2: the stale copy or the busy page (Retry-After) for a deep sorted page that has no rank file yet. Exits. */
    private static function deepBusy()
    {
        if (class_exists('\app\common\util\EdgeCache') && method_exists('\app\common\util\EdgeCache', 'shed')) {
            \app\common\util\EdgeCache::shed(); // exits
        }
        \app\common\util\RequestGate::busy();
        exit;
    }

    /**
     * B3 continuation page: the rows after the cursor in list order ($col, vod_id), reading at most ROW_BUDGET index rows:
     * the stop point is found first on the cheapest index of the list (index only: recycle / category range), then the
     * filtered read runs between the cursor and the stop point. Fewer than $limit matches inside the budget = the matches
     * found plus a cursor at the stop point (never an empty dead end); nothing past the end = no next cursor. The page is
     * page 1 of an open pager: page_cursor_on, page_cursor_next = '<value>.<vod_id>' or ''. $col is one of KEYSET_COLS (no
     * quoting: ThinkPHP's builder refuses back-quoted names in field / order).
     */
    private function afterPage(array $where, $where2, string $col, string $dir, array $after, int $limit, $field, int $parent, bool $noPrefilter = false): array
    {
        $v = max(0, (int)$after[0]);
        $id = max(0, (int)$after[1]);
        $desc = strtolower($dir) !== 'asc';
        $dir = $desc ? 'desc' : 'asc';
        if ($desc && $col === 'vod_time' && self::textLikeFilter($where) && self::countLimitSec() > 0
            && ($m = self::textMatchIds($where, $where2, $parent)) !== null) {
            // round 6 (D-7, search-r6 R6-2): a text filter's continuation ("Older results", also below a page 1 whose walk
            // stopped) jumps to its next MATCH rows below the cursor (textMatchIds: every match, in order) instead of passing
            // WALK_AFTER_ROWS index rows per page (round 5: about 690 empty pages before the first of 30,000 old matches)
            $ids = self::matchIdsAfter($m, $v, $id, $limit + 1);
            $rows = $this->rowsByIds(array_slice($ids, 0, $limit), $where, $where2, $field);
            $next = '';
            if (count($ids) > $limit && $rows) {
                $last = $rows[count($rows) - 1];
                $next = (int)($last['vod_time'] ?? 0) . '.' . (int)($last['vod_id'] ?? 0);
            }
            return ['code' => 1, 'msg' => lang('data_list'), 'page' => 1, 'pagecount' => 1, 'limit' => $limit, 'total' => count($rows), 'list' => $rows,
                'total_all' => count($rows), 'total_more' => false, 'page_cursor_on' => 1, 'page_cursor_next' => $next, 'page_after' => $v . '.' . $id];
        }
        if ($noPrefilter && $desc && $col === 'vod_time' && self::textLikeFilter($where) && self::countLimitSec() > 0) {
            // round 7 (r9 synthesis S-1 "S-R7-P1"): a text filter without a MATCH path continues with the plain LIKE below the
            // cursor under the request's time left (timedWalk(): exact rows; the list's end = no next link; out of time = the
            // rows found and a cursor where the read stopped) instead of WALK_AFTER_ROWS index rows per page (sparse values:
            // pages with few or no rows before their next match)
            $t = $this->timedWalk($where, $where2, $parent, null, [$v, $id], $limit + 1, $field, self::WALK_PAGE1_ROWS * self::WALK_PAGE1_GROW);
            if ($t !== null) {
                $rows = array_slice($t['rows'], 0, $limit);
                $next = '';
                if (count($t['rows']) > $limit && $rows) {
                    $last = $rows[count($rows) - 1];
                    $next = (int)($last['vod_time'] ?? 0) . '.' . (int)($last['vod_id'] ?? 0);
                } elseif ($t['cursor'] !== null) {
                    $next = (string)$t['cursor'];
                }
                return ['code' => 1, 'msg' => lang('data_list'), 'page' => 1, 'pagecount' => 1, 'limit' => $limit, 'total' => count($rows), 'list' => $rows,
                    'total_all' => count($rows), 'total_more' => false, 'page_cursor_on' => 1, 'page_cursor_next' => $next, 'page_after' => $v . '.' . $id];
            }
        }
        $op = $desc ? '<' : '>';
        $after = $col . ' ' . ($desc ? '<=' : '>=') . ' ' . $v . ' AND (' . $col . ' ' . $op . ' ' . $v . ' OR vod_id ' . $op . ' ' . $id . ')';
        $bq = Db::name('Vod')->field($col . ' AS sv, vod_id')->where('vod_recycle_time', 0);
        $typeEq = self::typeEqOf($where);
        if ($parent > 0) {
            $bq->where('type_id_1', $parent);
        } elseif ($typeEq > 0) {
            $bq->where('type_id', $typeEq)->where('vod_status', 1);
        }
        // round 5 (S-3): a text-filtered list (tag / actor / class / director / name LIKE) continues ("Older results", also the
        // continuation of a bounded page 1) with a smaller budget: its walk reads the rows whole for the LIKE, not only the
        // index (WALK_AFTER_ROWS, ~2,500 reads cold)
        $budget = self::textLikeFilter($where) ? self::WALK_AFTER_ROWS : self::ROW_BUDGET;
        $stopRow = $bq->where($after)->order($col . ' ' . $dir . ', vod_id ' . $dir)->limit($budget - 1, 1)->select();
        $stop = $stopRow ? [(int)$stopRow[0]['sv'], (int)$stopRow[0]['vod_id']] : null;
        $q = Db::name('Vod')->field($field)->where($where)->where($where2)->where($after);
        if ($parent > 0) {
            $q->where('type_id_1|type_id', $parent);
        }
        if ($stop !== null) {
            $q->where($col . ' ' . ($desc ? '>=' : '<=') . ' ' . $stop[0] . ' AND (' . $col . ' ' . ($desc ? '>' : '<') . ' ' . $stop[0]
                . ' OR vod_id ' . ($desc ? '>=' : '<=') . ' ' . $stop[1] . ')');
        }
        $rows = $q->order($col . ' ' . $dir . ', vod_id ' . $dir)->limit($limit + 1)->select();
        $next = '';
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $last = $rows[$limit - 1];
            $next = (int)($last[$col] ?? 0) . '.' . (int)($last['vod_id'] ?? 0);
        } elseif ($stop !== null) {
            $next = $stop[0] . '.' . $stop[1];
        }
        return ['code' => 1, 'msg' => lang('data_list'), 'page' => 1, 'pagecount' => 1, 'limit' => $limit, 'total' => count($rows), 'list' => $rows,
            'total_all' => count($rows), 'total_more' => false, 'page_cursor_on' => 1, 'page_cursor_next' => $next, 'page_after' => $v . '.' . $id];
    }

    /**
     * One page of rows; parent lists through the UNION ALL of their two index ranges; deep pages ids first (deferred join).
     * S-O1: $ftIds (the page planner found the MATCH cheaper) = the ids are read through the FilterMatch condition of
     * $where2, without FORCE INDEX, ids first, sorted with the walk's tie order (vod_id in the sort direction); otherwise the
     * condition is dropped and the list walks its sort index as before. Rows by primary key never carry it.
     */
    private function pageRows(array $where, $where2, string $order, int $offset, int $limit, $field, int $parent, bool $deferred, bool $ftIds = false, int $walkRows = 0)
    {
        $plain = self::ftPlain($where2);
        $bound = '';
        if ($walkRows > 0 && !$ftIds && $plain !== $where2) {
            // round-4 fp-port-planner-walk: the walk of a list whose MATCH the planner found dearer may examine at most about
            // $walkRows rows of its range; a page it cannot fill there returns null (the caller reads the MATCH rows instead)
            $bound = self::walkWindow($where, $parent, $order, $walkRows);
            if ($bound === null) {
                $bound = ''; // no window (range too small, unknown index, other sort): the walk as before
            }
        }
        if (!$ftIds || $plain === $where2) {
            $ftIds = false;
            $where2 = $plain;
        } elseif ($parent === 0) {
            $deferred = true;
            $order = (string)self::ftTieOrder($order); // parent lists below sort with the vod_id tie order already
        }
        if ($bound !== '') {
            $where2 = ($where2 !== '' && $where2 !== null ? '(' . $where2 . ') AND ' : '') . $bound;
            $rows = $this->pageRows($where, $where2, $order, $offset, $limit, $field, $parent, $deferred, false, 0);
            return count($rows) < $limit ? null : $rows;
        }
        $force = $ftIds ? '' : self::forcedIndex($where, $parent, $order);
        if ($parent > 0) {
            if (!preg_match('/^(vod_[a-z_]+) (asc|desc)$/D', $order, $sort)) {
                // an expression sort (duration): the plain OR form
                return Db::name('Vod')->field($field)->where($where)->where($where2)->where('type_id_1|type_id', $parent)
                    ->order($order)->limit($offset . ',' . $limit)->select();
            }
            if (!self::parentHasOwnRows($parent)) {
                // one index range only (the usual case): read it in index order, no UNION and no outer sort (EXPLAIN: the D-L1
                // index, no filesort); ids first, rows by primary key
                $q = Db::name('Vod')->field('vod_id')->where($where)->where($where2)->where('type_id_1', $parent);
                if ($force !== '') {
                    $q->force($force);
                }
                $ids = array_map('intval', $q->order($sort[1] . ' ' . $sort[2] . ', vod_id ' . $sort[2])->limit($offset . ',' . $limit)->column('vod_id'));
                return $this->rowsByIds($ids, $where, $plain, $field);
            }
            $n = $offset + $limit;
            $parts = [];
            $q = Db::name('Vod')->field('vod_id,' . $sort[1] . ' AS sort_v')->where($where)->where($where2)->where('type_id_1', $parent);
            if ($force !== '') {
                $q->force($force);
            }
            $parts[] = $q->order($sort[1] . ' ' . $sort[2] . ', vod_id ' . $sort[2])->limit($n)->buildSql();
            if (self::parentHasOwnRows($parent)) {
                $parts[] = Db::name('Vod')->field('vod_id,' . $sort[1] . ' AS sort_v')->where($where)->where($where2)->where('type_id', $parent)
                    ->order($sort[1] . ' ' . $sort[2] . ', vod_id ' . $sort[2])->limit($n)->buildSql();
            }
            $sql = 'SELECT vod_id FROM (' . implode(' UNION ALL ', $parts) . ') parent_list ORDER BY sort_v ' . strtoupper($sort[2])
                . ', vod_id ' . strtoupper($sort[2]) . ' LIMIT ' . $offset . ',' . $limit;
            $ids = array_map(function ($r) { return (int)$r['vod_id']; }, Db::query($sql));
            return $this->rowsByIds($ids, $where, $plain, $field);
        }
        $q = Db::name('Vod');
        if ($force !== '') {
            $q->force($force);
        }
        if ($deferred) {
            $ids = $q->field('vod_id')->where($where)->where($where2)->order($order)->limit($offset . ',' . $limit)->column('vod_id');
            return $this->rowsByIds($ids, $where, $plain, $field);
        }
        return $q->field($field)->where($where)->where($where2)->order($order)->limit($offset . ',' . $limit)->select();
    }

    /**
     * D-S5 / L6: a visitor search or list query failed. A statement timeout (DbTimeout, C11) gets the stale copy or the busy
     * page (EdgeCache::shed when lane H's edge cache is there, else RequestGate::busy); any other database error is logged
     * and answered with the busy page. Never a second query. Other errors (and admin / CLI requests) are re-thrown.
     */
    public static function visitorQueryFailed(\Throwable $e)
    {
        $db = $e instanceof \PDOException || $e instanceof \think\exception\DbException
            || (class_exists('\app\common\util\DbTimeout') && \app\common\util\DbTimeout::is($e));
        if (!$db || !defined('ENTRANCE') || ENTRANCE !== 'index') {
            throw $e;
        }
        $timeout = class_exists('\app\common\util\DbTimeout') && \app\common\util\DbTimeout::is($e);
        // a timeout under load is an operating event: the framework log (runtime/log, per the site's log setting); any other
        // database error is a real fault: the PHP error log
        $line = '[Vod] visitor query ' . ($timeout ? 'timed out' : 'failed') . ': ' . get_class($e) . ' ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 300);
        if ($timeout && class_exists('\think\Log')) {
            \think\Log::record($line, 'notice');
            \think\Log::save();
        } else {
            error_log($line);
        }
        if (class_exists('\app\common\util\EdgeCache') && method_exists('\app\common\util\EdgeCache', 'shed')) {
            \app\common\util\EdgeCache::shed(); // exits
        }
        \app\common\util\RequestGate::busy();
        exit;
    }

    public function infoData($where,$field='*',$cache=0)
    {
        if(empty($where) || !is_array($where)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $info = [];
        $key = $this->vodDetailCacheKey($where);
        $data_cache = $key !== '' && $field === '*';
        $where = $this->mergeRecycleWhere($where);
        if($GLOBALS['config']['app']['cache_core']==1 && $data_cache) {
            $info = Cache::get($key);
        }

        if($GLOBALS['config']['app']['cache_core']==0 || $cache==0 || empty($info['vod_id'])) {
            $info = $this->field($field)->where($where)->find();
            if (empty($info)) {
                return ['code' => 1002, 'msg' => lang('obtain_err')];
            }
            $info = $info->toArray();
            $info['vod_play_list']=[];
            $info['vod_down_list']=[];
            $info['vod_plot_list']=[];
            $info['vod_pic_screenshot_list']=[];

            if (!empty($info['vod_play_from'])) {
                $info['vod_play_list'] = mac_play_list($info['vod_play_from'], $info['vod_play_url'], $info['vod_play_server'], $info['vod_play_note'], 'play');
            }
            if (!empty($info['vod_down_from'])) {
                $info['vod_down_list'] = mac_play_list($info['vod_down_from'], $info['vod_down_url'], $info['vod_down_server'], $info['vod_down_note'], 'down');
            }
            if (!empty($info['vod_plot_name'])) {
                $info['vod_plot_list'] = mac_plot_list($info['vod_plot_name'], $info['vod_plot_detail']);
            }
            if(!empty($info['vod_pic_screenshot'])){
                $info['vod_pic_screenshot_list'] = mac_screenshot_list($info['vod_pic_screenshot']);
            }


            // Add category metadata.
            if (!empty($info['type_id'])) {
                $type_list = model('Type')->getCache('type_list');
                $info['type'] = $type_list[$info['type_id']];
                $info['type_1'] = $type_list[$info['type']['type_pid']];
            }
            // user groups
            if (!empty($info['group_id'])) {
                $group_list = model('Group')->getCache('group_list');
                $info['group'] = $group_list[$info['group_id']];
            }
            if($GLOBALS['config']['app']['cache_core']==1 && $data_cache && $cache==1) {
                Cache::set($key, $info);
            }
        }
        return ['code'=>1,'msg'=>lang('obtain_ok'),'info'=>$info];
    }

    public function saveData($data)
    {
        return \app\common\util\VodDeletion::withMediaLock(function () use ($data) { return $this->saveDataWithMediaLock($data); });
    }

    private function saveDataWithMediaLock($data)
    {
        $validate = \think\Loader::validate('Vod');
        if(!$validate->check($data)){
            return ['code'=>1001,'msg'=>lang('param_err').': '.$validate->getError() ];
        }
        $vodId = intval($data['vod_id'] ?? 0);
        $oldInfo = [];
        if ($vodId > 0) {
            $old = $this->field('vod_id,vod_en,vod_sub')->where(['vod_id' => ['eq', $vodId]])->find();
            $oldInfo = $old ? $old->toArray() : [];
        }

        $rawTypeId = $data['type_id'] ?? null;
        if (!is_scalar($rawTypeId) || !preg_match('/^[1-9][0-9]*$/D', trim((string)$rawTypeId))) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $typeId = (int)$rawTypeId;
        $type_list = model('Type')->getCache('type_list');
        $type_info = is_array($type_list) && isset($type_list[$typeId]) && is_array($type_list[$typeId])
            ? $type_list[$typeId] : null;
        if ($type_info === null || intval($type_info['type_mid'] ?? 0) !== 1) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $data['type_id'] = $typeId;
        $data['type_id_1'] = intval($type_info['type_pid'] ?? 0);

        if(empty($data['vod_en'])){
            $data['vod_en'] = Pinyin::get($data['vod_name']);
        }

        if(empty($data['vod_letter'])){
            // first CHARACTER, not first byte: substr() cut a multibyte vod_en into an invalid byte -> SQL 1366 on save (step 3:
            // 62 of 1,000 admin edits at 7.1 M with non-Latin titles); the importer stores the same mb form
            $data['vod_letter'] = mb_strtoupper(mb_substr((string)$data['vod_en'], 0, 1, 'UTF-8'), 'UTF-8');
        }

        if(!empty($data['vod_content'])) {
            $pattern_src = '/<img[\s\S]*?src\s*=\s*[\"|\'](.*?)[\"|\'][\s\S]*?>/';
            @preg_match_all($pattern_src, $data['vod_content'], $match_src1);
            if (!empty($match_src1)) {
                foreach ($match_src1[1] as $v1) {
                    $v2 = str_replace($GLOBALS['config']['upload']['protocol'] . ':', 'mac:', $v1);
                    $data['vod_content'] = str_replace($v1, $v2, $data['vod_content']);
                }
            }
            unset($match_src1);
        }

        if(empty($data['vod_blurb'])){
            $data['vod_blurb'] = mac_substring( strip_tags($data['vod_content']) ,100);
        }

        if(empty($data['vod_play_url'])){
            $data['vod_play_url'] = '';
        }
        if(empty($data['vod_down_url'])){
            $data['vod_down_url'] = '';
        }
        if(!empty($data['vod_pic_screenshot'])){
            $data['vod_pic_screenshot'] = str_replace( array(chr(10),chr(13)), array('','#'),$data['vod_pic_screenshot']);
        }
        if(!empty($data['vod_play_from'])) {
            $data['vod_play_from'] = join('$$$', $data['vod_play_from']);
            $data['vod_play_server'] = join('$$$', $data['vod_play_server']);
            $data['vod_play_note'] = join('$$$', $data['vod_play_note']);
            $data['vod_play_url'] = join('$$$', $data['vod_play_url']);
            $data['vod_play_url'] = str_replace( array(chr(10),chr(13)), array('','#'),$data['vod_play_url']);
        }
        else{
            $data['vod_play_from'] = '';
            $data['vod_play_server'] = '';
            $data['vod_play_note'] = '';
            $data['vod_play_url'] = '';
        }

        if(!empty($data['vod_down_from'])) {
            $data['vod_down_from'] = join('$$$', $data['vod_down_from']);
            $data['vod_down_server'] = join('$$$', $data['vod_down_server']);
            $data['vod_down_note'] = join('$$$', $data['vod_down_note']);
            $data['vod_down_url'] = join('$$$', $data['vod_down_url']);
            $data['vod_down_url'] = str_replace(array(chr(10),chr(13)), array('','#'),$data['vod_down_url']);
        }else{
            $data['vod_down_from']='';
            $data['vod_down_server']='';
            $data['vod_down_note']='';
            $data['vod_down_url']='';
        }
        
        if(!empty($data['uptime'])){
            $data['vod_time'] = time();
        }
        if(!empty($data['uptag'])){
            $data['vod_tag'] = mac_get_tag($data['vod_name'], $data['vod_content']);
        }
        unset($data['uptime']);
        unset($data['uptag']);

        $data = VodValidate::formatDataBeforeDb($data);
        if(!empty($data['vod_id'])){
            $where=[];
            $where['vod_id'] = ['eq',$data['vod_id']];
            // fp-port-c (FINISH-PORT-REVIEW-b P2-1): an edit may show the video again (status) or change its tags / actors:
            // its search-queue entry commits with the row (searchTouch below still drains it inline)
            $res = self::queuedWrite(function () use ($where, $data) { return $this->allowField(true)->where($where)->update($data); }, [(int)$data['vod_id']]);
        }
        else{
            $data['vod_plot'] = 0;
            $data['vod_plot_name']='';
            $data['vod_plot_detail']='';
            $data['vod_time_add'] = time();
            $data['vod_time'] = time();
            try {
                $res = $this->allowField(true)->insert($data, false, true);
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), '1062') !== false || stripos($e->getMessage(), 'Duplicate entry') !== false) {
                    return ['code' => 1002, 'msg' => 'This video already exists in the selected category.'];
                }
                throw $e;
            }
            $vodId = intval($res);
            if ($res > 0) {
                try {
                    if (model('VodSearch')->isFrontendEnabled()) {
                        model('VodSearch')->checkAndUpdateTopResults(['vod_id' => $res] + $data);
                    }
                } catch (\Throwable $e) {
                    // Search indexing is secondary; a successful video insert must still succeed.
                    error_log('[Vod::saveData] Search index update failed: ' . $e->getMessage());
                }
            }
        }
        if(false === $res){
            return ['code'=>1002,'msg'=>lang('save_err').': '.$this->getError() ];
        }
        if ((int)$res > 0) {
            // ft-vocab (fp-port): an edit may keep vod_time; the next filter page adds its words. First, so a failing cache
            // call below cannot skip it (touched() never throws)
            if (class_exists('\app\common\util\FilterVocab')) { \app\common\util\FilterVocab::touched([$vodId]); }
            try {
                $fresh = $vodId > 0 ? $this->field('vod_id,vod_en,vod_sub')->where(['vod_id' => ['eq', $vodId]])->find() : null;
                $freshInfo = $fresh && is_object($fresh) ? $fresh->toArray() : (is_array($fresh) ? $fresh : $data);
                $this->invalidateVodDetail($oldInfo);
                $this->invalidateVodDetail($freshInfo);
                $this->touchVodCatalogRevision();
            } catch (\Throwable $e) {
                // Cache invalidation is secondary; the database save has already succeeded.
                error_log('[Vod::saveData] Post-save cache maintenance failed: ' . $e->getMessage());
            }
            // 1.2.0 (D-S6): the saved video is searchable (or gone from search when hidden) after the inline drain.
            self::searchTouch([$vodId], isset($data['vod_status']) && (int)$data['vod_status'] !== 1);
        }
        return ['code'=>1,'msg'=>lang('save_ok')];
    }

    public function savePlot($data)
    {
        return \app\common\util\VodDeletion::withMediaLock(function () use ($data) { return $this->savePlotWithMediaLock($data); });
    }

    private function savePlotWithMediaLock($data)
    {
        $validate = \think\Loader::validate('Vod');
        if(!$validate->check($data)){
            return ['code'=>1001,'msg'=>lang('param_err').': '.$validate->getError() ];
        }
        $vodId = intval($data['vod_id'] ?? 0);
        $old = $vodId > 0 ? $this->field('vod_id,vod_en,vod_sub')->where(['vod_id' => ['eq', $vodId]])->find() : null;
        if ($vodId > 0 && !$old) {
            return ['code'=>1002,'msg'=>lang('obtain_err')];
        }
        $oldInfo = $old ? $old->toArray() : [];

        if(!empty($data['vod_plot_name'])) {
            $data['vod_plot'] = 1;
            $data['vod_plot_name'] = join('$$$', $data['vod_plot_name']);
            $data['vod_plot_detail'] = join('$$$', $data['vod_plot_detail']);
        }else{
            $data['vod_plot'] = 0;
            $data['vod_plot_name']='';
            $data['vod_plot_detail']='';
        }

        if(!empty($data['vod_id'])){
            $where=[];
            $where['vod_id'] = ['eq',$data['vod_id']];
            $res = $this->allowField(true)->where($where)->update($data);
        }
        else{
            $res = false;
        }
        if(false === $res){
            return ['code'=>1002,'msg'=>lang('save_err').': '.$this->getError() ];
        }
        if ((int)$res > 0) {
            if ($vodId > 0 && array_intersect(array_keys($data), ['vod_tag', 'vod_actor', 'vod_class', 'vod_director'])
                && class_exists('\app\common\util\FilterVocab')) {
                // ft-vocab (fp-port): allowField(true) saves whatever columns were posted; first, before the cache calls below
                \app\common\util\FilterVocab::touched([$vodId]);
            }
            $fresh = $vodId > 0 ? $this->field('vod_id,vod_en,vod_sub')->where(['vod_id' => ['eq', $vodId]])->find() : null;
            $this->invalidateVodDetail($oldInfo);
            $this->invalidateVodDetail($fresh ? $fresh->toArray() : $data);
            $this->touchVodCatalogRevision();
        }
        return ['code'=>1,'msg'=>lang('save_ok')];
    }

    public function delData($where)
    {
        if (!is_array($where)) { return ['code'=>1001,'msg'=>lang('param_err')]; }
        try {
            $result=\app\common\util\VodDeletion::deleteWhere($this->mergeRecycleWhere($where));
        } catch (\Throwable $error) {
            $busy=in_array($error->getMessage(), ['Video media is busy. Retry shortly.', 'A related import is running. Pause it and retry deletion.', 'An import is still discovering videos. Pause it and retry deletion.'], true);
            error_log('[VodDeletion] Permanent deletion refused: '.get_class($error));
            return ['code'=>1002,'msg'=>lang($busy ? 'vod/permanent_delete_busy' : 'vod/permanent_delete_failed')];
        }
        foreach ($result['deleted_rows'] as $row) {
            $this->invalidateVodDetail($row);
            foreach (['en','vi','th','id','ko','zh','ja','fr','de','es','pt','ru','ar','tr','hi','ms','tl'] as $locale) {
                Cache::rm('trans_vod_complete_v4_'.$row['vod_id'].'_'.$locale);
            }
        }
        if ($result['deleted'] > 0) {
            // 1.2.0 (D-S6): deleted videos leave the search table (queued; the drain only deletes their docs) and the edge cache.
            self::searchTouch(array_column($result['deleted_rows'], 'vod_id'), true);
            $this->touchVodCatalogRevision();
            Cache::rm('vod_search_top_result_v2_50000');
            Cache::rm($GLOBALS['config']['app']['cache_flag'].'_data_count');
        }
        $result['msg']=lang('vod/permanent_delete_ok');
        if ($result['cleanup_failed']) { $result['msg'].=' '.lang('vod/permanent_delete_cleanup_warning'); }
        unset($result['deleted_rows']);
        return $result;
    }

    public function fieldData($where,$update)
    {
        if (!is_array($update) || !array_intersect(array_keys($update), ['vod_pic','vod_pic_thumb','vod_pic_slide','vod_pic_screenshot','vod_content','vod_blurb','vod_plot_detail','vod_rel_vod'])) { return $this->fieldDataWithMediaLock($where,$update); }
        return \app\common\util\VodDeletion::withMediaLock(function () use ($where,$update) { return $this->fieldDataWithMediaLock($where,$update); });
    }

    private function fieldDataWithMediaLock($where,$update)
    {
        if(!is_array($update) || array_key_exists('vod_recycle_time', $update)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        if (!defined('ENTRANCE') || ENTRANCE !== 'admin') {
            $where = $this->mergeRecycleWhere($where);
        }
        $list = $this->field('vod_id,vod_en,vod_sub')->where($where)->select();
        if (array_intersect(array_keys($update), self::FT_FIELDS)) {
            // fp-port-c (FINISH-PORT-REVIEW-b P2-1): a status change (list switch, batch toggle) or an edit of a searchable field
            // queues the videos for the search table in the same transaction as the row update (searchTouch below stays)
            $qIds = [];
            foreach ($list as $v) { $qIds[] = (int)(is_array($v) ? $v['vod_id'] : $v['vod_id']); }
            $res = self::queuedWrite(function () use ($where, $update) { return $this->allowField(true)->where($where)->update($update); }, $qIds);
        } else {
            $res = $this->allowField(true)->where($where)->update($update);
        }
        if($res===false){
            return ['code'=>1001,'msg'=>lang('set_err').': '.$this->getError() ];
        }
        if ((int)$res > 0 && array_intersect(array_keys($update), ['vod_tag', 'vod_actor', 'vod_class', 'vod_director', 'vod_status'])
            && class_exists('\app\common\util\FilterVocab')) {
            // ft-vocab (fp-port): batch edits of the filter columns; before the cache calls below, so they cannot skip it.
            // fp-port-c: a status change too (a hidden row's item-path hash goes, a row shown again gets its document checked)
            $ftIds = [];
            foreach ($list as $v) { $ftIds[] = (int)(is_array($v) ? $v['vod_id'] : $v['vod_id']); }
            \app\common\util\FilterVocab::touched($ftIds);
        }

        if ((int)$res > 0 && $this->updateAffectsVodOutput($update)) {
            foreach($list as $v){
                $this->invalidateVodDetail(is_array($v) ? $v : $v->toArray());
            }
            $this->touchVodCatalogRevision();
        }
        // 1.2.0 (D-S6): a change of a searchable field, the status or the category re-queues these videos; hit counters do not.
        if ((int)$res > 0 && array_intersect(array_keys($update), self::FT_FIELDS)) {
            $ids = [];
            foreach ($list as $v) {
                $ids[] = (int)(is_array($v) ? $v['vod_id'] : $v['vod_id']);
            }
            self::searchTouch($ids, array_key_exists('vod_status', $update) && !(is_scalar($update['vod_status']) && (int)$update['vod_status'] === 1));
        }

        return ['code'=>1,'msg'=>lang('set_ok')];
    }

    public function updateToday($flag='vod')
    {
        $today = strtotime(date('Y-m-d'));
        $where = ['vod_recycle_time'=>0];
        $where['vod_time'] = ['gt',$today];
        if($flag=='type'){
            $ids = $this->where($where)->column('type_id');
        }
        else{
            $ids = $this->where($where)->column('vod_id');
        }
        if(empty($ids)){
            $ids = [];
        }else{
            $ids = array_unique($ids);
        }
        return ['code'=>1,'msg'=>lang('obtain_ok'),'data'=> join(',',$ids) ];
    }



    private function vodDetailCacheKey(array $where)
    {
        $lookupField = '';
        $lookupValue = '';
        foreach (['vod_id','vod_en','vod_sub'] as $field) {
            if (isset($where[$field]) && is_array($where[$field]) &&
                isset($where[$field][0], $where[$field][1]) && $where[$field][0] === 'eq') {
                if ($lookupField !== '') {
                    return '';
                }
                $lookupField = $field;
                $lookupValue = (string)$where[$field][1];
            }
        }
        if ($lookupField === '' || $lookupValue === '') {
            return '';
        }

        $scope = 'any';
        foreach ($where as $field => $condition) {
            if ($field === $lookupField) {
                continue;
            }
            if ($field === 'vod_status' && is_array($condition) &&
                isset($condition[0], $condition[1]) && $condition[0] === 'eq' && (int)$condition[1] === 1) {
                $scope = 'published';
                continue;
            }
            return '';
        }

        return $GLOBALS['config']['app']['cache_flag'] . '_vod_detail_' .
            $lookupField . '_' . md5($lookupValue) . '_' . $scope;
    }

    private function invalidateVodDetail(array $row)
    {
        if ($this->ftCollect !== null && !empty($row['vod_id'])) {
            $this->ftCollect[(int)$row['vod_id']] = true;
        }
        foreach (['vod_id','vod_en','vod_sub'] as $field) {
            if (isset($row[$field]) && $row[$field] !== '') {
                foreach (['any','published'] as $scope) {
                    Cache::rm($GLOBALS['config']['app']['cache_flag'] . '_vod_detail_' .
                        $field . '_' . md5((string)$row[$field]) . '_' . $scope);
                }
            }
        }
    }

    public function invalidateDetailCache(array $row)
    {
        $this->invalidateVodDetail($row);
    }

    public function clearListCache()
    {
        $this->touchVodCatalogRevision();
    }

    /** C9 (XVID-SCALE-BRIEF D-L12): cached revision of this request. */
    private static $listRevisionCache = null;

    /** C9: revision file runtime/list_revision.json ({"rev":N,"at":unix,"pending":0|1}). */
    private static function listRevisionFile()
    {
        $dir = defined('RUNTIME_PATH') ? rtrim(RUNTIME_PATH, '/\\') : rtrim(sys_get_temp_dir(), '/\\');
        return $dir . DIRECTORY_SEPARATOR . 'list_revision.json';
    }

    private static function readListRevision()
    {
        $file = self::listRevisionFile();
        $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        if (!is_array($data) || !isset($data['rev'])) {
            return ['rev' => 0, 'at' => 0, 'pending' => 0];
        }
        return ['rev' => max(0, (int)$data['rev']), 'at' => max(0, (int)($data['at'] ?? 0)), 'pending' => empty($data['pending']) ? 0 : 1];
    }

    /**
     * C9: current list revision (>= 0). List and page caches put it in their key instead of Cache::tag('vod') (HP-H-2).
     * A bump that was held back by the 5-minute rule is applied here once the 5 minutes have passed.
     */
    public static function listRevision(): int
    {
        if (self::$listRevisionCache !== null) {
            return self::$listRevisionCache;
        }
        $r = self::readListRevision();
        if ($r['pending'] && time() - $r['at'] >= 300) {
            self::bumpListRevision(true);
            $r = self::readListRevision();
        }
        return self::$listRevisionCache = $r['rev'];
    }

    /**
     * C9: bump the list revision. Imports call it at batch / job ends: at most once per 5 minutes (a held-back bump is
     * marked pending and applied by the next reader after the 5 minutes); admin saves pass $force = true.
     */
    public static function bumpListRevision(bool $force = false): void
    {
        $file = self::listRevisionFile();
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) {
            return;
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                return;
            }
            $r = self::readListRevision();
            $now = time();
            if (!$force && $r['at'] > 0 && $now - $r['at'] < 300 && $now >= $r['at']) {
                if ($r['pending']) {
                    return;
                }
                $r['pending'] = 1;
            } else {
                $r = ['rev' => $r['rev'] + 1, 'at' => $now, 'pending' => 0];
            }
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($r)) !== false && !@rename($tmp, $file)) {
                @unlink($tmp);
            }
            self::$listRevisionCache = $r['rev'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Live 1.1 API (2026-10-17): the catalog revision in cache keys, kept for its callers (api Provide / Providedown,
     * All::page_cache_key; admin "Clear cache" reaches it through clearListCache()). 1.2.0 (merge, xv-fix-M-r2): it is
     * the C9 list revision, so imports bump it at most every 5 minutes and admin saves at once (D-L12): the page and API
     * caches survive a running import. The 1.1 newest-video probe every 5 s (FORCE INDEX idx_status_recycle_time) is not
     * used: that index is not in the 1.2.0 index set, and it would re-key every cached page after each import batch.
     * $forceRefresh re-reads the revision file instead of this request's copy.
     */
    public static function catalogRevision($forceRefresh = false)
    {
        if ($forceRefresh) {
            self::$listRevisionCache = null;
        }
        return 'r' . self::listRevision();
    }

    /** Live 1.1 API (2026-10-17): a new catalog revision now. 1.2.0: a forced C9 list revision bump (as an admin save). */
    public static function bumpCatalogRevision()
    {
        try {
            self::bumpListRevision(true);
        } catch (\Throwable $e) {
            // Cache invalidation is secondary; never turn a successful write into an error.
            error_log('[Vod] List cache invalidation failed: ' . $e->getMessage());
        }
    }

    private function touchVodCatalogRevision()
    {
        // 1.2.0 (D-L12): list, search and count caches carry the list revision in their key; an admin save bumps it at once.
        self::bumpListRevision(true);
        // The other caches still tagged 'vod' (page cache until HP-H-2, provider API, sitemap) are cleared as before; the
        // list caches are no longer tagged, so this tag index stays small.
        try {
            Cache::clear($this->name);
        } catch (\Throwable $e) {
            // Cache invalidation is secondary; never turn a successful DB write into an error.
            error_log('[Vod] List cache invalidation failed: ' . $e->getMessage());
        }
    }

    private function updateAffectsVodOutput(array $update)
    {
        if (empty($update)) {
            return false;
        }
        // Public provider routes use this exact atomic hit increment. It is a
        // high-frequency counter update and does not change list membership.
        if (count($update) === 1 && isset($update['vod_hits']) && is_array($update['vod_hits']) &&
            isset($update['vod_hits'][0], $update['vod_hits'][1]) &&
            $update['vod_hits'][0] === 'inc' && (int)$update['vod_hits'][1] === 1) {
            return false;
        }
        // Admin edits to any counter can change displayed values or ordering,
        // so all other non-empty updates invalidate tagged VOD list caches.
        return true;
    }

    private function buildDurationOrderExpr($order)
    {
        $order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';
        $expr = "(CASE
            WHEN vod_duration REGEXP '^[0-9]{1,2}:[0-9]{1,2}:[0-9]{1,2}$' THEN
                CAST(SUBSTRING_INDEX(vod_duration,':',1) AS UNSIGNED) * 3600
                + CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(vod_duration,':',2),':',-1) AS UNSIGNED) * 60
                + CAST(SUBSTRING_INDEX(vod_duration,':',-1) AS UNSIGNED)
            WHEN vod_duration REGEXP '^[0-9]{1,3}:[0-9]{1,2}$' THEN
                CAST(SUBSTRING_INDEX(vod_duration,':',1) AS UNSIGNED) * 60
                + CAST(SUBSTRING_INDEX(vod_duration,':',-1) AS UNSIGNED)
            WHEN vod_duration REGEXP '^[0-9]+$' THEN
                CAST(vod_duration AS UNSIGNED) * 60
            ELSE NULL
        END)";
        return "{$expr} IS NULL, {$expr} {$order}";
    }
}
