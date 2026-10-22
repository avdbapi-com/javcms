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

use think\Cache;
use think\Db;

/**
 * Vocabulary of the FULLTEXT-indexed filter columns (ft-vocab, FilterMatch v2, 2026-10-17).
 *
 * FilterMatch narrows `col LIKE '%w%'` with MATCH(col) AGAINST(...). A word index finds words by their start, so a single
 * word w can only be pre-filtered exactly through the list of index words that CONTAIN w: T(w) = vocabulary tokens t with
 * `t LIKE '%w%'` (same column collation). Every row the LIKE finds holds such a token, so
 * MATCH(col) AGAINST('t1 t2 ... tn' IN BOOLEAN MODE) AND col LIKE '%w%' returns the same rows as the LIKE alone, provided
 *  1. the vocabulary holds every token of every row (freshness, below),
 *  2. its tokens are cut at least where InnoDB's parser cuts: this tokenizer splits only on ASCII characters other than
 *     A-Z a-z 0-9 _ and keeps every non-ASCII byte inside the word, while InnoDB's default parser also splits on many
 *     non-ASCII characters. A token of ours that is pure ASCII is therefore exactly an InnoDB token; a token with a
 *     non-ASCII character is flagged (F_NONASCII) and FilterMatch never uses a group that contains one,
 *  3. the token is in the index: FilterMatch refuses groups with a token shorter than max(min_token_size, 3), longer than
 *     max_token_size, a stopword, or a piece of a token longer than 84 characters (F_LONG).
 * Lookups run in SQL with the column's collation (the token column is created with it), so accent / width rules are the
 * row LIKE's own; a non-ASCII spelling that collides with an ASCII token under the collation sets F_NONASCII on it.
 * Only for the *_general_ci collations (COLLATIONS); FilterMatch keeps the plain LIKE for columns in any other.
 *
 * Table {prefix}vod_ft_vocab (col, token, flags, seen), PRIMARY KEY (col, token):
 *  col 1-4 = vod_tag, vod_actor, vod_class, vod_director; col 0 = state (token = key, seen = value);
 *  col 255 = videos changed by a save path that may keep vod_time (token = vod_id, seen = time of its latest save),
 *  consumed by the next catch-up that read the row after that save.
 *  seen = UNIX time the token was last confirmed; a full build deletes tokens not confirmed since it started.
 *
 * Freshness (exactness depends on it). The state holds a fingerprint of the newest rows: count / max / XOR of
 * CRC32(vod_id, vod_time, vod_tag, vod_actor, vod_class, vod_director) of the rows with vod_id > ifloor (the last
 * ID_WINDOW ids; a primary-key range, so the filter columns cost nothing extra) and count / XOR of CRC32(vod_id/vod_time)
 * of the rows with vod_time >= tfloor (the last TIME_MARGIN seconds; covered by the vod_time index). usable() compares it
 * with the database (once per request, ~1 ms) and, when new / re-timed / re-tagged rows or queued ids exist, adds their
 * tokens first (catch-up, under a non-blocking named lock, at most DELTA_MAX rows; more -> the vocabulary is marked
 * stale: FilterMatch falls back to the plain LIKE until the next full build). Saves that may keep vod_time queue their
 * ids (touched(): Vod::saveData, Vod::fieldData, Vod::savePlot, Collect, AvdbImport); raw SQL tools mark the vocabulary
 * stale (stale(): admin Database sql / replace / import). A full build runs from tools/vod-ft-vocab.php,
 * tools/vod-indexes.php --apply and housekeeping (resumable, BATCH rows per step, daily). Not seen until that build: a
 * raw UPDATE outside JavCMS that keeps vod_time on a row older than the last ID_WINDOW ids.
 *
 * Lookup cache. T(w) is cached per column + kind + word under (rev, nonce): rev counts the changes of this table's word
 * set (a catch-up that added words or flags, a full-build batch that added words or flags while pages use the
 * vocabulary, every build end) and only grows while the table lives; nonce is a random number drawn with every new rev.
 * Every statement that adds a word or a flag to a vocabulary pages may use is followed, under the same lock, by a new
 * (rev, nonce), so no list cached for a smaller word set is served afterwards. A table that starts again at rev 1
 * (dropped, truncated, reinstalled while the runtime cache stays) or an old copy restored by hand never meets a list
 * cached for another word set: the pair names one word set.
 *
 * Lock: one named lock (GET_LOCK) per database + prefix, the same for the app, housekeeping and the CLI tools (they
 * pass dbKey() of application/database.php), so a build batch and a page catch-up never interleave.
 *
 * Writes (fifth pass, FT-VOCAB-REVIEW-d P3-1 / P3-2): the words a catch-up or a build batch adds go into the table in ONE
 * transaction with the queue entries they consume and the state they move (cursor, floors, fingerprint, the new
 * (rev, nonce)); the end of a build writes ready and its new pair in one transaction too. A process that dies in between
 * (php-cgi killed, server restart) leaves nothing half done: the server rolls the transaction back, the queue entries
 * stay, and the next catch-up or batch adds the words again with a new pair. The stale mark is cleared only by one
 * conditional statement (seen < b_start), never written back from a value read earlier, so a mark set while a build
 * ends stays. touched() retries a queue write once after a deadlock / lock wait timeout and marks the vocabulary stale
 * when the ids still cannot be queued (their new words could otherwise be missed).
 *
 * Long words (fifth pass, FT-VOCAB-REVIEW-d P2-1): InnoDB indexes no word longer than innodb_ft_max_token_size.
 * longPrefix() tells FilterMatch whether a word of a column that starts with a value's last word is that long; then
 * v1's term +word* cannot find its row and is not used.
 *
 * Off switch: state key 'off' (tools/vod-ft-vocab.php --off / --on): no groups (plain LIKE, v1 behaviour), no catch-ups
 * and no builds until it is removed; builds never clear it. The save hooks keep queueing, so --on catches up.
 *
 * JavCMS 1.2.0 (fp-port, 2026-10-19), on top of the live package f:
 *  - Backlog instead of a full rebuild: more than one catch-up's worth of new ids (a bulk import between two filter
 *    requests) or of queued ids (an admin batch edit of many videos) no longer marks the vocabulary stale (a full build
 *    reads 7.1 M videos). A catch-up reads them in key order, at most $chunk per call, writes ifloor = the last id it
 *    read and the state key 'pend' in the same transaction, and the next call goes on from there. While 'pend' is set
 *    the vocabulary is not usable (plain LIKE; no fingerprint read, which would cover the whole backlog); page requests
 *    move it by BACKLOG_PAGE rows, housekeeping and the import hook by DELTA_MAX. More than DELTA_MAX re-timed rows in
 *    the time window still mark it stale, as before.
 *  - imported(): the importers (AvdbImport batch ends incl. bulk windows, Collect pages, tools/import.php through
 *    AvdbImport) queue the ids they saved and run the catch-up themselves after the commit, so the vocabulary stays
 *    usable during a long import instead of waiting for a filter request.
 *  - housekeepPdo(): the build / backlog slice of Housekeeping::scale() on the scale run's own connection (cron, cron
 *    URL, schedule callback, page-view slices, tools/upgrade.php), with the site's lock name; it creates the table when
 *    an upgraded site has none yet (ensureTable(), only under COLLATIONS).
 *
 * JavCMS 1.2.0 item path (fp-port-b, 2026-10-19; FINISH-PORT-REVIEW P2-3): FilterMatch has no word path for a value with
 * non-ASCII characters (InnoDB's parser cuts such words in ways no PHP tokenizer can promise), so a CJK actor or tag ran
 * the plain LIKE. For the item columns vod_tag / vod_actor / vod_class the vocabulary also keeps every comma item
 * (FtText::splitList, raw as stored) that holds a CJK / kana / Hangul / Thai / Lao / Khmer / Myanmar character of the BMP
 * (cols 5-7, token = item + 0x1F + 12 hex digits of md5(item), so collation-equal spellings with other field tokens are
 * kept apart). FilterMatch::items() finds the items that LIKE-match a value (same pattern, same collation) and reads the
 * rows through the search table's field tokens of those items (FtText::fieldToken: tg_ for vod_tag and vod_class items,
 * ac_ for vod_actor items), which hold no title text. That is exact while every visible row with such an item has a
 * search document holding the item's field token, is queued for one (vod_ft_queue), lies in an open bulk window, or is an
 * exception row, which this class keeps true:
 *  - col 252 (token = vod id in 10 digits, seen = crc32 of the row's sorted field tokens): a catch-up or a build batch
 *    that reads a visible row whose tokens hash differently (new row, edited row, raw insert, a row an import batch cut
 *    short wrote) reads its search document and, when a token is missing, queues the row into vod_ft_queue in the same
 *    transaction as the hash (drain() writes the document again from the row);
 *  - col 254 (token = vod id, flags X_ITEM / X_DOC): rows whose item cannot be stored whole (longer than ITEM_MAX_CHARS)
 *    or has no valid field token, rows that are not UTF-8, and rows whose document was cut at FtText::DOC_MAX before its
 *    field tokens: the item path always reads them (at most XROW_MAX, else it is off);
 *  - state 'xitems': written by the end of a full build that this version started ('b_items'), so the item path waits for
 *    one complete pass (a live d/f table or one built by the first 1.2.0 port gets it from the next build: needsBuild()).
 * The search table's own freshness (ready, 'ft' mode, no rebuild due but 'langs', bulk window, queue) is checked by
 * FilterMatch for every value.
 *
 * Visibility (fp-port-c, 2026-10-19; FINISH-PORT-REVIEW-b P2-1): a row that leaves the lists (hidden, recycled) loses its
 * search document at the next drain, and a hash entry of col 252 only proves the document of the row as it was read. So:
 *  - every JavCMS write that makes rows visible (or may: AvdbImport apply / restore / presence restore / rollback, admin
 *    status batch, list switch, edit, recycle-bin restore) queues them for the search table INSIDE its own transaction
 *    (searchQueue()): there is no moment at which such a row is visible without a document or a queue entry, however the
 *    writer is cut afterwards (runner killed, timeout, restart, lease lost); the hide paths queue the same way and call
 *    touched(), so the vocabulary drops the row's hash while it is hidden;
 *  - a catch-up checks the document of every row it reads because it was queued (col 255: touched()) whatever its hash
 *    says (a new row has no hash and is checked anyway), and a full build checks the document of every visible row it
 *    reads: a missing token queues the row in the same transaction. So a raw-SQL change the admin tools mark stale (stale(): plain LIKE until the
 *    next full build) and any row a cut writer left behind are healed by the next build, and a row a hook queued (col 255)
 *    is healed by the next request's catch-up;
 *  - 'xiver' (ITEMS_VERSION) is written by the end of a full build that ran with those checks from its first row; the item
 *    path waits for it, so a table built by an earlier version of the item path is built once more first. The same build end
 *    writes 'xibuilt' = 'built' (one statement): a full build that older code finishes later (a process still running the
 *    code of before the update rewrites 'built' and keeps or drops the item rows on its own terms) leaves 'xibuilt' behind,
 *    so the item path waits for the next build by this version (itemsComplete(), needsBuild()).
 * Not covered (as for the words, see above): raw SQL outside JavCMS that makes a hidden row visible without the admin
 * tools' stale mark; the next full build (daily, or tools/vod-ft-vocab.php --rebuild) or 1.2.0's search self-heal finds it.
 */
