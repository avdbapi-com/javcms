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

use think\Cache;
use think\Db;

/**
 * FULLTEXT pre-filter for the video filter pages (ft-filters, 2026-10-17).
 *
 * The actor / tag / class / director (and name) filters match `col LIKE '%v1%' OR col LIKE '%v2%' ...` (mac_like_arr),
 * which reads every row. prefilter() returns an extra condition, MATCH(col) AGAINST(... IN BOOLEAN MODE), that the
 * caller ANDs to that unchanged LIKE: the FULLTEXT index narrows the rows, the LIKE still decides, so the result is the
 * same only if the MATCH keeps EVERY row the LIKE finds. A FULLTEXT index finds words by their start, while LIKE also
 * finds a value inside a longer word ("Wife" in "Housewife", "Censored" in "Uncensored"). So a word of the value is
 * used only when the value itself proves the word starts a word in the row: it follows a separator inside the value.
 *  - word after a separator and before one ("Rin" in "(Rin Sample)")  -> +word   (whole word in every matching row)
 *  - word after a separator at the end of the value ("Sample" in "Ann Sample")   -> +word*  (the row's word starts so)
 *  - the first word of a value that starts with a letter or digit: never (it can end a longer word in the row).
 * v2 (ft-vocab): a word the value cannot prove to start a row word is expanded through the vocabulary of the column
 * (FilterVocab, {prefix}vod_ft_vocab): a single-word value w becomes the group of index words that CONTAIN w
 * (+(t1 t2 ... tn): every row with LIKE '%w%' holds one of them), and the first word f of a multi-word value whose other
 * words give no term becomes the group of index words that END with f (the value continues with a separator, so the
 * row word holding f ends there). A group is used only while the vocabulary is complete (FilterVocab::usable(): built,
 * not stale, caught up with new / re-timed / queued rows) and every word in it is one the index holds (ASCII
 * [a-z0-9_], max(min_token_size, 3) to max_token_size characters, no stopword, at most FilterVocab::GROUP_CAP words);
 * otherwise the value keeps the plain LIKE as in v1. An empty group (no row holds w) becomes +w: the LIKE finds no row.
 * A word is used only if the index holds it on every supported server: printable ASCII values only (separators then
 * match only themselves under general_ci), no LIKE wildcard / escape / apostrophe in the value, at least
 * max(innodb_ft_min_token_size, 3) and at most innodb_ft_max_token_size characters, not an InnoDB default stopword
 * (and, for word*, no stopword starts with it). Several comma values: every value needs a term, else no pre-filter.
 * The index must exist (checked once per CACHE_TTL); otherwise prefilter() returns '' and the LIKE runs as before.
 * A column whose FULLTEXT index was created WITH PARSER (ngram, MeCab, a plugin) keeps the plain LIKE (the parser cuts
 * other words than the built-in one every term relies on).
 * Long words (FT-VOCAB-REVIEW-d P2-1, FT-VOCAB-REVIEW-e P2-1 / P3-1): InnoDB indexes no word longer than
 * innodb_ft_max_token_size, so +word* (the last word of a multi-word value) cannot find a row whose word starting with it
 * is that long; the catalogue has such words in vod_name (up to 231 characters at the default and highest setting, 84).
 * +word* is used only when the column's vocabulary answers that no such word exists (FilterVocab::longPrefix() false).
 * Without that answer it is dropped: the column has no vocabulary (vod_name, a column in another collation or with a
 * parser index, no vocabulary table), the vocabulary is not usable now (not built, stale, switched off, a catch-up that
 * cannot run), or it knows such a word. The value's other terms stay (its whole words, +word); with none left it gets
 * the first-word group or keeps the plain LIKE.
 * JavCMS 1.2.0 (xv-owner-S, S-O1): the size guard is priced by a bounded probe instead of a COUNT. The package skipped a
 * MATCH selecting more than 100,000 rows after an unbounded COUNT of it; that COUNT reads every selected row (MariaDB does
 * not count FULLTEXT hits from the index): 28-54 s cold for "+dick*" ... "+ass*" (510 k - 1.77 M rows) at 7.1 M videos with
 * a 512 MB pool, inside a visitor request. Here the probe reads at most ROWS_PROBE + 1 rows under a PROBE_SEC statement
 * limit: the server decodes the whole posting list first (about 1 us per selected row, warm: 0.11 s at 150 k, 0.49 s at
 * 510 k, 1.78 s at 1.77 M), so a probe that runs out of time twice means a MATCH whose decoding alone costs more than the
 * LIKE it would narrow (dense common values, measured: "big-ass" page 1.85 s with it, 0.16 s without) -> no pre-filter.
 * Otherwise counts take the MATCH (its rows hold every LIKE row and are a subset of the table) and Vod prices the page ids
 * with selected(): the probe's number, or the exact number a finished count read (noteRows), cached ROWS_TTL.
 * JavCMS 1.2.0 port of v2 + package f (fp-port, 2026-10-19): the vocabulary groups, the parser check and the long-word rule
 * of the live package, on top of the bounded probe; every 1.2.0 filter path (visitor lists, landing counts, Topic) uses
 * prefilter(), so each returns exactly the plain LIKE's rows whatever state the search index (vod_ft) is in.
 * Item path (fp-port-b, FINISH-PORT-REVIEW P2-3): items() narrows a visitor tag / class / actor filter whose values hold a
 * CJK / kana / Hangul / Thai character (no word path above) to the ids of the rows whose search document holds the field
 * token of an item that LIKE-matches the value (FilterVocab cols 5-7: the column's items with such a character), plus the
 * search queue, an open bulk window and the exception rows; the LIKE still decides. Only for lists of visible rows
 * (vod_status = 1, vod_recycle_time = 0: the rows the search table holds) and only while the search table is ready, in
 * 'ft' mode, has no rebuild due (but 'langs') and its queue is short. The condition is an id list (ITEMS_MARK) that
 * inSql() / strip() / selected() know, so the 1.2.0 list planner reads its rows by primary key.
 */
final class FilterMatch
{
    /** v2: the capability holds the vocabulary columns and the parser check (a v1 entry would hide both for CACHE_TTL). */
    const CACHE_KEY = 'filter_match_v2_';
    const CACHE_TTL = 600;
    /** Smallest word length used, whatever the server says (an index built before a lower setting holds no shorter words). */
    const MIN_TOKEN_FLOOR = 3;
    /** At most this many words per value go into the MATCH (the longest ones). */
    const MAX_TERMS = 4;
    /** At most this many comma values (OR groups) get a MATCH; longer lists keep the plain LIKE. */
    const MAX_GROUPS = 20;
    /** listData: the page ids use the MATCH only below this many matches (above it the sort index finds a page sooner). */
    const IDS_MAX_TOTAL = 50000;
    /** selected(): at most this many rows are read to price a MATCH (more = "more than ROWS_PROBE"). */
    const ROWS_PROBE = 2000;
    /** Statement limit of one price probe (seconds); two probes over it = HUGE (no pre-filter). */
    const PROBE_SEC = 0.6;
    /** fp-port: one probe that ran past this many times its limit (the server decoded the whole posting list) = HUGE at once. */
    const OVERRUN = 2;
    /** fp-port denseBySample(): ids read (in SAMPLE_SLICES ranges), the estimate's factor over rowLimit(), its statement
     * limit, smallest table. */
    const SAMPLE_ROWS = 4000;
    const SAMPLE_SLICES = 4;
    const SAMPLE_DENSE = 1;
    const SAMPLE_SEC = 0.3;
    const SAMPLE_MIN_IDS = 50000;
    /** indexRows() value of a MATCH whose posting list is too long to be worth decoding. */
    const HUGE = -1;
    /**
     * The live package's row guard (ft-filters 13:07, rowLimit): no MATCH when it selects more rows than this (times one per
     * four comma values): a dense value is found sooner by walking the sort index than by reading every MATCH row. 1.2.0
     * prices it with bounded probes (indexRows), never with the package's unbounded COUNT.
     */
    const MAX_INDEX_ROWS = 100000;
    /** Seconds the row count of one MATCH expression is cached. */
    const ROWS_TTL = 86400;
    /** At most this many words (terms + group words) in one AGAINST; more keeps the plain LIKE. */
    const MAX_TOTAL_TERMS = 400;
    /** Longest word looked up in the vocabulary (= FilterVocab::MAX_WORD). */
    const VOCAB_MAX_WORD = 40;
    /** InnoDB's highest (and default) innodb_ft_max_token_size (vocabLookup()'s default when the server reports none). */
    const INNODB_MAX_TOKEN = 84;
    /** InnoDB's built-in stopword list (MySQL 5.6+ / MariaDB 10.0+ INNODB_FT_DEFAULT_STOPWORD). Always skipped. */
    const STOPWORDS = 'a,about,an,are,as,at,be,by,com,de,en,for,from,how,i,in,is,it,la,of,on,or,that,the,this,to,was,what,when,where,who,will,with,und,www';
    /** One condition build() made (group words may hold "_": InnoDB's built-in parser keeps it inside a word). */
    const COND_RE = "/MATCH\\(`[a-z0-9_]+`\\) AGAINST\\('[a-z0-9_+* ()]+' IN BOOLEAN MODE\\)/";
    /** fp-port-b item path: the marker after every condition items() made, and the condition itself. */
    const ITEMS_MARK = '/*fm-items*/';
    const ITEMS_RE = '/\((?:1=0|`vod_id` IN \([0-9,]+\)(?: OR `vod_id` >= [0-9]+)?|`vod_id` >= [0-9]+)\) \/\*fm-items\*\//';
    /** Item path: most search-table rows read for one filter (more: the plain LIKE), queued ids taken along, ids of an
     * open bulk window (above: no item path), field tokens in one AGAINST, statement limit of the read (s), cache (s). */
    const ITEMS_ROWS_MAX = 10000;
    const ITEMS_QUEUE_MAX = 10000;
    const ITEMS_WINDOW_MAX = 50000;
    const ITEMS_TOKENS_MAX = 400;
    const ITEMS_SEC = 1.0;
    const ITEMS_TTL = 600;

