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
 * C2 (lane S): search planner over the search table. XVID-SCALE-BRIEF D-S3, D-S4, D-S5, D-S8; logic as the LAB prototype
 * the JavCMS build tools (bench method hybrid4) with the D-S3 changes:
 *  - rare words (df <= 50,000) go to MATCH ... AGAINST (boolean, all required) when the smallest df <= FT_MAX and the
 *    posting sum <= DECODE_MAX; common words become LIKE filters ('% w %', '_' escaped with ESCAPE '!');
 *  - otherwise the newest-walk over the clustered FTS_DOC_ID: deepening windows W0, 2*W0, 4*W0 ... below the previous
 *    one until enough ids are found or the walk budget is spent; W0 = clamp(K*N/R*1.5, 20k, WALK_CAP), divided by the
 *    category's share inside a category (ScaleCounts::share, C4);
 *  - walk budget (1.2.0 verify fixes, G1 all-lang + G1 DB part): WALK_CAP documents (ScaleTier) AND WALK_PAGES pages,
 *    the pages estimated from the document bytes of each window (4 sampled documents per window): translated documents
 *    (D3 all-lang, ~4x the bytes) shrink the walk instead of reading 4x the pages. Round-2 (R2-2): the text bytes per page
 *    read come from the table as stored (FtIndex::pageTextBytes: DATA_LENGTH / docs and the page size of the last term
 *    build), so an uncompressed DYNAMIC table is priced as what it is; PAGE_TEXT_BYTES only when unknown;
 *  - recall (G1b): a rare part (df <= RECALL_DF) the walk cannot fill one page with gets one MATCH under its own short
 *    statement limit (RECALL_SEC; MariaDB SET STATEMENT, MySQL MAX_EXECUTION_TIME hint); before the walk when the walk
 *    budget is expected to hold fewer matches than the page needs, after it when the walk came back short. A timeout
 *    keeps the walk's answer (no second query, no error page); round-2 r2-1: a timeout on a deeper page never ends in
 *    "past the last page" (404): the page keeps the continuation cursor ('partial');
 *  - candidates (round-2 verify fixes G1 DB part, R2-2): a query with an exact token (not the prefix group) that has a
 *    kind-1 term-map row (every doc id of the token, df < D_FULL; FtIndex::TERM_MIN 500) checks those docs by primary key
 *    with the full filter, newest first, instead of walking WALK_PAGES pages: a phrase of 890 matches whose words have
 *    890-6,600 docs reads tens of pages instead of 2,200-4,200; after a first chunk with a low hit rate a rare part goes to
 *    the recall MATCH (its rows are then few);
 *  - hard rule: no FULLTEXT query ever runs with a posting sum above DECODE_MAX (300,000), whatever path asks for it;
 *  - stale or empty statistics (FtIndex::termsStale): words without stats are common -> walk only, never MATCH;
 *  - FT_MAX = clamp(round(300 / ms_per_read), 300, 3000) (daily probe); WALK_CAP from ScaleTier (C3), fallback 150,000;
 *  - prefix: walk path only, last token only, >= 3 characters before any "__" suffix (D2);
 *  - every token must match ^[a-z0-9_]{1,84}$ right before SQL is built; type ids pass intval; the boolean string is bound
 *    (or quoted after the [a-z0-9_ "+] check);
 *  - hidden categories at query time: walk rows and FULLTEXT ids are filtered in PHP (one walk serves the landing facts
 *    and the visitor list of a tag / actor page: per-request memo of visitor requests, also for term-map windows and rows);
 *  - tag / actor tokens missing from fresh statistics have df < TERM_MIN by construction and go to MATCH without a probe
 *    (round-2 O-S1: the newest-slice probe cost more than the MATCH); video codes keep one probe of the newest
 *    FIELD_PROBE_ROWS docs; words keep the 4-slice probe;
 *  - MySQL before 8.0: a MATCH of several rare groups matches the rarest group only and filters the others with LIKE
 *    (5.7 spends 0.45 s in the boolean AND of two small posting lists); round-2 R2-3: a query of several groups whose
 *    tokens are all fresh-known <= RECALL_DF or missing from fresh stats is not probed there (5,000-doc probe windows cost
 *    1,016-1,491 reads per 2-word name on a SHARED host);
 *  - cursor: the walk continues below the cursor (FTS_DOC_ID of the last id of the previous page);
 *  - table in mode 'walk' (no FULLTEXT on this engine): walk only (D-S5).
 * XVID-NOLIMIT-BRIEF C1-C4 (no page limits):
 *  - C1: the FULLTEXT path keeps FT_LIMIT 3,000 newest matches (covers FT_MAX <= 3,000 and every unknown word), so the
 *    last page of a rare query is reachable; a list cut at FT_LIMIT continues below its last doc (cursor);
 *  - C2: a single token (word, tg_ tag, ac_ actor) without a category restriction pages through the term map
 *    (FtIndex::mapRow: every matching doc id, or every step-th plus the exact newest MAP_HEAD and the exact appended ids)
 *    at any depth with an exact total (exact token, like the FULLTEXT path; the walk's prefix rule stays for multi-word
 *    queries and words without a map);
 *  - C3: everything else has numbered pages up to NUMBERED_FIRST (50), then "Older results" (cursor = the next doc id
 *    below what was read); a walk capped with fewer than per hits shows what it found and the next cursor (the old
 *    'newest:N' dead end is gone); cursor 1 = "Oldest results" (the reverse walk from the oldest doc, same caps); a page
 *    above NUMBERED_FIRST without a cursor = msg 'page_range' (the caller answers 404). Round-2 N10: the reverse walk
 *    starts at the first doc of the query's rarest exact token, reads a kind-1 token's own docs when it has one, and a
 *    reverse walk that ends its budget short returns 'prev' (the up-cursor: opt 'up' continues the reverse walk above that
 *    doc), so the "Oldest results" page is never an empty dead end.
 * Errors are never caught here (the caller decides, D-S5 "no fallback after a database error"), except the recall MATCH's
 * own statement limit.
 *
 * search(string $wd, array $opt): array -> ['ids', 'total', 'exact', 'path' 'ft'|'walk'|'map'|'none'|'old', 'has_more',
 * 'cursor' (the doc id the next page continues below when more ids follow, else 0), 'msg']; additive 'next' (C3: the
 * value of the "Older results" link where the page needs one: page NUMBERED_FIRST, a cursor page, the end of a capped walk
 * or of a list cut at FT_LIMIT; else 0); msg is a machine key: '' | 'type_more'
 * (no searchable token) | 'page_range' (a walk page above NUMBERED_FIRST without a cursor) | 'partial' (round-2: the
 * recall MATCH ran out of time on a deeper page; the page keeps 'next'); the caller maps it to a visitor string
 * (template_lang, lane T). Additive: 'oldest' true on the "Oldest results" page and on up-cursor pages; 'prev' (round 2)
 * on those pages: the doc the "Newer results" link continues ABOVE (opt 'up'), 0 = none.
 * $opt: 'type_ids' int[], 'page' int (1-based), 'per' int, 'token' 'tg_<slug>'|'ac_<slug>', 'cursor' int (1 = oldest);
 * optional (additive): 'hidden' int[] (hidden type ids; default: the visitor group's popedom filter); 'up' int (round 2:
 * the oldest-first walk continues above this doc; the page is shown newest first).
 * Additive result key 'found': ids found so far, counted from the first result (FULLTEXT: all ids, <= 1,000; walk: the
 * ids before this page + the ids read for it and one look-ahead id). The pager counts these (D-S4: no link past them).
 */
final class FtPlan
{
    const K = 60;
    const DECODE_MAX = 300000;
    const COMMON = 50000;
    const FALLBACK_MAX = 6000;
    const FT_LIMIT = 3000;
    /** df assumed for a token missing from fresh statistics (below FtIndex::TERM_MIN by construction) */
    const UNKNOWN_DF = 250;
    /** newest docs read to confirm that a word missing from fresh stats is rare (G1c); at most PROBE_MAX probes per query */
    const PROBE_ROWS = 20000;
    const PROBE_MAX = 3;
    /** video code missing from fresh stats: newest docs probed; FIELD_PROBE_HITS matches there = common (walk) */
    const FIELD_PROBE_ROWS = 2000;
    const FIELD_PROBE_HITS = 2;
    const W_MIN = 20000;
    /** C3: numbered pages from page 1 for queries without a term map (then "Older results" cursors) */
    const NUMBERED_FIRST = 50;
    const PER_MAX = 100;
    const WALK_CAP_DEFAULT = 150000;
    /**
     * Page budget of one walk. Measured at 7.1 M (MariaDB 11.4, compressed table): the newest 150,000 English documents
     * (291 text bytes each on average) are 3,208 buffer-pool pages, i.e. ~13,600 text bytes per page read
     * (PAGE_TEXT_BYTES, used while FtIndex::pageTextBytes() is unknown); 1,800 pages x 0.3 ms (VPS SSD random read) = 0.54 s
     * keeps the G1 DB part p95 under 0.6 s.
     */
    const WALK_PAGES = 1800;
    const PAGE_TEXT_BYTES = 13600;
    const REF_DOC_BYTES = 291;
    /** documents sampled per window to price it in pages (4 positions x WINDOW_SAMPLE rows) */
    const WINDOW_SAMPLE = 4;
    const WINDOW_MIN = 1000;
    /** recall MATCH (G1b): rare parts up to this df, under this statement limit (seconds) */
    const RECALL_DF = 5000;
    const RECALL_SEC = 1.0;
    /**
     * Round-3 R3-1 (MySQL before 8.0, SHARED hosts): the recall MATCH of the rarest group fetches every one of its rows
     * (the other groups are LIKE filters there) and MAX_EXECUTION_TIME(1000) let it run 1.25 s on 5.7. There it runs
     * under RECALL_SEC_OLD and only when its priced cold reads (recallCost) fit what is left of RECALL_BUDGET_OLD seconds
     * since the search started (the G1 DB target); otherwise the page keeps the walk's answer with the continuation.
     */
    const RECALL_SEC_OLD = 0.5;
    const RECALL_BUDGET_OLD = 0.6;
    /** candidates path: docs checked by primary key per request at most (~ page reads when cold) */
    const CAND_BUDGET = 1500;
    /** candidates path: the first chunk (its hit rate decides between more candidates and the recall MATCH) */
    const CAND_FIRST = 60;
    const CAND_CHUNK_MAX = 400;

    /** Last plan details (path, windows, rows read, df) for benches and the admin test box. */
    public static $last = [];
    /** Statement limit of the recall MATCH (seconds; RECALL_SEC; tests force a timeout with a tiny value). */
    public static $recallSec = self::RECALL_SEC;

    /** Per-request memo (visitor requests only): df rows, probe answers, walk states, FULLTEXT rows, map rows / windows. */
    private static $memo = [];
    private static $engine = null;

    public static function resetMemo(): void
    {
        self::$memo = [];
        self::$engine = null;
    }

    public static function ftMax(float $msPerRead): int
    {
        $ms = $msPerRead > 0 ? $msPerRead : 0.1;
        return (int)max(300, min(3000, round(300 / $ms)));
    }

    /** Tokens that may reach SQL: ^[a-z0-9_]{1,84}$ only; others are dropped (se-R8c). */
    public static function safeTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if (is_string($t) && preg_match('/^[a-z0-9_]{1,84}$/D', $t)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * LIKE filter of one group: "ft_text LIKE '% t1 t2 %' ESCAPE '!'" ('_' escaped as '!_'); $prefix matches the last
     * token as a prefix ('% teach%'). Null when a token is not safe (nothing is built from it).
     */
    public static function likeOf(array $tokens, bool $prefix = false): ?string
    {
        if (!$tokens || count(self::safeTokens($tokens)) !== count($tokens)) {
            return null;
        }
        $p = str_replace('_', '!_', implode(' ', $tokens));
        return "ft_text LIKE '% " . $p . ($prefix ? "%'" : " %'") . " ESCAPE '!'";
    }

    /** Memo only inside visitor requests: CLI tools and tests change the data between two searches of one process. */
    private static function memoOn(): bool
    {
        return defined('ENTRANCE') && ENTRANCE === 'index' && PHP_SAPI !== 'cli';
    }

    private static function memoKey(string $kind, array $parts): string
    {
        $s = FtIndex::state();
        return $kind . ':' . md5(json_encode([FtIndex::t(FtIndex::T_FT), (int)($s['docs'] ?? 0), (int)($s['next_doc_id'] ?? 0), $parts]));
    }

    /** ['kind' => 'mariadb'|'mysql'|'', 'id' => 110405] of the search connection (one query per request). */
    private static function engine(): array
    {
        if (self::$engine === null) {
            try {
                $v = (string)FtIndex::v('SELECT VERSION()');
            } catch (\Throwable $e) {
                $v = '';
            }
            self::$engine = class_exists(__NAMESPACE__ . '\\DbTimeout') ? DbTimeout::parseVersion($v) : ['kind' => '', 'id' => 0];
        }
        return self::$engine;
    }

    /** Text bytes per page read: the table as stored (FtIndex, last term build), else the measured constant. */
    private static function pageTextBytes(): float
    {
        $v = method_exists(FtIndex::class, 'pageTextBytes') ? FtIndex::pageTextBytes() : 0.0;
        return $v > 0 ? $v : (float)self::PAGE_TEXT_BYTES;
    }

    /** MIN / MAX FTS_DOC_ID (one index read each end; memoized per request). */
    private static function ends(string $ft): array
    {
        $key = self::memoOn() ? self::memoKey('ends', []) : '';
        if ($key !== '' && isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $e = FtIndex::q('SELECT MIN(FTS_DOC_ID) a, MAX(FTS_DOC_ID) b FROM ' . $ft);
        $r = [(int)($e[0]['a'] ?? 0), (int)($e[0]['b'] ?? 0)];
        if ($key !== '') {
            self::$memo[$key] = $r;
        }
        return $r;
    }

    /**
     * A token missing from fresh statistics should have df < TERM_MIN (the term table keeps every token at or above). True =
     * confirmed rare: fewer than k matches in PROBE_ROWS docs (4 slices over the doc range), k = 3 x the matches a
     * df-TERM_MIN word would have there (at least 3); the scan stops at k (a common word costs a few rows). More than
     * PROBE_MAX unknown tokens in one query: the rest count as common (walk only). Measured step 3: with 20 % of the term
     * rows deleted, unprobed common words reached MATCH and ran into the 3 s limit (150 of 1,000 adversarial queries).
     * Video codes (FtText code groups, e.g. erk343): one probe of the newest FIELD_PROBE_ROWS docs instead (a code paid 576
     * reads for the 4 slices); a code that became common after the last term build is new, so its matches are among the
     * newest docs. Tag / actor tokens are not probed (search()).
     */
    private static function probeUnknown(string $token, int $docs, int &$probes, bool $code = false): bool
    {
        $key = self::memoOn() ? self::memoKey('probe', [$token, $code]) : '';
        if ($key !== '' && isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        if (++$probes > self::PROBE_MAX) {
            return false;
        }
        $like = self::likeOf([$token]);
        if ($like === null) {
            return false;
        }
        $ft = FtIndex::t(FtIndex::T_FT);
        list($lo, $hi) = self::ends($ft);
        if ($code) {
            $r = FtIndex::q('SELECT COUNT(*) n FROM (SELECT 1 FROM ' . $ft . ' WHERE FTS_DOC_ID > ' . max($lo - 1, $hi - self::FIELD_PROBE_ROWS) . ' AND FTS_DOC_ID <= ' . $hi
                . ' AND ' . $like . ' LIMIT ' . self::FIELD_PROBE_HITS . ') p');
            $rare = (int)($r[0]['n'] ?? 0) < self::FIELD_PROBE_HITS;
            if ($key !== '') {
                self::$memo[$key] = $rare;
            }
            return $rare;
        }
        $docs = max(1, $docs);
        $window = min(self::PROBE_ROWS, $docs);
        $k = max(3, (int)ceil(3 * $window * FtIndex::TERM_MIN / $docs));
        // 4 slices spread over the whole doc range (clustered catalogs: the newest docs alone are not a fair sample)
        $slice = (int)ceil($window / 4);
        $span = max(1, $hi - $lo + 1);
        $hits = 0;
        $rare = true;
        foreach ([0.125, 0.375, 0.625, 0.875] as $f) {
            $from = $lo + (int)floor($span * $f) - intdiv($slice, 2);
            $r = FtIndex::q('SELECT COUNT(*) n FROM (SELECT 1 FROM ' . $ft . ' WHERE FTS_DOC_ID >= ' . max($lo, $from) . ' AND FTS_DOC_ID < ' . (max($lo, $from) + $slice)
                . ' AND ' . $like . ' LIMIT ' . ($k - $hits) . ') p');
            $hits += (int)($r[0]['n'] ?? 0);
            if ($hits >= $k) {
                $rare = false;
                break;
            }
        }
        if ($key !== '') {
            self::$memo[$key] = $rare;
        }
        return $rare;
    }

    private static function intList($v): array
    {
        $out = [];
        foreach ((array)$v as $x) {
            if (is_scalar($x) && (int)$x > 0 && (int)$x <= 65535) {
                $out[(int)$x] = true;
            }
        }
        return array_keys($out);
    }

    /** Type ids hidden from the current visitor (the popedom filter that list queries apply today). */
    public static function hiddenTypes(): array
    {
        if (!defined('ENTRANCE') || ENTRANCE !== 'index' || (int)($GLOBALS['config']['app']['popedom_filter'] ?? 0) !== 1
            || !function_exists('mac_get_popedom_filter') || !isset($GLOBALS['user']['group']['group_type'])) {
            return [];
        }
        try {
            return self::intList(explode(',', (string)mac_get_popedom_filter($GLOBALS['user']['group']['group_type'])));
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function result(string $path, array $more = []): array
    {
        return array_merge(['ids' => [], 'total' => 0, 'exact' => false, 'path' => $path, 'has_more' => false, 'cursor' => 0, 'msg' => '', 'found' => 0], $more);
    }

    private static function walkCap(): int
    {
        if (class_exists(__NAMESPACE__ . '\\ScaleTier')) {
            try {
                $c = (int)(ScaleTier::get()['walk_cap'] ?? 0);
                if ($c > 0) {
                    return $c;
                }
            } catch (\Throwable $e) {
            }
        }
        return self::WALK_CAP_DEFAULT;
    }

    private static function share(array $types): float
    {
        if (!$types || !class_exists(__NAMESPACE__ . '\\ScaleCounts')) {
            return 1.0;
        }
        try {
            $s = ScaleCounts::share($types);
        } catch (\Throwable $e) {
            $s = null;
        }
        return $s === null ? 1.0 : max(0.0001, min(1.0, (float)$s));
    }

    /** Document frequencies of the tokens from the term table (<= 12 primary-key reads; memoized per request). */
    private static function dfOf(array $tokens): array
    {
        if (!$tokens) {
            return [];
        }
        $key = self::memoOn() ? self::memoKey('df', $tokens) : '';
        if ($key !== '' && isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $df = [];
        $bind = [];
        $in = [];
        foreach (array_values($tokens) as $i => $t) {
            $in[] = ':t' . $i;
            $bind[':t' . $i] = $t;
        }
        foreach (FtIndex::q('SELECT term, df FROM ' . FtIndex::t(FtIndex::T_TERM) . ' WHERE term IN (' . implode(',', $in) . ')', $bind) as $r) {
            $df[(string)$r['term']] = (int)$r['df'];
        }
        if ($key !== '') {
            self::$memo[$key] = $df;
        }
        return $df;
    }

    /**
     * Round-2 (filter parity, import-r2 finding 3 / O-S1): true when the tag / actor token is a whole stored item of at
     * least one published video: a term-table row (df >= TERM_MIN), or one MATCH row of a rarer token (LIMIT 1, posting
     * lists below TERM_MIN). False when the search table cannot answer (not ready, walk mode: the caller keeps LIKE).
     */
    /**
     * Round 2 (O-S1 CJK filter values): true when a value made only of CJK / kana / Hangul / Thai runs (each run >= 2
     * characters, single spaces between runs) cannot occur in the actor / tag / class / director field of any indexed
     * video. Every search document holds the overlapping bigrams of each such run of those fields (FtText::doc: their
     * values are short, far below DOC_RUN_MAX), so a value whose bigrams no document holds together matches no row of a
     * LIKE on those fields either: the visitor filter page answers empty at once instead of a LIKE over every row. False
     * whenever that cannot be proven: index not ready or not in 'ft' mode, a bulk window open, other characters, every
     * bigram a frequent term (then they almost surely meet; the LIKE decides), or the check itself out of time.
     */
    public static function valueAbsent(string $value): bool
    {
        $value = trim($value);
        $run = '[' . FtText::RUN_CLASS . ']{2,}';
        if ($value === '' || strlen($value) > 300 || !preg_match('/^' . $run . '(?: ' . $run . ')*$/u', $value) || !FtIndex::ready()) {
            return false;
        }
        $s = FtIndex::state();
        if ((string)($s['mode'] ?? 'ft') !== 'ft' || (int)($s['bulk_job'] ?? 0) > 0) {
            return false;
        }
        $tokens = [];
        foreach (FtText::groups($value) as $g) {
            if (empty($g['cjk'])) {
                return false;
            }
            foreach ($g['t'] as $t) {
                $tokens[(string)$t] = true;
            }
        }
        $tokens = array_keys($tokens);
        if (!$tokens || count($tokens) > 24) {
            return false;
        }
        foreach ($tokens as $t) {
            if (!preg_match('/^z[a-z0-9]{2,40}$/D', $t)) {
                return false;
            }
        }
        $key = self::memoOn() ? self::memoKey('absent', $tokens) : '';
        if ($key !== '' && isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $df = self::dfOf($tokens);
        $frequent = 0;
        foreach ($tokens as $t) {
            if (($df[$t] ?? 0) > 0) {
                $frequent++;
            }
        }
        $absent = false;
        if ($frequent < count($tokens)) {
            try {
                $rows = FtIndex::q(FtIndex::limited('SELECT FTS_DOC_ID FROM ' . FtIndex::t(FtIndex::T_FT) . ' WHERE MATCH(ft_text) AGAINST('
                    . FtIndex::pdo()->quote('+' . implode(' +', $tokens)) . ' IN BOOLEAN MODE) LIMIT 1', 0.5));
                $absent = !$rows;
            } catch (\Throwable $e) {
                $absent = false; // out of time or refused: not proven, the LIKE decides
            }
        }
        if ($key !== '') {
            self::$memo[$key] = $absent;
        }
        return $absent;
    }

    /** Round 5 (D-1): seconds a tag-route proof (or its refusal) is kept in the app cache, keyed by everything it read. */
    const ROUTE_TTL = 600;
    /** Round 5 (D-1): flagged vocabulary words read by one fold check at most (more = not proven). */
    const ROUTE_FOLD_ROWS = 5000;
    /**
     * Characters FtText folds to ASCII letters that utf8mb4_general_ci does not equate with that fold (measured on MariaDB
     * 11.4 for the 528 entries of FtText's fold map: 37 of them, upper and lower case): a stored word with one of them can
     * share a field token with an ASCII word without LIKE-matching it ("bløwjob" -> tg_blowjob, but 'ø' <> 'o').
     */
    const ROUTE_FOLD_APART = ['Æ', 'Ð', 'Ø', 'Þ', 'ß', 'æ', 'ð', 'ø', 'þ', 'Đ', 'đ', 'Ħ', 'ħ', 'Ĳ', 'ĳ', 'ĸ', 'Ł', 'ł', 'Ŋ', 'ŋ', 'Œ', 'œ', 'Ŧ', 'ŧ',
        'Ǆ', 'ǅ', 'ǆ', 'Ǉ', 'ǈ', 'ǉ', 'Ǌ', 'ǋ', 'ǌ', 'Ǳ', 'ǲ', 'ǳ', 'ẞ'];
    /** Test hook (tests/ft_filter_match.php): [value => token|null] answers instead of the proof. */
    public static $routeHook = null;
    /** Last tag-route decision (benches, tests): ['value', 'token', 'why']. */
    public static $routeLast = [];

    /**
     * Round 5 (owner decision D-1 (c), 2026-10-19 15:00): the field token tg_<word> of a visitor's tag value when its rows
     * provably ARE the plain LIKE's rows (vod_tag LIKE '%value%' on the visible rows) in the LIKE's order (vod_time DESC,
     * vod_id DESC), else null (the list keeps fp-port's LIKE + FilterMatch path). Proof, all of it:
     *  1. the value is one ASCII word of 3-40 letters / digits, not a placeholder, and FtText::fieldToken() gives tg_<word>;
     *  2. the search table is ready, in 'ft' mode, no bulk window, no rebuild running, NOTHING queued (every visible row has
     *     its current document: fp-port-c queues every visibility write in its own transaction), its term map usable and
     *     not behind, a term build of this version has run (term_doc > 0: every tg_ token of every document up to term_doc is
     *     in the term table, exact) and the document order is known (inv_doc <> 1);
     *  3. no row holds the word inside a longer item: FilterVocab (fresh: lookup() catches up first) has no other word of
     *     vod_tag containing it ("blowjobs", "xblowjob", any non-ASCII spelling the collation equates: null) and the tag
     *     dictionary holds no other tg_ token containing it ("POV Blowjob" -> tg_pov_blowjob), nor any document above
     *     term_doc; a tg_ token cut at 84 bytes (it may hide the word) is checked against its rows' raw vod_tag;
     *  4. no token row lacks the LIKE match: no vod_class word contains it (tg_ also holds vod_class items), and no vod_tag /
     *     vod_class word with a character FtText folds but the collation keeps apart folds onto it (ROUTE_FOLD_APART);
     *     the term build found no document cut at FtText::DOC_MAX (its field tokens may be missing), none above term_doc;
     *  5. order: inv_doc = 0 (document order = (vod_time, vod_id) order since the last checked build), or no document at or
     *     above inv_doc holds the token (at most MAP_GAP_MAX documents to look at).
     * With all of that the term-map page p of the token (FtPlan C2, ranks from the newest) is page p of the LIKE list and
     * its total is the LIKE count. The caller still reads the page's rows with the exact LIKE and falls back to the LIKE
     * path when a row is missing (tagRouteBroken). Not covered (as fp-port's item path): raw SQL outside JavCMS that changes
     * a row without queueing it, and stored tag letters written as numeric HTML entities ("bl&#111;wjob").
     * Cached ROUTE_TTL seconds per value + vocabulary (rev, nonce) + term build + order marks + newest document.
     */
    public static function tagRoute(string $value): ?string
    {
        self::$routeLast = ['value' => $value, 'token' => null, 'why' => ''];
        $deny = function (string $why) {
            self::$routeLast['why'] = $why;
            return null;
        };
        if (is_array(self::$routeHook) && array_key_exists($value, self::$routeHook)) {
            $t = self::$routeHook[$value];
            self::$routeLast['token'] = is_string($t) ? $t : null;
            return is_string($t) ? $t : $deny('hook');
        }
        // 1. the value
        if (!preg_match('/^[A-Za-z0-9]{3,40}$/D', $value)) {
            return $deny('value');
        }
        $w = strtolower($value);
        $tok = 'tg_' . $w;
        if (isset(FtText::PLACEHOLDERS[$w]) || isset(FtText::STOP[$w]) || FtText::fieldToken('tg', $value) !== $tok) {
            return $deny('token');
        }
        try {
            if (class_exists('\think\Cache') && \think\Cache::get(self::refuseKey($value))) {
                return $deny('refused'); // a page of the route met a row the exact LIKE does not list (tagRouteRefuse)
            }
        } catch (\Throwable $e) {
            // no cache: the proof decides
        }
        // 2. the search table
        try {
            if (!FtIndex::ready()) {
                return $deny('ready');
            }
            $s = FtIndex::state();
            if ((string)($s['mode'] ?? '') !== 'ft' || (int)($s['bulk_job'] ?? 0) > 0 || (int)($s['rebuild_started_at'] ?? 0) > 0) {
                return $deny('state');
            }
            if (!array_key_exists('inv_doc', $s) || !array_key_exists('term_doc', $s)) {
                return $deny('schema');
            }
            $termDoc = (int)$s['term_doc'];
            $invDoc = (int)$s['inv_doc'];
            if ($termDoc <= 0 || $invDoc === 1 || (int)($s['term_at'] ?? 0) <= 0) {
                return $deny('terms');
            }
            if (!method_exists(FtIndex::class, 'mapUsable') || !FtIndex::mapUsable()) {
                return $deny('map');
            }
            $ft = FtIndex::t(FtIndex::T_FT);
            $qk = self::memoOn() ? self::memoKey('route_queue', []) : '';
            if ($qk !== '' && isset(self::$memo[$qk])) {
                $queued = self::$memo[$qk];
            } else {
                $queued = (bool)FtIndex::q('SELECT vod_id FROM ' . FtIndex::t(FtIndex::T_QUEUE) . ' LIMIT 1');
                if ($qk !== '') {
                    self::$memo[$qk] = $queued;
                }
            }
            if ($queued) {
                return $deny('queue');
            }
            if (self::mapRowOf($ft, $tok) === null) {
                return $deny('maprow'); // no map row (a tag newer than the last term build) or the map is behind
            }
            list(, $maxDoc) = self::ends($ft);
            // round 6 (D-9): with the order / dictionary tracking on (FtIndex::routeTracking) the documents written since the
            // term build need no scan (their new tg_ tokens are in vod_ft_newtok, their inversions in vod_ft_inv), so neither
            // gap limits the route; without it the round-5 rules stand
            $track = method_exists(FtIndex::class, 'routeTracking') ? FtIndex::routeTracking() : null;
            if ($track !== null && $track['tok_doc'] > $termDoc + 1) {
                $track = null; // the dictionary tracking does not reach down to the term build (never: terms() writes both)
            }
            if ($track === null && ($maxDoc - $termDoc > FtIndex::MAP_GAP_MAX || ($invDoc > 1 && $maxDoc - $invDoc > FtIndex::MAP_GAP_MAX))) {
                return $deny('behind');
            }
            // 3 / 4 (vocabulary part): fresh vocabulary, then its (rev, nonce) keys the cached part
            if (!class_exists(__NAMESPACE__ . '\\FilterVocab')) {
                return $deny('vocab');
            }
            $vocab = FilterVocab::app();
            $sub = $vocab->lookup('vod_tag', 'sub', $w, 1, FilterVocab::MAX_CHARS, []);
            if ($sub === null || $sub !== [$w]) {
                return $deny($sub === null ? 'vocab' : 'word');
            }
            $cls = $vocab->lookup('vod_class', 'sub', $w, 1, FilterVocab::MAX_CHARS, []);
            if ($cls !== []) {
                return $deny($cls === null ? 'vocab' : 'class');
            }
            $st = $vocab->probe()['state'];
            $rev = (int)($st['rev'] ?? 0) . '.' . (int)($st['nonce'] ?? 0);
        } catch (\Throwable $e) {
            return $deny('error');
        }
        // round 6: with the tracking on the proof depends on the term build, the dictionary additions (tok_n), the inverted
        // documents (inv_n) and the cut documents, not on every new document (round 5 keyed it by the newest doc: every import
        // proved every value again)
        $key = 'jv_tagroute_v2_' . md5(json_encode([$ft, (function_exists('config') ? (string)config('database.database') : ''), $w, $rev, (int)$s['term_at'], $termDoc, $invDoc,
            (int)($s['inv_n'] ?? 0), $track !== null ? ['t', $track['tok_n'], $track['cut_doc'], $track['tok_doc']] : $maxDoc]));
        $mk = self::memoOn() ? 'route:' . $key : '';
        if ($mk !== '' && isset(self::$memo[$mk])) {
            $hit = self::$memo[$mk];
        } else {
            $hit = null;
            try {
                $hit = class_exists('\think\Cache') ? \think\Cache::get($key) : null;
            } catch (\Throwable $e) {
                $hit = null;
            }
        }
        if (!is_array($hit) || !array_key_exists('why', $hit)) {
            try {
                $why = self::tagRouteData($ft, $w, $tok, $termDoc, $invDoc, $maxDoc, $track);
            } catch (\Throwable $e) {
                return $deny('error'); // not cached: asked again next time
            }
            $hit = ['why' => $why];
            try {
                if (class_exists('\think\Cache')) {
                    \think\Cache::set($key, $hit, self::ROUTE_TTL);
                }
            } catch (\Throwable $e) {
                // not cached: the next request proves again
            }
        }
        if ($mk !== '') {
            self::$memo[$mk] = $hit;
        }
        if ($hit['why'] !== '') {
            return $deny((string)$hit['why']);
        }
        self::$routeLast['token'] = $tok;
        return $tok;
    }

    /** Round 5 (D-1): the value is refused for ROUTE_TTL seconds (a route page met a row the exact LIKE does not list). */
    public static function tagRouteRefuse(string $value): void
    {
        try {
            if (class_exists('\think\Cache')) {
                \think\Cache::set(self::refuseKey($value), 1, self::ROUTE_TTL);
            }
        } catch (\Throwable $e) {
            // not remembered: the next request proves again and meets the row again
        }
        error_log('[FtPlan] tag route refused for one value (a page row did not match the LIKE): ' . substr(md5($value), 0, 8));
    }

    private static function refuseKey(string $value): string
    {
        return 'jv_tagroute_refuse_' . md5((function_exists('config') ? (string)config('database.database') . '|' . (string)config('database.prefix') : '') . '|' . $value);
    }

    /**
     * Round 5 (D-1): the data part of tagRoute(): '' = proven, else the reason. Reads the tag dictionary (tg_ rows of the term
     * table), the documents above term_doc, the documents at or above inv_doc and the flagged vocabulary words.
     */
    private static function tagRouteData(string $ft, string $w, string $tok, int $termDoc, int $invDoc, int $maxDoc, ?array $track = null): string
    {
        $term = FtIndex::t(FtIndex::T_TERM);
        // (4. a document cut at FtText::DOC_MAX may lack its field tokens: a term build that found one wrote term_doc = 0)
        // 3. another tg_ token of the dictionary containing the word ('!' escapes LIKE's '_')
        if (FtIndex::q('SELECT term FROM ' . $term . " WHERE term LIKE 'tg!_%' ESCAPE '!' AND term LIKE :p AND term <> :t LIMIT 1", [':p' => '%' . $w . '%', ':t' => $tok])) {
            return 'dict';
        }
        // round 6 (D-9): the tg_ tokens documents written since the term build brought (vod_ft_newtok, complete while the
        // tracking is on), and the lowest document cut at DOC_MAX since then
        if ($track !== null) {
            if (FtIndex::q('SELECT term FROM ' . FtIndex::t(FtIndex::T_NEWTOK) . " WHERE term LIKE 'tg!_%' ESCAPE '!' AND term LIKE :p AND term <> :t LIMIT 1", [':p' => '%' . $w . '%', ':t' => $tok])) {
                return 'new_docs';
            }
            if ($track['cut_doc'] > 0) {
                return 'cutdocs';
            }
        }
        // a tg_ token cut at FtText::TOKEN_MAX may hide the word after its 84th byte: the raw vod_tag of every row holding one
        // decides. Round 10 (search-r10 FR-1, orchestrator O-26 (a)): the rows of ALL cut tokens (term table, and with the
        // tracking on the dictionary additions since the term build) are read once per term build and dictionary state
        // (cutTokenRows(), cached), then ONE bounded LIKE over those rows per value; round 5 refused every value as soon as more
        // than 20 cut tokens existed: the live copy after the update holds 26 (about 400 rows), so no tag value of the parity
        // list (and none of 61 sampled tags) could be proven, whatever else held
        $cutIds = self::cutTokenRows($ft, $term, $termDoc, $track);
        if ($cutIds === null) {
            return 'cut_tokens';
        }
        foreach (array_chunk($cutIds, 1000) as $chunk) {
            if (FtIndex::q('SELECT vod_id FROM ' . FtIndex::t('vod') . ' WHERE vod_id IN (' . implode(',', $chunk) . ') AND vod_tag LIKE :p LIMIT 1', [':p' => '%' . $w . '%'])) {
                return 'cut_tokens';
            }
        }
        // documents written since the term build (at most MAP_GAP_MAX): another tg_ token containing the word, or a cut document
        // (round 6: only without the tracking, which records both as they are written)
        if ($track === null && $maxDoc > $termDoc) {
            foreach (FtIndex::q('SELECT ft_text FROM ' . $ft . ' WHERE FTS_DOC_ID > ' . $termDoc . ' AND ft_text LIKE :p', [':p' => '%' . $w . '%']) as $r) {
                $txt = (string)$r['ft_text'];
                if (strlen($txt) >= FtText::DOC_MAX - 200) {
                    return 'cutdocs';
                }
                foreach (explode(' ', trim($txt)) as $x) {
                    if ($x !== $tok && strncmp($x, 'tg_', 3) === 0 && strpos($x, $w) !== false) {
                        return 'new_docs';
                    }
                    if (strncmp($x, 'tg_', 3) === 0 && strlen($x) >= FtText::TOKEN_MAX) {
                        // round 10: a cut tg_ token in a document since the term build (its item may hold the word after the
                        // cut; the document holds the word): not proven
                        return 'cut_tokens';
                    }
                }
            }
            if (FtIndex::q('SELECT FTS_DOC_ID FROM ' . $ft . ' WHERE FTS_DOC_ID > ' . $termDoc . ' AND LENGTH(ft_text) >= ' . (FtText::DOC_MAX - 200) . ' LIMIT 1')) {
                return 'cutdocs';
            }
        }
        // 5. order: no document at or above the first out-of-order one holds the token. Round 6 (D-9): with the tracking on,
        // the token's inverted documents are listed (vod_ft_inv) and its pages put them at their (vod_time, vod_id) place
        // (mapPageOrdered); only more of them than one page can place refuse the value
        if ($track !== null) {
            $invMax = self::routeInvMax();
            $invd = FtIndex::invDocs($tok, $invMax);
            if ($invd === null || count($invd) > $invMax) {
                return 'order';
            }
        } elseif ($invDoc > 1 && FtIndex::q('SELECT FTS_DOC_ID FROM ' . $ft . ' WHERE FTS_DOC_ID >= ' . $invDoc . ' AND ' . self::likeOf([$tok]) . ' LIMIT 1')) {
            return 'order';
        }
        // 4. flagged vocabulary words of vod_tag / vod_class with a character the collation keeps apart from FtText's fold
        $vt = FtIndex::t(FilterVocab::TABLE);
        $or = [];
        $bind = [];
        foreach (self::ROUTE_FOLD_APART as $i => $ch) {
            $or[] = '`token` LIKE BINARY :c' . $i;
            $bind[':c' . $i] = '%' . $ch . '%';
        }
        $rows = FtIndex::q('SELECT `token` FROM `' . str_replace('`', '', $vt) . '` WHERE `col` IN (' . (int)FilterVocab::COLS['vod_tag'] . ',' . (int)FilterVocab::COLS['vod_class'] . ')'
            . ' AND (`flags` & ' . (int)FilterVocab::F_NONASCII . ') <> 0 AND (' . implode(' OR ', $or) . ') LIMIT ' . (self::ROUTE_FOLD_ROWS + 1), $bind);
        if (count($rows) > self::ROUTE_FOLD_ROWS) {
            return 'fold_rows';
        }
        foreach ($rows as $r) {
            foreach (FtText::tokens((string)$r['token']) as $x) {
                if (strpos($x, $w) !== false) {
                    return 'fold';
                }
            }
        }
        return '';
    }

    /** Round 10 (search-r10 FR-1): most cut tg_ tokens the tag route's cut-token check reads (more: the value is not proven). */
    const ROUTE_CUT_TOKENS = 1000;
    /**
     * Round 10: most rows of those tokens it reads (more: not proven; the live copy: 26 tokens, about 400 rows). 40,000 = the
     * most round 5 read (20 tokens of at most 2,000 rows each), so no value round 5 proved is refused now.
     */
    const ROUTE_CUT_ROWS = 40000;

    /**
     * Round 10 (search-r10 FR-1, orchestrator O-26 (a)): the vod ids of the documents that hold a tg_ token cut at
     * FtText::TOKEN_MAX (an item longer than the token may hold a word after the cut that its token does not show), for the
     * tag route's proof: the cut tokens of the term table (every document up to the term build) and, with the tracking on, of
     * vod_ft_newtok (the tokens documents brought since the build), read through one MATCH per 200 of them. null = more than
     * ROUTE_CUT_TOKENS tokens or ROUTE_CUT_ROWS rows, or a token that cannot be matched: no value is proven. Kept ROUTE_TTL
     * seconds in the app cache (and per request) per search table, database, term build and tracked dictionary state, so the
     * proof of a value costs one bounded LIKE over these rows instead of a MATCH per cut token (round 5 read at most 20 cut
     * tokens and refused every value once a site had more).
     */
    private static function cutTokenRows(string $ft, string $term, int $termDoc, ?array $track): ?array
    {
        $s = FtIndex::state();
        $key = 'jv_tagroute_cut_v1_' . md5(json_encode([$ft, (function_exists('config') ? (string)config('database.database') : ''),
            (int)($s['term_at'] ?? 0), $termDoc, $track !== null ? [(int)$track['tok_n'], (int)$track['tok_doc']] : null]));
        $mk = self::memoOn() ? 'cut:' . $key : '';
        if ($mk !== '' && array_key_exists($mk, self::$memo)) {
            return self::$memo[$mk];
        }
        $hit = null;
        try {
            $hit = class_exists('\think\Cache') ? \think\Cache::get($key) : null;
        } catch (\Throwable $e) {
            $hit = null;
        }
        if (is_array($hit) && array_key_exists('ids', $hit) && ($hit['ids'] === null || is_array($hit['ids']))) {
            $ids = $hit['ids'] === null ? null : array_values(array_map('intval', $hit['ids']));
        } else {
            $ids = self::cutTokenRowsRead($ft, $term, $track);
            try {
                if (class_exists('\think\Cache')) {
                    \think\Cache::set($key, ['ids' => $ids], self::ROUTE_TTL);
                }
            } catch (\Throwable $e) {
                // not cached: the next proof reads them again
            }
        }
        if ($mk !== '') {
            self::$memo[$mk] = $ids;
        }
        return $ids;
    }

    /** Round 10: the read behind cutTokenRows() (errors reach tagRoute(), which proves nothing for them). */
    private static function cutTokenRowsRead(string $ft, string $term, ?array $track): ?array
    {
        $tables = [$term];
        if ($track !== null) {
            $tables[] = FtIndex::t(FtIndex::T_NEWTOK);
        }
        $toks = [];
        foreach ($tables as $t) {
            $rows = FtIndex::q('SELECT term FROM ' . $t . " WHERE term LIKE 'tg!_%' ESCAPE '!' AND LENGTH(term) >= " . FtText::TOKEN_MAX
                . ' LIMIT ' . (self::ROUTE_CUT_TOKENS + 1));
            foreach ($rows as $r) {
                $x = (string)$r['term'];
                if (!preg_match('/^tg_[a-z0-9_]+$/D', $x)) {
                    return null;
                }
                $toks[$x] = true;
            }
            if (count($toks) > self::ROUTE_CUT_TOKENS) {
                return null;
            }
        }
        $ids = [];
        foreach (array_chunk(array_map('strval', array_keys($toks)), 200) as $chunk) {
            $rows = FtIndex::q('SELECT vod_id FROM ' . $ft . ' WHERE MATCH(ft_text) AGAINST(' . FtIndex::pdo()->quote(implode(' ', $chunk))
                . ' IN BOOLEAN MODE) LIMIT ' . (self::ROUTE_CUT_ROWS + 1));
            foreach ($rows as $r) {
                $ids[(int)$r['vod_id']] = true;
            }
            if (count($ids) > self::ROUTE_CUT_ROWS) {
                return null;
            }
        }
        $ids = array_keys($ids);
        sort($ids);
        return $ids;
    }

    public static function tokenExists(string $token): bool
    {
        if (!preg_match('/^(tg|ac)_[a-z0-9_]{1,81}$/D', $token) || !FtIndex::ready()) {
            return false;
        }
        $key = self::memoOn() ? self::memoKey('exists', [$token]) : '';
        if ($key !== '' && isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $df = self::dfOf([$token]);
        if (isset($df[$token]) && $df[$token] > 0) {
            $yes = true;
        } elseif ((string)(FtIndex::state()['mode'] ?? 'ft') !== 'ft') {
            $yes = false;
        } else {
            $r = FtIndex::q('SELECT FTS_DOC_ID FROM ' . FtIndex::t(FtIndex::T_FT) . ' WHERE MATCH(ft_text) AGAINST(' . FtIndex::pdo()->quote('+' . $token) . ' IN BOOLEAN MODE) LIMIT 1');
            $yes = (bool)$r;
        }
        if ($key !== '') {
            self::$memo[$key] = $yes;
        }
        return $yes;
    }

    public static function search(string $wd, array $opt): array
    {
        self::$last = [];
        $t0 = microtime(true);
        if (!FtIndex::ready()) {
            return self::result('old');
        }
        $s = FtIndex::state();
        $page = max(1, (int)($opt['page'] ?? 1));
        $per = max(1, min(self::PER_MAX, (int)($opt['per'] ?? 20)));
        $cursor = max(0, (int)($opt['cursor'] ?? 0));
        $up = max(0, (int)($opt['up'] ?? 0));
        // C3: cursor 1 (no doc lies below it) is the "Oldest results" page; round 2: 'up' continues it above that doc
        $oldest = $cursor === 1 || $up > 0;
        if ($oldest) {
            $cursor = 0;
            $page = 1;
        }
        $token = isset($opt['token']) && is_string($opt['token']) ? $opt['token'] : '';
        // round 5 (S-1, flood-r5 R5-1): 'window' => n for callers with paging off (template blocks: the "same performer" block,
        // D-L8 lists): page 1 only, the FULLTEXT path keeps the newest n matches instead of FT_LIMIT (ids beyond the block are
        // never read again for the sort; MariaDB still fetches every row of the MATCH once, so the cold cost of a token is
        // bounded by FtIndex::FIELD_TERM_MIN: tokens from there on page through the term map)
        $window = !$oldest && $cursor === 0 && $up === 0 && $page === 1 ? max(0, min(self::FT_LIMIT, (int)($opt['window'] ?? 0))) : 0;
        $q = $token !== '' ? FtText::tokenQuery($token) : FtText::query($wd);
        if (!$q['groups']) {
            return self::result('none', ['msg' => 'type_more']);
        }
        $types = self::intList($opt['type_ids'] ?? []);
        $hidden = array_key_exists('hidden', $opt) ? self::intList($opt['hidden']) : self::hiddenTypes();
        $ft = FtIndex::t(FtIndex::T_FT);
        $mapOk = method_exists(FtIndex::class, 'mapUsable') && FtIndex::mapUsable();

        // C2: one token, no category restriction -> the term map (every page, exact total); null = no map row / map behind
        if (!$types && $cursor === 0 && $up === 0 && count($q['groups']) === 1 && count($q['groups'][0]['t']) === 1 && self::safeTokens($q['groups'][0]['t']) && $mapOk) {
            $tok0 = (string)$q['groups'][0]['t'][0];
            // round 6 (D-9, search-r6 R6-5): the D-1 route ('order_time') pages in (vod_time, vod_id) order: documents an admin
            // save or a re-import wrote at the top with an older key take their place (mapPageOrdered); more of them than one
            // page can place, or the tracking off: no map answer (the route's proof refuses such a value before)
            $invMax = self::routeInvMax();
            $inv = !empty($opt['order_time']) && !$oldest && strncmp($tok0, 'tg_', 3) === 0 ? FtIndex::invDocs($tok0, $invMax) : null;
            if ($inv !== null && count($inv) > $invMax) {
                $m = null;
            } elseif ($inv) {
                $m = self::mapPageOrdered($ft, $tok0, $page, $per, $hidden, $inv);
            } else {
                $m = self::mapPage($ft, $tok0, $oldest ? 0 : $page, $per, $hidden);
            }
            if ($m !== null) {
                self::$last = ['path' => 'map', 'df' => $m['total']] + (isset(self::$last['map']) ? ['map' => self::$last['map']] : []);
                return $m;
            }
        }

        // document frequencies of the query tokens (<= 12 primary-key reads)
        $tokens = self::safeTokens($q['tokens']);
        $df = self::dfOf($tokens);
        $stale = FtIndex::termsStale($s);
        $N = max(1, (int)$s['docs']);
        $eng = self::engine();
        // R2-3: MySQL < 8.0 matches the rarest group only (ftIds); with every token rare or unknown no window probe there
        $noProbe = false;
        if ($eng['kind'] === 'mysql' && $eng['id'] < 80000 && !$stale && count($q['groups']) >= 2) {
            $noProbe = true;
            foreach ($tokens as $x) {
                if (isset($df[$x]) && $df[$x] > self::RECALL_DF) {
                    $noProbe = false;
                    break;
                }
            }
        }
        $rare = [];
        $likeAll = [];
        $likeCommon = [];
        $R = PHP_INT_MAX;
        $Rr = PHP_INT_MAX;
        $dec = 0;
        $sel = 1.0;
        $allKnown = true;
        $probes = 0;
        $probeCommon = [];
        $last = count($q['groups']) - 1;
        $exactTok = []; // tokens of the groups that must match exactly (every group but a prefix last group): candidate sources
        foreach ($q['groups'] as $gi => $g) {
            $t = self::safeTokens($g['t']);
            if (count($t) !== count($g['t']) || !$t) {
                continue;
            }
            $d = PHP_INT_MAX;
            $gs = 0;
            $known = true;
            foreach ($t as $x) {
                if (isset($df[$x])) {
                    $v = $df[$x];
                } elseif ($stale) {
                    $known = false;
                    $v = self::COMMON + 1;
                } elseif (preg_match('/^(tg|ac)_/', $x) || $noProbe) {
                    // round 2 (O-S1 actor, R2-3): a tag / actor token missing from fresh stats has df < TERM_MIN by
                    // construction (whole items: a new common tag shows in the stats after the next term build); no probe
                    $v = self::UNKNOWN_DF;
                } else {
                    // missing from fresh stats = df < TERM_MIN by construction, unless the term table lost rows (G1c "20 %
                    // stale"): a bounded probe confirms it before the word may reach MATCH
                    $v = self::probeUnknown($x, (int)$s['docs'], $probes, !empty($g['code'])) ? self::UNKNOWN_DF : self::COMMON + 1;
                    if ($v > self::COMMON) {
                        $known = false;
                        $probeCommon[] = $x;
                    }
                }
                $d = min($d, $v);
                $gs += $v;
            }
            $allKnown = $allKnown && $known;
            $prefix = $gi === $last && !empty($q['prefix']);
            $likeAll[] = self::likeOf($t, $prefix);
            if (!$prefix) {
                foreach ($t as $x) {
                    $exactTok[$x] = true;
                }
            }
            $R = min($R, $d);
            $sel *= min(1.0, $d / $N);
            if (!$known || $d > self::COMMON) {
                $likeCommon[] = self::likeOf($t);
            } else {
                $rare[] = ['t' => $t, 'd' => $d];
                $Rr = min($Rr, $d);
                $dec += $gs;
            }
        }
        if (!$likeAll) {
            return self::result('none', ['msg' => 'type_more']);
        }
        $estimate = count($likeAll) === 1 ? $R : (int)min($R, ceil($sel * $N));
        $mode = (string)($s['mode'] ?? 'ft');
        $ftMax = self::ftMax((float)($s['ms_per_read'] ?? 0.1));
        $typeSql = '';
        if ($types) {
            $typeSql .= ' AND (type_id IN (' . implode(',', $types) . ') OR type_id_1 IN (' . implode(',', $types) . '))';
        }
        self::$last = ['groups' => count($likeAll), 'df' => $df, 'stale' => $stale, 'probe_common' => $probeCommon, 'R' => $R, 'rare_df' => $Rr === PHP_INT_MAX ? null : $Rr,
            'posting_sum' => $dec, 'ft_max' => $ftMax, 'mode' => $mode, 'no_probe' => $noProbe];

        // Row budget: the rarest word's df <= FT_MAX, or (fresh stats) a word missing from the term table: its df is below
        // the TERM_MIN the table stores, and D-S3 keeps it on the FULLTEXT path whatever FT_MAX says (step 3: with FT_MAX at
        // its floor of 300 on a slow disk, the unknown df had sent every unknown word to a full WALK_CAP walk).
        $withinBudget = $Rr <= $ftMax || ($Rr === self::UNKNOWN_DF && !$stale);
        self::$last['within_budget'] = $withinBudget;

        // 1. FULLTEXT on the rare part (never above DECODE_MAX postings: hard rule sc-S3c); its "Oldest results" page is the
        //    last page of the list (up-cursor pages come from the reverse walk only)
        if ($up === 0 && $mode === 'ft' && $rare && $withinBudget && $dec <= self::DECODE_MAX) {
            $docs = [];
            $ids = self::ftIds($ft, $rare, $likeCommon, $types, $hidden, 0.0, $cursor, $docs, $window > 0 ? $window : self::FT_LIMIT);
            self::$last['path'] = 'ft';
            return self::page($ids, $page, $per, 'ft', count($ids), true, $docs, $cursor > 0, $oldest);
        }

        $walkCap = self::walkCap();
        // C3: a walk page above the numbered range needs a cursor (the "Older results" link of page NUMBERED_FIRST)
        if (!$oldest && $cursor === 0 && $page > self::NUMBERED_FIRST) {
            return self::result('none', ['msg' => 'page_range']);
        }
        $offset = $cursor > 0 ? 0 : ($page - 1) * $per;
        // the page and one look-ahead id (the pager links one page past the found ids)
        $need = $cursor > 0 ? $per + 1 : min(self::NUMBERED_FIRST * $per + 1, $offset + $per + 1);
        // recall is for words: the rarest rare group has no 1-2 letter token ('x__', the padded short form: a hostile 'a%b'
        // is not worth a 3,700-read MATCH)
        $rarestWord = false;
        foreach ($rare as $g) {
            if ($g['d'] === $Rr) {
                $rarestWord = true;
                foreach ($g['t'] as $x) {
                    if (substr($x, -2) === '__') {
                        $rarestWord = false;
                    }
                }
                break;
            }
        }
        $recallOk = $cursor === 0 && !$oldest && $mode === 'ft' && $rare && $allKnown && $dec <= self::DECODE_MAX && $Rr <= self::RECALL_DF && $rarestWord;
        $budgetDocs = (int)min($walkCap, floor(self::WALK_PAGES * self::pageTextBytes() / self::REF_DOC_BYTES));
        $recallTried = false;
        // round-3 R3-1: MySQL < 8.0 runs the recall under RECALL_SEC_OLD and only when its priced reads fit the DB budget left
        $oldMysql = $eng['kind'] === 'mysql' && $eng['id'] > 0 && $eng['id'] < 80000;
        $recallSec = $oldMysql ? min(self::$recallSec, self::RECALL_SEC_OLD) : self::$recallSec;
        $recallSkipped = false;
        $affordable = function () use ($oldMysql, $Rr, $N, $s, $t0, &$recallSkipped) {
            if (!$oldMysql) {
                return true;
            }
            $cost = self::recallCost($Rr, $N, (float)($s['ms_per_read'] ?? 0.1));
            $left = self::RECALL_BUDGET_OLD - (microtime(true) - $t0);
            self::$last['recall_cost'] = round($cost, 3);
            self::$last['recall_left'] = round($left, 3);
            if ($cost <= $left) {
                return true;
            }
            $recallSkipped = true;
            self::$last['recall_skipped'] = true;
            return false;
        };
        $where = implode(' AND ', $likeAll) . $typeSql;
        $sig = implode('|', $likeAll) . $typeSql;

        // candidate source: the exact token with the smallest df that has a kind-1 term-map row (every doc id); the first doc
        // of the rarest exact token with any map row bounds the reverse walk from below (no match lies under it)
        $cand = null;
        $firstDoc = 0;
        if ($mapOk && $exactTok) {
            $order = [];
            foreach (array_keys($exactTok) as $x) {
                if (isset($df[$x])) {
                    $order[$x] = $df[$x];
                }
            }
            asort($order);
            foreach (array_slice(array_keys($order), 0, 2) as $x) {
                $e = self::mapRowOf($ft, $x);
                if ($e === null) {
                    continue;
                }
                if ($firstDoc === 0 && strlen($e['data']) >= 4) {
                    $firstDoc = (int)unpack('V', substr($e['data'], 0, 4))[1];
                }
                if ($e['kind'] === 1) {
                    $cand = $e + ['token' => $x];
                    break;
                }
            }
        }
        self::$last['cand'] = $cand ? ['token' => $cand['token'], 'df' => $cand['df'] + $cand['tail_n']] : null;

        // 2a. reverse walk ("Oldest results", up-cursor pages)
        if ($oldest) {
            $share = self::share($types);
            $w0 = (int)min($walkCap, max(self::W_MIN, ceil(self::K * $N / max(1, $R) * 1.5 / $share)));
            return self::oldestWalk($ft, $where, $per, $hidden, $w0, $walkCap, $cand, max($up, $firstDoc > 1 ? $firstDoc - 1 : 0), $up, $sig);
        }

        // 2b. candidates (round 2): the kind-1 token's own docs checked by primary key, newest first
        if ($cand !== null) {
            $first = (int)min(self::CAND_BUDGET, max(self::CAND_FIRST, 3 * $need));
            $st = self::candWalk($ft, $where, $cand, $need, $hidden, $cursor, $first, $sig);
            if (!$st['end'] && $st['found'] < $need && $recallOk) {
                // a low hit rate in the first chunk: the rare part's MATCH reads only the few rows that match it all
                $rate = ($st['found'] + 0.5) / max(1, $st['checked']);
                if (($need - $st['found']) / $rate > self::CAND_BUDGET - $st['checked'] && $affordable()) {
                    $recallTried = true;
                    $docs = [];
                    $ids = self::ftIds($ft, $rare, $likeCommon, $types, $hidden, $recallSec, 0, $docs);
                    if ($ids !== null) {
                        self::$last['path'] = 'cand->recall';
                        return self::page($ids, $page, $per, 'ft', count($ids), true, $docs);
                    }
                    self::$last['recall_timeout'] = true;
                }
            }
            if (!$st['end'] && $st['found'] < $need && $st['checked'] < self::CAND_BUDGET) {
                $st = self::candWalk($ft, $where, $cand, $need, $hidden, $cursor, self::CAND_BUDGET, $sig);
            }
            self::$last += ['cand_checked' => $st['checked'], 'found' => $st['found'], 'need' => $need];
            $res = self::walkResult($st, $page, $per, $offset, $need, $cursor, $estimate, 'cand');
            if (($recallTried && !$st['end'] && empty($res['ids']) && $page > 1) || ($recallSkipped && !$st['end'] && (($page === 1 && $st['found'] < $per) || ($page > 1 && $st['found'] <= $offset)))) {
                $res = self::partial($res, $offset, $st);
            }
            return $res;
        }

        // 1b. recall first: the walk budget is expected to hold fewer matches of the rare part than one page needs (df below
        //    ~1,660 at 7 M: a MATCH of <= ~3,000 reads), on every page so that the pages of a rare word stay one list
        if ($recallOk && $Rr * $budgetDocs / $N < $per && $affordable()) {
            $recallTried = true;
            $docs = [];
            $ids = self::ftIds($ft, $rare, $likeCommon, $types, $hidden, $recallSec, 0, $docs);
            if ($ids !== null) {
                self::$last['path'] = 'recall';
                return self::page($ids, $page, $per, 'ft', count($ids), true, $docs);
            }
            self::$last['recall_timeout'] = true;
        }

        // 2. deepening newest-walk under the document and page budget
        $share = self::share($types);
        $w0 = (int)min($walkCap, max(self::W_MIN, ceil(self::K * $N / max(1, $R) * 1.5 / $share)));
        $st = self::walk($ft, $where, $need, $hidden, $w0, $walkCap, $cursor, $sig);
        $f = count($st['ids']);
        self::$last += ['w0' => $w0, 'windows' => $st['windows'], 'rows_read' => $st['read'], 'pages_est' => (int)round($st['bytes'] / self::pageTextBytes()), 'walk_cap' => $walkCap,
            'found' => $f, 'need' => $need];

        // 3. walk came back short on page 1, or empty on a deeper page (recall, G1b): the rare part with fresh stats, df <=
        //    RECALL_DF and within DECODE_MAX gets one MATCH under RECALL_SEC. A partly filled deeper page keeps the walk's
        //    answer: the walk's ids are the newest matches in the same order as the MATCH's, so the pages stay one list (a MATCH fetches 1.7-2.0 rows per matching doc of its rarest word, measured at
        //    7.1 M; the step-3 "walk->ft" fallback within FT_MAX is step 1 now: the same conditions)
        $timedOut = !empty(self::$last['recall_timeout']);
        if ($recallOk && !$recallTried && !$st['end'] && (($page === 1 && $f < $per) || ($page > 1 && $f <= $offset)) && $affordable()) {
            $docs = [];
            $ids = self::ftIds($ft, $rare, $likeCommon, $types, $hidden, $recallSec, 0, $docs);
            self::$last['path'] = 'walk->recall';
            if ($ids !== null) {
                return self::page($ids, $page, $per, 'ft', count($ids), true, $docs);
            }
            self::$last['recall_timeout'] = true;
            $timedOut = true;
        }
        $res = self::walkResult($st, $page, $per, $offset, $need, $cursor, $estimate, 'walk');
        self::$last['path'] = !empty($res['capped']) ? 'walk(capped)' : 'walk';
        // r2-1: the recall MATCH ran out of time and the capped walk holds nothing for this deeper page: not "past the last
        // page" (404) but a page that keeps the continuation below what the walk read
        if ($timedOut && !$st['end'] && empty($res['ids']) && $page > 1) {
            $res = self::partial($res, $offset, $st);
        } elseif ($recallSkipped && !$st['end'] && (($page === 1 && $f < $per) || ($page > 1 && $f <= $offset))) {
            // R3-1: the recall did not fit the DB budget (MySQL < 8.0): the walk's answer with its continuation, msg 'partial'
            $res = self::partial($res, $offset, $st);
        }
        return $res;
    }

    /**
     * Round-3 R3-1: seconds of cold reads a recall MATCH of the rarest group (df $d of $N docs) costs on MySQL < 8.0, where
     * it fetches every matching row: the distinct table pages of $d rows spread over P pages, P x (1 - e^(-d / P)), P = N /
     * docs per page (the table as stored, FtIndex::docsPerPage(); else the text bytes per page read / REF_DOC_BYTES),
     * x ms_per_read (the daily probe).
     */
    private static function recallCost(int $d, int $N, float $msPerRead): float
    {
        $perPage = method_exists(FtIndex::class, 'docsPerPage') ? FtIndex::docsPerPage() : 0.0;
        if ($perPage <= 0) {
            $perPage = max(1.0, self::pageTextBytes() / self::REF_DOC_BYTES);
        }
        $P = max(1.0, $N / $perPage);
        $pages = $P * (1 - exp(-max(0, $d) / $P));
        return $pages * max(0.01, $msPerRead) / 1000.0;
    }

    /**
     * Result of a newest-first scan state (walk or candidates) for this page: ids, total / exact, 'next' (C3) and 'cursor'
     * (C2), 'found' (the pager's count), 'capped' (the budget ended before the page and its look-ahead were found).
     */
    private static function walkResult(array $st, int $page, int $per, int $offset, int $need, int $cursor, int $estimate, string $path): array
    {
        $found = $st['ids'];
        $docOf = $st['doc'];
        $reachedEnd = $st['end'];
        $f = count($found);
        $capped = !$reachedEnd && $f < $need;
        $pageIds = array_slice($found, $offset, $per);
        // C3 continuation: a full cursor page, or page NUMBERED_FIRST with more matches, continues below its last doc; the
        // last page of a capped walk continues below everything the walk read (never a dead end)
        $next = 0;
        if ($cursor > 0) {
            if ($f > $per && $pageIds) {
                $next = (int)$docOf[end($pageIds)];
            } elseif ($capped) {
                $next = (int)$st['floor'] + 1;
            }
        } elseif ($f > $offset + $per) {
            if ($page >= self::NUMBERED_FIRST && $pageIds) {
                $next = (int)$docOf[end($pageIds)];
            }
        } elseif ($capped) {
            $next = (int)$st['floor'] + 1;
        }
        if ($next <= 1 || ($cursor > 0 && $next >= $cursor)) {
            $next = 0; // nothing older (or no progress): no link
        }
        $hasMore = $f > $offset + $per || $next > 0;
        // 'cursor' (C2 contract): the doc the next page continues below whenever more ids follow (any page); 'next' (C3):
        // the value of the "Older results" link, only where the page needs one
        $cursorOut = $f > ($cursor > 0 ? $per : $offset + $per) && $pageIds ? (int)$docOf[end($pageIds)] : $next;
        if ($f >= $need) {
            $total = max($estimate, $f);
            $exact = false;
        } else {
            $total = $f;
            $exact = $reachedEnd && $cursor === 0;
        }
        return self::result($path === 'cand' ? 'walk' : $path, ['ids' => $pageIds, 'total' => (int)$total, 'exact' => $exact, 'has_more' => $hasMore, 'cursor' => $cursorOut,
            'msg' => '', 'found' => $f, 'capped' => $capped, 'next' => $next]);
    }

    /** r2-1: a deeper page the scan could not reach after a recall timeout: 200 with the continuation, never "past the end". */
    private static function partial(array $res, int $offset, array $st): array
    {
        $next = (int)$st['floor'] + 1;
        $res['msg'] = 'partial';
        $res['partial'] = true;
        $res['found'] = max((int)$res['found'], $offset + 1);
        $res['total'] = max((int)$res['total'], $offset + 1);
        $res['exact'] = false;
        if ($next > 1) {
            $res['next'] = $next;
            $res['cursor'] = $next;
            $res['has_more'] = true;
        }
        return $res;
    }

    /**
     * The term-map entry of a token for this request (memoized): FtIndex::mapRow (main row, head, appended chunks, cover)
     * plus 'extra' = the matching docs above the cover found by LIKE (at most MAP_GAP_MAX docs were added since); null =
     * no map row, or the map is behind.
     */
    private static function mapEntry(string $ft, string $token): ?array
    {
        $key = self::memoOn() ? self::memoKey('map', [$token]) : '';
        if ($key !== '' && array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        $row = self::mapRowOf($ft, $token);
        if ($row !== null) {
            list(, $maxDoc) = self::ends($ft);
            $cover = (int)$row['cover'];
            $row['extra'] = [];
            if ($maxDoc > $cover) {
                foreach (FtIndex::q('SELECT FTS_DOC_ID FROM ' . $ft . ' WHERE FTS_DOC_ID > ' . $cover . ' AND ' . self::likeOf([$token]) . ' ORDER BY FTS_DOC_ID') as $r) {
                    $row['extra'][] = (int)$r['FTS_DOC_ID'];
                }
            }
        }
        if ($key !== '') {
            self::$memo[$key] = $row;
        }
        return $row;
    }

    /**
     * FtIndex::mapRow of a token for this request (memoized), normalized (head / tail / tail_n / cover always set); null =
     * no map row, or the map is behind (more than MAP_GAP_MAX docs above the row's cover).
     */
    private static function mapRowOf(string $ft, string $token): ?array
    {
        $key = self::memoOn() ? self::memoKey('mrow', [$token]) : '';
        if ($key !== '' && array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        $row = FtIndex::mapRow($token);
        if ($row !== null) {
            $row['tail_n'] = (int)($row['tail_n'] ?? 0);
            $row['head'] = (string)($row['head'] ?? '');
            $row['tail'] = (string)($row['tail'] ?? '');
            $row['cover'] = (int)($row['cover'] ?? $row['last_doc']);
            // round-3 F-r3-1: the term's dead doc ids (deleted since its row / chunks were written) leave the exact lists here
            // (kind-1 ids, appended ids, the kind-2 head: head_live); the dead ids of a kind-2 sampled row stay counted in df
            // and are subtracted by rank (dead_main, ascending)
            $row['head_live'] = $row['head'];
            $row['dead_main'] = [];
            $dead = isset($row['dead']) && is_string($row['dead']) && $row['dead'] !== '' ? array_values(unpack('V*', $row['dead'])) : [];
            if ($dead) {
                $set = array_flip($dead);
                $keep = function (string $packed) use ($set) {
                    if ($packed === '') {
                        return '';
                    }
                    $out = [];
                    foreach (unpack('V*', $packed) as $d) {
                        if (!isset($set[$d])) {
                            $out[] = $d;
                        }
                    }
                    return $out ? pack('V*', ...$out) : '';
                };
                $row['tail'] = $keep($row['tail']);
                $row['tail_n'] = intdiv(strlen($row['tail']), 4);
                if ((int)$row['kind'] === 1) {
                    $row['data'] = $keep((string)$row['data']);
                    $row['df'] = intdiv(strlen($row['data']), 4);
                } else {
                    $row['head_live'] = $keep($row['head']);
                    foreach ($dead as $d) {
                        if ($d <= (int)$row['last_doc']) {
                            $row['dead_main'][] = $d;
                        }
                    }
                }
            }
            list(, $maxDoc) = self::ends($ft);
            if ($maxDoc - $row['cover'] > FtIndex::MAP_GAP_MAX) {
                $row = null;
            }
        }
        if ($key !== '') {
            self::$memo[$key] = $row;
        }
        return $row;
    }

    /** [vod_id, type_id, vod_time] of these docs (primary key), memoized per doc within the request; deleted docs are absent. */
    private static function docRows(string $ft, array $docs): array
    {
        $out = [];
        $miss = [];
        foreach ($docs as $d) {
            $d = (int)$d;
            if ($d < 1) {
                continue;
            }
            if (isset(self::$memo['row:' . $d])) {
                if (self::$memo['row:' . $d] !== false) {
                    $out[$d] = self::$memo['row:' . $d];
                }
            } else {
                $miss[$d] = true;
            }
        }
        if ($miss) {
            $got = [];
            foreach (FtIndex::q('SELECT FTS_DOC_ID, vod_id, type_id, vod_time FROM ' . $ft . ' WHERE FTS_DOC_ID IN (' . implode(',', array_keys($miss)) . ')') as $r) {
                $got[(int)$r['FTS_DOC_ID']] = [(int)$r['vod_id'], (int)$r['type_id'], (int)$r['vod_time']];
            }
            foreach (array_keys($miss) as $d) {
                if (self::memoOn()) {
                    self::$memo['row:' . $d] = $got[$d] ?? false;
                }
                if (isset($got[$d])) {
                    $out[$d] = $got[$d];
                }
            }
        }
        return $out;
    }

    /**
     * C2 page of one token through the term map: ranks counted from the oldest match. Ranks of the main row: kind 1 = the doc
     * ids straight from the list; kind 2 = the exact head for its newest MAP_HEAD ranks (round-2 F4: page 1 of a common tag
     * reads ids, no 243 k-doc LIKE window), else the two sample entries around the page give a doc range read in FTS_DOC_ID
     * order with the token's LIKE filter (rows memoized per window, so the landing facts and the visitor list read it once).
     * Ranks above df: the appended chunks (exact), then the docs above the cover found by LIKE. Total = exact df. Null = no
     * map row, or the map is behind. $page 0 = the last page (oldest).
     */
    private static function mapPage(string $ft, string $token, int $page, int $per, array $hidden): ?array
    {
        $row = self::mapEntry($ft, $token);
        if ($row === null) {
            return null;
        }
        $T = self::mapTotal($row);
        if ($page === 0) {
            $page = max(1, (int)ceil($T / $per));
        }
        $hi = $T - ($page - 1) * $per - 1;
        if ($hi < 0 || $T === 0) {
            return self::result('map', ['ids' => [], 'total' => $T, 'exact' => true, 'found' => $T]);
        }
        $lo = max(0, $T - $page * $per);
        $byDoc = self::mapRanks($ft, $token, $row, $lo, $hi, $windows);
        if ($byDoc === null) {
            return null;
        }
        krsort($byDoc);
        $hset = $hidden ? array_flip($hidden) : [];
        $ids = [];
        foreach ($byDoc as $v) {
            if (!isset($hset[$v[1]]) && !in_array($v[0], $ids, true)) {
                $ids[] = $v[0];
            }
        }
        self::$last['map'] = ['kind' => $row['kind'], 'df' => (int)$row['df'] - ((int)$row['kind'] === 2 ? count((array)($row['dead_main'] ?? [])) : 0),
            'tail' => (int)$row['tail_n'], 'head' => count(self::unpackIds((string)($row['head_live'] ?? $row['head']))), 'extra' => count($row['extra']),
            'lo' => $lo, 'hi' => $hi, 'windows' => $windows, 'dead' => (int)($row['dead_n'] ?? 0)];
        return self::result('map', ['ids' => $ids, 'total' => $T, 'exact' => true, 'has_more' => $lo > 0, 'found' => $T]);
    }

    /** Round 6: the live matches of a token's map entry (main row less its dead sampled ids, appended ids, docs above the cover). */
    private static function mapTotal(array $row): int
    {
        $deadMain = isset($row['dead_main']) && is_array($row['dead_main']) ? $row['dead_main'] : [];
        $n = (int)$row['df'] - ((int)$row['kind'] === 2 ? count($deadMain) : 0);
        return $n + (int)$row['tail_n'] + count($row['extra']);
    }

    private static function unpackIds(string $packed): array
    {
        return $packed === '' ? [] : array_values(unpack('V*', $packed));
    }

    /**
     * The docs at the map ranks $lo .. $hi (counted from the oldest live match) of a token's map entry: doc => [vod_id,
     * type_id, vod_time], or null when the entry cannot answer (no sampled list for a kind-2 window). Round 6: split out of
     * mapPage() (the same arithmetic), so the route's (vod_time, vod_id) pages (mapPageOrdered) read a window of ranks too.
     * Round-3 F-r3-1: live ranks. Kind 1 / appended ids / head are live lists already (mapRowOf); a kind-2 sampled row keeps
     * its df ranks and loses the dead ids below each sample (deadMain) in the window arithmetic below.
     */
    private static function mapRanks(string $ft, string $token, array $row, int $lo, int $hi, &$windows = 0): ?array
    {
        $windows = 0;
        $deadMain = isset($row['dead_main']) && is_array($row['dead_main']) ? $row['dead_main'] : [];
        $n = (int)$row['df'] - ((int)$row['kind'] === 2 ? count($deadMain) : 0);
        $tn = (int)$row['tail_n'];
        $list = self::unpackIds((string)$row['data']);
        $headRaw = self::unpackIds((string)$row['head']);
        $head = self::unpackIds((string)($row['head_live'] ?? $row['head']));
        $tail = self::unpackIds((string)$row['tail']);
        $hn = count($head);
        $byDoc = [];   // doc => [vod_id, type_id, vod_time]
        $wantDocs = [];
        $wLo = null;
        $wHi = null;
        for ($r = $lo; $r <= $hi; $r++) {
            if ($r >= $n + $tn) {
                $d = $row['extra'][$r - $n - $tn] ?? 0;
            } elseif ($r >= $n) {
                $d = $tail[$r - $n] ?? 0;
            } elseif ((int)$row['kind'] === 1) {
                $d = $list[$r] ?? 0;
            } elseif ($hn > 0 && $r >= $n - $hn) {
                $d = $head[$r - ($n - $hn)] ?? 0;
            } else {
                $d = -1;
                $wLo = $wLo === null ? $r : min($wLo, $r);
                $wHi = $wHi === null ? $r : max($wHi, $r);
            }
            if ($d > 0) {
                $wantDocs[] = $d;
            }
        }
        if ($wLo !== null) {
            $step = max(1, (int)$row['step']);
            // live rank of the first live doc at or above sample k: k x step minus the dead ids below the sample's doc
            // (non-decreasing in k: between two samples at most step ids die)
            $rankOf = function (int $k) use ($list, $step, $deadMain) {
                return $k * $step - ($deadMain ? self::countBelow($deadMain, (int)$list[$k]) : 0);
            };
            $last = count($list) - 1;
            if ($last < 0) {
                return null;
            }
            $k0 = 0;
            for ($a = 0, $b = $last; $a <= $b;) {
                $mid = intdiv($a + $b, 2);
                if ($rankOf($mid) <= $wLo) {
                    $k0 = $mid;
                    $a = $mid + 1;
                } else {
                    $b = $mid - 1;
                }
            }
            $k1 = $last + 1;
            for ($a = $k0, $b = $last; $a <= $b;) {
                $mid = intdiv($a + $b, 2);
                if ($rankOf($mid) > $wHi) {
                    $k1 = $mid;
                    $b = $mid - 1;
                } else {
                    $a = $mid + 1;
                }
            }
            if (!isset($list[$k0])) {
                return null;
            }
            $d1 = isset($list[$k1]) ? $list[$k1] : ($headRaw ? $headRaw[0] : $row['last_doc'] + 1);
            $skip = $wLo - $rankOf($k0);
            $take = $wHi - $wLo + 1;
            $wkey = 'win:' . $token . ':' . (int)$list[$k0] . ':' . (int)$d1;
            $rows = null;
            if (self::memoOn() && isset(self::$memo[$wkey]) && self::$memo[$wkey]['limit'] >= $skip + $take) {
                $rows = self::$memo[$wkey]['rows'];
            }
            if ($rows === null) {
                $rows = FtIndex::q('SELECT FTS_DOC_ID, vod_id, type_id, vod_time FROM ' . $ft . ' WHERE FTS_DOC_ID >= ' . (int)$list[$k0] . ' AND FTS_DOC_ID < ' . (int)$d1
                    . ' AND ' . self::likeOf([$token]) . ' ORDER BY FTS_DOC_ID ASC LIMIT ' . ($skip + $take));
                $windows = 1;
                if (self::memoOn()) {
                    self::$memo[$wkey] = ['limit' => $skip + $take, 'rows' => $rows];
                }
            }
            foreach (array_slice($rows, $skip, $take) as $r) {
                $byDoc[(int)$r['FTS_DOC_ID']] = [(int)$r['vod_id'], (int)$r['type_id'], (int)$r['vod_time']];
            }
        }
        if ($wantDocs) {
            $byDoc += self::docRows($ft, $wantDocs);
        }
        return $byDoc;
    }

    /**
     * Round 6 (D-9): inverted documents of one token one route page puts in place at most (more: the route is refused and
     * the list takes the exact LIKE path until a rebuild re-numbers the documents). A page reads per + 2 x inverted + 1 map
     * ranks. 7.1 M c set, 20,000 old videos re-imported (h/invdemo.php --more=20000): tg_cameltoe 92 inverted documents,
     * tg_leggings 52, tg_wedding 91, tg_atm 94, tg_18yearsold 62, tg_riding 539, tg_skinny 695 (300 refused the last two;
     * cameltoe page 1,000 cold: 1,422 reads, 0.25 s).
     */
    const ROUTE_INV_MAX = 1000;
    /** Test hook (tests/ft_route_order.php): the cap instead of ROUTE_INV_MAX (null). */
    public static $routeInvMaxHook = null;

    /** Round 6: ROUTE_INV_MAX, or the test hook's value. */
    private static function routeInvMax(): int
    {
        return self::$routeInvMaxHook !== null ? max(0, (int)self::$routeInvMaxHook) : self::ROUTE_INV_MAX;
    }

    /**
     * Round 6 (orchestrator D-9, search-r6 R6-5): page $page of a token's matches in (vod_time, vod_id) DESC order, the plain
     * LIKE's order, while some of its documents are inverted (written at the top with an older key: an admin save or a
     * re-import of an old video; $inv = [doc, vod_time, vod_id] of every such document holding the token, FtIndex::invDocs,
     * complete while the tracking is on). Every other document of the token (in-order: no earlier document has a larger
     * key) sorts by doc id exactly as by key. For an in-order doc x: rank(x) = (newest-first map rank of x - inverted docs
     * above x in doc order) + inverted docs with a larger key. An inverted doc i takes the place right below the in-order doc
     * y+ with the smallest key above its own: rank(i) = (in-order docs at or above y+) + inverted docs with a larger key. The
     * page reads the map ranks [A - m - 1, A + per + m] (A = (page - 1) x per, m = inverted docs): every in-order doc of the
     * page lies there (its map rank is its rank plus at most m), and so does y+ of every inverted doc of the page (between y+
     * and i only inverted docs sort). Null = the map cannot answer (the caller falls back like an unusable map).
     */
    private static function mapPageOrdered(string $ft, string $token, int $page, int $per, array $hidden, array $inv): ?array
    {
        $row = self::mapEntry($ft, $token);
        if ($row === null) {
            return null;
        }
        $T = self::mapTotal($row);
        $m = count($inv);
        $A = ($page - 1) * $per;
        if ($T === 0 || $A >= $T) {
            return self::result('map', ['ids' => [], 'total' => $T, 'exact' => true, 'found' => $T]);
        }
        $wa = max(0, $A - $m - 1);                 // newest-first map ranks of the window
        $wb = min($T - 1, $A + $per + $m);
        $byDoc = self::mapRanks($ft, $token, $row, $T - 1 - $wb, $T - 1 - $wa, $windows);
        if ($byDoc === null || count($byDoc) !== $wb - $wa + 1) {
            return null; // a doc of the window is gone (deleted meanwhile): no answer rather than a wrong rank
        }
        ksort($byDoc); // doc order = map order
        $invByDoc = [];
        foreach ($inv as $v) {
            $invByDoc[(int)$v[0]] = [(int)$v[1], (int)$v[2]];
        }
        $invDocsDesc = array_keys($invByDoc);
        rsort($invDocsDesc);
        $nInv = count($invDocsDesc);
        // the inverted keys, largest first: "inverted docs with a larger key" is one binary search ((vod_time, vod_id) pairs
        // compare element by element)
        $invKeys = array_values($invByDoc);
        usort($invKeys, function ($a, $b) { return $b <=> $a; });
        $largerThan = function (array $key) use ($invKeys, $nInv): int {
            $lo = 0;
            $hi = $nInv;
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if ($invKeys[$mid] > $key) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            return $lo;
        };
        // window docs newest first with their newest-first map rank
        $win = [];
        $rank = $wb;
        foreach ($byDoc as $d => $v) {
            $win[] = ['d' => $d, 'rank' => $rank, 'v' => $v];
            $rank--;
        }
        $win = array_reverse($win); // rank $wa first = doc order descending
        $out = [];
        $inOrder = []; // in-order window docs: [key, jNew, vod_id, type_id], newest first (their keys strictly descending)
        $above = 0;    // inverted docs above the current window doc in doc order (both lists descend: one pass)
        foreach ($win as $w) {
            $d = $w['d'];
            while ($above < $nInv && $invDocsDesc[$above] > $d) {
                $above++;
            }
            if (isset($invByDoc[$d])) {
                continue;
            }
            $key = [(int)$w['v'][2], (int)$w['v'][0]];
            $jNew = $w['rank'] - $above;
            $inOrder[] = [$key, $jNew, (int)$w['v'][0], (int)$w['v'][1]];
            $t = $jNew + $largerThan($key);
            if ($t >= $A && $t < $A + $per) {
                $out[$t] = [(int)$w['v'][0], (int)$w['v'][1]];
            }
        }
        $nIo = count($inOrder);
        $reachTop = $wa === 0;
        $reachBottom = $wb === $T - 1;
        $invRows = null;
        foreach ($invByDoc as $d => $k) {
            // in-order keys descend: those above $k are a prefix [0, $j); y+ = the last of them (the smallest key above $k)
            $lo = 0;
            $hi = $nIo;
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if ($inOrder[$mid][0] > $k) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            $yPlus = $lo > 0 ? $inOrder[$lo - 1] : null;
            $hasBelow = $lo < $nIo;
            if ($yPlus !== null && ($hasBelow || $reachBottom)) {
                $cnt = $yPlus[1] + 1;
            } elseif ($yPlus === null && $reachTop) {
                $cnt = 0;
            } else {
                continue; // its place lies outside the window: not on this page
            }
            $t = $cnt + $largerThan($k);
            if ($t >= $A && $t < $A + $per) {
                if ($invRows === null) {
                    $invRows = self::docRows($ft, array_keys($invByDoc));
                }
                if (!isset($invRows[$d])) {
                    return null; // an inverted doc vanished meanwhile
                }
                $out[$t] = [(int)$invRows[$d][0], (int)$invRows[$d][1]];
            }
        }
        ksort($out);
        $hset = $hidden ? array_flip($hidden) : [];
        $ids = [];
        foreach ($out as $v) {
            if (!isset($hset[$v[1]]) && !in_array($v[0], $ids, true)) {
                $ids[] = $v[0];
            }
        }
        self::$last['map'] = ['kind' => $row['kind'], 'ordered' => true, 'inverted' => $m, 'window' => [$wa, $wb], 'windows' => $windows];
        return self::result('map', ['ids' => $ids, 'total' => $T, 'exact' => true, 'has_more' => $A + $per < $T, 'found' => $T]);
    }

    /** Pure: how many values of the ascending int list $sorted are below $x (binary search). */
    private static function countBelow(array $sorted, int $x): int
    {
        $a = 0;
        $b = count($sorted);
        while ($a < $b) {
            $mid = intdiv($a + $b, 2);
            if ($sorted[$mid] < $x) {
                $a = $mid + 1;
            } else {
                $b = $mid;
            }
        }
        return $a;
    }

    /**
     * Candidates scan (round 2), newest first, resumable in the request (memo per query + cursor): first the docs above the
     * map's cover (a LIKE range of at most MAP_GAP_MAX docs, the full filter), then the kind-1 token's own doc ids (main
     * list + appended chunks) below the cursor, checked by primary key with the full filter in chunks whose size follows the
     * hit rate, until $need visible matches, the end of the list, or $budget docs checked. Returns the walk() shape plus
     * 'checked' and 'found'; 'floor' + 1 = the continuation cursor (every candidate at or above it was checked).
     */
    private static function candWalk(string $ft, string $where, array $cand, int $need, array $hidden, int $cursor, int $budget, string $sig): array
    {
        $key = self::memoOn() ? self::memoKey('cand', [$sig, $cursor, $cand['token']]) : '';
        $st = $key !== '' && isset(self::$memo[$key]) ? self::$memo[$key] : null;
        $hset = $hidden ? array_flip($hidden) : [];
        if ($st === null) {
            list(, $maxDoc) = self::ends($ft);
            $list = $cand['data'] === '' ? [] : array_values(unpack('V*', $cand['data']));
            if ($cand['tail'] !== '') {
                $list = array_merge($list, array_values(unpack('V*', $cand['tail'])));
            }
            $cover = (int)($cand['cover'] ?? $cand['last_doc']);
            $top = $cursor > 0 ? min($maxDoc, $cursor - 1) : $maxDoc;
            // first candidate position below the cursor (the list is ascending)
            $pos = count($list) - 1;
            if ($cursor > 0) {
                $a = 0;
                $b = count($list);
                while ($a < $b) {
                    $mid = intdiv($a + $b, 2);
                    if ($list[$mid] < $cursor) {
                        $a = $mid + 1;
                    } else {
                        $b = $mid;
                    }
                }
                $pos = $a - 1;
            }
            $st = ['list' => $list, 'pos' => $pos, 'cover' => $cover, 'gap_hi' => $top, 'gap' => $top > $cover, 'rows' => [], 'checked' => 0, 'end' => false,
                'floor' => $top, 'chunks' => 0];
        }
        $visible = 0;
        foreach ($st['rows'] as $r) {
            if (!isset($hset[$r[2]])) {
                $visible++;
            }
        }
        // the docs added after the map's cover: the newest, read first by range (resumable: gap_hi = below what was read;
        // rows of hidden categories do not count, so the range continues until the page is full or the range is done)
        while ($st['gap'] && $visible < $need) {
            $lim = max(1, $need - $visible);
            $rows = FtIndex::q('SELECT vod_id, FTS_DOC_ID, type_id FROM ' . $ft . ' WHERE FTS_DOC_ID > ' . $st['cover'] . ' AND FTS_DOC_ID <= ' . $st['gap_hi'] . ' AND ' . $where
                . ' ORDER BY FTS_DOC_ID DESC LIMIT ' . $lim);
            foreach ($rows as $r) {
                $st['rows'][] = [(int)$r['vod_id'], (int)$r['FTS_DOC_ID'], (int)$r['type_id']];
                if (!isset($hset[(int)$r['type_id']])) {
                    $visible++;
                }
            }
            if (count($rows) >= $lim) {
                $st['gap_hi'] = (int)$rows[count($rows) - 1]['FTS_DOC_ID'] - 1;
                $st['floor'] = $st['gap_hi'];
            } else {
                $st['gap'] = false;
                $st['floor'] = min($st['floor'], $st['cover']);
            }
        }
        while ($visible < $need && !$st['end'] && $st['checked'] < $budget && !$st['gap']) {
            if ($st['pos'] < 0) {
                $st['end'] = true;
                break;
            }
            $got = $visible;
            $rate = $st['checked'] > 0 ? ($got + 0.5) / $st['checked'] : 0.5;
            $size = $st['chunks'] === 0 ? min(self::CAND_FIRST, $budget) : (int)ceil(($need - $visible) / max(0.002, $rate) * 1.25);
            // at least 20 per statement, never past the budget or below the start of the list (a negative slice offset
            // would count from the end)
            $size = (int)min(max(20, $size), self::CAND_CHUNK_MAX, max(1, $budget - $st['checked']), $st['pos'] + 1);
            $chunk = array_slice($st['list'], $st['pos'] - $size + 1, $size);
            $st['pos'] -= $size;
            $st['chunks']++;
            $st['checked'] += count($chunk);
            $rows = FtIndex::q('SELECT vod_id, FTS_DOC_ID, type_id FROM ' . $ft . ' WHERE FTS_DOC_ID IN (' . implode(',', array_map('intval', $chunk)) . ') AND ' . $where);
            usort($rows, function ($x, $y) { return (int)$y['FTS_DOC_ID'] <=> (int)$x['FTS_DOC_ID']; });
            foreach ($rows as $r) {
                $st['rows'][] = [(int)$r['vod_id'], (int)$r['FTS_DOC_ID'], (int)$r['type_id']];
                if (!isset($hset[(int)$r['type_id']])) {
                    $visible++;
                }
            }
            $st['floor'] = (int)$chunk[0] - 1;
            if ($st['pos'] < 0) {
                $st['end'] = true;
            }
        }
        if ($key !== '') {
            self::$memo[$key] = $st;
        }
        $ids = [];
        $doc = [];
        foreach ($st['rows'] as $r) {
            if (isset($hset[$r[2]])) {
                continue;
            }
            $ids[] = $r[0];
            $doc[$r[0]] = $r[1];
            if (count($ids) >= $need) {
                break;
            }
        }
        // the end counts only when every visible match read so far is in the answer
        $end = $st['end'] && count($ids) < $need;
        return ['ids' => $ids, 'doc' => $doc, 'read' => $st['checked'], 'bytes' => 0.0, 'windows' => $st['chunks'], 'end' => $end,
            'floor' => (int)$st['floor'], 'checked' => $st['checked'], 'found' => count($ids)];
    }

    /**
     * C3 "Oldest results" and its up-cursor pages: the reverse scan upward from the oldest doc (or above $up), until $per
     * matches are found; shown newest first. Round 2 (N10): with a kind-1 candidate token its own docs are checked by
     * primary key (oldest first, CAND_BUDGET docs); otherwise windows upward from the first doc of the query's rarest exact
     * token (deepening like the newest walk, WALK_CAP documents and WALK_PAGES pages). 'prev' = the doc the "Newer results"
     * link continues above: the highest match of a full page, or the position the budget reached (never an empty dead end);
     * 0 when the scan reached the newest doc.
     */
    private static function oldestWalk(string $ft, string $where, int $per, array $hidden, int $w0, int $walkCap, ?array $cand, int $from, int $up, string $sig): array
    {
        list($minDoc, $maxDoc) = self::ends($ft);
        $hset = $hidden ? array_flip($hidden) : [];
        $found = [];      // [vod_id, doc], ascending
        $reached = $from; // every doc at or below it was checked (or holds no match: below the rarest token's first doc)
        $end = false;
        $read = 0;
        $up = max($up, 0);
        if ($cand !== null) {
            $list = $cand['data'] === '' ? [] : array_values(unpack('V*', $cand['data']));
            if ($cand['tail'] !== '') {
                $list = array_merge($list, array_values(unpack('V*', $cand['tail'])));
            }
            $cover = (int)($cand['cover'] ?? $cand['last_doc']);
            $a = 0;
            $b = count($list);
            while ($a < $b) {
                $mid = intdiv($a + $b, 2);
                if ($list[$mid] <= $from) {
                    $a = $mid + 1;
                } else {
                    $b = $mid;
                }
            }
            $pos = $a;
            $checked = 0;
            while (count($found) < $per && $checked < self::CAND_BUDGET && $pos < count($list)) {
                $size = (int)min(self::CAND_CHUNK_MAX, max(20, 3 * ($per - count($found))), self::CAND_BUDGET - $checked, count($list) - $pos);
                $chunk = array_slice($list, $pos, $size);
                $pos += $size;
                $checked += count($chunk);
                $rows = FtIndex::q('SELECT vod_id, FTS_DOC_ID, type_id FROM ' . $ft . ' WHERE FTS_DOC_ID IN (' . implode(',', array_map('intval', $chunk)) . ') AND ' . $where);
                usort($rows, function ($x, $y) { return (int)$x['FTS_DOC_ID'] <=> (int)$y['FTS_DOC_ID']; });
                foreach ($rows as $r) {
                    if (!isset($hset[(int)$r['type_id']])) {
                        $found[] = [(int)$r['vod_id'], (int)$r['FTS_DOC_ID']];
                        if (count($found) >= $per) {
                            break;
                        }
                    }
                }
                $reached = (int)end($chunk);
            }
            $read = $checked;
            if (count($found) < $per && $pos >= count($list)) {
                // the token's list is done: the docs above the cover come last (a LIKE range of at most MAP_GAP_MAX docs)
                if ($maxDoc > $cover) {
                    $rows = FtIndex::q('SELECT vod_id, FTS_DOC_ID, type_id FROM ' . $ft . ' WHERE FTS_DOC_ID > ' . max($cover, $from) . ' AND ' . $where
                        . ' ORDER BY FTS_DOC_ID ASC LIMIT ' . ($per - count($found)));
                    foreach ($rows as $r) {
                        if (!isset($hset[(int)$r['type_id']])) {
                            $found[] = [(int)$r['vod_id'], (int)$r['FTS_DOC_ID']];
                        }
                    }
                }
                $end = count($found) < $per;
                $reached = $maxDoc;
            }
            self::$last += ['path' => 'oldest(cand)', 'rows_read' => $read, 'found' => count($found)];
        } else {
            $budget = (float)self::WALK_PAGES * self::pageTextBytes();
            $lo = max($minDoc - 1, $from);
            $w = $w0;
            $bytes = 0.0;
            while (count($found) < $per && $lo < $maxDoc && $maxDoc > 0) {
                if ($read >= $walkCap || $bytes >= $budget) {
                    break;
                }
                $win = min($w, $walkCap - $read);
                $avg = self::windowBytes($ft, $lo, min($maxDoc, $lo + $win));
                $win = min($win, (int)floor(($budget - $bytes) / $avg));
                if ($win < self::WINDOW_MIN && $lo + $win < $maxDoc) {
                    break;
                }
                $hi = min($maxDoc, $lo + max(1, $win));
                $cur = $lo;
                while (true) {
                    $lim = $per - count($found);
                    $rows = FtIndex::q('SELECT vod_id, FTS_DOC_ID, type_id FROM ' . $ft . ' WHERE FTS_DOC_ID > ' . $cur . ' AND FTS_DOC_ID <= ' . $hi . ' AND ' . $where
                        . ' ORDER BY FTS_DOC_ID ASC LIMIT ' . $lim);
                    foreach ($rows as $r) {
                        $cur = (int)$r['FTS_DOC_ID'];
                        if (!isset($hset[(int)$r['type_id']])) {
                            $found[] = [(int)$r['vod_id'], (int)$r['FTS_DOC_ID']];
                        }
                    }
                    if (count($rows) < $lim || count($found) >= $per) {
                        break;
                    }
                }
                $read += $hi - $lo;
                $bytes += ($hi - $lo) * $avg;
                $lo = $hi;
                $reached = $hi;
                $w *= 2;
            }
            $end = $lo >= $maxDoc && count($found) < $per;
            if ($lo >= $maxDoc) {
                $reached = $maxDoc;
            }
            self::$last += ['path' => 'oldest', 'rows_read' => $read, 'found' => count($found)];
        }
        $found = array_slice($found, 0, $per);
        if (count($found) >= $per) {
            $prev = (int)$found[count($found) - 1][1];          // a full page: the next newer page continues above its newest match
        } else {
            $prev = $end || $reached >= $maxDoc ? 0 : $reached;   // the budget ended short: continue the scan above where it got
        }
        $ids = [];
        $doc = [];
        foreach (array_reverse($found) as $f) {
            $ids[] = $f[0];
            $doc[$f[0]] = $f[1];
        }
        // the "Older results" link of an up-cursor page continues below its oldest match (the earlier page)
        $next = $up > 0 && $found ? (int)$found[0][1] : 0;
        return self::result('walk', ['ids' => $ids, 'total' => count($ids), 'exact' => false, 'found' => count($ids), 'oldest' => true, 'prev' => $prev,
            'next' => $next > 1 ? $next : 0, 'has_more' => $prev > 0]);
    }

    /**
     * Average text bytes of the documents in (lo, hi]: WINDOW_SAMPLE positions, 1 row each (one index dive per position;
     * the walk reads these pages anyway). REF_DOC_BYTES when the window is empty.
     */
    private static function windowBytes(string $ft, int $lo, int $hi): float
    {
        $parts = [];
        $span = max(1, $hi - $lo);
        for ($i = 0; $i < self::WINDOW_SAMPLE; $i++) {
            $p = $hi - (int)floor($span * ($i + 0.5) / self::WINDOW_SAMPLE);
            $parts[] = '(SELECT LENGTH(ft_text) l FROM ' . $ft . ' WHERE FTS_DOC_ID <= ' . max($lo + 1, $p) . ' AND FTS_DOC_ID > ' . $lo . ' ORDER BY FTS_DOC_ID DESC LIMIT 1)';
        }
        $r = FtIndex::q('SELECT AVG(l) a, COUNT(*) n FROM (' . implode(' UNION ALL ', $parts) . ') s');
        $a = (float)($r[0]['a'] ?? 0);
        return $a > 0 ? max(32.0, $a) : (float)self::REF_DOC_BYTES;
    }

    /**
     * The deepening newest-walk, resumable inside one visitor request: the state (window, budget used, rows read with
     * their category) is kept per (query, categories, cursor), so a second call with other hidden categories or a larger
     * need (landing facts + visitor list of a tag page) continues where the first one stopped instead of walking again.
     * Hidden categories are filtered in PHP. Returns ['ids', 'doc' => [vod_id => FTS_DOC_ID], 'read', 'bytes', 'windows', 'end'].
     */
    private static function walk(string $ft, string $where, int $need, array $hidden, int $w0, int $walkCap, int $cursor, string $sig): array
    {
        $key = self::memoOn() ? self::memoKey('walk', [$sig, $cursor]) : '';
        $st = $key !== '' && isset(self::$memo[$key]) ? self::$memo[$key] : null;
        if ($st === null) {
            list($minDoc, $maxDoc) = self::ends($ft);
            $st = ['min' => $minDoc, 'max' => $maxDoc, 'hi' => $cursor > 0 ? min($maxDoc, $cursor - 1) : $maxDoc, 'lo' => null, 'top' => 0, 'w' => $w0,
                'read' => 0, 'bytes' => 0.0, 'windows' => 0, 'rows' => [], 'end' => false, 'capped' => false];
        }
        $hset = $hidden ? array_flip($hidden) : [];
        $visible = function () use (&$st, $hset) {
            $n = 0;
            foreach ($st['rows'] as $r) {
                if (!isset($hset[$r[2]])) {
                    $n++;
                }
            }
            return $n;
        };
        $bytesBudget = (float)self::WALK_PAGES * self::pageTextBytes();
        $v = $visible();
        while ($v < $need && !$st['end'] && !$st['capped']) {
            if ($st['lo'] === null) {
                if ($st['hi'] < $st['min'] || $st['max'] === 0) {
                    $st['end'] = true;
                    break;
                }
                if ($st['read'] >= $walkCap || $st['bytes'] >= $bytesBudget) {
                    $st['capped'] = true;
                    break;
                }
                $win = min($st['w'], $walkCap - $st['read']);
                $avg = self::windowBytes($ft, $st['hi'] - $win, $st['hi']);
                $byBytes = (int)floor(($bytesBudget - $st['bytes']) / $avg);
                if ($byBytes < $win) {
                    $win = $byBytes;
                    if ($win < self::WINDOW_MIN) {
                        $st['capped'] = true;
                        break;
                    }
                }
                $st['lo'] = $st['hi'] - $win;
                $st['top'] = $st['hi'];
                $st['read'] += $win;
                $st['bytes'] += $win * $avg;
                $st['windows']++;
                $st['w'] *= 2;
            }
            $limit = $need - $v;
            $rows = FtIndex::q('SELECT vod_id, FTS_DOC_ID, type_id FROM ' . $ft . ' WHERE FTS_DOC_ID > ' . $st['lo'] . ' AND FTS_DOC_ID <= ' . $st['top'] . ' AND ' . $where
                . ' ORDER BY FTS_DOC_ID DESC LIMIT ' . $limit);
            foreach ($rows as $r) {
                $st['rows'][] = [(int)$r['vod_id'], (int)$r['FTS_DOC_ID'], (int)$r['type_id']];
                if (!isset($hset[(int)$r['type_id']])) {
                    $v++;
                }
            }
            if (count($rows) < $limit) {
                // window exhausted: the next one starts below it
                $st['hi'] = $st['lo'];
                $st['lo'] = null;
                if ($st['hi'] < $st['min']) {
                    $st['end'] = true;
                }
            } else {
                $st['top'] = (int)$rows[count($rows) - 1]['FTS_DOC_ID'] - 1;
            }
        }
        if ($key !== '') {
            self::$memo[$key] = $st;
        }
        $ids = [];
        $doc = [];
        foreach ($st['rows'] as $r) {
            if (isset($hset[$r[2]])) {
                continue;
            }
            $ids[] = $r[0];
            $doc[$r[0]] = $r[1];
            if (count($ids) >= $need) {
                break;
            }
        }
        // the end of the table counts only when every visible row read so far is in the answer
        $end = $st['end'] && count($ids) < $need;
        // floor: every doc above it was read (C3: a capped walk continues below it)
        $floor = $st['lo'] === null ? $st['hi'] : $st['lo'];
        return ['ids' => $ids, 'doc' => $doc, 'read' => $st['read'], 'bytes' => $st['bytes'], 'windows' => $st['windows'], 'end' => $end, 'floor' => (int)$floor];
    }

    /**
     * Newest FT_LIMIT ids of the rare part (+ LIKE filters of the common part), categories and hidden categories filtered
     * in PHP. $limitSec > 0: the statement runs under that limit (MariaDB >= 10.1.2 SET STATEMENT max_statement_time,
     * MySQL >= 5.7.8 MAX_EXECUTION_TIME hint) and a timeout returns null; engines without a statement limit return null
     * without running it. MySQL before 8.0: the MATCH carries the rarest group only, the others become LIKE filters.
     * $cursor > 1: only docs below it (C3 continuation of a list cut at FT_LIMIT); $docs (out): vod_id => FTS_DOC_ID.
     */
    private static function ftIds(string $ft, array $rare, array $likeCommon, array $types, array $hidden, float $limitSec, int $cursor = 0, ?array &$docs = null, int $limit = self::FT_LIMIT): ?array
    {
        $eng = self::engine();
        if (count($rare) > 1 && $eng['kind'] === 'mysql' && $eng['id'] < 80000) {
            usort($rare, function ($a, $b) { return $a['d'] <=> $b['d']; });
            foreach (array_slice($rare, 1) as $g) {
                $l = self::likeOf($g['t']);
                if ($l !== null) {
                    $likeCommon[] = $l;
                }
            }
            $rare = [$rare[0]];
            self::$last['ft_rarest_only'] = true;
        }
        $b = FtText::booleanOf($rare);
        if (!preg_match('/^[a-z0-9_ "+]+$/D', $b)) {
            return [];
        }
        $limit = max(1, min(self::FT_LIMIT, $limit));
        $key = self::memoOn() ? self::memoKey('ft', [$b, $likeCommon, $cursor, $limit]) : '';
        if ($key !== '' && isset(self::$memo[$key])) {
            $rows = self::$memo[$key];
        } else {
            $sql = 'SELECT vod_id, type_id, type_id_1, FTS_DOC_ID FROM ' . $ft . ' WHERE MATCH(ft_text) AGAINST(' . FtIndex::pdo()->quote($b) . ' IN BOOLEAN MODE)'
                . ($likeCommon ? ' AND ' . implode(' AND ', $likeCommon) : '') . ($cursor > 1 ? ' AND FTS_DOC_ID < ' . (int)$cursor : '')
                . ' ORDER BY FTS_DOC_ID DESC LIMIT ' . $limit;
            if ($limitSec > 0) {
                // round 8 (flood-r8 R8-1): never past the request's database deadline (FtIndex::capSec, as FtIndex::limited())
                $limitSec = method_exists(__NAMESPACE__ . '\\FtIndex', 'capSec') ? FtIndex::capSec($limitSec) : $limitSec;
                if ($eng['kind'] === 'mariadb' && $eng['id'] >= 100102) {
                    $sql = 'SET STATEMENT max_statement_time=' . sprintf('%.3F', max(0.001, $limitSec)) . ' FOR ' . $sql;
                } elseif ($eng['kind'] === 'mysql' && $eng['id'] >= 50708) {
                    $sql = 'SELECT /*+ MAX_EXECUTION_TIME(' . (int)round($limitSec * 1000) . ') */' . substr($sql, 6);
                } else {
                    return null;
                }
                try {
                    $rows = FtIndex::q($sql);
                } catch (\Throwable $e) {
                    if (class_exists(__NAMESPACE__ . '\\DbTimeout') && DbTimeout::is($e)) {
                        return null;
                    }
                    throw $e;
                }
            } else {
                $rows = FtIndex::q($sql);
            }
            if ($key !== '') {
                self::$memo[$key] = $rows;
            }
        }
        $tset = $types ? array_flip($types) : null;
        $hset = $hidden ? array_flip($hidden) : [];
        $ids = [];
        $docs = [];
        // a list cut at FT_LIMIT: the last row's doc, so the last page can continue below it even when that row is hidden
        $docs[0] = count($rows) >= $limit ? (int)$rows[count($rows) - 1]['FTS_DOC_ID'] : 0;
        foreach ($rows as $r) {
            $t = (int)$r['type_id'];
            if (isset($hset[$t])) {
                continue;
            }
            if ($tset !== null && !isset($tset[$t]) && !isset($tset[(int)$r['type_id_1']])) {
                continue;
            }
            $ids[] = (int)$r['vod_id'];
            $docs[(int)$r['vod_id']] = (int)$r['FTS_DOC_ID'];
        }
        return $ids;
    }

    /**
     * A page of a complete id list (FULLTEXT / recall paths). $docs[0] > 0 = the list was cut at FT_LIMIT: its last page
     * continues below that doc (cursor); on a cursor page ($cursorPage) a full page continues below its last doc;
     * $oldest = the last page of the list.
     */
    private static function page(array $ids, int $page, int $per, string $path, int $total, bool $exact, array $docs = [], bool $cursorPage = false, bool $oldest = false): array
    {
        $cut = (int)($docs[0] ?? 0);
        if ($oldest) {
            $page = max(1, (int)ceil(count($ids) / $per));
        }
        $offset = $cursorPage ? 0 : ($page - 1) * $per;
        $slice = array_slice($ids, $offset, $per);
        $more = count($ids) > $offset + $per;
        $next = 0;
        if (!$oldest && $more && $cursorPage && $slice && isset($docs[end($slice)])) {
            $next = $docs[end($slice)];
        } elseif (!$oldest && !$more && $cut > 1) {
            $next = $cut;
            $exact = false;
        }
        $cursorOut = $more && $slice && isset($docs[end($slice)]) ? $docs[end($slice)] : $next;
        return self::result($path, ['ids' => $slice, 'total' => $total, 'exact' => $exact, 'has_more' => $more || $next > 0, 'cursor' => $cursorOut,
            'found' => count($ids), 'next' => $next] + ($oldest ? ['oldest' => true] : []));
    }
}