final class FilterVocab
{
    /** 2 (2026-10-17, third pass): (rev, nonce) lookup key + filter columns in the id fingerprint; a version-1 table is rebuilt once. */
    const VERSION = 2;
    const TABLE = 'vod_ft_vocab';
    const COLS = ['vod_tag' => 1, 'vod_actor' => 2, 'vod_class' => 3, 'vod_director' => 4];
    const COL_STATE = 0;
    const COL_DIRTY = 255;
    const F_NONASCII = 1;
    const F_LONG = 2;
    /** Longest token stored whole (InnoDB's innodb_ft_max_token_size maximum default); longer ones are stored as windows. */
    const MAX_CHARS = 84;
    const WINDOW_STEP = 44;
    /** Longest word looked up: every substring up to this length of a long token lies inside one of its windows. */
    const MAX_WORD = 40;
    /** Most tokens one group may have (a longer OR is slower than the plain scan, and a sign of a very short word). */
    const GROUP_CAP = 200;
    const DELTA_MAX = 20000;
    /** 1.2.0: rows (new ids, queued ids) one page request's catch-up reads while a backlog is worked off (about 0.1 s). */
    const BACKLOG_PAGE = 5000;
    const ID_WINDOW = 500;
    const TIME_MARGIN = 300;
    const PROBE_TTL = 5;
    const LOOKUP_TTL = 86400;
    const BATCH = 2000;
    const REBUILD_AGE = 86400;
    const CACHE_KEY = 'filter_vocab_v2_';
    /**
     * Collations of the filter columns (and the token column) under which the vocabulary is used. The groups rely on
     * every character that LIKE-matches an ASCII separator being that separator, and on one weight per character:
     * true for *_general_ci (a BMP scan finds no look-alike on MySQL 5.7 / 8.0 and MariaDB 10.3 / 11.4), false for the
     * UCA collations (utf8mb4_unicode_ci, utf8mb4_0900_ai_ci: U+00A0 and U+3000 LIKE-match a space). Others: plain LIKE.
     */
    const COLLATIONS = ['utf8mb4_general_ci', 'utf8_general_ci', 'utf8mb3_general_ci'];
    /** fp-port-b item path: item columns (their RUN-bearing items are words of cols 5-7). */
    const ITEM_COLS = ['vod_tag' => 5, 'vod_actor' => 6, 'vod_class' => 7];
    /** Search-index field token prefix of each item column (FtText::docFromRow: vod_tag and vod_class items tg_, vod_actor ac_). */
    const ITEM_PREFIX = ['vod_tag' => 'tg', 'vod_actor' => 'ac', 'vod_class' => 'tg'];
    /** Longest item stored (with its 13-character key suffix it fits the 84-character token column). */
    const ITEM_MAX_CHARS = 71;
    /** col 252: per-row hash of the field tokens its search document must hold. */
    const COL_HASH = 252;
    /** col 254: rows the item path always reads; X_ITEM: an item that cannot be stored, X_DOC: a document cut at DOC_MAX. */
    const COL_XROW = 254;
    const X_ITEM = 1;
    const X_DOC = 2;
    /** Most stored items one value may match (more: no item path for it). */
    const ITEM_CAP = 200;
    /** Most exception rows (col 254); more: the item path is off. */
    const XROW_MAX = 2000;
    /**
     * fp-port-c: version of the item path's bookkeeping. 2 = full builds check the document of every visible row they read
     * and catch-ups that of every queued / new row (FINISH-PORT-REVIEW-b P2-1). A table whose last full build was made by
     * an older version (state 'xiver' below this) is built once more before the item path is used.
     */
    const ITEMS_VERSION = 2;

    private $q;
    private $x;
    private $tx;
    private $vocab;
    private $vod;
    private $cache;
    private $key;
    private $lockName;
    private $okUntil = 0.0;
    private $okValue = false;
    private $probeMemo = null;
    private $probeAt = 0.0;
    private $lookups = [];
    private $handled = [];
    private $handledFor = 0;
    /** fp-port-b: the search tables (vod_ft, its queue and state row) and whether all three exist (null = not checked yet). */
    private $ft;
    private $ftq;
    private $fts;
    private $ftHave = null;
    private static $app = null;