    private static $memo = [];
    private static $rows = [];
    /** fp-port: seconds of the first probe per indexRows() key in this request (a later guard check reuses it). */
    private static $probeSec = [];
    /**
     * JavCMS 1.2.0 round 6 (flood-r6 R5-2): in a visitor request a MATCH with more rows than ROWS_PROBE is priced from the
     * search index's term statistics (statsRows()) instead of the second probe (LIMIT rowLimit() + 1: up to PROBE_SEC of
     * cold reads, 0.62 s / 8,217 rows for "+bbw" on a crawler landing): dense when the statistics put it above
     * STATS_DENSE x rowLimit() (STATS_DENSE > 1: the statistics count a word in every field, so they over-estimate a column).
     */
    const STATS_DENSE = 2;
    /**
     * Round 6 (fix r6 measurement, 7.1 M clustered set, cold): a visitor request leaves a MATCH the statistics put above
     * this many rows to the list's own walk (no pre-filter: HUGE, not dense), without any probe. Reading every row of such a
     * MATCH (the bounded count, the page's ORDER BY: MariaDB fetches all MATCH rows) cost 8-15 k cold reads per landing
     * ('Yuvin B' 12,860 MATCH rows: 15,337 reads, 1.7 s cold, 3.5 s warm, then the statement limit); the walk finds the newest
     * matches of a common value in a few hundred pages, and a walk that stops before its page still reads the MATCH rows
     * (Vod::textMatchIds(), at most Vod::MATCH_IDS_MAX). = Vod::COUNT_BOUND + 1: above it the list could not page through
     * the MATCH ids anyway.
     */
    const STATS_WALK = 10001;
    /** Round 6: words the term table does not hold (below FtIndex::TERM_MIN) count this many documents each in statsRows(). */
    const STATS_MISSING_DF = 1000;
    /** Round 6: indexRows() keys known dense in this request (a spread sample or a finished count above the row guard). */
    private static $dense = [];
    /**
     * Test hooks (tests/ft_filter_match.php): a visitor request true / false instead of the SAPI check (null), and the term
     * statistics' estimate of every condition (an int; false = no statistics) instead of the term table (null).
     */
    public static $visitorHook = null;
    public static $statsHook = null;
    /**
     * JavCMS 1.2.0 round 10 (the final rehearsal on a copy of the live demo site, search-r10 FR-1 / FR-2; orchestrator O-26 (a) /
     * (b)): the term-statistics shortcuts of rounds 6-8 (a visitor request leaves a MATCH the statistics put above STATS_WALK,
     * or dense above STATS_DENSE x the guard, to the list's own walk, without a probe) and the row-budget walks they lead to
     * were measured and needed with 7.1 M videos on 2 cores, where the rows of such a MATCH are cold disk reads. A site below
     * scale tier 3 (ScaleTier: fewer than 1,000,000 published videos, or a lower tier the admin pinned) keeps the deployed
     * 1.2.0 behaviour: every MATCH is priced by its bounded probes and the lists and landings count through it. Measured on the
     * live copy (525,975 videos, a warm pool): with the shortcuts "Amateur" (35,889 matches, a MATCH the probe prices at about
     * 36,000 rows, under the 100,000-row guard) got the open-ended pager and no "10000+" total, and the lists of "cow",
     * "DEEP", "Dirty Talk", "Por", "Rina" and "High Visi" changed their pager between identical cold requests with the time a
     * plain LIKE count got, while the deployed code counted all of them through the MATCH in every run (the statistics count
     * a word in every field of 16 languages: "deep", "cow" and "high" put a tag MATCH of 1,000-5,000 rows above 10,001).
     * Test hook $bigHook: true / false instead of the tier (null).
     */
    public static $bigHook = null;
    private static $anchors;

    /**
     * Round 10: true when the big-catalog shortcuts of rounds 6-8 apply (scale tier 3: 1,000,000 published videos or more,
     * or tier 3 pinned; $bigHook in tests). Read once per request (ScaleTier's snapshot file, no database read); false when the
     * tier cannot be read (the probes then price every MATCH, as the deployed 1.2.0 did).
     */
    public static function bigCatalog()
    {
        if (self::$bigHook !== null) {
            return (bool)self::$bigHook;
        }
        if (isset(self::$memo["\0big"])) {
            return self::$memo["\0big"];
        }
        $tier = 1;
        try {
            if (class_exists(__NAMESPACE__ . '\\ScaleTier')) {
                $tier = (int)(ScaleTier::get()['tier'] ?? 1);
            }
        } catch (\Throwable $error) {
            $tier = 1;
        }
        return self::$memo["\0big"] = $tier >= 3;
    }
    /**
     * JavCMS 1.2.0 round 8 (flood-r8 R8-1, orchestrator O-12): true after a probe that the request's database deadline cut short
     * (its limit lowered by FtIndex::capSec(), or the time already spent): its HUGE answers this request only and is never
     * cached for ROWS_TTL (a deadline says nothing about the value); probeCut() reads and clears it.
     */
    private static $probeCut = false;