    /**
     * @param callable $query   function (string $sql, array $bind): array of assoc rows
     * @param callable $execute function (string $sql, array $bind): int affected rows
     * @param string   $prefix  table prefix
     * @param mixed    $cache   true: think\Cache holds the lookups (the app); false: none (CLI); or an object with
     *                          get($key) and set($key, $value, $ttl) (tests: one cache shared by several instances, as
     *                          think\Cache is shared by the requests)
     * @param string   $dbKey   identifies the database (host|name) in cache and lock names
     * @param callable $tx      function (callable $fn): mixed, runs $fn in ONE transaction on the connection of $query /
     *                          $execute (commit; on any error roll back and rethrow). app() and pdo() pass one. null: the
     *                          writes are separate statements (not crash-safe; only tests use that)
     */
    public function __construct(callable $query, callable $execute, $prefix, $cache = false, $dbKey = '', ?callable $tx = null)
    {
        $prefix = (string)$prefix;
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
            throw new \InvalidArgumentException('bad table prefix');
        }
        $this->q = $query;
        $this->x = $execute;
        $this->tx = $tx;
        $this->vocab = $prefix . self::TABLE;
        $this->vod = $prefix . 'vod';
        $this->ft = $prefix . 'vod_ft';
        $this->ftq = $prefix . 'vod_ft_queue';
        $this->fts = $prefix . 'vod_ft_state';
        $this->cache = is_object($cache) && method_exists($cache, 'get') && method_exists($cache, 'set') ? $cache : (bool)$cache;
        $this->key = md5($dbKey . '|' . $prefix);
        $this->lockName = 'jv_ftvocab_' . substr($this->key, 0, 20);
    }

    /** Instance on the application's connection (think\Db), with think\Cache. */
    public static function app()
    {
        if (self::$app === null) {
            self::$app = new self(
                function ($sql, array $bind) { return Db::query($sql, $bind); },
                function ($sql, array $bind) { return Db::execute($sql, $bind); },
                (string)config('database.prefix'), true,
                self::dbKey(['hostname' => config('database.hostname'), 'hostport' => config('database.hostport'), 'database' => config('database.database')]),
                function (callable $fn) {
                    $c = Db::connect();
                    $link = $c->getPdo();
                    if ($link instanceof \PDO && $link->inTransaction()) {
                        // inside a transaction of the caller our commit would not be one: no vocabulary writes now
                        throw new \RuntimeException('ft-vocab: the connection is inside a transaction');
                    }
                    if ($c->startTrans() === false) {
                        throw new \RuntimeException('ft-vocab: no database connection');
                    }
                    try {
                        $r = $fn();
                        $c->commit();
                        return $r;
                    } catch (\Throwable $e) {
                        try { $c->rollback(); } catch (\Throwable $e2) { /* connection gone: the server rolled it back */ }
                        throw $e;
                    }
                });
        }
        return self::$app;
    }

    /**
     * Instance on a PDO connection (CLI tools, tests); $cache: see the constructor (default none). $dbKey: the CLI tools
     * pass dbKey(<the array of application/database.php>), so their builds share the app's named lock and never
     * interleave with page catch-ups or housekeeping; default (tests): a key of this PDO object.
     */
    public static function pdo(\PDO $pdo, $prefix, $cache = false, $dbKey = null)
    {
        return new self(
            function ($sql, array $bind) use ($pdo) { $st = $pdo->prepare($sql); $st->execute(array_values($bind)); return $st->columnCount() ? $st->fetchAll(\PDO::FETCH_ASSOC) : []; },
            function ($sql, array $bind) use ($pdo) { $st = $pdo->prepare($sql); $st->execute(array_values($bind)); return $st->rowCount(); },
            $prefix, $cache, $dbKey === null ? spl_object_hash($pdo) : (string)$dbKey, self::pdoTx($pdo));
    }

    /** Transaction runner on a PDO connection (the constructor's $tx; tests build instances with it). */
    public static function pdoTx(\PDO $pdo)
    {
        return function (callable $fn) use ($pdo) {
            if ($pdo->inTransaction()) {
                throw new \RuntimeException('ft-vocab: the connection is inside a transaction');
            }
            $pdo->beginTransaction();
            try {
                $r = $fn();
                $pdo->commit();
                return $r;
            } catch (\Throwable $e) {
                try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (\Throwable $e2) { /* connection gone: the server rolled it back */ }
                throw $e;
            }
        };
    }

    /**
     * Database part of the lock and cache names: hostname|hostport|database exactly as application/database.php holds
     * them (app() reads them through config(), the CLI tools from the included file: the same strings, so ONE lock).
     */
    public static function dbKey(array $cfg)
    {
        $p = [];
        foreach (['hostname', 'hostport', 'database'] as $k) {
            $v = $cfg[$k] ?? '';
            $p[] = is_scalar($v) || $v === null ? (string)$v : md5(serialize($v));
        }
        return implode('|', $p);
    }

    /**
     * CREATE TABLE IF NOT EXISTS for {prefix}vod_ft_vocab; $charset / $collation = those of the video table's filter
     * columns (install.sql: utf8mb4 / utf8mb4_general_ci), so a token LIKE compares exactly like the row LIKE.
     */
    public static function createSql($prefix, $charset = 'utf8mb4', $collation = 'utf8mb4_general_ci')
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', (string)$prefix) || !preg_match('/^[a-z0-9]+$/D', (string)$charset) || !preg_match('/^[a-z0-9_]+$/D', (string)$collation)) {
            throw new \InvalidArgumentException('bad prefix, charset or collation');
        }
        return 'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE . '` (' .
            ' `col` tinyint unsigned NOT NULL,' .
            ' `token` varchar(84) CHARACTER SET ' . $charset . ' COLLATE ' . $collation . ' NOT NULL,' .
            ' `flags` tinyint unsigned NOT NULL DEFAULT 0,' .
            ' `seen` int unsigned NOT NULL DEFAULT 0,' .
            ' PRIMARY KEY (`col`,`token`)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'FULLTEXT filter vocabulary (FilterVocab)\'';
    }

    // ------------------------------------------------------------------ tokenizer (pure)

    /**
     * Tokens of one column value => flags. Splits on every ASCII byte other than A-Z a-z 0-9 _ ; bytes >= 0x80 (all of a
     * UTF-8 multibyte character) stay inside the word, so the cut points are a subset of InnoDB's. Tokens longer than
     * MAX_CHARS characters are stored as overlapping windows flagged F_LONG (InnoDB indexes no such token).
     */
    public static function tokenize($text)
    {
        $out = [];
        if (!is_string($text) || $text === '') {
            return $out;
        }
        preg_match_all('/[A-Za-z0-9_\x80-\xFF]+/', $text, $m);
        foreach ($m[0] as $t) {
            $flags = preg_match('/[\x80-\xFF]/', $t) ? self::F_NONASCII : 0;
            if ($flags === 0 ? strlen($t) <= self::MAX_CHARS : self::chars($t) <= self::MAX_CHARS) {
                $out[$t] = ($out[$t] ?? 0) | $flags;
                continue;
            }
            $ch = preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($ch)) {
                $ch = str_split($t); // not UTF-8: byte windows (still a superset, flagged)
            }
            $n = count($ch);
            for ($i = 0; ; $i += self::WINDOW_STEP) {
                $w = implode('', array_slice($ch, $i, self::MAX_CHARS));
                $out[$w] = ($out[$w] ?? 0) | $flags | self::F_LONG;
                if ($i + self::MAX_CHARS >= $n) {
                    break;
                }
            }
        }
        return $out;
    }

    private static function chars($s)
    {
        return (int)preg_match_all('/[^\x80-\xBF]/', $s);
    }

    /** [col id => [token => flags]] of rows holding the four columns. */
    public static function rowsTokens(array $rows)
    {
        $by = [];
        foreach ($rows as $r) {
            foreach (self::COLS as $name => $id) {
                if (isset($r[$name]) && $r[$name] !== '') {
                    foreach (self::tokenize((string)$r[$name]) as $t => $f) {
                        $t = (string)$t;
                        $by[$id][$t] = ($by[$id][$t] ?? 0) | $f;
                    }
                }
            }
        }
        return $by;
    }

    // ------------------------------------------------------------------ item path (pure parts, fp-port-b)

    /** 10-digit key of a vod id in cols 252 / 254 (string order = id order, so a build batch reads its id range). */
    public static function rowKey($id)
    {
        return sprintf('%010d', max(0, (int)$id));
    }

    /** FtText (the search table's text rules) for the CLI tools that load this file alone; it ships next to this file. */
    private static function needFtText()
    {
        if (!class_exists(__NAMESPACE__ . '\\FtText', true)) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'FtText.php';
        }
    }

    /** The BMP part of FtText::RUN_CLASS (a character class body without brackets). */
    public static function runBmp()
    {
        static $class = null;
        if ($class === null) {
            self::needFtText();
            $class = (string)preg_replace('/\\\\x\{[0-9A-Fa-f]{5,6}\}(?:-\\\\x\{[0-9A-Fa-f]{5,6}\})?/', '', FtText::RUN_CLASS);
        }
        return $class;
    }

    /** Word of cols 5-7 for an item: the item, 0x1F and 12 hex digits of md5(item) (spellings equal under the collation stay apart). */
    public static function itemWord($item)
    {
        return $item . "\x1F" . substr(md5((string)$item), 0, 12);
    }

    /** The item of a word of cols 5-7 (null when the word has no valid key suffix). */
    public static function itemOf($word)
    {
        $word = (string)$word;
        $p = strrpos($word, "\x1F");
        if ($p === false || !preg_match('/^[0-9a-f]{12}$/D', substr($word, $p + 1))) {
            return null;
        }
        $item = substr($word, 0, $p);
        return substr(md5($item), 0, 12) === substr($word, $p + 1) ? $item : null;
    }

    /**
     * Item path facts of one row: ['items' => [col => [word => 0]] (RUN-bearing items of the item columns),
     * 'tokens' => sorted field tokens their search document must hold, 'x' => true when the row is an exception
     * (an item longer than ITEM_MAX_CHARS or without a valid field token, a column that is not UTF-8)].
     */
    public static function rowItems(array $r)
    {
        static $run = null;
        if ($run === null) {
            $run = '/[' . self::runBmp() . ']/u'; // loads FtText too
        }
        $out = ['items' => [], 'tokens' => [], 'x' => false];
        $toks = [];
        foreach (self::ITEM_COLS as $name => $c) {
            $v = isset($r[$name]) ? (string)$r[$name] : '';
            if ($v === '' || !preg_match('/[\x80-\xFF]/', $v)) {
                continue; // ASCII only: no item with a RUN character
            }
            if (!mb_check_encoding($v, 'UTF-8')) {
                $out['x'] = true; // FtText::splitList finds no item in it, so its document has no field token: always read
                continue;
            }
            foreach (FtText::splitList($v) as $item) {
                $item = (string)$item;
                if (!preg_match($run, $item)) {
                    continue;
                }
                $tok = FtText::fieldToken(self::ITEM_PREFIX[$name], $item);
                if (self::chars($item) > self::ITEM_MAX_CHARS || preg_match('/[\x00-\x1F\x7F]/', $item) || !preg_match('/^(tg|ac)_[a-z0-9_]{1,81}$/D', $tok)) {
                    $out['x'] = true;
                    continue;
                }
                $out['items'][$c][self::itemWord($item)] = 0;
                $toks[$tok] = true;
            }
        }
        $toks = array_map('strval', array_keys($toks));
        sort($toks, SORT_STRING);
        $out['tokens'] = $toks;
        return $out;
    }

    // ------------------------------------------------------------------ state

    public function state()
    {
        $s = [];
        foreach (call_user_func($this->q, 'SELECT `token`, `seen` FROM `' . $this->vocab . '` WHERE `col` = 0', []) as $r) {
            $s[(string)$r['token']] = (int)$r['seen'];
        }
        return $s;
    }

    /** Writes state keys in ONE statement, so a reader sees them change together (rev with its nonce, ready with built). */
    private function setState(array $kv)
    {
        if (!$kv) {
            return;
        }
        $vals = [];
        $bind = [];
        foreach ($kv as $k => $v) {
            $vals[] = '(0, ?, 0, ?)';
            array_push($bind, (string)$k, max(0, min(4294967295, (int)$v)));
        }
        call_user_func($this->x, 'INSERT INTO `' . $this->vocab . '` (`col`, `token`, `flags`, `seen`) VALUES ' . implode(',', $vals) . ' ON DUPLICATE KEY UPDATE `seen` = VALUES(`seen`)', $bind);
    }

    /** Random revision nonce, 1 .. 2^31-1 (stored with every new rev; see "Lookup cache" above). */
    private static function nonce()
    {
        try {
            return random_int(1, 2147483647);
        } catch (\Throwable $e) {
            return mt_rand(1, 2147483647);
        }
    }

    private function one($sql, array $bind = [])
    {
        $r = call_user_func($this->q, $sql, $bind);
        return $r ? reset($r[0]) : null;
    }

    private function lock($wait)
    {
        return (int)$this->one('SELECT GET_LOCK(?, ?) AS l', [$this->lockName, max(0, (int)$wait)]) === 1;
    }

    private function unlock()
    {
        try { $this->one('SELECT RELEASE_LOCK(?) AS l', [$this->lockName]); } catch (\Throwable $e) { /* connection gone: released */ }
    }

    /** Runs $fn in one transaction when this instance has a transaction runner (app(), pdo()), else directly. */
    private function atomic(callable $fn)
    {
        return $this->tx === null ? $fn() : call_user_func($this->tx, $fn);
    }

    /** True for a deadlock or a lock wait timeout (MySQL 1213 / 1205): the statement or transaction can run again. */
    public static function transient(\Throwable $e)
    {
        if ($e instanceof \PDOException && isset($e->errorInfo[1]) && in_array((int)$e->errorInfo[1], [1205, 1213], true)) {
            return true;
        }
        return (bool)preg_match('/\b(?:1205|1213)\b|deadlock found|lock wait timeout/i', $e->getMessage());
    }

    // ------------------------------------------------------------------ freshness

    /**
     * Fingerprint of the rows a catch-up would read for these floors (index ranges: PRIMARY and vod_time). The id window
     * is a primary-key range that reads the rows themselves, so it also covers the four filter columns (a raw UPDATE of
     * one of them that keeps vod_time changes it); the time window stays on the covering vod_time index (id / time only).
     */
    private function fingerprint($ifloor, $tfloor)
    {
        $a = call_user_func($this->q, 'SELECT COUNT(*) AS c, COALESCE(MAX(`vod_id`), 0) AS m, COALESCE(BIT_XOR(CRC32(CONCAT_WS(0x1F, `vod_id`, `vod_time`, `vod_tag`, `vod_actor`, `vod_class`, `vod_director`))), 0) AS x FROM `' . $this->vod . '` WHERE `vod_id` > ?', [(int)$ifloor]);
        $b = call_user_func($this->q, 'SELECT COUNT(*) AS c, COALESCE(BIT_XOR(CRC32(CONCAT(`vod_id`, \'/\', `vod_time`))), 0) AS x FROM `' . $this->vod . '` WHERE `vod_time` >= ?', [(int)$tfloor]);
        return crc32($a[0]['c'] . '/' . $a[0]['m'] . '/' . $a[0]['x'] . '/' . $b[0]['c'] . '/' . $b[0]['x']);
    }

    /**
     * ['state' => [...], 'fp' => int, 'dirty' => int]. Read from the database once per request (three small index
     * reads, about 1 ms), so a row written by any path is seen by the next request; a long-running process (CLI)
     * reuses it for PROBE_TTL s.
     */
    public function probe($fresh = false)
    {
        if (!$fresh && $this->probeMemo !== null && microtime(true) - $this->probeAt < self::PROBE_TTL) {
            return $this->probeMemo;
        }
        $s = $this->state();
        $p = ['state' => $s, 'fp' => 0, 'dirty' => 0];
        // 1.2.0: no fingerprint while a backlog is worked off ('pend'): its id window would cover every backlog row, and
        // the vocabulary is not usable then anyway (usable() runs the next catch-up slice instead)
        if (!empty($s['ready']) && empty($s['off']) && empty($s['pend'])) {
            $p['fp'] = $this->fingerprint($s['ifloor'] ?? 0, $s['tfloor'] ?? 0);
            $p['dirty'] = (int)$this->one('SELECT COUNT(*) AS n FROM `' . $this->vocab . '` WHERE `col` = 255');
        }
        $this->probeMemo = $p;
        $this->probeAt = microtime(true);
        return $p;
    }

    private function forgetProbe()
    {
        $this->probeMemo = null;
        $this->okUntil = 0.0;
    }

    /** Built by this version, not stale, not switched off, with a revision nonce (every build end writes one). */
    private static function readyState(array $s)
    {
        return !empty($s['ready']) && (int)($s['ver'] ?? 0) === self::VERSION && empty($s['stale']) && empty($s['off']) && !empty($s['nonce']);
    }

    /**
     * True when the vocabulary may be used for this request: built, current version, not stale, not switched off, and
     * every row the fingerprint covers plus every queued id is in it (a catch-up runs first when needed). False -> plain LIKE.
     */
    public function usable()
    {
        if ($this->okUntil > microtime(true)) {
            return $this->okValue;
        }
        $ok = false;
        try {
            $p = $this->probe();
            if (self::readyState($p['state'])) {
                // 1.2.0: a backlog ('pend') is never usable; this request moves it by BACKLOG_PAGE rows (false until done)
                $ok = (empty($p['state']['pend']) && $p['dirty'] === 0 && (int)$p['fp'] === (int)($p['state']['fp'] ?? -1))
                    || $this->catchUp(0, self::BACKLOG_PAGE);
            }
        } catch (\Throwable $e) {
            $ok = false;
        }
        $this->okValue = $ok;
        $this->okUntil = microtime(true) + ($ok ? self::PROBE_TTL : 1);
        return $ok;
    }

    /**
     * Adds the tokens of new / re-timed / queued rows. Waits at most $wait s for the lock (0 = never: false if busy).
     * 1.2.0: at most $chunk new ids and $chunk queued ids per call (the rest is a backlog for the next call, state 'pend');
     * true only when the vocabulary is complete and ready afterwards.
     */
    public function catchUp($wait = 0, $chunk = self::DELTA_MAX)
    {
        if (!$this->lock($wait)) {
            return false;
        }
        try {
            $s = $this->state();
            if (!empty($s['off']) || (!self::readyState($s) && empty($s['b_start']))) {
                return false;
            }
            $ok = $this->deltaLocked($s, $chunk) === true;
        } finally {
            $this->unlock();
        }
        $this->forgetProbe();
        return $ok && self::readyState($this->state());
    }

    /**
     * The catch-up itself; caller holds the lock. true: every row is in the vocabulary (state 'pend' cleared); 'pend'
     * (1.2.0): more than $chunk new ids or queued ids, the first $chunk of each were added and the rest waits (ifloor =
     * the last new id read, state 'pend' set, in the same transaction); false (and stale) when more than DELTA_MAX rows
     * of the time window would be needed.
     */
    private function deltaLocked(array $s, $chunk = self::DELTA_MAX)
    {
        $chunk = max(1, min(self::DELTA_MAX, (int)$chunk));
        // $now is taken before anything is read: a queue entry with seen < $now was saved (row first, then queue()) before
        // the rows below are read, so it is consumed; a save from this second on keeps its entry for the next catch-up
        $now = (int)$this->one('SELECT UNIX_TIMESTAMP() AS t');
        $maxId = (int)$this->one('SELECT COALESCE(MAX(`vod_id`), 0) AS m FROM `' . $this->vod . '`');
        $if0 = (int)($s['ifloor'] ?? 0);
        $tf0 = (int)($s['tfloor'] ?? 0);
        // the id floor follows the newest id; it goes down when the newest rows were deleted (a new id, e.g. after an
        // AUTO_INCREMENT reset, can then be at or below the old floor); those up to ID_WINDOW extra rows are read below
        $if1 = $maxId > $if0 ? max($if0, $maxId - self::ID_WINDOW) : max(0, $maxId - self::ID_WINDOW);
        $tf1 = max($tf0, $now - self::TIME_MARGIN);
        $fp1 = $this->fingerprint($if1, $tf1); // before reading: every row it counts is read below (id > min(if0, if1), time >= tf0)
        // fp-port-b: vod_status / vod_recycle_time for the item path (only visible rows need a search document)
        $cols = '`vod_id`, `vod_status`, `vod_recycle_time`, `' . implode('`, `', array_keys(self::COLS)) . '`';
        $lim = self::DELTA_MAX + 1;
        $b = call_user_func($this->q, 'SELECT ' . $cols . ' FROM `' . $this->vod . '` WHERE `vod_time` >= ? LIMIT ' . $lim, [$tf0]);
        if (count($b) > self::DELTA_MAX) {
            $this->setState(['stale' => $now]);
            return false;
        }
        // 1.2.0 backlog: queued ids and new ids in key order, at most $chunk of each in this call (PRIMARY ranges); what
        // is left stays queued / above the new ifloor for the next call, with the state key 'pend' (not usable meanwhile)
        $lo = min($if0, $if1);
        $dirty = call_user_func($this->q, 'SELECT `token` FROM `' . $this->vocab . '` WHERE `col` = 255 ORDER BY `token` LIMIT ' . ($chunk + 1), []);
        $a = call_user_func($this->q, 'SELECT ' . $cols . ' FROM `' . $this->vod . '` WHERE `vod_id` > ? ORDER BY `vod_id` LIMIT ' . ($chunk + 1), [$lo]);
        $pend = count($dirty) > $chunk || count($a) > $chunk;
        if (count($dirty) > $chunk) {
            $dirty = array_slice($dirty, 0, $chunk);
        }
        $cut = null;
        if (count($a) > $chunk) {
            $a = array_slice($a, 0, $chunk);
            $cut = (int)$a[$chunk - 1]['vod_id']; // every id in (lo, cut] is read now; the next call reads id > cut
        }
        $rows = array_merge($a, $b);
        $ids = [];
        foreach ($dirty as $d) {
            if (preg_match('/^[0-9]{1,10}$/D', (string)$d['token'])) {
                $ids[] = (int)$d['token'];
            }
        }
        foreach (array_chunk($ids, 1000) as $part) {
            $rows = array_merge($rows, call_user_func($this->q, 'SELECT ' . $cols . ' FROM `' . $this->vod . '` WHERE `vod_id` IN (' . implode(',', $part) . ')', []));
        }
        $byCol = self::rowsTokens($rows);
        // fp-port-b item path: the rows' RUN-bearing items (cols 5-7), their field-token hashes (a document missing a token
        // is queued for vod_ft in the same transaction) and the exception rows. fp-port-c (FINISH-PORT-REVIEW-b P2-1): the
        // document of every row read because it was queued (a save, import, status change, restore or hide hook: touched())
        // is checked whatever its hash says: a row hidden and shown again keeps an unchanged hash while the drain deleted its
        // document in between. (New rows have no hash and are checked anyway; the id window's re-read of the newest rows and
        // the time window keep the hash rule, so a catch-up reads no document it has no reason to doubt.)
        $force = [];
        foreach ($ids as $fid) {
            $force[(int)$fid] = true;
        }
        $plan = $this->itemPlan($rows, null, (int)($s['dpend'] ?? 0), $force);
        foreach ($plan['items'] as $c => $words) {
            foreach ($words as $w => $f) {
                $byCol[$c][(string)$w] = $f;
            }
        }
        $queued = array_map('strval', array_column($dirty, 'token'));
        $set = ['ifloor' => $if1, 'tfloor' => $tf1, 'fp' => $fp1, 'caught' => $now];
        if ($pend) {
            // backlog left: the floor stays below the ids not read yet; the fingerprint is not used while 'pend' is set
            $set = ['ifloor' => $cut !== null ? $cut : $if1, 'tfloor' => $tf1, 'fp' => $cut !== null ? 0 : $fp1, 'caught' => $now, 'pend' => $now];
        }
        $hadPend = !empty($s['pend']);
        // one transaction (FT-VOCAB-REVIEW-d P3-2): the words, the queue entries they consume and the new state (with a
        // new (rev, nonce) when words or flags were added) become visible together. A process that dies in between leaves
        // nothing: the words are rolled back with the rest, the entries stay queued, and the next catch-up adds the words
        // again and writes the new pair (before, the words stayed without a new pair, so word lists cached for the old
        // word set were served again)
        // fp-port-b: rows read inside a bulk window wait for their documents ('dpend'); with the window closed a slice is checked
        $dp = $this->dpendCheck($s);
        $this->atomic(function () use ($byCol, $queued, $set, $now, $s, $pend, $hadPend, $plan, $dp) {
            $changed = $this->upsert($byCol, (int)($s['b_start'] ?? 0));
            $this->itemWrite($plan, (int)($s['dpend'] ?? 0));
            if ($dp !== null) {
                if ($dp['plan'] !== null) {
                    $this->itemWrite($dp['plan']);
                }
                if ((int)$dp['dpend'] > 0) {
                    $this->setState(['dpend' => (int)$dp['dpend']]);
                } else {
                    call_user_func($this->x, "DELETE FROM `" . $this->vocab . "` WHERE `col` = 0 AND `token` = 'dpend'", []);
                }
            }
            foreach (array_chunk($queued, 500) as $chunk) {
                call_user_func($this->x, 'DELETE FROM `' . $this->vocab . '` WHERE `col` = 255 AND `seen` < ? AND `token` IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                    array_merge([$now], $chunk));
            }
            if ($changed > 0) {
                // a new word set: new rev with a new nonce (one statement), so no list cached for another word set is reused
                $set['rev'] = (int)($s['rev'] ?? 0) + 1;
                $set['nonce'] = self::nonce();
            }
            $this->setState($set);
            if (!$pend && $hadPend) {
                // the backlog is worked off: usable again with this catch-up's floors and fingerprint (same transaction)
                call_user_func($this->x, "DELETE FROM `" . $this->vocab . "` WHERE `col` = 0 AND `token` = 'pend'", []);
            }
        });
        return $pend ? 'pend' : true;
    }

    /**
     * Inserts the tokens (new ones with seen = now), ORs their flags into existing rows (a collation-equal spelling with
     * other flags) and, while a full build that started at $bStart runs, confirms existing tokens (seen = now).
     * Returns the number of rows whose token set or flags changed.
     */
    private function upsert(array $byCol, $bStart)
    {
        $changed = 0;
        foreach ($byCol as $c => $toks) {
            foreach (array_chunk($toks, 500, true) as $chunk) {
                $vals = [];
                $bind = [];
                $flagged = [];
                foreach ($chunk as $t => $f) {
                    $vals[] = '(?, ?, ?, UNIX_TIMESTAMP())';
                    array_push($bind, (int)$c, (string)$t, (int)$f);
                    if ($f) {
                        $flagged[(int)$f][] = (string)$t;
                    }
                }
                $changed += (int)call_user_func($this->x, 'INSERT IGNORE INTO `' . $this->vocab . '` (`col`, `token`, `flags`, `seen`) VALUES ' . implode(',', $vals), $bind);
                foreach ($flagged as $f => $list) {
                    $changed += (int)call_user_func($this->x, 'UPDATE `' . $this->vocab . '` SET `flags` = `flags` | ? WHERE `col` = ? AND (`flags` & ?) <> ? AND `token` IN (' . implode(',', array_fill(0, count($list), '?')) . ')',
                        array_merge([$f, (int)$c, $f, $f], $list));
                }
                if ($bStart > 0) {
                    $list = array_map('strval', array_keys($chunk));
                    call_user_func($this->x, 'UPDATE `' . $this->vocab . '` SET `seen` = UNIX_TIMESTAMP() WHERE `col` = ? AND `seen` < ? AND `token` IN (' . implode(',', array_fill(0, count($list), '?')) . ')',
                        array_merge([(int)$c, (int)$bStart], $list));
                }
            }
        }
        return $changed;
    }

    // ------------------------------------------------------------------ item path bookkeeping (fp-port-b)

    /**
     * The search table as the item path needs it: ['bulk' => start of an open bulk window (0 = none)] when vod_ft, its
     * queue and its state row exist and the table is ready, in 'ft' mode and has no rebuild due for a bulk window ('bulk'); null
     * otherwise (then no document is checked and the item path is off). The rule is conservative, not what exactness rests on:
     * FtIndex::markRebuild() keeps the first reason, so a window's 'bulk' can sit behind an earlier 'out_of_order' (its documents
     * never appended); rows read inside the window carry 'dpend', rows read after it are checked against their documents.
     */
    private function ftDocs()
    {
        if ($this->ftHave === null) {
            try {
                $n = (int)$this->one('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?)', [$this->ft, $this->ftq, $this->fts]);
                $this->ftHave = $n === 3;
            } catch (\Throwable $e) {
                $this->ftHave = false;
            }
        }
        if (!$this->ftHave) {
            return null;
        }
        $r = call_user_func($this->q, 'SELECT `ready`, `mode`, `bulk_job`, `rebuild_due` FROM `' . $this->fts . '` WHERE `id` = 1', []);
        if (!$r || (int)$r[0]['ready'] !== 1 || (string)$r[0]['mode'] !== 'ft' || (string)$r[0]['rebuild_due'] === 'bulk') {
            return null;
        }
        return ['bulk' => max(0, (int)$r[0]['bulk_job'])];
    }

    /**
     * Item path bookkeeping of the rows a catch-up or a build batch read (lock held; reads only). Returns the writes for
     * the caller's transaction: 'items' [col => [word => 0]], 'hash' [key => crc] / 'unhash' [keys] (col 252), 'xrow'
     * [key => flags] / 'unx' [keys] (col 254), 'touch' [vod ids] for vod_ft_queue (a visible row whose search document
     * lacks one of its field tokens: drain() writes the document again from the row). $range [lo, hi] (a build batch read
     * every row with lo < vod_id <= hi): entries of ids in that range that were not read (deleted rows) are dropped too.
     * An entry is only ever written for a document found holding the tokens or for a row queued in the same transaction;
     * dropping one is always safe (the next read checks the document again). $dpendNow: rows from this id on wait for a bulk
     * window's append (state 'dpend'): they are neither checked nor queued here (dpendCheck() does it once they can be).
     * fp-port-c: $force = true (a full build) or [vod id => true] (a catch-up's queued rows, col 255): the document of these
     * rows is checked even when their hash is unchanged (a hidden row's document is deleted by the drain while its hash stays).
     */
    private function itemPlan(array $rows, $range = null, $dpendNow = 0, $force = [])
    {
        $out = ['items' => [], 'hash' => [], 'unhash' => [], 'xrow' => [], 'unx' => [], 'touch' => [], 'dpend' => 0];
        $ft = $this->ftDocs();
        $facts = [];
        foreach ($rows as $r) {
            $id = (int)$r['vod_id'];
            $f = self::rowItems($r);
            foreach ($f['items'] as $c => $words) {
                foreach ($words as $w => $_) {
                    $out['items'][$c][$w] = 0;
                }
            }
            $vis = (int)($r['vod_status'] ?? 1) === 1 && (int)($r['vod_recycle_time'] ?? 0) === 0;
            // a document is expected (and checked) for a visible row with tokens below an open bulk window
            $f['check'] = $f['tokens'] && !$f['x'] && $vis && $ft !== null && ($ft['bulk'] <= 0 || $id < $ft['bulk']) && ((int)$dpendNow <= 0 || $id < (int)$dpendNow);
            if ($f['tokens'] && !$f['x'] && $vis && $ft !== null && $ft['bulk'] > 0 && $id >= $ft['bulk'] && ($out['dpend'] === 0 || $id < $out['dpend'])) {
                // inside an open bulk window: its document comes with the window's append (bulkEnd, a deferred append), which may
                // run long after the window closed; until a check finds it, the item path reads every id from here on ('dpend')
                $out['dpend'] = $id;
            }
            $f['id'] = $id;
            $facts[self::rowKey($id)] = $f;
        }
        $have = [self::COL_HASH => [], self::COL_XROW => []];
        if ($range !== null) {
            foreach (call_user_func($this->q, 'SELECT `col`, `token`, `flags`, `seen` FROM `' . $this->vocab . '` WHERE `col` IN (' . self::COL_HASH . ', ' . self::COL_XROW . ') AND `token` > ? AND `token` <= ?',
                [self::rowKey($range[0]), self::rowKey($range[1])]) as $e) {
                $have[(int)$e['col']][(string)$e['token']] = [(int)$e['seen'], (int)$e['flags']];
            }
        } else {
            foreach (array_chunk(array_map('strval', array_keys($facts)), 1000) as $part) {
                foreach (call_user_func($this->q, 'SELECT `col`, `token`, `flags`, `seen` FROM `' . $this->vocab . '` WHERE `col` IN (' . self::COL_HASH . ', ' . self::COL_XROW . ') AND `token` IN ('
                    . implode(',', array_fill(0, count($part), '?')) . ')', $part) as $e) {
                    $have[(int)$e['col']][(string)$e['token']] = [(int)$e['seen'], (int)$e['flags']];
                }
            }
        }
        $verify = [];
        $xflags = [];
        foreach ($facts as $k => $f) {
            $k = (string)$k;
            $x = $f['x'] ? self::X_ITEM : 0;
            $oldX = isset($have[self::COL_XROW][$k]) ? $have[self::COL_XROW][$k][1] : 0;
            if ($f['check']) {
                $crc = crc32(implode(' ', $f['tokens']));
                $h = $have[self::COL_HASH][$k] ?? null;
                if ($h !== null && (int)$h[0] === (int)$crc && $force !== true && !isset($force[(int)$f['id']])) {
                    $x |= $oldX & self::X_DOC; // unchanged since its document was checked: a cut document stays an exception
                } else {
                    $verify[$k] = $crc;
                }
            } elseif ($f['tokens'] && !$f['x']) {
                $x |= $oldX & self::X_DOC; // not checked now (search table not usable, bulk window, hidden row): keep the mark
            }
            $xflags[$k] = $x;
        }
        if ($verify) {
            $docs = [];
            foreach (array_chunk(array_map('strval', array_keys($verify)), 200) as $part) {
                $ids = [];
                foreach ($part as $k) {
                    $ids[] = (int)$facts[$k]['id'];
                }
                foreach (call_user_func($this->q, 'SELECT `vod_id`, `ft_text` FROM `' . $this->ft . '` WHERE `vod_id` IN (' . implode(',', $ids) . ')', []) as $d) {
                    $docs[(int)$d['vod_id']] = (string)$d['ft_text'];
                }
            }
            foreach ($verify as $k => $crc) {
                $k = (string)$k;
                $id = (int)$facts[$k]['id'];
                $doc = $docs[$id] ?? null;
                $ok = $doc !== null;
                if ($ok) {
                    foreach ($facts[$k]['tokens'] as $t) {
                        if (strpos($doc, ' ' . $t . ' ') === false) {
                            $ok = false;
                            break;
                        }
                    }
                }
                if (!$ok) {
                    $out['touch'][] = $id;
                    if ($doc !== null && strlen($doc) >= FtText::DOC_MAX - FtText::TOKEN_MAX - 2) {
                        $xflags[$k] |= self::X_DOC; // cut at DOC_MAX before its field tokens: a new document would be cut too
                    }
                } else {
                    $xflags[$k] &= ~self::X_DOC;
                }
                $out['hash'][$k] = (int)$crc;
            }
        }
        foreach ($facts as $k => $f) {
            $k = (string)$k;
            if (!$f['check'] && isset($have[self::COL_HASH][$k])) {
                $out['unhash'][] = $k;
            }
            $x = $xflags[$k];
            if ($x !== 0 && (!isset($have[self::COL_XROW][$k]) || $have[self::COL_XROW][$k][1] !== $x)) {
                $out['xrow'][$k] = $x;
            } elseif ($x === 0 && isset($have[self::COL_XROW][$k])) {
                $out['unx'][] = $k;
            }
        }
        if ($range !== null) {
            foreach ([self::COL_HASH => 'unhash', self::COL_XROW => 'unx'] as $c => $list) {
                foreach ($have[$c] as $k => $_) {
                    if (!isset($facts[(string)$k])) {
                        $out[$list][] = (string)$k; // no such row any more
                    }
                }
            }
        }
        return $out;
    }

    /** Rows one dpendCheck() reads. */
    const DPEND_CHUNK = 2000;

    /**
     * fp-port-b: the check behind 'dpend' (lock held; reads only). While no bulk window is open and the search table is usable,
     * the rows from 'dpend' on (DPEND_CHUNK per catch-up, by vod_id) are checked as itemPlan() checks a row, up to the first
     * visible row with a RUN item that has no search document yet (the window's append, bulkEnd or a deferred one, has not
     * reached it): 'dpend' moves to that row, or past the slice, or is cleared at the end of the table. Returns null (nothing to
     * do now) or ['plan' => itemPlan() of the rows checked (null: none), 'dpend' => the new value (0 = clear)].
     */
    private function dpendCheck(array $s)
    {
        $d = (int)($s['dpend'] ?? 0);
        if ($d <= 0) {
            return null;
        }
        $ft = $this->ftDocs();
        if ($ft === null || $ft['bulk'] > 0) {
            return null; // a window is open (or the search table is not usable): the mark stays
        }
        $cols = '`vod_id`, `vod_status`, `vod_recycle_time`, `' . implode('`, `', array_keys(self::COLS)) . '`';
        $rows = call_user_func($this->q, 'SELECT ' . $cols . ' FROM `' . $this->vod . '` WHERE `vod_id` >= ? ORDER BY `vod_id` LIMIT ' . self::DPEND_CHUNK, [$d]);
        if (!$rows) {
            return ['plan' => null, 'dpend' => 0];
        }
        $need = [];
        foreach ($rows as $r) {
            $f = self::rowItems($r);
            if ($f['tokens'] && !$f['x'] && (int)($r['vod_status'] ?? 1) === 1 && (int)($r['vod_recycle_time'] ?? 0) === 0) {
                $need[] = (int)$r['vod_id'];
            }
        }
        $have = [];
        foreach (array_chunk($need, 500) as $ids) {
            foreach (call_user_func($this->q, 'SELECT `vod_id` FROM `' . $this->ft . '` WHERE `vod_id` IN (' . implode(',', $ids) . ')', []) as $x) {
                $have[(int)$x['vod_id']] = true;
            }
        }
        $stop = 0;
        foreach ($need as $id) {
            if (!isset($have[$id])) {
                $stop = $id;
                break;
            }
        }
        $checked = [];
        foreach ($rows as $r) {
            if ($stop > 0 && (int)$r['vod_id'] >= $stop) {
                break;
            }
            $checked[] = $r;
        }
        $next = $stop > 0 ? $stop : (count($rows) < self::DPEND_CHUNK ? 0 : (int)end($rows)['vod_id'] + 1);
        return ['plan' => $checked ? $this->itemPlan($checked) : null, 'dpend' => $next];
    }

    /** Writes an itemPlan() (inside the caller's transaction); $dpendNow: the current state key 'dpend' (0 = none). */
    private function itemWrite(array $p, $dpendNow = 0)
    {
        if (!empty($p['dpend']) && ((int)$dpendNow === 0 || (int)$p['dpend'] < (int)$dpendNow)) {
            $this->setState(['dpend' => (int)$p['dpend']]);
        }
        foreach (array_chunk($p['hash'], 500, true) as $chunk) {
            $vals = [];
            $bind = [];
            foreach ($chunk as $k => $crc) {
                $vals[] = '(' . self::COL_HASH . ', ?, 0, ?)';
                array_push($bind, (string)$k, max(0, min(4294967295, (int)$crc)));
            }
            call_user_func($this->x, 'INSERT INTO `' . $this->vocab . '` (`col`, `token`, `flags`, `seen`) VALUES ' . implode(',', $vals) . ' ON DUPLICATE KEY UPDATE `seen` = VALUES(`seen`)', $bind);
        }
        foreach ([self::COL_HASH => $p['unhash'], self::COL_XROW => $p['unx']] as $c => $keys) {
            foreach (array_chunk(array_values(array_unique(array_map('strval', $keys))), 500) as $chunk) {
                call_user_func($this->x, 'DELETE FROM `' . $this->vocab . '` WHERE `col` = ' . (int)$c . ' AND `token` IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
            }
        }
        foreach (array_chunk($p['xrow'], 500, true) as $chunk) {
            $vals = [];
            $bind = [];
            foreach ($chunk as $k => $flags) {
                $vals[] = '(' . self::COL_XROW . ', ?, ?, UNIX_TIMESTAMP())';
                array_push($bind, (string)$k, (int)$flags);
            }
            call_user_func($this->x, 'INSERT INTO `' . $this->vocab . '` (`col`, `token`, `flags`, `seen`) VALUES ' . implode(',', $vals) . ' ON DUPLICATE KEY UPDATE `flags` = VALUES(`flags`), `seen` = VALUES(`seen`)', $bind);
        }
        $touch = array_values(array_unique(array_map('intval', $p['touch'])));
        sort($touch, SORT_NUMERIC); // fp-port-c: ascending, the order every queue writer and the drain take the rows in
        foreach (array_chunk($touch, 500) as $chunk) {
            call_user_func($this->x, 'INSERT IGNORE INTO `' . $this->ftq . '` (`vod_id`, `queued_at`) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, UNIX_TIMESTAMP())')), $chunk);
        }
    }

    // ------------------------------------------------------------------ lookups

    /**
     * Tokens of column $col that contain ('sub'), end with ('suf') or start with ('pre') $word (lower-case ASCII letters /
     * digits), lower-cased: [] when there is none; null when a group cannot be used (vocabulary not usable, more than
     * $cap tokens, a flagged token, or a token the index may not hold: shorter than $min, longer than $max, a stopword).
     */
    public function lookup($col, $kind, $word, $min, $max, array $stop, $cap = self::GROUP_CAP)
    {
        if (!isset(self::COLS[$col]) || !in_array($kind, ['sub', 'suf', 'pre'], true) || !is_string($word)
            || !preg_match('/^[a-z0-9]{1,' . self::MAX_WORD . '}$/D', $word) || !$this->usable()) {
            return null;
        }
        $st = $this->probe()['state'];
        // (rev, nonce) names one word set: rev alone restarts at 1 when the table is dropped / truncated and built again
        $rev = (int)($st['rev'] ?? 0) . '.' . (int)($st['nonce'] ?? 0);
        $key = self::CACHE_KEY . 'look_' . $this->key . '_' . md5($col . '|' . $kind . '|' . $word . '|' . $rev . '|' . (int)$cap);
        if (array_key_exists($key, $this->lookups)) {
            $rows = $this->lookups[$key];
        } else {
            $rows = $this->cacheGet($key);
            if (!is_array($rows)) {
                $pat = $kind === 'sub' ? '%' . $word . '%' : ($kind === 'suf' ? '%' . $word : $word . '%');
                $rows = [];
                foreach (call_user_func($this->q, 'SELECT `token`, `flags` FROM `' . $this->vocab . '` WHERE `col` = ? AND `token` LIKE ? LIMIT ' . ((int)$cap + 1), [self::COLS[$col], $pat]) as $r) {
                    $rows[] = [(string)$r['token'], (int)$r['flags']];
                }
                $this->cacheSet($key, $rows, self::LOOKUP_TTL);
            }
            $this->lookups[$key] = $rows;
        }
        if (count($rows) > $cap) {
            return null;
        }
        $out = [];
        foreach ($rows as $r) {
            $t = strtolower($r[0]);
            if ($r[1] !== 0 || !preg_match('/^[a-z0-9_]+$/D', $t) || strlen($t) < $min || strlen($t) > $max || isset($stop[$t])) {
                return null;
            }
            $out[$t] = true;
        }
        return array_map('strval', array_keys($out)); // "2040" must stay a string
    }

    /**
     * True when column $col holds a word that starts with $word (lower-case ASCII letters / digits, up to MAX_CHARS) and
     * is longer than $max characters: a word InnoDB's FULLTEXT index does not hold (it skips words longer than
     * innodb_ft_max_token_size). Words longer than MAX_CHARS are stored as windows flagged F_LONG, the first one starting
     * where the word starts, so `token LIKE 'word%'` meets it. FilterMatch then may not use the v1 term +word*: the prefix
     * search cannot find that row (FT-VOCAB-REVIEW-d P2-1). false: no such word; null: unknown (vocabulary not usable).
     * Cached like lookup() under (rev, nonce): every word or flag added to the vocabulary comes with a new pair.
     */
    public function longPrefix($col, $word, $max)
    {
        if (!isset(self::COLS[$col]) || !is_string($word) || !preg_match('/^[a-z0-9]{1,' . self::MAX_CHARS . '}$/D', $word) || !$this->usable()) {
            return null;
        }
        $st = $this->probe()['state'];
        $rev = (int)($st['rev'] ?? 0) . '.' . (int)($st['nonce'] ?? 0);
        $key = self::CACHE_KEY . 'long_' . $this->key . '_' . md5($col . '|' . $word . '|' . (int)$max . '|' . $rev);
        if (array_key_exists($key, $this->lookups)) {
            $hit = $this->lookups[$key];
        } else {
            $hit = $this->cacheGet($key);
            if (!is_array($hit) || !isset($hit[0]) || ($hit[0] !== 0 && $hit[0] !== 1)) {
                $r = call_user_func($this->q, 'SELECT 1 AS x FROM `' . $this->vocab . '` WHERE `col` = ? AND `token` LIKE ? AND ((`flags` & ' . self::F_LONG . ') <> 0 OR CHAR_LENGTH(`token`) > ?) LIMIT 1',
                    [self::COLS[$col], $word . '%', (int)$max]);
                $hit = [$r ? 1 : 0];
                $this->cacheSet($key, $hit, self::LOOKUP_TTL);
            }
            $this->lookups[$key] = $hit;
        }
        return $hit[0] === 1;
    }

    /**
     * fp-port-b item path: null when it cannot be used now (vocabulary not usable: not built, stale, off, behind; no full
     * build by this version yet ('xitems'); more than XROW_MAX exception rows); else ['x' => the exception rows' vod ids,
     * 'dpend' => the first id that may still wait for its search document after a bulk window (0 = none)].
     */
    public function itemState()
    {
        if (!$this->usable()) {
            return null;
        }
        $s = $this->probe()['state'];
        if (!self::itemsComplete($s)) {
            return null; // fp-port-c: the last full build was not one by this version with its document checks
        }
        $x = [];
        foreach (call_user_func($this->q, 'SELECT `token` FROM `' . $this->vocab . '` WHERE `col` = ' . self::COL_XROW . ' LIMIT ' . (self::XROW_MAX + 1), []) as $r) {
            $x[] = (int)$r['token'];
        }
        return count($x) > self::XROW_MAX ? null : ['x' => $x, 'dpend' => max(0, (int)($s['dpend'] ?? 0))];
    }

    /**
     * fp-port-b item path: the stored items of item column $col that LIKE-match '%' . $value . '%' (the row LIKE's own
     * pattern, as mac_like_arr() builds it, under the column's collation: the token column has it): [] when none, null
     * when more than $cap (or no item column). The caller checks itemState() first. Cached like lookup() under (rev, nonce):
     * every catch-up or build batch that adds an item writes a new pair.
     */
    public function itemsLike($col, $value, $cap = self::ITEM_CAP)
    {
        if (!isset(self::ITEM_COLS[$col]) || !is_string($value) || $value === '' || strlen($value) > 1024) {
            return null;
        }
        $st = $this->probe()['state'];
        $rev = (int)($st['rev'] ?? 0) . '.' . (int)($st['nonce'] ?? 0);
        $key = self::CACHE_KEY . 'item_' . $this->key . '_' . md5($col . '|' . $value . '|' . $rev . '|' . (int)$cap);
        if (array_key_exists($key, $this->lookups)) {
            $rows = $this->lookups[$key];
        } else {
            $rows = $this->cacheGet($key);
            if (!is_array($rows)) {
                $rows = [];
                foreach (call_user_func($this->q, 'SELECT `token` FROM `' . $this->vocab . '` WHERE `col` = ? AND `token` LIKE ? LIMIT ' . ((int)$cap + 1),
                    [self::ITEM_COLS[$col], '%' . $value . '%']) as $r) {
                    $rows[] = (string)$r['token'];
                }
                $this->cacheSet($key, $rows, self::LOOKUP_TTL);
            }
            $this->lookups[$key] = $rows;
        }
        if (count($rows) > $cap) {
            return null;
        }
        $out = [];
        foreach ($rows as $w) {
            $item = self::itemOf($w);
            if ($item === null) {
                return null; // a word without its key: never guess
            }
            $out[$item] = true;
        }
        return array_map('strval', array_keys($out));
    }

    private function cacheGet($key)
    {
        try {
            if ($this->cache === true) {
                return Cache::get($key);
            }
            if (is_object($this->cache)) {
                return $this->cache->get($key);
            }
        } catch (\Throwable $e) {
            // look up again
        }
        return null;
    }

    private function cacheSet($key, $value, $ttl)
    {
        try {
            if ($this->cache === true) {
                Cache::set($key, $value, $ttl);
            } elseif (is_object($this->cache)) {
                $this->cache->set($key, $value, $ttl);
            }
        } catch (\Throwable $e) {
            // look up again next time
        }
    }

    // ------------------------------------------------------------------ build

    public function needsBuild(array $s, $now = null)
    {
        if (!empty($s['off'])) {
            return false; // switched off (--off): no builds until --on, so housekeeping does not rebuild in a loop
        }
        $now = $now === null ? time() : (int)$now;
        // fp-port-b: a vocabulary without the item path's words (a live d/f table, one built by the first 1.2.0 port) is
        // built once more; it stays usable for the other filters meanwhile. fp-port-c: the same for a table whose last full
        // build had an older item-path version ('xiver', ITEMS_VERSION: document checks of every visible row) or was finished
        // by older code after this version's build ('xibuilt' behind 'built')
        return !self::readyState($s) || (int)($s['built'] ?? 0) < $now - self::REBUILD_AGE || !self::itemsComplete($s);
    }

    /**
     * fp-port-c: true when the item path's bookkeeping is complete: the LAST full build (state 'built') was started by this
     * version with its document checks ('xiver' >= ITEMS_VERSION) and wrote 'xitems' and 'xibuilt' at its end ('xibuilt' =
     * 'built', written in the same statement). A full build that older code finishes afterwards (a housekeeping process
     * still running the code of before the update) moves 'built' but not 'xibuilt', so the item path waits (plain LIKE)
     * until this version has built again. Pure: also used by tools/upgrade.php and tools/vod-ft-vocab.php.
     */
    public static function itemsComplete(array $s)
    {
        $built = (int)($s['built'] ?? 0);
        return !empty($s['xitems']) && (int)($s['xiver'] ?? 1) >= self::ITEMS_VERSION && $built > 0 && (int)($s['xibuilt'] ?? -1) === $built;
    }

    /**
     * Off switch (tools/vod-ft-vocab.php --off / --on). Off: every filter uses the plain LIKE (v1 behaviour), no catch-up,
     * no build step (a running build pauses); builds never clear it. On: the next request catches up what changed meanwhile
     * (new / re-timed rows by the floors, queued ids; more than DELTA_MAX -> stale -> housekeeping rebuilds).
     */
    public function setOff($off)
    {
        if ($off) {
            $this->setState(['off' => max(1, (int)$this->one('SELECT UNIX_TIMESTAMP() AS t'))]);
        } else {
            call_user_func($this->x, 'DELETE FROM `' . $this->vocab . '` WHERE `col` = 0 AND `token` = ?', ['off']);
        }
        $this->forgetProbe();
    }

    /** Asks for a new full build at the next step (no effect while one runs). */
    public function requestRebuild()
    {
        $this->setState(['built' => 0]);
        $this->forgetProbe();
    }

    /**
     * One resumable build slice: starts a full build when needed (or $force), then reads BATCH rows per step by vod_id
     * until $budget seconds are used, sleeping $pauseMs between steps. The named lock is held per step only, so page
     * catch-ups interleave. Returns ['state' => idle|locked|building|done, ...].
     */
    public function buildStep($budget = 5.0, $pauseMs = 0, $lockWait = 2, $force = false, $log = null, $batch = self::BATCH)
    {
        $deadline = microtime(true) + max(0.001, (float)$budget);
        if (!$this->lock($lockWait)) {
            return ['state' => 'locked'];
        }
        try {
            $s = $this->state();
            if (!empty($s['off'])) {
                return ['state' => 'off']; // switched off: nothing is built, not even with $force (--on first)
            }
            if (empty($s['b_start'])) {
                if (!$force && !$this->needsBuild($s)) {
                    return ['state' => 'idle', 'built' => (int)($s['built'] ?? 0)];
                }
                $now = (int)$this->one('SELECT UNIX_TIMESTAMP() AS t');
                if ((int)($s['stale'] ?? 0) >= $now) {
                    return ['state' => 'wait']; // marked stale this second: a build starting now could not clear it; next second
                }
                $max = (int)$this->one('SELECT COALESCE(MAX(`vod_id`), 0) AS m FROM `' . $this->vod . '`');
                // fp-port-b: 'b_items' = this build keeps the item path's words, hashes and exceptions from its first row on
                // (finishLocked() writes 'xitems' only for such a build); fp-port-c: its value is the item-path version the
                // build runs with (ITEMS_VERSION: every visible row's document checked), written back as 'xiver' at the end
                $set = ['b_start' => $now, 'b_cursor' => 0, 'b_max' => $max, 'b_items' => self::ITEMS_VERSION,
                    'b_wm_time' => (int)$this->one('SELECT COALESCE(MAX(`vod_time`), 0) AS m FROM `' . $this->vod . '`')];
                if (empty($s['ready']) || (int)($s['ver'] ?? 0) !== self::VERSION) {
                    $set += ['ready' => 0, 'ver' => self::VERSION, 'ifloor' => max(0, $max - self::ID_WINDOW), 'tfloor' => $now - self::TIME_MARGIN, 'fp' => 0];
                } elseif (!empty($s['stale'])) {
                    // stale (pages do not use it): this build reads every row, so the catch-up at its end only needs what
                    // changes from now on; with the old floors it could exceed DELTA_MAX again and end not ready
                    $set += ['ifloor' => max(0, $max - self::ID_WINDOW), 'tfloor' => $now - self::TIME_MARGIN, 'fp' => 0];
                }
                $this->setState($set);
                $this->handled = [];
                if ($log) { $log(sprintf('build started: %d videos up to id %d', (int)$this->one('SELECT COUNT(*) AS n FROM `' . $this->vod . '`'), $max)); }
            }
        } finally {
            $this->unlock();
        }
        $cols = '`vod_id`, `vod_status`, `vod_recycle_time`, `' . implode('`, `', array_keys(self::COLS)) . '`';
        $steps = 0;
        while (true) {
            if (!$this->lock($lockWait)) {
                return ['state' => 'locked', 'steps' => $steps];
            }
            try {
                $s = $this->state();
                if (!empty($s['off'])) {
                    return ['state' => 'off', 'steps' => $steps]; // switched off meanwhile: the build pauses here
                }
                if (empty($s['b_start'])) {
                    return ['state' => 'done', 'steps' => $steps];
                }
                if ($this->handledFor !== (int)$s['b_start']) { // another build than the one this process remembers
                    $this->handled = [];
                    $this->handledFor = (int)$s['b_start'];
                }
                $cursor = (int)($s['b_cursor'] ?? 0);
                $rows = call_user_func($this->q, 'SELECT ' . $cols . ' FROM `' . $this->vod . '` WHERE `vod_id` > ? AND `vod_id` <= ? ORDER BY `vod_id` LIMIT ' . max(50, (int)$batch),
                    [$cursor, (int)$s['b_max']]);
                if (!$rows) {
                    try {
                        $r = $this->finishLocked($s);
                    } catch (\Throwable $e) {
                        $this->handled = [];
                        if (self::transient($e)) {
                            return ['state' => 'locked', 'steps' => $steps]; // a lock conflict: the next step finishes it
                        }
                        throw $e;
                    }
                    if ($log) { $log('build finished: ' . json_encode($r)); }
                    return ['state' => 'done', 'steps' => $steps] + $r;
                }
                $by = self::rowsTokens($rows);
                $last = (int)end($rows)['vod_id'];
                // fp-port-b item path: the batch read every row of (cursor, last], so its hash / exception entries are exact;
                // fp-port-c: a build started by this version checks the document of every visible row it reads (force), so a row
                // hidden and shown again, or made visible by raw SQL (stale mark), is queued if its document is missing
                $plan = !empty($s['b_items']) ? $this->itemPlan($rows, [$cursor, $last], (int)($s['dpend'] ?? 0),
                    (int)$s['b_items'] >= self::ITEMS_VERSION ? true : []) : null;
                if ($plan !== null) {
                    foreach ($plan['items'] as $c => $words) {
                        foreach ($words as $w => $f) {
                            $by[$c][(string)$w] = $f;
                        }
                    }
                }
                foreach ($by as $c => $toks) { // tokens this process already confirmed in this build need no second write
                    foreach ($toks as $t => $f) {
                        $k = $c . "\t" . $t;
                        if (isset($this->handled[$k]) && $this->handled[$k] === $f) { unset($by[$c][$t]); } else { $this->handled[$k] = $f; }
                    }
                }
                $ready = self::readyState($s);
                try {
                    // one transaction (FT-VOCAB-REVIEW-d P3-2): the batch's words and its cursor (with the new pair when it
                    // added words) are written together; after a crash the batch is read again and adds them again
                    $this->atomic(function () use ($by, $s, $last, $ready, $plan) {
                        $chg = $this->upsert($by, (int)$s['b_start']);
                        if ($plan !== null) {
                            $this->itemWrite($plan, (int)($s['dpend'] ?? 0));
                        }
                        $set = ['b_cursor' => $last];
                        if ($chg > 0 && $ready) {
                            // pages use this vocabulary while it is rebuilt: words (or flags) this batch added are a new word
                            // set, so a new rev with a new nonce, together with the cursor. Without it a word list cached
                            // before the build read an edited / imported row stays in use: the next catch-up finds the row's
                            // new word present (INSERT IGNORE changes nothing, so no new rev) and the MATCH drops the row
                            // until the build ends (FT-VOCAB-REVIEW-c P1-1). Rare: catch-ups add new words first.
                            $set['rev'] = (int)($s['rev'] ?? 0) + 1;
                            $set['nonce'] = self::nonce();
                        }
                        $this->setState($set);
                    });
                } catch (\Throwable $e) {
                    $this->handled = []; // what this batch confirmed was rolled back: confirm everything again
                    if (self::transient($e)) {
                        return ['state' => 'locked', 'steps' => $steps];
                    }
                    throw $e;
                }
                $steps++;
            } finally {
                $this->unlock();
            }
            if ($log && $steps % 50 === 0) { $log(sprintf('  ... id %d of %d', $last, (int)$s['b_max'])); }
            if (microtime(true) >= $deadline) {
                return ['state' => 'building', 'steps' => $steps, 'cursor' => $last, 'max' => (int)$s['b_max']];
            }
            if ($pauseMs > 0) {
                usleep((int)$pauseMs * 1000);
            }
        }
    }

    /** End of a full build (lock held): catch up what changed meanwhile, drop tokens not seen since it started. */
    private function finishLocked(array $s)
    {
        $bStart = (int)$s['b_start'];
        // queued ids whose latest save (seen, refreshed by every queue()) is from before the build started were read by it;
        // an id saved again after b_start stays queued and is read by the catch-up below
        call_user_func($this->x, 'DELETE FROM `' . $this->vocab . '` WHERE `col` = 255 AND `seen` < ?', [$bStart]);
        // 1.2.0: a backlog left by this catch-up ('pend': ids above the new ifloor or still queued) keeps the vocabulary
        // ready but not usable until catch-ups work it off; only a stale result (time window too big) ends the build not ready
        $ok = $this->deltaLocked($s) !== false;
        $deleted = 0;
        do {
            // words of cols 1-7 (fp-port-b: cols 5-7 = items) not confirmed since the build started; cols 252 / 254 keep no
            // time in seen (252: a hash) and were made exact batch by batch (itemPlan with the batch's id range)
            $n = (int)call_user_func($this->x, 'DELETE FROM `' . $this->vocab . '` WHERE `col` BETWEEN 1 AND 251 AND `seen` < ? LIMIT 5000', [$bStart]);
            $deleted += $n;
        } while ($n >= 5000);
        $s2 = $this->state();
        $now = (int)$this->one('SELECT UNIX_TIMESTAMP() AS t');
        // ready, the new rev and its nonce appear together. The vocabulary does not depend on the server's FULLTEXT
        // settings (it holds every word); FilterMatch checks each group word against the CURRENT settings.
        $set = ['ready' => $ok ? 1 : 0, 'ver' => self::VERSION, 'cols' => 15, 'built' => $now, 'wm_id' => (int)$s['b_max'], 'wm_time' => (int)($s['b_wm_time'] ?? 0),
            'b_start' => 0, 'b_cursor' => 0, 'rev' => (int)($s2['rev'] ?? 0) + 1, 'nonce' => self::nonce()];
        if (!empty($s['b_items'])) {
            $set['xitems'] = $now; // fp-port-b: every row was read with the item path's bookkeeping: items, hashes, exceptions complete
            $set['xiver'] = (int)$s['b_items']; // fp-port-c: with this item-path version's document checks (ITEMS_VERSION)
            if ((int)$s['b_items'] >= self::ITEMS_VERSION) {
                $set['xibuilt'] = $now; // fp-port-c: = 'built' of this statement (itemsComplete())
            }
        }
        // one transaction with the conditional clear of the stale mark (FT-VOCAB-REVIEW-d P3-1). The mark is never written
        // back from a value read earlier: a stale() that lands while this build ends (admin raw SQL after the cursor passed
        // the row) has seen >= b_start and stays, so pages keep the plain LIKE and the next build reads that row; only a
        // mark older than the build start is removed (every row was read after it). A catch-up above that found more than
        // DELTA_MAX rows has set its own mark (seen = now) and left $ok false.
        $this->atomic(function () use ($set, $ok, $bStart) {
            $this->setState($set);
            if ($ok) {
                call_user_func($this->x, "DELETE FROM `" . $this->vocab . "` WHERE `col` = 0 AND `token` = 'stale' AND `seen` < ?", [$bStart]);
            }
            // keys written by version 1 that nothing reads; fp-port-b: the build's item marker
            call_user_func($this->x, "DELETE FROM `" . $this->vocab . "` WHERE `col` = 0 AND `token` IN ('min', 'max', 'stopfp', 'b_items')", []);
        });
        $this->handled = [];
        $this->forgetProbe();
        return ['deleted_tokens' => $deleted, 'ready' => $set['ready'], 'stale' => (int)($this->state()['stale'] ?? 0)];
    }

    /** Status for the CLI: state, token counts per column, queued ids; 1.2.0: 'backlog' = new ids above ifloor while 'pend'. */
    public function status()
    {
        $s = $this->state();
        $counts = [];
        foreach (call_user_func($this->q, 'SELECT `col`, COUNT(*) AS n, SUM(`flags` & 1 <> 0) AS na, SUM(`flags` & 2 <> 0) AS lg FROM `' . $this->vocab . '` GROUP BY `col`', []) as $r) {
            $counts[(int)$r['col']] = ['tokens' => (int)$r['n'], 'non_ascii' => (int)$r['na'], 'long' => (int)$r['lg']];
        }
        $out = ['state' => $s, 'counts' => $counts];
        if (!empty($s['pend'])) {
            $out['backlog'] = (int)$this->one('SELECT COUNT(*) AS n FROM `' . $this->vod . '` WHERE `vod_id` > ?', [(int)($s['ifloor'] ?? 0)]);
        }
        return $out;
    }

    /**
     * 1.2.0: CREATE TABLE IF NOT EXISTS with the character set / collation of vod_tag, only when that collation is one of
     * COLLATIONS (elsewhere the vocabulary is never used). 'exists' | 'created' | 'skipped:<collation>' | 'no_vod_table'.
     */
    public function ensureTable()
    {
        $pre = substr($this->vocab, 0, -strlen(self::TABLE));
        $have = (int)$this->one('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->vocab]);
        if ($have > 0) {
            return 'exists';
        }
        $col = call_user_func($this->q, "SELECT CHARACTER_SET_NAME AS s, COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'vod_tag'", [$this->vod]);
        if (!$col || empty($col[0]['c'])) {
            return 'no_vod_table';
        }
        $coll = strtolower((string)$col[0]['c']);
        if (!in_array($coll, self::COLLATIONS, true)) {
            return 'skipped:' . $coll;
        }
        call_user_func($this->x, self::createSql($pre, strtolower((string)$col[0]['s']), $coll), []);
        $this->forgetProbe();
        return 'created';
    }

    /**
     * Housekeeping slice on this instance: works off a backlog first ('pend', catch-ups of DELTA_MAX rows, BACKLOG_PAGE in
     * a light run), then a build slice when a build is due (first build, stale, older than REBUILD_AGE) or running.
     * false when there is nothing to do, else 'pending' (backlog left) or the build state (building / done / locked / wait).
     */
    public function housekeepOn($deadline, $light = false)
    {
        $s = $this->state();
        if (!empty($s['off'])) {
            return false;
        }
        $ret = false;
        // behind = a backlog ('pend'), queued ids, or new / re-timed / re-tagged rows since the last catch-up (fingerprint):
        // housekeeping catches up itself, so a bulk or raw insert on a site with cron does not wait for a visitor to pay
        $behind = false;
        if (self::readyState($s)) {
            $p = $this->probe(true);
            $behind = !empty($s['pend']) || $p['dirty'] > 0 || (int)$p['fp'] !== (int)($s['fp'] ?? -1);
        }
        if ($behind) {
            $ret = 'pending';
            do {
                $before = (int)($s['ifloor'] ?? 0) . '/' . (int)($s['caught'] ?? 0);
                $done = $this->catchUp(0, $light ? self::BACKLOG_PAGE : self::DELTA_MAX);
                $s = $this->state();
                if ($done) {
                    $ret = 'caught_up';
                    break;
                }
                if (!self::readyState($s)) {
                    $ret = false; // marked stale (more than DELTA_MAX re-timed rows): the build below takes over
                    break;
                }
                if ($before === (int)($s['ifloor'] ?? 0) . '/' . (int)($s['caught'] ?? 0)) {
                    break; // no progress (the lock is busy: a page catch-up or another run holds it); the next run goes on
                }
            } while (!$light && microtime(true) < (float)$deadline);
            if ($ret === 'pending') {
                return $ret;
            }
        }
        // fp-port-b: rows read inside a closed bulk window wait for their search documents ('dpend'; the window's append may be
        // deferred): a full run moves the mark, DPEND_CHUNK rows per catch-up, until it is cleared, it stops at a row whose
        // document is still missing (no progress), or the deadline; the item path's id range then shrinks without a new save
        if (!$light && self::readyState($s) && !empty($s['dpend']) && empty($s['b_start'])) {
            do {
                $before = (int)$s['dpend'];
                $this->catchUp(0, self::DELTA_MAX);
                $s = $this->state();
                if (empty($s['dpend'])) {
                    $ret = $ret ?: 'caught_up';
                    break;
                }
                if ((int)$s['dpend'] === $before || !self::readyState($s)) {
                    break;
                }
            } while (microtime(true) < (float)$deadline);
        }
        if (empty($s['b_start']) && !$this->needsBuild($s)) {
            return $ret;
        }
        if ($light || microtime(true) < (float)$deadline) {
            $r = $this->buildStep($light ? 0.001 : max(0.02, (float)$deadline - microtime(true)), 0, 0, false, null, $light ? 500 : self::BATCH);
            return $r['state'];
        }
        return $ret;
    }

    // ------------------------------------------------------------------ hooks (app)

    /**
     * Save paths: the four columns of these videos may have changed (vod_time possibly kept). Never throws; call it after
     * the save is committed (every hook does). A deadlock or lock wait timeout on a queue row is retried once; ids that
     * still cannot be queued mark the vocabulary stale (FT-VOCAB-REVIEW-d P3-2: their new words could be missed
     * otherwise), so filters use the plain LIKE until the next full build. Without a vocabulary table both do nothing.
     */
    public static function touched(array $ids)
    {
        for ($try = 0; $try < 2; $try++) {
            try {
                self::app()->queue($ids);
                return;
            } catch (\Throwable $e) {
                if ($try > 0 || !self::transient($e)) {
                    break;
                }
            }
        }
        self::stale();
    }

    /**
     * Queues video ids for the next catch-up (col 255, token = id, seen = now). An id that is queued already gets
     * seen = now again: a queue entry is deleted only when it was read after its LAST save (finishLocked: seen before
     * the build started; deltaLocked: seen before the catch-up started), so a second save is never lost.
     */
    public function queue(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) { return $i > 0; })));
        $ids = array_map('strval', $ids);
        sort($ids, SORT_STRING); // the order of the key (col, token): row locks taken in the order a catch-up deletes them
        foreach (array_chunk($ids, 500) as $chunk) {
            call_user_func($this->x, 'INSERT INTO `' . $this->vocab . '` (`col`, `token`, `flags`, `seen`) VALUES ' . implode(',', array_fill(0, count($chunk), '(255, ?, 0, UNIX_TIMESTAMP())')) .
                ' ON DUPLICATE KEY UPDATE `seen` = UNIX_TIMESTAMP()', $chunk);
        }
        if ($ids) {
            $this->forgetProbe();
        }
    }

    /** fp-port-c: whether the app connection's {prefix}vod_ft_queue exists (true: for the process; false: re-checked after 60 s). */
    private static $ftqHave = null;
    private static $ftqAt = 0.0;

    /**
     * fp-port-c (FINISH-PORT-REVIEW-b P2-1): queues video ids for the search table (vod_ft_queue, as FtIndex::touch() does) on
     * the application connection. Meant to run INSIDE the transaction of the write that changes their visibility (restore,
     * show, hide, recycle) or their items, so the row change and its queue entry commit together: a visible row then never
     * lacks both its search document and a queue entry, whatever happens to the writer after the commit (runner killed,
     * php-cgi or proxy timeout, restart, lease lost, StorageGuard), and FilterMatch::items() reads every queued id. Ids are
     * written in ascending order, the order in which the drain and the vocabulary's catch-up lock queue rows (no lock-order
     * deadlock between them). Unlike touched() it THROWS on a database error, so the caller's transaction fails with it
     * instead of committing the row without its entry. Without the queue table (search tables not installed: their backfill
     * reads every row) it does nothing. Returns the number of new queue rows.
     */
    public static function searchQueue(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) { return $i > 0 && $i <= 4294967295; })));
        if (!$ids) {
            return 0;
        }
        $table = (string)config('database.prefix') . 'vod_ft_queue';
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table)) {
            throw new \RuntimeException('ft-vocab: bad table prefix');
        }
        if (self::$ftqHave !== true && (self::$ftqHave === null || microtime(true) - self::$ftqAt > 60)) {
            $r = Db::query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            self::$ftqHave = (int)($r[0]['n'] ?? 0) > 0;
            self::$ftqAt = microtime(true);
        }
        if (!self::$ftqHave) {
            return 0;
        }
        sort($ids, SORT_NUMERIC);
        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $n += (int)Db::execute('INSERT IGNORE INTO `' . $table . '` (`vod_id`, `queued_at`) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, UNIX_TIMESTAMP())')), $chunk);
        }
        return $n;
    }

    /** Raw SQL changed the video table in an unknown way: no vocabulary groups until the next full build. Never throws. */
    public static function stale()
    {
        try {
            $v = self::app();
            call_user_func($v->x, 'INSERT INTO `' . $v->vocab . '` (`col`, `token`, `flags`, `seen`) VALUES (0, \'stale\', 0, UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE `seen` = UNIX_TIMESTAMP()', []);
            $v->forgetProbe();
        } catch (\Throwable $e) {
            // no vocabulary table
        }
    }

    /**
     * Housekeeping: a build slice when a build is due (first build, stale, older than REBUILD_AGE) or running; false
     * when there is nothing to do, else the slice state (building / done / locked). A page tick ($light) reads one
     * batch of 500 videos (about 10 ms); larger runs read batches of BATCH until the deadline. Never throws.
     */
    public static function housekeep($deadline, $light = false)
    {
        try {
            return self::app()->housekeepOn($deadline, $light);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 1.2.0: the housekeeping slice of Housekeeping::scale() on the scale run's own connection $pdo (statement limit set by
     * the run). $root: the site folder; its application/database.php gives the site's lock name (dbKey), so this slice,
     * page catch-ups and the CLI tools share one named lock. Creates the table when it is missing (ensureTable()). Never
     * throws: false (nothing to do / no table / error) or the slice state of housekeepOn().
     */
    public static function housekeepPdo(\PDO $pdo, $prefix, $root, $deadline, $light = false)
    {
        try {
            $file = rtrim(str_replace('\\', '/', (string)$root), '/') . '/application/database.php';
            $cfg = is_file($file) ? include $file : [];
            $v = self::pdo($pdo, (string)$prefix, false, self::dbKey(is_array($cfg) ? $cfg : []));
            $t = $v->ensureTable();
            if ($t !== 'exists' && $t !== 'created') {
                return false;
            }
            return $v->housekeepOn($deadline, $light);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 1.2.0 import hook (AvdbImport batch ends, Collect pages; call it after the commit, never inside a transaction): the
     * ids saved by this batch are queued (touched()) and the catch-up runs at once in the importing process, waiting at
     * most $wait s for the lock, so a long import keeps the vocabulary current instead of leaving thousands of rows to the
     * next filter request (or a backlog). Ids of rows the catch-up finds anyway (new ids above the id floor) may be left
     * out of $ids. Never throws.
     */
    public static function imported(array $ids, $wait = 1)
    {
        if ($ids) {
            self::touched($ids);
        }
        try {
            $v = self::app();
            $s = $v->state();
            if (empty($s['off']) && (self::readyState($s) || !empty($s['b_start']))) {
                $v->catchUp(max(0, (int)$wait), self::DELTA_MAX);
            }
        } catch (\Throwable $e) {
            // no table, the connection inside a transaction, a lock conflict: the next filter request catches up
        }
    }

    /** Clears the per-process memo (tests, FilterMatch::forget()). */
    public static function forget()
    {
        if (self::$app !== null) {
            self::$app->lookups = [];
            self::$app->forgetProbe();
        }
    }
}