    /**
     * Condition to AND to `col LIKE` over mac_like_arr($raw) ('' = none). $col: a column name such as vod_tag;
     * $table: full table name (default: the vod table).
     */
    public static function prefilter($col, $raw, $table = null)
    {
        try {
            if (!is_string($raw) || $raw === '' || !is_string($col) || !preg_match('/^[a-z0-9_]{1,64}$/D', $col)) {
                return '';
            }
            $table = $table === null ? Db::name('vod')->getTable() : (string)$table;
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table)) {
                return '';
            }
            $cap = self::capability($table);
            $vocab = null;
            if (!empty($cap['vocab'][$col]) && class_exists('\app\common\util\FilterVocab')) {
                $vocab = self::vocabLookup(FilterVocab::app(), $cap, $col);
            }
            $sql = self::build($cap, $col, $raw, $vocab);
            if ($sql === '') {
                return '';
            }
            // fp-port: a value a spread sample of rows shows to be dense gets no MATCH and no FULLTEXT probe (denseBySample)
            if (self::denseBySample($table, $col, $raw, $sql)) {
                return '';
            }
            // round-2 verify fix (search-r2 O-S1, import-r2 finding 5): the live package's row guard, priced with bounded
            // probes: no MATCH when it selects more than rowLimit() rows (dense values) or cannot be priced in time (HUGE)
            $rows = self::indexRows($table, $sql, self::listFactor($raw), self::rowLimit($raw));
            if ($rows === self::HUGE || $rows > self::rowLimit($raw)) {
                return '';
            }
            return $sql;
        } catch (\Throwable $error) {
            return '';
        }
    }

    /**
     * fp-port-b item path: condition to AND to `$col LIKE` over mac_like_arr($raw) for a list of VISIBLE rows only
     * (vod_status = 1 AND vod_recycle_time = 0: the caller guarantees it), '' = none. $col: vod_tag, vod_class or vod_actor.
     * Every value must be one itemSafe() accepts (a CJK / kana / Hangul / Thai character of the BMP, no wildcard, escape,
     * control character or item separator, nothing to trim): a row whose column LIKE-matches it holds the match inside one
     * comma item (FtText::splitList), and that item holds the value's RUN character. The vocabulary lists every such item
     * (FilterVocab cols 5-7) and the rows whose search document lacks the item's field token are queued for it, lie in an
     * open bulk window or are exception rows (FilterVocab), so the ids read here are a superset of the LIKE's rows.
     */
    public static function items($col, $raw, $table = null)
    {
        try {
            if (!is_string($col) || !class_exists('\app\common\util\FilterVocab') || !isset(FilterVocab::ITEM_COLS[$col])
                || !class_exists('\app\common\util\FtIndex') || !class_exists('\app\common\util\FtText')
                || !is_string($raw) || $raw === '' || strlen($raw) > 2048) {
                return '';
            }
            $values = explode(',', $raw); // exactly the values mac_like_arr() turns into LIKE '%value%'
            if (count($values) > self::MAX_GROUPS) {
                return '';
            }
            foreach ($values as $value) {
                if (!self::itemSafe($value)) {
                    return ''; // checked first: an ASCII value (most of them) leaves here without any database read
                }
            }
            $table = $table === null ? Db::name('vod')->getTable() : (string)$table;
            if ($table !== (string)config('database.prefix') . 'vod') {
                return '';
            }
            $cap = self::capability($table);
            if (empty($cap['vocab'][$col])) {
                return ''; // no vocabulary for this column (collation, no table): the plain LIKE
            }
            $v = FilterVocab::app();
            $vs = $v->itemState();
            if ($vs === null) {
                return '';
            }
            $ft = self::itemSearchState();
            if ($ft === null) {
                return '';
            }
            $tokens = [];
            foreach (array_unique($values) as $value) {
                $items = $v->itemsLike($col, $value);
                if ($items === null) {
                    return '';
                }
                foreach ($items as $item) {
                    $tok = FtText::fieldToken(FilterVocab::ITEM_PREFIX[$col], $item);
                    if (!preg_match('/^(tg|ac)_[a-z0-9_]{1,81}$/D', $tok)) {
                        return ''; // never reached for a stored item (rowItems() keeps only valid ones); never guess
                    }
                    $tokens[$tok] = true;
                }
            }
            if (count($tokens) > self::ITEMS_TOKENS_MAX) {
                return '';
            }
            $ids = [];
            if ($tokens) {
                $ids = self::itemRows(array_map('strval', array_keys($tokens)), $ft);
                if ($ids === null) {
                    return ''; // too many rows or out of time: the plain LIKE
                }
            }
            foreach (array_merge($ft['queue'], $vs['x']) as $id) {
                $ids[] = (int)$id;
            }
            // every id from the open bulk window's start on, or from the first row read inside a closed window whose search
            // document may still be on its way (FilterVocab 'dpend'), at most ITEMS_WINDOW_MAX ids up to the newest row
            $from = 0;
            foreach ([(int)$ft['window'], (int)($vs['dpend'] ?? 0)] as $w) {
                if ($w > 0 && ($from === 0 || $w < $from)) {
                    $from = $w;
                }
            }
            if ($from > 0) {
                $top = (int)(Db::query('SELECT COALESCE(MAX(`vod_id`), 0) AS m FROM `' . Db::name('vod')->getTable() . '`')[0]['m'] ?? 0);
                if ($top - $from + 1 > self::ITEMS_WINDOW_MAX) {
                    return '';
                }
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) { return $i > 0; })));
            sort($ids, SORT_NUMERIC);
            $parts = [];
            if ($ids) {
                $parts[] = '`vod_id` IN (' . implode(',', $ids) . ')';
            }
            if ($from > 0) {
                $parts[] = '`vod_id` >= ' . $from;
            }
            return '(' . ($parts ? implode(' OR ', $parts) : '1=0') . ') ' . self::ITEMS_MARK;
        } catch (\Throwable $error) {
            return '';
        }
    }

    /**
     * fp-port-b: true when the item path may narrow the LIKE of this one comma value: UTF-8, at most 255 bytes, at least
     * one RUN character of the BMP (FilterVocab::runBmp(); identity under *_general_ci, so the matching row item holds it
     * and is stored), no LIKE wildcard or escape (% _ \: a match could then reach across items), no control character, no
     * FtText item separator (，、; the comma never reaches here), and nothing PHP's trim() removes at either end (splitList
     * trims its items).
     */
    public static function itemSafe($value)
    {
        if (!is_string($value) || $value === '' || strlen($value) > 255 || !mb_check_encoding($value, 'UTF-8')
            || trim($value) !== $value || strpbrk($value, "%_\\,") !== false || preg_match('/[\x00-\x1F\x7F]/', $value)
            || preg_match('/[\x{FF0C}\x{3001}]/u', $value)) {
            return false;
        }
        return (bool)preg_match('/[' . FilterVocab::runBmp() . ']/u', $value);
    }

    /**
     * fp-port-b: the search table as the item path needs it, read once per request (a long-running process: every 2 s):
     * null unless vod_ft exists and is ready, in 'ft' mode, with no rebuild due for a bulk window and at most ITEMS_QUEUE_MAX
     * queued ids; else ['window' => bulk window start (0 = none), 'queue' => queued vod ids, 'key' => the document set's
     * counters (cache key of itemRows())]. items() bounds the id range it reads above the window (ITEMS_WINDOW_MAX).
     */
    private static function itemSearchState()
    {
        static $memo = null;
        static $at = 0.0;
        if ($memo !== null && microtime(true) - $at < 2.0) {
            return $memo === false ? null : $memo;
        }
        $at = microtime(true);
        $memo = false;
        if (!FtIndex::installed()) {
            return null;
        }
        // fp-port-c (FINISH-PORT-REVIEW-b P3-d): the queue first, then the state whose counters key the cached id lists. A
        // drain that commits in between has written the documents of the ids it took out of the queue before its counters
        // move, so the ids read here are in the queue or in a document set at least as new as the key; read the other way
        // round, an id list cached before the drain could be used with a queue read after it, missing the row for a request
        $queue = array_map('intval', array_column(Db::query('SELECT `vod_id` FROM ' . FtIndex::t(FtIndex::T_QUEUE) . ' LIMIT ' . (self::ITEMS_QUEUE_MAX + 1)), 'vod_id'));
        if (count($queue) > self::ITEMS_QUEUE_MAX) {
            return null;
        }
        $s = FtIndex::state(true);
        if (!$s || (int)($s['ready'] ?? 0) !== 1 || (string)($s['mode'] ?? '') !== 'ft' || (string)($s['rebuild_due'] ?? '') === 'bulk') {
            return null; // 'bulk' (conservative): a window's documents were never written; a 'bulk' masked by an earlier reason
                         // (markRebuild keeps the first) is covered by FilterVocab 'dpend' / the document check
        }
        $window = max(0, (int)($s['bulk_job'] ?? 0));
        $key = implode('.', [(int)($s['next_doc_id'] ?? 0), (int)($s['docs'] ?? 0), (int)($s['stale_ops'] ?? 0), (int)($s['built_at'] ?? 0), (int)($s['rebuild_started_at'] ?? 0)]);
        $memo = ['window' => $window, 'queue' => $queue, 'key' => $key];
        return $memo;
    }

    /**
     * fp-port-b: vod ids of the search documents holding one of the field tokens $tokens (one MATCH, optional terms),
     * at most ITEMS_ROWS_MAX, under an ITEMS_SEC statement limit; null when there are more or the read ran out of time.
     * Cached ITEMS_TTL under the document set's counters (any document written, deleted or swapped changes them).
     */
    private static function itemRows(array $tokens, array $ft)
    {
        sort($tokens, SORT_STRING);
        $key = self::cacheKey('items|' . $ft['key'] . '|' . implode(' ', $tokens));
        $hit = null;
        try { $hit = Cache::get($key); } catch (\Throwable $error) { $hit = null; }
        if (is_array($hit) && isset($hit['ids']) && is_array($hit['ids'])) {
            return $hit['ids'];
        }
        if ($hit === 'huge') {
            return null;
        }
        foreach ($tokens as $t) {
            if (!preg_match('/^(tg|ac)_[a-z0-9_]{1,81}$/D', $t)) {
                return null;
            }
        }
        $sql = 'SELECT `vod_id` FROM ' . FtIndex::t(FtIndex::T_FT) . " WHERE MATCH(`ft_text`) AGAINST('" . implode(' ', $tokens) . "' IN BOOLEAN MODE) LIMIT " . (self::ITEMS_ROWS_MAX + 1);
        try {
            $rows = Db::query(FtIndex::limited($sql, self::ITEMS_SEC));
        } catch (\Throwable $error) {
            if (FtIndex::isTimeout($error)) {
                return null;
            }
            throw $error;
        }
        if (count($rows) > self::ITEMS_ROWS_MAX) {
            try { Cache::set($key, 'huge', self::ITEMS_TTL); } catch (\Throwable $error) { /* read again */ }
            return null;
        }
        $ids = array_map('intval', array_column($rows, 'vod_id'));
        try { Cache::set($key, ['ids' => $ids], self::ITEMS_TTL); } catch (\Throwable $error) { /* read again next request */ }
        return $ids;
    }

    /**
     * Rows of $table that the condition $sql (from build()) selects, cached ROWS_TTL per table + expression (the
     * expression names the column): the number a finished count noted (noteRows), else a probe that reads at most
     * ROWS_PROBE + 1 of them (ROWS_PROBE + 1 = "more than ROWS_PROBE"; at most ~0.6 s of cold reads at 0.3 ms per read).
     */
    private static function indexRows($table, $sql, $factor = 1, $limit = 0)
    {
        $key = self::cacheKey($table . '|rows|' . $sql);
        if (isset(self::$rows[$key])) {
            $hit = self::$rows[$key];
        } else {
            $hit = null;
            try { $hit = Cache::get($key); } catch (\Throwable $error) { $hit = null; }
        }
        $firstSec = null;
        if (!is_int($hit)) {
            // round 6 (flood-r6 R5-2): a visitor request asks the term statistics first; the probe (up to ROWS_PROBE + 1 cold
            // row reads) runs only when they leave the answer open: dense above STATS_DENSE x the guard (no probe at all), the
            // list's walk above STATS_WALK (HUGE, not dense, no probe), at most ROWS_PROBE rows by their upper estimate (that
            // estimate, no probe). Round 10 (search-r10 FR-1 / FR-2): only on a big catalog (bigCatalog(): scale tier 3); below
            // it the probes price every MATCH as in the deployed 1.2.0
            $est = self::visitorRequest() && self::bigCatalog() ? self::statsRows($sql) : null;
            if ($est !== null && (int)$limit > self::ROWS_PROBE && $est > self::STATS_DENSE * (int)$limit) {
                $hit = self::HUGE;
                self::markDense($key);
            } elseif ($est !== null && $est > self::STATS_WALK) {
                $hit = self::HUGE; // the list walks (not dense: Vod::textMatchIds() may read it when the walk stops early)
            } elseif ($est !== null && $est <= self::ROWS_PROBE) {
                $hit = (int)$est;
            } else {
                $hit = self::priceProbe($table, $sql, $factor, 0, $firstSec);
                self::$probeSec[$key] = $firstSec;
            }
            if (self::probeCut()) {
                return self::$rows[$key] = $hit; // round 8 (R8-1): cut by the request's deadline: this request only, never cached
            }
            try { Cache::set($key, $hit, self::ROWS_TTL); } catch (\Throwable $error) { /* count again next request */ }
        } elseif (isset(self::$probeSec[$key])) {
            // fp-port: the first probe ran earlier in this request (selected() prices before prefilter() checks the guard):
            // its time decides the shortcut below as if it had run here (7.1 M "+toons": 0.55 s, then a second probe of 1.26 s)
            $firstSec = self::$probeSec[$key];
        }
        // more than ROWS_PROBE: a second bounded probe tells whether the row guard ($limit) is passed (exact up to it, HUGE
        // when it cannot finish in the probe time; one try: the first probe read the index pages); cached like the first
        // answer. When reading the first ROWS_PROBE + 1 rows already took half the probe time, the guard cannot pass in
        // time and the MATCH would cost more than the walk: HUGE at once, without the second probe
        if ($limit > self::ROWS_PROBE && $hit === self::ROWS_PROBE + 1) {
            $est = self::visitorRequest() && self::bigCatalog() ? self::statsRows($sql) : null;
            if ($est !== null && $est > self::STATS_DENSE * (int)$limit) {
                // round 6 (flood-r6 R5-2): a visitor request prices it from the term statistics instead of the second probe
                // (no cold read of up to rowLimit() rows): dense above STATS_DENSE x the guard (round 10: big catalogs only)
                $hit = self::HUGE;
                self::markDense($key);
            } elseif ($est !== null && $est > self::STATS_WALK) {
                $hit = self::HUGE; // more rows than the list should read for one page: its walk (see STATS_WALK)
            } elseif ($firstSec !== null && $firstSec > self::PROBE_SEC * max(1, (int)$factor) / 2) {
                $hit = self::HUGE;
            } elseif ($est !== null) {
                // round 6: under STATS_WALK by the statistics: "more than the probe, under the guard" without the second
                // probe (the pre-filter is used; the counts through it read at most that many rows, under their time limit)
            } else {
                $hit = self::priceProbe($table, $sql, $factor, (int)$limit + 1, $secondSec, 1);
                if ($hit !== self::HUGE && $hit > (int)$limit) {
                    self::markDense($key); // a finished count above the guard (round 6: textMatchIds() never reads it)
                }
            }
            if (self::probeCut()) {
                return self::$rows[$key] = $hit; // round 8 (R8-1): cut by the request's deadline: this request only, never cached
            }
            try { Cache::set($key, $hit, self::ROWS_TTL); } catch (\Throwable $error) { /* count again next request */ }
        }
        return self::$rows[$key] = $hit;
    }

    /** Round 6: true in a visitor request (the front end; admin pages and the command line keep the probes). */
    private static function visitorRequest()
    {
        if (self::$visitorHook !== null) {
            return (bool)self::$visitorHook;
        }
        return PHP_SAPI !== 'cli' && defined('ENTRANCE') && ENTRANCE === 'index';
    }

    /** Round 6: $key (an indexRows() key) is known dense: a spread sample or a finished count put it above the row guard. */
    private static function markDense($key)
    {
        self::$dense[$key] = true;
        try { Cache::set($key . '|dense', 1, self::ROWS_TTL); } catch (\Throwable $error) { /* known again next time */ }
    }

    /** Round 6: true when $key is known dense (markDense(): this request, or cached ROWS_TTL). */
    private static function knownDense($key)
    {
        if (isset(self::$dense[$key])) {
            return true;
        }
        try {
            return Cache::get($key . '|dense') === 1;
        } catch (\Throwable $error) {
            return false;
        }
    }

    /**
     * Round 6 (flood-r6 R5-2): an upper estimate of the rows the single condition $sql (from build()) selects, from the search
     * index's term statistics (FtIndex::T_TERM: documents per word over every searchable field of the published videos,
     * sampled above FtIndex::TERM_EXACT_MAX documents), or null when there are none (search index not installed / not
     * ready / no term refresh yet, or a condition this cannot read). One value: the smallest of its required items; an item
     * +word = df(word), +word* = the df of every word starting with it, +(t1 t2 ...) = the sum; several values (optional
     * groups) = the sum of the groups. A word the table does not hold counts STATS_MISSING_DF documents. Never a probe of the
     * video table: two primary-key / range reads of the term table, memoized per request.
     */
    private static function statsRows($sql)
    {
        if (self::$statsHook !== null) {
            return self::$statsHook === false ? null : (int)self::$statsHook;
        }
        try {
            if (!preg_match("/AGAINST\\('([a-z0-9_+* ()]+)' IN BOOLEAN MODE\\)/", (string)$sql, $m) || !class_exists(__NAMESPACE__ . '\\FtIndex')
                || !FtIndex::installed() || !FtIndex::ready() || (int)(FtIndex::state()['term_at'] ?? 0) <= 0) {
                return null;
            }
            $expr = trim($m[1]);
            $groups = [];
            if ($expr !== '' && $expr[0] === '(') {
                if (!preg_match_all('/\(((?:[^()]|\([^()]*\))*)\)/', $expr, $gm)) {
                    return null;
                }
                $groups = $gm[1];
            } else {
                $groups = [$expr];
            }
            $total = 0;
            foreach ($groups as $g) {
                if (!preg_match_all('/\+(\([a-z0-9_ ]+\)|[a-z0-9_]+\*?)/', $g, $im)) {
                    return null;
                }
                $best = null;
                foreach ($im[1] as $item) {
                    $n = 0;
                    if ($item[0] === '(') {
                        foreach (preg_split('/\s+/', trim($item, '() '), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                            $n += self::statsDf($w, false);
                        }
                    } else {
                        $n = self::statsDf(rtrim($item, '*'), substr($item, -1) === '*');
                    }
                    $best = $best === null ? $n : min($best, $n);
                }
                if ($best === null) {
                    return null;
                }
                $total += $best;
            }
            return $total;
        } catch (\Throwable $error) {
            return null;
        }
    }

    /** Round 6: documents of a word (or of every word starting with it: $prefix) in the term table; STATS_MISSING_DF when none. */
    private static function statsDf($word, $prefix)
    {
        $k = "\0df|" . ($prefix ? '*' : '') . $word;
        if (isset(self::$memo[$k])) {
            return self::$memo[$k];
        }
        $n = null;
        if (preg_match('/^[a-z0-9_]{1,84}$/D', (string)$word) && strncmp((string)$word, 'tg_', 3) !== 0 && strncmp((string)$word, 'ac_', 3) !== 0) {
            $t = FtIndex::t(FtIndex::T_TERM);
            if ($prefix) {
                $r = FtIndex::q('SELECT COALESCE(SUM(df), 0) AS n, COUNT(*) AS k FROM ' . $t . " WHERE term LIKE :p ESCAPE '!' AND term NOT LIKE 'tg!_%' ESCAPE '!' AND term NOT LIKE 'ac!_%' ESCAPE '!'",
                    [':p' => str_replace('_', '!_', (string)$word) . '%']);
                // the words below TERM_MIN are not in the table: one missing-word share for them
                $n = (int)($r[0]['n'] ?? 0) + self::STATS_MISSING_DF;
            } else {
                $r = FtIndex::q('SELECT df FROM ' . $t . ' WHERE term = :t', [':t' => (string)$word]);
                $n = $r ? (int)$r[0]['df'] : self::STATS_MISSING_DF;
            }
        }
        return self::$memo[$k] = ($n === null ? self::STATS_MISSING_DF : $n);
    }

    /**
     * JavCMS 1.2.0 round 6 (orchestrator D-7, search-r6 R6-2): the condition build() makes for $col / $raw WITHOUT the row
     * guard, and whether the guard's knowledge calls it dense: ['sql' => '' | condition, 'dense' => bool]. 'dense' = a spread
     * sample of rows (denseBySample), a finished count (the second probe) or the term statistics (statsRows) put it above
     * the guard. A guard that only ran out of time is not dense: Vod::textMatchIds() reads such a MATCH under its own time
     * limit when the walk of its list stopped before a page. Reads the capability and the vocabulary like prefilter(); never
     * runs a probe of its own.
     */
    public static function plan($col, $raw, $table = null)
    {
        $none = ['sql' => '', 'dense' => false];
        try {
            if (!is_string($raw) || $raw === '' || !is_string($col) || !preg_match('/^[a-z0-9_]{1,64}$/D', $col)) {
                return $none;
            }
            $table = $table === null ? Db::name('vod')->getTable() : (string)$table;
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table)) {
                return $none;
            }
            $cap = self::capability($table);
            $vocab = null;
            if (!empty($cap['vocab'][$col]) && class_exists('\app\common\util\FilterVocab')) {
                $vocab = self::vocabLookup(FilterVocab::app(), $cap, $col);
            }
            $sql = self::build($cap, $col, $raw, $vocab);
            if ($sql === '') {
                return $none;
            }
            $key = self::cacheKey($table . '|rows|' . $sql);
            $rows = self::$rows[$key] ?? null;
            if ($rows === null) {
                try { $rows = Cache::get($key); } catch (\Throwable $error) { $rows = null; }
            }
            $dense = self::knownDense($key) || (is_int($rows) && $rows !== self::HUGE && $rows > self::rowLimit($raw) && $rows !== self::ROWS_PROBE + 1);
            // 'est': the term statistics' upper estimate of its rows (statsRows; null = no statistics, or round 10: below scale
            // tier 3, where the probes price it), so a caller that would read every row of it (Vod::textMatchIds) can leave a
            // large one alone without a probe
            return ['sql' => $sql, 'dense' => $dense, 'est' => self::bigCatalog() ? self::statsRows($sql) : null];
        } catch (\Throwable $error) {
            return $none;
        }
    }

    /**
     * fp-port (7.1 M speed batch): the first FULLTEXT probe of a dense value reads its whole posting list before the server
     * can stop it (cold, 7.1 M videos: "+(big bigass ...)" 2.25 M rows 3.1 s, director "+updating" 6.65 M rows 5 s; a dense
     * tag 2.3 s at 525k), once per ROWS_TTL and value. SAMPLE_ROWS ids in SAMPLE_SLICES primary-key ranges spread over the
     * id space (old and new videos alike; a few hundred pages) tested with the list's own LIKE (mac_like_arr, as listData
     * builds it) estimate the value's rows; more than SAMPLE_DENSE x rowLimit() = dense: HUGE is noted for the condition (as
     * two timed-out probes would) without any probe, and the sort-index walk finds its page at once. It only ever drops a
     * MATCH, so the rows stay the LIKE's; a sparse or unrepresentative sample leaves the probes as they were. Small tables
     * (fewer than SAMPLE_MIN_IDS ids) skip it. Returns true when the condition is (now) noted HUGE.
     */
    private static function denseBySample($table, $col, $raw, $sql)
    {
        $key = self::cacheKey($table . '|rows|' . $sql);
        $hit = self::$rows[$key] ?? null;
        if ($hit === null) {
            try { $hit = Cache::get($key); } catch (\Throwable $error) { $hit = null; }
        }
        if (is_int($hit)) {
            return $hit === self::HUGE;
        }
        if (!function_exists('mac_like_arr')) {
            return false;
        }
        try {
            $likes = array_values(mac_like_arr($raw));
            if (!$likes || count($likes) > self::MAX_GROUPS) {
                return false;
            }
            $top = Db::query('SELECT MAX(`vod_id`) AS m FROM `' . $table . '`');
            $top = (int)($top[0]['m'] ?? 0);
            if ($top < self::SAMPLE_MIN_IDS) {
                return false;
            }
            $cond = implode(' OR ', array_fill(0, count($likes), '`' . $col . '` LIKE ?'));
            $per = intdiv(self::SAMPLE_ROWS, self::SAMPLE_SLICES);
            $ranges = [];
            $bind = [];
            for ($i = 0; $i < self::SAMPLE_SLICES; $i++) {
                $lo = (int)floor($top * (2 * $i + 1) / (2 * self::SAMPLE_SLICES));
                $ranges[] = '`vod_id` BETWEEN ? AND ?';
                $bind[] = $lo;
                $bind[] = $lo + $per - 1;
            }
            $inner = 'COUNT(*) AS n, COALESCE(SUM(' . $cond . '), 0) AS m FROM `' . $table . '` WHERE ' . implode(' OR ', $ranges);
            $kind = self::serverKind();
            // round 8 (flood-r8 R8-1): never past the request's database deadline (out of time: false, the probes decide)
            $sampleSec = method_exists(__NAMESPACE__ . '\\FtIndex', 'capSec') ? FtIndex::capSec(self::SAMPLE_SEC) : self::SAMPLE_SEC;
            if ($kind === 'mariadb') {
                $q = 'SET STATEMENT max_statement_time=' . sprintf('%.2F', $sampleSec) . ' FOR SELECT ' . $inner;
            } elseif ($kind === 'mysql') {
                $q = 'SELECT /*+ MAX_EXECUTION_TIME(' . (int)round($sampleSec * 1000) . ') */ ' . $inner;
            } else {
                $q = 'SELECT ' . $inner;
            }
            $r = Db::query($q, array_merge($likes, $bind));
            $n = (int)($r[0]['n'] ?? 0);
            $m = (int)($r[0]['m'] ?? 0);
        } catch (\Throwable $error) {
            return false; // out of time or no vod_id column: the probes decide
        }
        if ($n < self::SAMPLE_ROWS / 2 || $m * $top <= self::SAMPLE_DENSE * self::rowLimit($raw) * $n) {
            return false;
        }
        self::$rows[$key] = self::HUGE;
        try { Cache::set($key, self::HUGE, self::ROWS_TTL); } catch (\Throwable $error) { /* sample again next request */ }
        self::markDense($key); // round 6: dense, not only slow to price (Vod::textMatchIds() never reads it)
        return true;
    }

    /**
     * Most rows a MATCH for $raw may select and still be faster than the plain LIKE (the live package's rowLimit of
     * 2026-10-17 13:07): MAX_INDEX_ROWS, times one per four comma values (the LIKE tests every value on every row, so a
     * long list makes the scan slower; a 26-value list: LIKE 6.9 s, MATCH over 110k rows 2.5 s).
     */
    public static function rowLimit($raw)
    {
        return self::MAX_INDEX_ROWS * max(1, intdiv(substr_count((string)$raw, ',') + 1, 4));
    }

    /**
     * The probe's time budget factor for $raw: one per four comma values (the package's rowLimit() scaling of 11:17: the
     * LIKE tests every value on every row, so a long list makes the plain scan slower and a longer decode still pays; a
     * 26-value list: LIKE 6.9 s, MATCH over 110 k rows 2.5 s).
     */
    public static function listFactor($raw)
    {
        return max(1, intdiv(substr_count((string)$raw, ',') + 1, 4));
    }

    /**
     * Rows the MATCH $sql selects, at most ROWS_PROBE + 1, or HUGE when two probes in a row need more than PROBE_SEC x
     * $factor (listFactor; the second one finds the index pages cached, so what remains is the posting-list decoding).
     * MariaDB: SET STATEMENT max_statement_time; MySQL 5.7.8+: MAX_EXECUTION_TIME hint; other servers: the elapsed time
     * decides after the fact.
     * Round 8 (flood-r8 R8-1, orchestrator O-12): the statement limit of each try never runs past the request's database
     * deadline (FtIndex::capSec(): the time left - CAP_STEP, at least MIN_CAP); a probe cut short by that deadline (or tried with the
     * time spent) answers HUGE for this request only, without a second try: probeCut() tells indexRows() to cache nothing.
     */
    private static function priceProbe($table, $sql, $factor = 1, $rowsMax = 0, &$elapsed = null, $tries = 2)
    {
        $elapsed = null;
        self::$probeCut = false;
        $limit = self::PROBE_SEC * max(1, (int)$factor);
        $cap = method_exists(__NAMESPACE__ . '\\FtIndex', 'capSec') ? FtIndex::capSec($limit) : $limit;
        $rowsMax = $rowsMax > 0 ? (int)$rowsMax : self::ROWS_PROBE + 1;
        $inner = 'COUNT(*) AS n FROM (SELECT 1 FROM `' . $table . '` WHERE ' . $sql . ' LIMIT ' . $rowsMax . ') fm_probe';
        $kind = self::serverKind();
        if ($kind === 'mariadb') {
            $q = 'SET STATEMENT max_statement_time=' . sprintf('%.2F', $cap) . ' FOR SELECT ' . $inner;
        } elseif ($kind === 'mysql') {
            $q = 'SELECT /*+ MAX_EXECUTION_TIME(' . (int)round($cap * 1000) . ') */ ' . $inner;
        } else {
            $q = 'SELECT ' . $inner;
        }
        for ($try = 0; $try < max(1, (int)$tries); $try++) {
            if ($try > 0 && $kind !== '') {
                // round 8 (R8-1): a second try gets the time left NOW (the first try spent part of it), never past the deadline
                $cap = method_exists(__NAMESPACE__ . '\\FtIndex', 'capSec') ? FtIndex::capSec($limit) : $limit;
                $q = $kind === 'mariadb' ? 'SET STATEMENT max_statement_time=' . sprintf('%.2F', $cap) . ' FOR SELECT ' . $inner
                    : 'SELECT /*+ MAX_EXECUTION_TIME(' . (int)round($cap * 1000) . ') */ ' . $inner;
            }
            $t0 = microtime(true);
            try {
                $res = Db::query($q);
            } catch (\Throwable $error) {
                if (!self::timedOut($error)) {
                    throw $error;
                }
                if ($cap < $limit) {
                    self::$probeCut = true; // round 8: the request's deadline, not the value, stopped it
                    break;
                }
                // fp-port (7.1 M speed batch): the server stops a FULLTEXT probe only after the posting list is decoded. A
                // try that ran past twice its limit ("+(big bigass ...)": 3.26 s and, warm, 3.17 s at 7.1 M; a dense tag
                // 2.3 s at 525k) would cost the same again: HUGE now, without the second try
                if (microtime(true) - $t0 > self::OVERRUN * $limit) {
                    break;
                }
                continue;
            }
            if ($kind === '' && microtime(true) - $t0 > $limit) {
                if (microtime(true) - $t0 > self::OVERRUN * $limit) {
                    break;
                }
                continue;
            }
            $elapsed = microtime(true) - $t0; // the answering try's time (round 2: indexRows() prices the row guard from it)
            return (int)($res[0]['n'] ?? 0);
        }
        return self::HUGE;
    }

    /** Round 8 (R8-1): true (once) when the last priceProbe() was cut short by the request's database deadline. */
    private static function probeCut()
    {
        $cut = self::$probeCut;
        self::$probeCut = false;
        return $cut;
    }

    /** 'mariadb' | 'mysql' (5.7.8+) | '' (no statement limit), from VERSION(), once per request. */
    private static function serverKind()
    {
        if (!isset(self::$memo["\0kind"])) {
            $kind = '';
            try {
                $v = (string)(Db::query('SELECT VERSION() AS v')[0]['v'] ?? '');
                if (stripos($v, 'mariadb') !== false) {
                    $kind = version_compare(preg_replace('/^([0-9.]+).*$/', '$1', $v), '10.1.2', '>=') ? 'mariadb' : '';
                } elseif (preg_match('/^[0-9.]+/', $v, $m)) {
                    $kind = version_compare($m[0], '5.7.8', '>=') ? 'mysql' : '';
                }
            } catch (\Throwable $error) {
                $kind = '';
            }
            self::$memo["\0kind"] = $kind;
        }
        return self::$memo["\0kind"];
    }

    /** True for a statement stopped by its time limit (MariaDB 1969, MySQL 3024). */
    private static function timedOut($error)
    {
        if (class_exists(__NAMESPACE__ . '\\DbTimeout') && DbTimeout::is($error)) {
            return true;
        }
        $msg = $error instanceof \Throwable ? $error->getMessage() : (string)$error;
        return strpos($msg, '1969') !== false || strpos($msg, '3024') !== false || stripos($msg, 'max_statement_time') !== false
            || stripos($msg, 'maximum statement execution time') !== false;
    }

    /**
     * JavCMS 1.2.0 (S-O1): records $rows as the exact number of rows the single condition build() made in $sql selects (a
     * count through that MATCH that ran to its end read exactly these rows; Vod measures them with the session counter
     * Rows_read). Ignored for fragments with no or several conditions.
     */
    public static function noteRows($sql, $rows, $table = null)
    {
        try {
            // fp-port-b: with an items() id list in the same fragment the count read those rows, not the MATCH's: nothing noted
            if (!is_int($rows) || $rows < 0 || strpos((string)$sql, self::ITEMS_MARK) !== false || !preg_match_all(self::COND_RE, (string)$sql, $m) || count(array_unique($m[0])) !== 1) {
                return;
            }
            $table = $table === null ? Db::name('vod')->getTable() : (string)$table;
            $key = self::cacheKey($table . '|rows|' . $m[0][0]);
            self::$rows[$key] = $rows;
            Cache::set($key, $rows, self::ROWS_TTL);
        } catch (\Throwable $error) {
            // the next pricing probes again
        }
    }

    /**
     * JavCMS 1.2.0 (xv-owner-S, S-O1): rows of $table selected by the conditions build() made in the SQL fragment $sql
     * (the largest one when there are several: the server reads the rows of one FULLTEXT index and checks the rest per
     * row), from the same cached number prefilter() uses: exact up to ROWS_PROBE (or when a finished count noted it),
     * ROWS_PROBE + 1 = more, HUGE when any of them is. Null when $sql holds none or the probe fails. Vod uses it to price a
     * MATCH against the sort-index walk of a list page.
     */
    public static function selected($sql, $table = null)
    {
        try {
            if (!self::inSql($sql)) {
                return null;
            }
            // fp-port-b: an items() id list prices itself (its ids are read by primary key); with MATCH conditions too, the
            // server reads the smaller of them and checks the rest per row
            $items = self::itemsSelected($sql);
            if (!preg_match_all(self::COND_RE, $sql, $m)) {
                return $items;
            }
            $table = $table === null ? Db::name('vod')->getTable() : (string)$table;
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table)) {
                return $items;
            }
            if ($items !== null) {
                return $items;
            }
            $n = 0;
            foreach (array_unique($m[0]) as $one) {
                $rows = self::indexRows($table, $one);
                if ($rows === self::HUGE) {
                    return self::HUGE;
                }
                $n = max($n, $rows);
            }
            return $n;
        } catch (\Throwable $error) {
            return null;
        }
    }

    /**
     * The condition for capability $cap (['ok', 'cols' => [col => true], 'min', 'max']); '' when the plain LIKE has to
     * do it alone. Pure (no database access) unless $vocab is given: function (string $kind, string $word): ?array, the
     * vocabulary lookup of this column (FilterVocab::lookup; tests pass a fake); kind 'long' returns ?bool
     * (FilterVocab::longPrefix, see prefixSafe()). vocabLookup() makes it.
     */
    public static function build(array $cap, $col, $raw, $vocab = null)
    {
        if (empty($cap['ok']) || empty($cap['cols'][$col]) || !is_string($raw) || $raw === '' || strlen($raw) > 2048) {
            return '';
        }
        $groups = [];
        foreach (explode(',', $raw) as $value) { // exactly the values mac_like_arr() turns into LIKE '%value%'
            $terms = self::terms($cap, $value, $vocab);
            if ($terms === null) {
                return ''; // this value can match rows the index cannot find: the OR needs the plain LIKE
            }
            $groups[implode(' ', $terms)] = true;
        }
        if (count($groups) > self::MAX_GROUPS || substr_count(implode(' ', array_keys($groups)), ' ') + 1 > self::MAX_TOTAL_TERMS) {
            return '';
        }
        // several values: one AGAINST with one optional group per value = OR (an OR of MATCH() calls cannot use the index)
        $expr = count($groups) === 1 ? key($groups) : '(' . implode(') (', array_keys($groups)) . ')';
        return 'MATCH(`' . $col . "`) AGAINST('" . $expr . "' IN BOOLEAN MODE)";
    }

    /**
     * Boolean-mode terms that every row matching LIKE '%$value%' holds, or null when there are none. $vocab: see build();
     * without it single-word values (and values whose only usable word is the first) get none, as in v1, and the last word
     * gives no +word* term (only a vocabulary can rule out a row word too long for the index; FT-VOCAB-REVIEW-e P2-1).
     */
    public static function terms(array $cap, $value, $vocab = null)
    {
        if (!is_string($value) || $value === '' || strlen($value) > 255 || !preg_match('/^[\x20-\x7E]+$/D', $value)
            || strpbrk($value, "%_\\'") !== false) {
            return null;
        }
        $min = max((int)($cap['min'] ?? 3), self::MIN_TOKEN_FLOOR);
        $max = (int)($cap['max'] ?? 84);
        $stop = array_flip(explode(',', self::STOPWORDS));
        $len = strlen($value);
        preg_match_all('/[A-Za-z0-9]+/', $value, $m, PREG_OFFSET_CAPTURE);
        if (count($m[0]) === 1 && $m[0][0][1] === 0 && strlen($m[0][0][0]) === $len) {
            // one word and nothing else (v2): the index words that contain it; else the plain LIKE
            if ($vocab === null || $len < $min || $len > $max || $len > self::VOCAB_MAX_WORD) {
                return null;
            }
            $word = strtolower($value);
            $group = $vocab('sub', $word);
            $item = is_array($group) ? self::group($group, $word) : null;
            return $item === null ? null : [$item];
        }
        $terms = [];
        $star = null;
        foreach ($m[0] as $hit) {
            list($word, $at) = $hit;
            $n = strlen($word);
            if ($at === 0 || $n < $min || $n > $max) {
                continue; // not proven to start a word in the row, or a length the index may not hold
            }
            $word = strtolower($word);
            if ($at + $n < $len) {
                if (!isset($stop[$word])) {
                    $terms[$word] = $n;
                }
            } else {
                foreach ($stop as $s => $_) {
                    if (strncmp((string)$s, $word, $n) === 0) {
                        continue 2; // a stopword starting with it is not in the index
                    }
                }
                $terms[$word . '*'] = $n;
                $star = $word;
            }
        }
        if ($star !== null && !self::prefixSafe($star, $vocab)) {
            // a row word starting with it may be longer than innodb_ft_max_token_size: InnoDB indexes no such word, so the
            // prefix search would lose that row (FT-VOCAB-REVIEW-d P2-1). Only the column's vocabulary can rule that out
            // (FT-VOCAB-REVIEW-e P2-1: vod_name has no vocabulary and the catalogue holds such words there). The other
            // terms stay.
            unset($terms[$star . '*']);
        }
        if (!$terms && $vocab !== null && $m[0] && $m[0][0][1] === 0 && strlen($m[0][0][0]) <= self::VOCAB_MAX_WORD) {
            // no other word gives a term (v2): the first word ends a row word, since the value goes on with a separator
            $word = strtolower($m[0][0][0]);
            $group = $vocab('suf', $word);
            $item = is_array($group) ? self::group($group, $word) : null;
            return $item === null ? null : [$item];
        }
        if (!$terms) {
            return null;
        }
        arsort($terms);
        $out = [];
        foreach (array_slice(array_keys($terms), 0, self::MAX_TERMS) as $t) {
            $out[] = '+' . $t;
        }
        return $out;
    }

    /**
     * The vocabulary callable of build() / terms() for column $col of capability $cap on the FilterVocab instance $v
     * (FilterVocab::app() in prefilter(); tests pass one on a PDO connection): kinds 'sub' / 'suf' / 'pre' ->
     * FilterVocab::lookup() (?array of words), kind 'long' -> FilterVocab::longPrefix() (?bool, P2-1).
     */
    public static function vocabLookup($v, array $cap, $col)
    {
        $min = max((int)($cap['min'] ?? 3), self::MIN_TOKEN_FLOOR);
        $max = (int)($cap['max'] ?? self::INNODB_MAX_TOKEN);
        $stop = array_flip(explode(',', self::STOPWORDS));
        return function ($kind, $word) use ($v, $col, $min, $max, $stop) {
            if ($kind === 'long') {
                return $v->longPrefix($col, $word, $max);
            }
            return $v->lookup($col, $kind, $word, $min, min($max, FilterVocab::MAX_CHARS), $stop);
        };
    }

    /**
     * True when the term +$word* (the last word of a multi-word value; v1) finds every row word that starts with $word:
     * none is longer than innodb_ft_max_token_size, which InnoDB does not index (FT-VOCAB-REVIEW-d P2-1). Only the
     * column's vocabulary can promise that: $vocab kind 'long' answers false (no such word). Anything else is no proof and
     * the term is not used (FT-VOCAB-REVIEW-e P2-1 / P3-1): true (such a word exists), null (the vocabulary is not usable
     * now: not built, stale, switched off, the catch-up lock busy, the table dropped), and no vocabulary for the column at
     * all ($vocab null: vod_name, a column in another collation or with a parser index, no vocabulary table). The server
     * setting is no evidence either: the catalogue has vod_name words of up to 231 characters at the default and highest 84.
     */
    private static function prefixSafe($word, $vocab)
    {
        return $vocab !== null && $vocab('long', $word) === false;
    }

    /**
     * One required item for a vocabulary group: +t, +(t1 t2 ...), or +word when no index word holds it (no row does).
     * null when a word is not lower-case [a-z0-9_] (never dropped: a missing word could lose rows; never reaches SQL).
     */
    private static function group(array $tokens, $word)
    {
        $ok = [];
        foreach ($tokens as $t) {
            $t = (string)$t; // array keys turn "2040" into an int
            if (!preg_match('/^[a-z0-9_]{1,84}$/D', $t)) {
                return null;
            }
            $ok[$t] = true;
        }
        if (!$ok) {
            return '+' . $word;
        }
        $ok = array_map('strval', array_keys($ok));
        return count($ok) === 1 ? '+' . $ok[0] : '+(' . implode(' ', $ok) . ')';
    }

    /** True when an SQL fragment (a where '_string') holds a condition build() or items() made. */
    public static function inSql($sql)
    {
        return is_string($sql) && ((strpos($sql, 'MATCH(`') !== false && strpos($sql, "' IN BOOLEAN MODE)") !== false) || strpos($sql, self::ITEMS_MARK) !== false);
    }

    /**
     * Replaces each condition build() or items() made in an SQL fragment by 1=1 (fallback when the server refuses them, and
     * the page ids of big match sets). 1=1 keeps every AND and parenthesis around it valid, whatever the caller wrapped.
     */
    public static function strip($sql)
    {
        if (!is_string($sql) || (strpos($sql, 'MATCH(`') === false && strpos($sql, self::ITEMS_MARK) === false)) {
            return $sql;
        }
        if (strpos($sql, self::ITEMS_MARK) !== false) {
            $sql = preg_replace(self::ITEMS_RE, '1=1', $sql);
        }
        return strpos($sql, 'MATCH(`') === false ? $sql : preg_replace(self::COND_RE, '1=1', $sql);
    }

    /** fp-port-b: rows an items() condition reads (its ids, plus the open bulk window's ids up to the newest row); null = none in $sql. */
    private static function itemsSelected($sql)
    {
        if (!is_string($sql) || strpos($sql, self::ITEMS_MARK) === false || !preg_match_all(self::ITEMS_RE, $sql, $m)) {
            return null;
        }
        $n = 0;
        foreach ($m[0] as $one) {
            $k = 0;
            if (preg_match('/`vod_id` IN \(([0-9,]+)\)/', $one, $ids)) {
                $k += substr_count($ids[1], ',') + 1;
            }
            if (preg_match('/`vod_id` >= ([0-9]+)/', $one, $w)) {
                try {
                    $top = (int)(Db::query('SELECT COALESCE(MAX(`vod_id`), 0) AS m FROM `' . Db::name('vod')->getTable() . '`')[0]['m'] ?? 0);
                } catch (\Throwable $error) {
                    $top = (int)$w[1] + self::ITEMS_WINDOW_MAX;
                }
                $k += max(0, $top - (int)$w[1] + 1);
            }
            $n = max($n, $k);
        }
        return $n;
    }

    /** True when $error is the server refusing a MATCH (index dropped since the capability was cached). */
    public static function refused($error)
    {
        $msg = $error instanceof \Throwable ? $error->getMessage() : (string)$error;
        return stripos($msg, 'FULLTEXT') !== false || strpos($msg, '1191') !== false; // ER_FT_MATCHING_KEY_NOT_FOUND
    }

    /**
     * True when $error comes from the deep-page anchor queries: the vod_page_anchor table or the idx_status_recycle_time
     * index (FORCE INDEX) disappeared after pageAnchorsReady() was cached. The caller forgets and takes the plain path.
     */
    public static function anchorFailure($error)
    {
        $msg = $error instanceof \Throwable ? $error->getMessage() : (string)$error;
        return stripos($msg, 'vod_page_anchor') !== false || stripos($msg, 'idx_status_recycle_time') !== false;
    }

    /**
     * True when the deep-page anchors of Vod::listData()/cachedCount() can run here: the table {prefix}vod_page_anchor
     * and the index idx_status_recycle_time (used with FORCE INDEX) exist. Cached like the capability; a site whose
     * schema step has not run yet keeps the plain queries instead of an SQL error.
     */
    public static function pageAnchorsReady()
    {
        if (self::$anchors !== null) {
            return self::$anchors;
        }
        $pre = (string)config('database.prefix');
        $key = self::cacheKey($pre . 'vod_page_anchor');
        $hit = null;
        try { $hit = Cache::get($key); } catch (\Throwable $error) { $hit = null; }
        if ($hit === 1 || $hit === 0) {
            return self::$anchors = $hit === 1;
        }
        $ok = false;
        try {
            $t = Db::query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$pre . 'vod_page_anchor']);
            $i = Db::query("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_status_recycle_time'", [$pre . 'vod']);
            $ok = (int)($t[0]['n'] ?? 0) > 0 && (int)($i[0]['n'] ?? 0) > 0;
        } catch (\Throwable $error) {
            $ok = false;
        }
        try { Cache::set($key, $ok ? 1 : 0, self::CACHE_TTL); } catch (\Throwable $error) { /* check again next request */ }
        return self::$anchors = $ok;
    }

    /** Forget the cached capability and anchor check (this and the next request probe again). */
    public static function forget($table = null)
    {
        self::$memo = [];
        self::$rows = [];
        self::$probeSec = [];
        self::$dense = [];
        self::$anchors = null;
        if (class_exists('\app\common\util\FilterVocab', false)) {
            FilterVocab::forget();
        }
        try {
            Cache::rm(self::cacheKey((string)config('database.prefix') . 'vod_page_anchor'));
        } catch (\Throwable $error) {
            // nothing cached
        }
        try {
            $table = $table === null ? Db::name('vod')->getTable() : (string)$table;
            Cache::rm(self::cacheKey($table));
        } catch (\Throwable $error) {
            // nothing cached
        }
    }

    /**
     * ['ok' => bool, 'cols' => [column => true] with a one-column FULLTEXT index, 'min', 'max', 'vocab' => [column => true]
     * whose words the vocabulary table holds with the column's collation] for $table.
     */
    public static function capability($table)
    {
        if (isset(self::$memo[$table])) {
            return self::$memo[$table];
        }
        $key = self::cacheKey($table);
        $cap = null;
        try { $cap = Cache::get($key); } catch (\Throwable $error) { $cap = null; }
        if (!is_array($cap) || !isset($cap['ok'])) {
            $cap = self::probe($table);
            try { Cache::set($key, $cap, self::CACHE_TTL); } catch (\Throwable $error) { /* probe again next request */ }
        }
        return self::$memo[$table] = $cap;
    }

    private static function cacheKey($table)
    {
        return self::CACHE_KEY . md5((string)config('database.hostname') . '|' . (string)config('database.database') . '|' . $table);
    }

    /**
     * Columns of $table (the video table) whose filter may use the vocabulary: {prefix}vod_ft_vocab exists, its token
     * column has the column's character set and collation (so a token LIKE is the row LIKE), that collation is a
     * *_general_ci one (FilterVocab::COLLATIONS: under the UCA collations NBSP / U+3000 LIKE-match a space, which breaks
     * the first-word groups), and the video table has an index starting with vod_time (the freshness probe reads it).
     */
    private static function probeVocab($table, array $cols)
    {
        $out = [];
        try {
            $pre = (string)config('database.prefix');
            if ($table !== $pre . 'vod' || !class_exists('\app\common\util\FilterVocab')) {
                return $out;
            }
            $tok = Db::query("SELECT CHARACTER_SET_NAME AS s, COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'token'", [$pre . FilterVocab::TABLE]);
            $idx = Db::query("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'vod_time' AND SEQ_IN_INDEX = 1", [$table]);
            if (empty($tok[0]['c']) || (int)($idx[0]['n'] ?? 0) === 0 || !in_array(strtolower((string)$tok[0]['c']), FilterVocab::COLLATIONS, true)) {
                return $out;
            }
            foreach (Db::query('SELECT COLUMN_NAME AS n, CHARACTER_SET_NAME AS s, COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) as $r) {
                $n = strtolower((string)$r['n']);
                if (isset(FilterVocab::COLS[$n]) && !empty($cols[$n]) && (string)$r['c'] === (string)$tok[0]['c'] && (string)$r['s'] === (string)$tok[0]['s']) {
                    $out[$n] = true;
                }
            }
        } catch (\Throwable $error) {
            return [];
        }
        return $out;
    }

    private static function probe($table)
    {
        $off = ['ok' => false, 'cols' => [], 'min' => 3, 'max' => 84];
        try {
            $engine = Db::query('SELECT ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (empty($engine[0]['e']) || strcasecmp($engine[0]['e'], 'InnoDB') !== 0) {
                return $off; // MyISAM / Aria use another word length and a 500-word stopword list
            }
            $vars = [];
            foreach (Db::query("SHOW VARIABLES LIKE 'innodb_ft_%'") as $row) {
                $row = array_values($row);
                $vars[strtolower((string)$row[0])] = (string)$row[1];
            }
            if (!isset($vars['innodb_ft_min_token_size']) || trim($vars['innodb_ft_server_stopword_table'] ?? '') !== ''
                || trim($vars['innodb_ft_user_stopword_table'] ?? '') !== '') {
                return $off; // unknown stopword list: no word can be promised to be in the index
            }
            $cols = [];
            $count = [];
            $rows = Db::query("SELECT INDEX_NAME AS i, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_TYPE = 'FULLTEXT'", [$table]);
            // an index WITH PARSER (MySQL ngram / MeCab, a plugin parser) cuts other words than the built-in parser that
            // every term and group relies on: its column keeps the plain LIKE. Only SHOW CREATE TABLE names the parser.
            $parsed = [];
            if ($rows) {
                if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', (string)$table)) {
                    return $off; // the name goes into the statement below
                }
                $ddl = Db::query('SHOW CREATE TABLE `' . $table . '`');
                $parsed = self::parserIndexes((string)(array_values($ddl[0] ?? [])[1] ?? ''));
                if ($parsed === null) {
                    return $off; // statement not readable: no word can be promised to be in the index
                }
            }
            $parserCols = [];
            foreach ($rows as $row) {
                $count[$row['i']] = ($count[$row['i']] ?? 0) + 1;
                if (isset($parsed[strtolower((string)$row['i'])])) {
                    $parserCols[strtolower((string)$row['c'])] = true;
                }
            }
            foreach ($rows as $row) {
                if ($count[$row['i']] === 1 && !isset($parserCols[strtolower((string)$row['c'])])) {
                    $cols[strtolower($row['c'])] = true; // MATCH(col) needs an index on exactly that column (built-in parser)
                }
            }
            return ['ok' => !empty($cols), 'cols' => $cols, 'min' => (int)$vars['innodb_ft_min_token_size'],
                'max' => (int)($vars['innodb_ft_max_token_size'] ?? 84), 'vocab' => self::probeVocab($table, $cols)];
        } catch (\Throwable $error) {
            return $off;
        }
    }

    /**
     * Lower-case names of the FULLTEXT indexes that SHOW CREATE TABLE ($ddl) lists WITH PARSER (MySQL ngram / MeCab, a
     * plugin parser): they cut words differently from InnoDB's built-in parser, so their columns keep the plain LIKE.
     * Index names may be quoted with backticks, double quotes (ANSI_QUOTES) or not at all (sql_quote_show_create = 0).
     * null when $ddl is not a CREATE TABLE statement or a FULLTEXT line WITH PARSER cannot be read (then nothing is
     * promised and probe() turns the pre-filter off).
     */
    public static function parserIndexes($ddl)
    {
        if (!is_string($ddl) || stripos($ddl, 'CREATE TABLE') === false) {
            return null;
        }
        $out = [];
        foreach (preg_split('/\R/', $ddl) as $line) {
            if (stripos($line, 'WITH PARSER') === false || stripos($line, 'FULLTEXT') === false) {
                continue;
            }
            if (!preg_match('/^\s*FULLTEXT\s+(?:KEY|INDEX)\s+(`(?:[^`]|``)+`|"(?:[^"]|"")+"|[A-Za-z0-9_$]+)/i', $line, $m)) {
                return null;
            }
            $name = $m[1];
            if ($name[0] === '`') {
                $name = str_replace('``', '`', substr($name, 1, -1));
            } elseif ($name[0] === '"') {
                $name = str_replace('""', '"', substr($name, 1, -1));
            }
            $out[strtolower($name)] = true;
        }
        return $out;
    }
}
