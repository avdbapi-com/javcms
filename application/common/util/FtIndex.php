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
 * C1 (lane S): the search table and its write path. XVID-SCALE-BRIEF D-S1, D-S6, D-S7, D-S9.
 *
 * Tables (application/install/sql/search-index.sql, created by ensureSchema() with the create fallbacks of D-S1):
 *   vod_ft        one document per published, not recycled video; FTS_DOC_ID = insertion order (higher = newer)
 *   vod_ft_term   tokens with df >= TERM_MIN (500 since the round-2 verify fixes; planner statistics, --terms)
 *   vod_ft_queue  write-path queue (touch/forget), drained by drain()
 *   vod_ft_replay ids written while a rebuild runs (replayed into the shadow table before and at the swap)
 *   vod_ft_state  one row: ready flag, mode ('ft' | 'walk'), counters, bulk window, rebuild flags, next_doc_id
 *   vod_ft_map    term map of the single-token pages (XVID-NOLIMIT-BRIEF C2): per term with df >= TERM_MIN either every
 *                 matching FTS_DOC_ID (kind 1) or every step-th one (kind 2), packed uint32 oldest first; mapBuild /
 *                 mapAppend / mapState (C16); state of the build in runtime/ft/ftmap.json
 *   vod_ft_mapt   small rows next to the term map (round-2 verify fixes N11 / F4): chunk 0 = the exact doc ids of the newest
 *                 MAP_HEAD ranks of a kind-2 term (page 1 without a LIKE window); chunks 1.. = exact doc ids appended by
 *                 mapAppend above the term's last_doc (a small row is rewritten instead of the whole id list); folded into
 *                 the main row by the next mapBuild, or by mapAppend when a term holds more than TAIL_MERGE chunks;
 *                 chunks DEAD_BASE.. (round-3 verify fix F-r3-1) = the term's dead doc ids: documents of its rank space
 *                 (main row + appended chunks) that the write path deleted since (a re-insert gives a video a new doc id)
 *   vod_ft_mapd   (round-3 F-r3-1) the documents the write path deleted while a term map exists: seq (insertion order), doc,
 *                 its text; folded into the per-term dead chunks by mapAppend / mapDeadPass, then removed; a full mapBuild
 *                 starts over. Pages subtract the dead ids of a term before ranking (exact totals, full pages, no ghost page)
 *
 * Write path (D-S6): every write that can change a searchable field, vod_status or vod_recycle_time calls touch($ids)
 * (one INSERT IGNORE into the queue). drain() deletes the old doc of each queued id and inserts a fresh one with the
 * next FTS_DOC_ID under GET_LOCK('<db>_vod_ft'); unpublished / deleted ids are only deleted. FTS_DOC_ID comes from
 * vod_ft_state.next_doc_id, never from MAX(FTS_DOC_ID)+1: InnoDB refuses (error 182) a user FTS_DOC_ID that is not
 * above every id it has seen, so re-using the id of a deleted newest doc would fail (measured on MariaDB 11.4).
 *
 * Connection: inside ThinkPHP the framework's PDO (think\Db) is used; CLI tools, housekeeping and tests pass their own
 * PDO with useConnection(). All SQL goes through q()/x(); identifiers are built only from fixed names and the
 * validated table prefix; every value is bound or passed through intval.
 */
final class FtIndex
{
    const T_FT = 'vod_ft';
    const T_TERM = 'vod_ft_term';
    const T_QUEUE = 'vod_ft_queue';
    const T_REPLAY = 'vod_ft_replay';
    const T_STATE = 'vod_ft_state';
    const T_NEW = 'vod_ft_new';
    const T_OLD = 'vod_ft_old';
    const T_MAP = 'vod_ft_map';
    const T_MAPT = 'vod_ft_mapt';
    const T_MAPD = 'vod_ft_mapd';
    /**
     * Round 6 (orchestrator D-9, search-r6 R6-5): order and dictionary tracking for the D-1 tag route.
     * vod_ft_inv = every document that sorts below an earlier one in (vod_time, vod_id) order (an admin save or a re-import
     * of an old video writes its document at the top with its old vod_time), one row per tg_ token it holds, with its key:
     * a token page of the route puts them at their (vod_time, vod_id) place (FtPlan::mapPageOrdered) instead of losing the
     * route (a document without a tg_ token cannot change a tag page: no row).
     * vod_ft_newtok = the tg_ tokens of documents written since the last term build that the term table does not hold
     * (the tag dictionary of the route's proof stays complete without scanning the documents above term_doc).
     */
    const T_INV = 'vod_ft_inv';
    const T_NEWTOK = 'vod_ft_newtok';
    /**
     * Round 6: inverted documents the tracking lists at most (vod_ft_inv rows: INV_MAX x INV_ROW_FACTOR); more turn it off
     * until a rebuild (the r5 rules then, and checkTriggers() has long marked the rebuild: out_of_order > 5 % of walk_cap).
     */
    const INV_MAX = 100000;
    const INV_ROW_FACTOR = 8;
    /** Self-heal bucket width (vod ids) and buckets per housekeeping run (D-S6). */
    const BUCKET = 5000;
    const HEAL_BUCKETS = 20;
    /** Self-heal: one bucket statement may use at most this long (s); the slice stops when its deadline is nearer (N17). */
    const HEAL_STMT_SEC = 4.0;
    /**
     * Term statistics: tokens with df >= TERM_MIN are stored; 1-in-SAMPLE sample above EXACT_MAX docs (D-S7). Round-2
     * verify fix (G1 DB part, R2-2): 500 instead of 1,000, so the term map holds kind-1 id lists down to df 500 (multi-token
     * phrases of 890-940 matches read their candidates from it instead of walking 1,800 pages; at 7.1 M about +10 k terms).
     */
    const TERM_MIN = 500;
    const TERM_SAMPLE = 20;
    const TERM_EXACT_MAX = 200000;
    /**
     * Round 5 (flood-r5 R5-1, synth S-1 "same performer window"): field tokens (tg_ tags / classes, ac_ actors) are counted
     * EXACTLY over every document, also above TERM_EXACT_MAX (words keep the 1-in-20 sample): the sample put actors of
     * 800-1,100 videos under TERM_MIN, so their "same performer" block ran MATCH ... LIMIT 3000 (MariaDB fetches every row
     * of a MATCH, also for a doc-id-only select: measured 5,483 page reads for 96 of 865 rows) instead of the term map. Field
     * tokens are kept from FIELD_TERM_MIN (100, CONTRACT HO-T-R5-1: "term-map rows for ac_ field tokens down to df ~100")
     * docs, so an actor of 100+ videos reads its block from the term map (exact; a MATCH of the rest fetches at most ~100
     * rows: about 1.6 cold reads per doc measured); every tg_ token is kept whatever its df (the complete tag / class
     * dictionary of the round-5 D-1 proof, FtPlan::tagProof). FIELD_EXACT_CAP distinct field tokens at most in memory;
     * above that the field tokens fall back to the sample (kept from FIELD_TERM_MIN there too).
     */
    const FIELD_TERM_MIN = 100;
    const FIELD_EXACT_CAP = 3000000;
    /** Test hook (tests/ft_index_db.php): TERM_EXACT_MAX for terms() (a small value runs the sampled path on a small table). */
    public static $termsExactMax = null;
    /** Rebuild / backfill batch size and the web step budget (D-S7, pr-R10.1). */
    const BATCH = 1000;
    const WEB_STEP_SEC = 9;
    /** A rebuild job whose last step is older than this is abandoned and may be restarted. */
    const JOB_STALE_SEC = 900;
    /** CLI rebuild: sleep this share of each batch's time (fill / replay), so visitors keep the cores (verify fix F9, G21) */
    const THROTTLE = 0.25;
    /** swap: RENAME retries after a lock / rename error (seconds to wait before each retry; verify fix F10, G21) */
    const SWAP_RETRY = [2, 4, 8, 16, 30];
    /** swap: the RENAME waits at most this long for the metadata lock per attempt (a long reader cannot hang the CLI) */
    const SWAP_LOCK_WAIT = 10;
    /** Inline drain after a single admin save handles at most this many ids. */
    const INLINE_MAX = 20;

    private static $pdo = null;
    /**
     * Round 8 (flood-r8 R8-1): true after useConnection() gave FtIndex a connection (housekeeping's scale run, the installer,
     * tools, tests), false while pdo() uses the framework's own connection (the front end). capSec() caps only statements of
     * the framework connection, the one DbTimeout's request deadline governs.
     */
    private static $givenPdo = false;
    private static $prefix = null;
    private static $dbName = null;
    private static $state = null;
    private static $tables = null;
    private static $hasTranslations = null;
    private static $lastSchema = [];
    private static $runtime = null;
    /** languages of the job being continued (a step without the site config must write the job's languages) */
    private static $langsFixed = null;

    // ------------------------------------------------------------------ connection

    /** Use this PDO (CLI tools, housekeeping, tests). $prefix must match [A-Za-z0-9_]*. */
    public static function useConnection(\PDO $pdo, string $prefix, string $dbName = ''): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{0,64}$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid table prefix');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::$pdo = $pdo;
        self::$givenPdo = true;
        self::$prefix = $prefix;
        self::$dbName = $dbName !== '' ? $dbName : (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        self::reset();
    }

    /** Forget cached state (tests, after DDL). */
    public static function reset(): void
    {
        self::$state = null;
        self::$tables = null;
        self::$hasTranslations = null;
        self::$mapMemo = null;
        self::$coverMemo = null;
        self::$ptbMemo = null;
        self::$engineMemo = null;
    }

    /** Folder for the small job / cursor files (runtime/ft inside the site, else the system temp folder). */
    public static function useRuntime(string $dir): void
    {
        self::$runtime = rtrim($dir, '/\\');
        self::$mapMemo = null;
        self::$coverMemo = null;
        self::$ptbMemo = null;
    }

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            if (!class_exists('\think\Db')) {
                throw new \RuntimeException('FtIndex: no database connection');
            }
            \think\Db::query('SELECT 1');
            $pdo = \think\Db::connect()->getPdo();
            if (!$pdo instanceof \PDO) {
                throw new \RuntimeException('FtIndex: no database connection');
            }
            $prefix = (string)\think\Config::get('database.prefix');
            if (!preg_match('/^[A-Za-z0-9_]{0,64}$/D', $prefix)) {
                throw new \RuntimeException('FtIndex: invalid table prefix');
            }
            self::$pdo = $pdo;
            self::$prefix = $prefix;
            self::$dbName = (string)\think\Config::get('database.database');
        }
        return self::$pdo;
    }

    /** Quoted table name with the site prefix. */
    public static function t(string $name): string
    {
        self::pdo();
        return '`' . self::$prefix . $name . '`';
    }

    public static function prefix(): string
    {
        self::pdo();
        return (string)self::$prefix;
    }

    /** SELECT -> list of assoc rows. */
    public static function q(string $sql, array $bind = []): array
    {
        $pdo = self::pdo();
        if (!$bind) {
            $st = $pdo->query($sql);
        } else {
            $st = $pdo->prepare($sql);
            $st->execute($bind);
        }
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $rows;
    }

    /** First column of the first row (null when no row). */
    public static function v(string $sql, array $bind = [])
    {
        $rows = self::q($sql, $bind);
        return $rows ? reset($rows[0]) : null;
    }

    /** Statement without result -> affected rows. */
    public static function x(string $sql, array $bind = []): int
    {
        $pdo = self::pdo();
        if (!$bind) {
            return (int)$pdo->exec($sql);
        }
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        return $st->rowCount();
    }

    private static function lockName(string $what): string
    {
        self::pdo();
        $n = self::$dbName . '_' . self::$prefix . $what;
        return strlen($n) > 60 ? substr($what, 0, 8) . '_' . md5($n) : $n;
    }

    /** Name of the single-flight job lock shared by backfill, rebuild, terms and the slim-index tool (se-R20). */
    public static function jobLockName(): string
    {
        return self::lockName('ft_job');
    }

    private static function getLock(string $what, int $wait): bool
    {
        return (int)self::v('SELECT GET_LOCK(:n, ' . max(0, $wait) . ')', [':n' => self::lockName($what)]) === 1;
    }

    /**
     * Round 7 (engines-r7 NEW-3): true while some connection holds the named lock $what (IS_USED_LOCK: this one included),
     * false when none does, null when the server cannot tell (the caller keeps its other evidence).
     */
    private static function lockHeld(string $what): ?bool
    {
        try {
            $r = self::q('SELECT IS_USED_LOCK(:n) AS h', [':n' => self::lockName($what)]);
            if (!$r) {
                return null;
            }
            $h = $r[0]['h'] ?? null;
            return $h !== null && $h !== '' && $h !== false;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function releaseLock(string $what): void
    {
        try {
            self::v('SELECT RELEASE_LOCK(:n)', [':n' => self::lockName($what)]);
        } catch (\Throwable $e) {
        }
    }

    private static function begin(): bool
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return false;
        }
        $pdo->beginTransaction();
        return true;
    }

    private static function commit(bool $own): void
    {
        if ($own && self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    private static function rollback(bool $own): void
    {
        if ($own && self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    // ------------------------------------------------------------------ schema

    /** Which of the search tables exist (cached per request). */
    private static function tables(): array
    {
        if (self::$tables === null) {
            $names = [];
            foreach ([self::T_FT, self::T_TERM, self::T_QUEUE, self::T_REPLAY, self::T_STATE, self::T_NEW, self::T_OLD, self::T_MAP, self::T_MAPT, self::T_MAPD, self::T_INV, self::T_NEWTOK, 'avdb_translation'] as $t) {
                $names[self::prefix() . $t] = $t;
            }
            $in = [];
            $bind = [];
            $i = 0;
            foreach (array_keys($names) as $full) {
                $in[] = ':t' . $i;
                $bind[':t' . $i] = $full;
                $i++;
            }
            $found = [];
            foreach (self::q('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', $in) . ')', $bind) as $r) {
                $n = (string)reset($r);
                if (isset($names[$n])) {
                    $found[$names[$n]] = true;
                }
            }
            self::$tables = $found;
        }
        return self::$tables;
    }

    private static function has(string $t): bool
    {
        return !empty(self::tables()[$t]);
    }

    /** True when all five search tables exist. */
    public static function installed(): bool
    {
        try {
            foreach ([self::T_FT, self::T_TERM, self::T_QUEUE, self::T_REPLAY, self::T_STATE] as $t) {
                if (!self::has($t)) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Statements of search-index.sql keyed by table name (without prefix), prefix applied. */
    public static function schemaStatements(): array
    {
        $file = dirname(__DIR__, 2) . '/install/sql/search-index.sql';
        $sql = is_file($file) ? (string)file_get_contents($file) : '';
        if (substr_count($sql, 'CREATE TABLE IF NOT EXISTS') !== 10) {
            throw new \RuntimeException('FtIndex: search-index.sql is missing or damaged');
        }
        $out = [];
        foreach (explode(';', $sql) as $st) {
            $st = trim($st);
            if ($st === '' || !preg_match('/CREATE TABLE IF NOT EXISTS `javcms_([a-z_]+)`/', $st, $m)) {
                continue;
            }
            $out[$m[1]] = str_replace('`javcms_', '`' . self::prefix(), $st);
        }
        return $out;
    }

    /**
     * The create-fallback ladder of D-S1 for the document table $table (vod_ft or vod_ft_new):
     *  1 as in the file (compressed), only when @@innodb_read_only_compressed is OFF or absent;
     *  2 without ROW_FORMAT=COMPRESSED;
     *  3 PRIMARY KEY (vod_id) + UNIQUE KEY FTS_DOC_ID_INDEX (FTS_DOC_ID) when the engine refuses the PK on FTS_DOC_ID;
     *  4 without the FULLTEXT key (mode 'walk').
     * Each candidate must pass one test INSERT and one DELETE (MariaDB 10.6.0-10.6.5 create compressed tables that refuse
     * writes). $fulltext=false builds the table without its FULLTEXT key (CLI rebuild: load first, ADD FULLTEXT after).
     * Returns ['variant' => 1-4, 'mode' => 'ft'|'walk', 'compressed' => bool, 'tried' => [variant => error]].
     */
    private static function createDocTable(string $table, bool $fulltext = true, int $only = 0): array
    {
        $base = self::schemaStatements()[self::T_FT];
        $base = str_replace('`' . self::prefix() . self::T_FT . '`', '`' . self::prefix() . $table . '`', $base);
        $compressed = ' ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8';
        $ftKey = ",\n  FULLTEXT KEY `ft` (`ft_text`)";
        if (strpos($base, $compressed) === false || strpos($base, $ftKey) === false) {
            throw new \RuntimeException('FtIndex: unexpected vod_ft DDL');
        }
        $plain = str_replace($compressed, '', $base);
        $alt = str_replace(["PRIMARY KEY (`FTS_DOC_ID`),\n  UNIQUE KEY `uk_vod` (`vod_id`),"], ["PRIMARY KEY (`vod_id`),\n  UNIQUE KEY `FTS_DOC_ID_INDEX` (`FTS_DOC_ID`),"], $plain);
        $roc = null;
        try {
            $roc = self::v('SELECT @@innodb_read_only_compressed');
        } catch (\Throwable $e) {
            $roc = null; // variable absent: not a MariaDB 10.6 server
        }
        $rocOn = $roc !== null && in_array(strtoupper((string)$roc), ['1', 'ON'], true);
        $ladder = [];
        if (!$rocOn) {
            $ladder[1] = $base;
        }
        $ladder[2] = $plain;
        $ladder[3] = $alt;
        $ladder[4] = str_replace($ftKey, '', $plain);
        $ladder[5] = str_replace($ftKey, '', $alt);
        if (!$fulltext) {
            foreach ($ladder as $k => $sql) {
                $ladder[$k] = str_replace($ftKey, '', $sql);
            }
        }
        $tried = [];
        if ($rocOn) {
            $tried[1] = 'skipped: innodb_read_only_compressed=ON';
        }
        $t = self::t($table);
        foreach ($ladder as $variant => $sql) {
            if ($only && $variant !== $only) {
                continue;
            }
            try {
                self::x('DROP TABLE IF EXISTS ' . $t);
                self::x($sql);
                self::x('INSERT INTO ' . $t . " (FTS_DOC_ID, vod_id, vod_time, type_id, type_id_1, ft_text) VALUES (1, 0, 0, 0, 0, ' probe ')");
                self::x('DELETE FROM ' . $t . ' WHERE FTS_DOC_ID = 1');
                $fmt = (string)self::v('SELECT ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', [':t' => self::prefix() . $table]);
                $hasFt = strpos($sql, 'FULLTEXT KEY') !== false;
                self::$tables = null;
                return ['variant' => $variant > 4 ? 4 : $variant, 'mode' => ($hasFt || !$fulltext) && $variant < 4 ? 'ft' : 'walk',
                    'compressed' => strcasecmp($fmt, 'Compressed') === 0, 'row_format' => $fmt, 'tried' => $tried, 'pk' => $variant === 3 || $variant === 5 ? 'vod_id' : 'FTS_DOC_ID'];
            } catch (\Throwable $e) {
                $tried[$variant] = substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
                try {
                    self::x('DROP TABLE IF EXISTS ' . $t);
                } catch (\Throwable $e2) {
                }
            }
        }
        self::$tables = null;
        throw new \RuntimeException('FtIndex: the search table could not be created: ' . json_encode($tried));
    }

    /**
     * Create the search tables when missing (code-guarded, idempotent; D-S1, 2.12 step 2a). A new install (no published
     * video) starts ready=1 and fills as videos arrive (D-S9); an existing catalog starts ready=0 until the backfill.
     */
    public static function ensureSchema(): void
    {
        $st = self::schemaStatements();
        $created = [];
        foreach ([self::T_TERM, self::T_QUEUE, self::T_REPLAY, self::T_STATE] as $t) {
            if (!self::has($t)) {
                self::x($st[$t]);
                $created[] = $t;
            }
        }
        if ($created) {
            self::$tables = null;
        }
        // state rows written by an earlier build may lack next_doc_id (additive, code-guarded); round 5: the document order
        // marks inv_doc / inv_n and the term build's cover term_doc (an upgraded table starts with inv_doc = 1 = "order not
        // known": no token page claims the plain LIKE's order until the next term build has checked it, termsDue())
        if (!in_array(self::T_STATE, $created, true)) {
            // round 6 (D-9): the order / dictionary tracking columns start "off" (inv_ok 0, tok_doc 0): the r5 proof rules
            // apply until the next term build turns them on (termsDue())
            foreach (['next_doc_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 2', 'inv_doc' => 'BIGINT UNSIGNED NOT NULL DEFAULT 1',
                         'inv_n' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'term_doc' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
                         'inv_ok' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0', 'hw_time' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                         'hw_id' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'tok_doc' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
                         'tok_n' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'cut_doc' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0'] as $name => $type) {
                $col = self::v('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
                    [':t' => self::prefix() . self::T_STATE, ':c' => $name]);
                if ((int)$col === 0) {
                    self::x('ALTER TABLE ' . self::t(self::T_STATE) . ' ADD COLUMN `' . $name . '` ' . $type);
                    self::$state = null;
                }
            }
        }
        $info = null;
        $newFt = false;
        if (!self::has(self::T_FT)) {
            $info = self::createDocTable(self::T_FT, true);
            self::$lastSchema = $info;
            $newFt = true;
        } else {
            $info = self::describeDocTable(self::T_FT);
            self::$lastSchema = $info + ['existing' => true];
        }
        $row = self::q('SELECT id FROM ' . self::t(self::T_STATE) . ' WHERE id = 1');
        if (!$row) {
            $docs = (int)self::v('SELECT COUNT(*) FROM ' . self::t(self::T_FT));
            $max = (int)self::v('SELECT COALESCE(MAX(FTS_DOC_ID), 1) FROM ' . self::t(self::T_FT));
            $published = self::q('SELECT vod_id FROM ' . self::t('vod') . ' WHERE vod_status = 1 AND vod_recycle_time = 0 LIMIT 1');
            $ready = ($docs > 0 || !$published) ? 1 : 0;
            self::x('INSERT IGNORE INTO ' . self::t(self::T_STATE) . ' (id, ready, mode, docs, built_at, langs, next_doc_id, inv_doc) VALUES (1, :r, :m, :d, :b, :l, :n, :i)',
                [':r' => $ready, ':m' => $info['mode'], ':d' => $docs, ':b' => $docs > 0 || !$published ? time() : 0,
                 ':l' => implode(',', self::langs()), ':n' => max(2, $max + 1), ':i' => $docs > 0 ? 1 : 0]);
        } elseif ($newFt) {
            // the document table was missing while the state row existed: start over (empty table, backfill needed)
            $published = self::q('SELECT vod_id FROM ' . self::t('vod') . ' WHERE vod_status = 1 AND vod_recycle_time = 0 LIMIT 1');
            self::x("UPDATE " . self::t(self::T_STATE) . " SET ready = :r, mode = :m, docs = 0, stale_ops = 0, out_of_order = 0, rebuild_started_at = 0, next_doc_id = 2, term_at = 0, docs_at_terms = 0, inv_doc = 0, term_doc = 0 WHERE id = 1",
                [':r' => $published ? 0 : 1, ':m' => $info['mode']]);
        } else {
            $mode = self::state(true)['mode'] ?? 'ft';
            if ($mode !== $info['mode']) {
                self::x('UPDATE ' . self::t(self::T_STATE) . ' SET mode = :m WHERE id = 1', [':m' => $info['mode']]);
            }
        }
        self::$state = null;
        // C2 term map table (secondary: a failure leaves the deep single-token pages on the continuation path)
        try {
            self::ensureMap();
        } catch (\Throwable $e) {
            error_log('[FtIndex] vod_ft_map: ' . substr($e->getMessage(), 0, 200));
        }
        // round 6 (D-9): the route's order / dictionary tracking tables (secondary: without them the r5 proof rules apply)
        try {
            foreach ([self::T_INV, self::T_NEWTOK] as $t) {
                if (!self::has($t)) {
                    self::x($st[$t]);
                    self::$tables = null;
                }
            }
        } catch (\Throwable $e) {
            error_log('[FtIndex] route tracking tables: ' . substr($e->getMessage(), 0, 200));
        }
    }

    /** What ensureSchema() found or created last (variant 1-4, mode, compressed, tried fallbacks). */
    /**
     * True on the MariaDB versions where an in-place ADD FULLTEXT on a ROW_FORMAT=COMPRESSED table crashes the server
     * (10.6.0 up to 10.10.x; 10.11 and later, 10.5 and older, MySQL measured fine). Unknown version -> false.
     */
    public static function zipFtAlterUnsafe(): bool
    {
        try {
            $ver = (string)self::v('SELECT VERSION()');
        } catch (\Throwable $e) {
            return false;
        }
        if (stripos($ver, 'mariadb') === false || !preg_match('/^(\d+)\.(\d+)\./', $ver, $m)) {
            return false;
        }
        $n = (int)$m[1] * 100 + (int)$m[2];
        return $n >= 1006 && $n < 1011;
    }

    public static function schemaInfo(): array
    {
        return self::$lastSchema;
    }

    private static function describeDocTable(string $table): array
    {
        $fmt = (string)self::v('SELECT ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', [':t' => self::prefix() . $table]);
        $idx = self::q('SELECT INDEX_NAME, INDEX_TYPE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', [':t' => self::prefix() . $table]);
        $ft = false;
        $pk = 'FTS_DOC_ID';
        foreach ($idx as $i) {
            if (strtoupper((string)$i['INDEX_TYPE']) === 'FULLTEXT') {
                $ft = true;
            }
            if ($i['INDEX_NAME'] === 'PRIMARY') {
                $pk = (string)$i['COLUMN_NAME'];
            }
        }
        $compressed = strcasecmp($fmt, 'Compressed') === 0;
        $variant = !$ft ? 4 : ($pk === 'vod_id' ? 3 : ($compressed ? 1 : 2));
        return ['variant' => $variant, 'mode' => $ft ? 'ft' : 'walk', 'compressed' => $compressed, 'row_format' => $fmt, 'pk' => $pk];
    }

    // ------------------------------------------------------------------ state

    /** The state row (cached per request; $fresh re-reads). Empty array when the table is missing. */
    public static function state(bool $fresh = false): array
    {
        if ($fresh || self::$state === null) {
            try {
                $rows = self::q('SELECT * FROM ' . self::t(self::T_STATE) . ' WHERE id = 1');
                self::$state = $rows ? $rows[0] : [];
            } catch (\Throwable $e) {
                self::$state = [];
            }
        }
        return self::$state;
    }

    /** True when the search table exists and vod_ft_state.ready = 1 (D-S5 decides from this, never from an error). */
    public static function ready(): bool
    {
        $s = self::state();
        return isset($s['ready']) && (int)$s['ready'] === 1;
    }

    private static function setState(array $set): void
    {
        $parts = [];
        $bind = [];
        foreach ($set as $k => $v) {
            if (!preg_match('/^[a-z_]+$/D', $k)) {
                continue;
            }
            $parts[] = '`' . $k . '` = :' . $k;
            $bind[':' . $k] = $v;
        }
        if ($parts) {
            self::x('UPDATE ' . self::t(self::T_STATE) . ' SET ' . implode(', ', $parts) . ' WHERE id = 1', $bind);
        }
        self::$state = null;
    }

    /**
     * Languages whose translated titles go into the documents (D3, setting search.ft_langs): a comma list ('en' always
     * included); '' = the D3 default: every offered site language, or English + the default site language when the
     * database limit is set and the database already uses more than half of it (StorageGuard, C13).
     */
    public static function langs(): array
    {
        $all = ['en', 'vi', 'th', 'id', 'ko', 'zh', 'ja', 'fr', 'de', 'es', 'pt', 'ru', 'ar', 'tr', 'hi', 'ms', 'tl'];
        if (self::$langsFixed !== null) {
            return self::$langsFixed;
        }
        if (!isset($GLOBALS['config']['site']) && !isset($GLOBALS['config']['search'])) {
            // housekeeping before the framework (no site settings): the languages the table was built with, so a drain or a
            // continued step never writes documents of other languages than its siblings (and never trips the 'langs' rule)
            $stored = (string)(self::state()['langs'] ?? '');
            $list = array_values(array_intersect($all, array_merge(['en'], explode(',', $stored))));
            if ($stored !== '' && $list) {
                return $list;
            }
        }
        $setting = trim((string)($GLOBALS['config']['search']['ft_langs'] ?? ''));
        if ($setting !== '') {
            $list = array_values(array_intersect($all, array_merge(['en'], preg_split('/[\s,]+/', strtolower($setting)) ?: [])));
            return $list ?: ['en'];
        }
        $offered = $all;
        $default = 'en';
        if (class_exists(__NAMESPACE__ . '\\AvdbLanguage') && isset($GLOBALS['config']['site'])) {
            try {
                $offered = AvdbLanguage::offeredCodes();
                $default = AvdbLanguage::defaultLocale();
            } catch (\Throwable $e) {
                $offered = $all;
            }
        }
        if (class_exists(__NAMESPACE__ . '\\StorageGuard')) {
            try {
                $s = StorageGuard::status();
                if (!empty($s['limit_mb']) && isset($s['db_mb']) && $s['db_mb'] !== null && $s['db_mb'] > 0.5 * $s['limit_mb']) {
                    $offered = [$default];
                }
            } catch (\Throwable $e) {
            }
        }
        return array_values(array_intersect($all, array_merge(['en'], $offered)));
    }

    private static function storageFull(): bool
    {
        if (!class_exists(__NAMESPACE__ . '\\StorageGuard')) {
            return false;
        }
        try {
            return StorageGuard::isFull();
        } catch (\Throwable $e) {
            return false;
        }
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
        return 150000;
    }

    private static function tier(): int
    {
        if (class_exists(__NAMESPACE__ . '\\ScaleTier')) {
            try {
                return (int)(ScaleTier::get()['tier'] ?? 1);
            } catch (\Throwable $e) {
            }
        }
        return 1;
    }

    // ------------------------------------------------------------------ write path

    private static function cleanIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && (int)$id > 0 && (int)$id <= 4294967295) {
                $out[(int)$id] = true;
            }
        }
        return array_keys($out);
    }

    /** Queue ids whose searchable fields, vod_status or vod_recycle_time changed (one INSERT IGNORE per 500 ids). */
    public static function touch(array $ids): void
    {
        $ids = self::cleanIds($ids);
        if (!$ids) {
            return;
        }
        try {
            if (!self::has(self::T_QUEUE)) {
                return; // not installed yet: the backfill covers these videos
            }
            $now = time();
            foreach (array_chunk($ids, 500) as $chunk) {
                $values = [];
                foreach ($chunk as $id) {
                    $values[] = '(' . $id . ',' . $now . ')';
                }
                self::x('INSERT IGNORE INTO ' . self::t(self::T_QUEUE) . ' (vod_id, queued_at) VALUES ' . implode(',', $values));
            }
        } catch (\Throwable $e) {
            // the queue is secondary: never turn a successful content write into an error; self-heal repairs it
            error_log('[FtIndex] touch failed: ' . substr($e->getMessage(), 0, 200));
        }
    }

    /** Queue ids of deleted videos; drain removes their docs (a missing or unpublished video is only deleted). */
    public static function forget(array $ids): void
    {
        self::touch($ids);
    }

    /** Inline drain after a single admin save (<= 20 ids, D-S6): touch + drain of exactly these ids' queue. */
    public static function touchNow(array $ids): void
    {
        $ids = self::cleanIds($ids);
        self::touch($ids);
        if ($ids && count($ids) <= self::INLINE_MAX) {
            try {
                self::drain(self::INLINE_MAX, 2);
            } catch (\Throwable $e) {
                error_log('[FtIndex] inline drain failed: ' . substr($e->getMessage(), 0, 200));
            }
        }
    }

    /**
     * Drain the queue: at most $limit ids, at most $budgetSec seconds; returns the number of ids processed. Does nothing
     * while StorageGuard::isFull() (C13) or when the lock is busy; skips ids inside an open bulk window; writes every id to
     * vod_ft_replay while a rebuild runs; counts deletes in stale_ops and old docs in out_of_order (D-S6).
     */
    public static function drain(int $limit = 500, int $budgetSec = 5): int
    {
        if (!self::installed() || self::storageFull()) {
            return 0;
        }
        $limit = max(1, min(10000, $limit));
        $deadline = microtime(true) + max(1, $budgetSec);
        if ((int)(self::state()['bulk_job'] ?? 0) > 0) {
            self::closeOrphanBulkWindow(); // import-r2 finding 8: a window no running / paused bulk job owns is closed
        }
        if (!self::getLock('vod_ft', 5)) {
            return 0;
        }
        $done = 0;
        try {
            $s = self::state(true);
            if (!$s) {
                return 0;
            }
            $bulkMin = (int)$s['bulk_job'];
            $rebuilding = (int)$s['rebuild_started_at'] > 0;
            $next = max(2, (int)$s['next_doc_id']);
            $stats = ['deleted' => 0, 'inserted' => 0, 'ooo' => 0];
            while ($done < $limit && microtime(true) < $deadline) {
                $take = min(100, $limit - $done);
                $where = $bulkMin > 0 ? ' WHERE vod_id < ' . $bulkMin : '';
                $ids = array_map('intval', array_column(self::q('SELECT vod_id FROM ' . self::t(self::T_QUEUE) . $where . ' ORDER BY queued_at, vod_id LIMIT ' . $take), 'vod_id'));
                if (!$ids) {
                    break;
                }
                $own = self::begin();
                try {
                    self::x('DELETE FROM ' . self::t(self::T_QUEUE) . ' WHERE vod_id IN (' . implode(',', $ids) . ')');
                    $r = self::applyDocs(self::T_FT, $ids, $next);
                    if ($rebuilding) {
                        $now = time();
                        self::x('INSERT IGNORE INTO ' . self::t(self::T_REPLAY) . ' (vod_id, queued_at) VALUES ' . implode(',', array_map(function ($id) use ($now) { return '(' . $id . ',' . $now . ')'; }, $ids)));
                    }
                    self::x('UPDATE ' . self::t(self::T_STATE) . ' SET docs = GREATEST(0, CAST(docs AS SIGNED) + :d), stale_ops = stale_ops + :s, out_of_order = out_of_order + :o, next_doc_id = :n WHERE id = 1',
                        [':d' => $r['inserted'] - $r['deleted'], ':s' => $r['deleted'], ':o' => $r['ooo'], ':n' => $next]);
                    self::commit($own);
                } catch (\Throwable $e) {
                    self::rollback($own);
                    if (class_exists(__NAMESPACE__ . '\\StorageGuard')) {
                        try {
                            StorageGuard::trip($e);
                        } catch (\Throwable $e2) {
                        }
                    }
                    throw $e;
                }
                foreach ($r as $k => $v) {
                    $stats[$k] += $v;
                }
                $done += count($ids);
            }
        } finally {
            self::releaseLock('vod_ft');
            self::$state = null;
        }
        if ($done) {
            self::checkTriggers();
        }
        if ($stats['deleted'] > 0) {
            self::deadPassAfterWrite();
        }
        return $done;
    }

    /**
     * Round-3 F-r3-1: after a write that deleted documents, fold the recorded deletes into the term map's dead chunks at
     * once (pages stay exact without the query-time pending check), when the map lock is free; bounded in time and never an
     * error for the writer (housekeeping's mapAppend folds whatever is left).
     */
    private static function deadPassAfterWrite(): void
    {
        try {
            if (self::has(self::T_MAPD) && self::mapUsable()) {
                self::mapDeadPass(1.0);
            }
        } catch (\Throwable $e) {
            error_log('[FtIndex] dead pass: ' . substr($e->getMessage(), 0, 200));
        }
    }

    /**
     * Rebuild the documents of $ids in $table: delete the old docs, insert fresh ones (published, not recycled) in
     * ascending vod_time, vod_id order with FTS_DOC_ID from $next. Returns counts deleted / inserted / ooo (docs whose
     * vod_time is more than one day older than the newest doc). The caller holds the write lock and the transaction.
     */
    private static function applyDocs(string $table, array $ids, &$next): array
    {
        $t = self::t($table);
        $in = implode(',', $ids);
        // round 6 (D-9): order / dictionary tracking of the D-1 tag route (vod_ft only, while a term build turned it on)
        $track = $table === self::T_FT ? self::tracking() : ['inv' => false, 'tok' => false];
        if ($table === self::T_FT && (self::has(self::T_MAPD) || $track['inv'])) {
            $old = self::q('SELECT FTS_DOC_ID d, ft_text t FROM ' . $t . ' WHERE vod_id IN (' . $in . ')');
            if (self::has(self::T_MAPD)) {
                // round-3 F-r3-1: the old documents of these videos leave the term map's rank space; their doc id and text go
                // to vod_ft_mapd in the same transaction as the delete (mapDeadPass folds them into the dead chunks of their terms)
                self::recordDead($old);
            }
            if ($track['inv'] && $old) {
                // an inverted document leaves vod_ft_inv with its document (same transaction; KEY doc)
                self::x('DELETE FROM ' . self::t(self::T_INV) . ' WHERE doc IN (' . implode(',', array_map(function ($r) { return (int)$r['d']; }, $old)) . ')');
            }
        }
        $deleted = self::x('DELETE FROM ' . $t . ' WHERE vod_id IN (' . $in . ')');
        $rows = self::q('SELECT vod_id, vod_name, vod_en, vod_sub, vod_remarks, vod_director, vod_tag, vod_class, vod_actor, vod_time, type_id, type_id_1'
            . ' FROM ' . self::t('vod') . ' WHERE vod_id IN (' . $in . ') AND vod_status = 1 AND vod_recycle_time = 0');
        if (!$rows) {
            return ['deleted' => $deleted, 'inserted' => 0, 'ooo' => 0];
        }
        usort($rows, function ($a, $b) {
            return [(int)$a['vod_time'], (int)$a['vod_id']] <=> [(int)$b['vod_time'], (int)$b['vod_id']];
        });
        $top = self::q('SELECT vod_time, vod_id FROM ' . $t . ' ORDER BY FTS_DOC_ID DESC LIMIT 1');
        $newest = $top ? (int)$top[0]['vod_time'] : 0;
        if ($table === self::T_FT && !$track['inv'] && $top && [max(0, (int)$rows[0]['vod_time']), (int)$rows[0]['vod_id']] < [$newest, (int)$top[0]['vod_id']]) {
            // round 5 (D-1): the first document of this batch sorts below the newest one (vod_time, vod_id): from its doc id
            // on, document order is no longer the plain LIKE's order (vod_time DESC, vod_id DESC); token pages check inv_doc
            // (FtPlan::orderOk). Same transaction as the insert; a table without the column (not upgraded yet) has no mark
            // to keep, and its token pages never claim that order (inv_doc missing = unknown). Round 6: with the order
            // tracking on, every such document is listed below instead (vod_ft_inv, against the tracked high-water key)
            self::markInversion((int)$next);
        }
        $titles = self::titles(array_map('intval', array_column($rows, 'vod_id')));
        $values = [];
        $bind = [];
        $ooo = 0;
        $i = 0;
        $hw = $track['inv'] ? $track['hw'] : null;
        $hw0 = $hw;
        $inverted = [];
        $toks = [];
        $cut = 0;
        foreach ($rows as $r) {
            $id = (int)$r['vod_id'];
            $time = max(0, (int)$r['vod_time']);
            if ($newest > 0 && $time < $newest - 86400) {
                $ooo++;
            }
            $newest = max($newest, $time);
            $doc = FtText::docFromRow($r, $titles[$id] ?? []);
            $values[] = '(' . (int)$next . ',' . $id . ',' . min($time, 4294967295) . ',' . max(0, min(65535, (int)$r['type_id'])) . ','
                . max(0, min(65535, (int)$r['type_id_1'])) . ',:d' . $i . ')';
            $bind[':d' . $i] = $doc;
            if ($track['inv'] || $track['tok']) {
                $tg = self::tgTokens($doc);
                if ($hw !== null) {
                    $key = [min($time, 4294967295), $id];
                    if ($key < $hw) {
                        $inverted[] = [(int)$next, $key[0], $id, $tg];
                    } else {
                        $hw = $key;
                    }
                }
                if ($track['tok']) {
                    foreach ($tg as $tok) {
                        $toks[$tok] = $toks[$tok] ?? (int)$next;
                    }
                    if (strlen($doc) >= FtText::DOC_MAX - 200 && $cut === 0) {
                        $cut = (int)$next; // cut at DOC_MAX: its field tokens, written last, may be missing
                    }
                }
            }
            $next++;
            $i++;
        }
        self::x('INSERT INTO ' . $t . ' (FTS_DOC_ID, vod_id, vod_time, type_id, type_id_1, ft_text) VALUES ' . implode(',', $values), $bind);
        if ($track['inv']) {
            self::recordInversions($inverted, $hw, $hw0);
        }
        if ($track['tok']) {
            self::recordNewTokens($toks, $cut);
        }
        return ['deleted' => $deleted, 'inserted' => count($rows), 'ooo' => $ooo];
    }

    /** Round 6 (D-9): the tg_ tokens of one document text (FtText::doc: space-separated tokens with a space at both ends). */
    private static function tgTokens(string $doc): array
    {
        if (!preg_match_all('/ (tg_[a-z0-9][a-z0-9_]*)(?= )/', ' ' . $doc . ' ', $m)) {
            return [];
        }
        return array_values(array_unique($m[1]));
    }

    /**
     * Round 6 (D-9): the route tracking switches of the write path, read from the state row of this transaction: 'inv' =
     * vod_ft_inv lists every inverted document (inv_ok = 1) and 'hw' = the high-water (vod_time, vod_id) key the next
     * document is compared with; 'tok' = vod_ft_newtok holds every tg_ token the term table lacks of the documents written
     * since the term build (tok_doc > 0).
     */
    private static function tracking(): array
    {
        $s = self::state(true);
        $inv = (int)($s['inv_ok'] ?? 0) === 1 && self::has(self::T_INV);
        $tok = (int)($s['tok_doc'] ?? 0) > 0 && self::has(self::T_NEWTOK);
        return ['inv' => $inv, 'hw' => [(int)($s['hw_time'] ?? 0), (int)($s['hw_id'] ?? 0)], 'tok' => $tok];
    }

    /**
     * Round 6 (D-9): inverted documents of one write ([doc, vod_time, vod_id, tg tokens] ...) into vod_ft_inv (one row per
     * tg_ token), the new high-water key and the order marks (inv_doc = the lowest such doc, inv_n counts them) in the
     * caller's transaction. More than INV_MAX x INV_ROW_FACTOR rows (the table statistics' count, approximate: a cap, not a
     * rule) turn the tracking off (inv_ok = 2) until a rebuild: the route keeps the r5 rules.
     */
    private static function recordInversions(array $inverted, array $hw, array $hw0): void
    {
        if ($inverted) {
            self::insertInvRows($inverted);
            self::markInversion((int)$inverted[0][0]);
            $rows = (int)self::v('SELECT COALESCE(TABLE_ROWS, 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                [':t' => self::prefix() . self::T_INV]);
            if ($rows > self::INV_MAX * self::INV_ROW_FACTOR) {
                self::x('UPDATE ' . self::t(self::T_STATE) . ' SET inv_ok = 2 WHERE id = 1');
                self::x('DELETE FROM ' . self::t(self::T_INV));
            }
        }
        if ($hw !== $hw0) {
            self::x('UPDATE ' . self::t(self::T_STATE) . ' SET hw_time = :t, hw_id = :i WHERE id = 1', [':t' => $hw[0], ':i' => $hw[1]]);
        }
        self::$state = null;
    }

    /**
     * Round 6 (D-9): the tg_ tokens of one write that the term table does not hold go into vod_ft_newtok (first document
     * that brought each); tok_n counts the additions (the route proof's cache key), cut_doc keeps the lowest document cut at
     * FtText::DOC_MAX since the term build (its field tokens may be missing). The caller's transaction.
     */
    private static function recordNewTokens(array $toks, int $cut): void
    {
        $add = [];
        foreach (array_chunk(array_keys($toks), 500) as $chunk) {
            $in = [];
            $bind = [];
            foreach ($chunk as $k => $tok) {
                $in[] = ':t' . $k;
                $bind[':t' . $k] = (string)$tok;
            }
            $have = [];
            foreach (self::q('SELECT term FROM ' . self::t(self::T_TERM) . ' WHERE term IN (' . implode(',', $in) . ')', $bind) as $r) {
                $have[(string)$r['term']] = true;
            }
            foreach ($chunk as $tok) {
                if (!isset($have[(string)$tok]) && strlen((string)$tok) <= 84) {
                    $add[(string)$tok] = (int)$toks[$tok];
                }
            }
        }
        $n = 0;
        foreach (array_chunk($add, 200, true) as $chunk) {
            $values = [];
            $bind = [];
            $k = 0;
            foreach ($chunk as $tok => $doc) {
                $values[] = '(:t' . $k . ',' . (int)$doc . ')';
                $bind[':t' . $k] = (string)$tok;
                $k++;
            }
            $n += self::x('INSERT IGNORE INTO ' . self::t(self::T_NEWTOK) . ' (term, doc) VALUES ' . implode(',', $values), $bind);
        }
        if ($n > 0 || $cut > 0) {
            self::x('UPDATE ' . self::t(self::T_STATE) . ' SET tok_n = tok_n + :n, cut_doc = IF(:c = 0, cut_doc, IF(cut_doc = 0, :c2, LEAST(cut_doc, :c3))) WHERE id = 1',
                [':n' => $n, ':c' => $cut, ':c2' => $cut, ':c3' => $cut]);
            self::$state = null;
        }
    }

    /**
     * Round 6 (D-9): what FtPlan's tag route needs of the tracking: null while it is off (an upgraded table before its
     * first term build, a rebuild's new table, more than INV_MAX inverted documents), else ['inv_n', 'tok_n', 'tok_doc',
     * 'cut_doc', 'hw' => [vod_time, vod_id]].
     */
    public static function routeTracking(): ?array
    {
        try {
            $s = self::state();
            if ((int)($s['inv_ok'] ?? 0) !== 1 || (int)($s['tok_doc'] ?? 0) <= 0 || !self::has(self::T_INV) || !self::has(self::T_NEWTOK)) {
                return null;
            }
            return ['inv_n' => (int)($s['inv_n'] ?? 0), 'tok_n' => (int)($s['tok_n'] ?? 0), 'tok_doc' => (int)$s['tok_doc'],
                'cut_doc' => (int)($s['cut_doc'] ?? 0), 'hw' => [(int)($s['hw_time'] ?? 0), (int)($s['hw_id'] ?? 0)]];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Round 6 (D-9): vod_ft_inv rows ([term, doc, vod_time, vod_id]) of inverted documents ([doc, vod_time, vod_id, tokens] ...). */
    private static function insertInvRows(array $inverted): void
    {
        $rows = [];
        foreach ($inverted as $v) {
            foreach ((array)$v[3] as $tok) {
                if (is_string($tok) && strlen($tok) <= 84) {
                    $rows[] = [$tok, (int)$v[0], (int)$v[1], (int)$v[2]];
                }
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $values = [];
            $bind = [];
            foreach ($chunk as $k => $r) {
                $values[] = '(:t' . $k . ',' . $r[1] . ',' . $r[2] . ',' . $r[3] . ')';
                $bind[':t' . $k] = $r[0];
            }
            self::x('INSERT INTO ' . self::t(self::T_INV) . ' (term, doc, vod_time, vod_id) VALUES ' . implode(',', $values)
                . ' ON DUPLICATE KEY UPDATE vod_time = VALUES(vod_time), vod_id = VALUES(vod_id)', $bind);
        }
    }

    /**
     * Round 6 (D-9): the inverted documents holding $token ([doc, vod_time, vod_id] ..., doc order), at most $max + 1 (more =
     * too many for one page), or null while the tracking is off. One primary-key range of vod_ft_inv.
     */
    public static function invDocs(string $token, int $max): ?array
    {
        if (!preg_match('/^tg_[a-z0-9_]{1,81}$/D', $token) || self::routeTracking() === null) {
            return null;
        }
        $out = [];
        foreach (self::q('SELECT doc, vod_time, vod_id FROM ' . self::t(self::T_INV) . ' WHERE term = :t ORDER BY doc LIMIT ' . ($max + 1), [':t' => $token]) as $r) {
            $out[] = [(int)$r['doc'], (int)$r['vod_time'], (int)$r['vod_id']];
        }
        return $out;
    }

    /**
     * Round 5 (D-1): a document inserted at doc id $doc sorts below an older one (vod_time, vod_id). inv_doc = the lowest such
     * doc id since the last checked build (0 = document order is the (vod_time, vod_id) order; 1 = not known), inv_n counts
     * the marks (terms() keeps a mark written while its scan ran). The caller holds the transaction of the insert. A table
     * without the columns (an older state table before ensureSchema ran) is left alone: its inv_doc is missing = unknown.
     */
    private static function markInversion(int $doc): void
    {
        try {
            self::x('UPDATE ' . self::t(self::T_STATE) . ' SET inv_doc = IF(inv_doc = 0, :d, LEAST(inv_doc, :d2)), inv_n = inv_n + 1 WHERE id = 1',
                [':d' => max(1, $doc), ':d2' => max(1, $doc)]);
            self::$state = null;
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), '1054') === false && stripos($e->getMessage(), 'Unknown column') === false) {
                throw $e;
            }
        }
    }

    /** Rows of vod_ft_mapd for deleted documents ([d => FTS_DOC_ID, t => ft_text], ...); 200 per statement, values bound. */
    private static function recordDead(array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            $values = [];
            $bind = [];
            foreach ($chunk as $i => $r) {
                $values[] = '(' . (int)$r['d'] . ',:t' . $i . ')';
                $bind[':t' . $i] = (string)$r['t'];
            }
            if ($values) {
                self::x('INSERT INTO ' . self::t(self::T_MAPD) . ' (doc, ft_text) VALUES ' . implode(',', $values), $bind);
            }
        }
    }

    /**
     * Translated titles of the configured languages (vod_name of avdb_translation payloads), keyed by vod id. Only the
     * title is read (JSON_EXTRACT; on engines without JSON functions the first 3,000 bytes of the payload), 200 ids per
     * query, so the 16-language payloads (descriptions included) never load into PHP.
     */
    private static function titles(array $ids): array
    {
        $langs = array_values(array_diff(self::langs(), ['en']));
        if (!$ids || !$langs || !self::has('avdb_translation')) {
            return [];
        }
        $bind = [];
        $in = [];
        foreach ($langs as $i => $l) {
            $in[] = ':l' . $i;
            $bind[':l' . $i] = $l;
        }
        $out = [];
        static $json = null;
        foreach (array_chunk(array_map('intval', $ids), 200) as $chunk) {
            $where = ' FROM ' . self::t('avdb_translation') . ' WHERE vod_id IN (' . implode(',', $chunk) . ') AND locale IN (' . implode(',', $in) . ')';
            $rows = null;
            if ($json !== false) {
                try {
                    $rows = self::q('SELECT vod_id, locale, JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.vod_name\')) n' . $where, $bind);
                    $json = true;
                } catch (\Throwable $e) {
                    $json = false;
                }
            }
            if ($json === false) {
                $rows = [];
                foreach (self::q('SELECT vod_id, locale, LEFT(payload, 3000) p' . $where, $bind) as $r) {
                    $n = null;
                    if (preg_match('/"vod_name"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/', (string)$r['p'], $m)) {
                        $n = json_decode($m[1]);
                    }
                    $rows[] = ['vod_id' => $r['vod_id'], 'locale' => $r['locale'], 'n' => is_string($n) ? $n : null];
                }
            }
            foreach ($rows as $r) {
                if (isset($r['n']) && is_string($r['n']) && $r['n'] !== '' && $r['n'] !== 'null') {
                    $out[(int)$r['vod_id']][(string)$r['locale']] = $r['n'];
                }
            }
        }
        return $out;
    }

    /** Set rebuild_due = $why when no reason is recorded yet ('bulk', 'undo', 'stale_ops', 'out_of_order', 'langs' ...). */
    public static function markRebuild(string $why): void
    {
        try {
            if (!self::has(self::T_STATE)) {
                return;
            }
            $why = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($why)), 0, 16);
            self::x("UPDATE " . self::t(self::T_STATE) . " SET rebuild_due = :w WHERE id = 1 AND rebuild_due = ''", [':w' => $why !== '' ? $why : 'manual']);
            self::$state = null;
        } catch (\Throwable $e) {
            error_log('[FtIndex] markRebuild failed: ' . substr($e->getMessage(), 0, 200));
        }
    }

    /**
     * Automatic rebuild triggers (D-S7): stale_ops > max(20,000, 2 % of docs); out_of_order > 5 % of the newest WALK_CAP
     * docs; documents built with other languages or another FtText version than configured now. Returns the reason set.
     */
    public static function checkTriggers(): string
    {
        $s = self::state(true);
        if (!$s || (int)$s['ready'] !== 1 || (string)$s['rebuild_due'] !== '') {
            return (string)($s['rebuild_due'] ?? '');
        }
        $docs = (int)$s['docs'];
        $why = '';
        if ((int)$s['stale_ops'] > max(20000, (int)ceil(0.02 * $docs))) {
            $why = 'stale_ops';
        } elseif ($docs > 0 && (int)$s['out_of_order'] > 0.05 * min(self::walkCap(), $docs)) {
            $why = 'out_of_order';
        } elseif ($docs > 0 && (string)$s['langs'] !== implode(',', self::langs())) {
            $why = 'langs';
        }
        if ($why !== '') {
            self::markRebuild($why);
        }
        return $why;
    }

    /**
     * True when the term statistics should be refreshed: docs grew > 5 % since docs_at_terms (or no statistics yet at
     * >= 1,000 docs), or the last refresh is older than a week (D-S7 "Term refresh").
     */
    public static function termsDue(): bool
    {
        $s = self::state(true);
        if (!$s || (int)$s['ready'] !== 1) {
            return false;
        }
        $docs = (int)$s['docs'];
        $at = (int)$s['docs_at_terms'];
        // round 5 (D-1): the document order was never checked by this version (upgraded table, or a table swapped in by a
        // rebuild whose terms phase did not run): the next term build checks it (1 = "not known"; a scan writes 0 or >= 3)
        if ($docs > 0 && array_key_exists('inv_doc', $s) && (int)$s['inv_doc'] === 1) {
            return true;
        }
        // round 6 (D-9): the tag route's order / dictionary tracking was never turned on (an upgraded table, a rebuild's new
        // table): the next term build turns it on (inv_ok 2 = too many inverted documents: off until a rebuild, not due)
        if ($docs > 0 && (int)($s['term_at'] ?? 0) > 0 && array_key_exists('inv_ok', $s) && (int)$s['inv_ok'] === 0
            && self::has(self::T_INV) && self::has(self::T_NEWTOK)) {
            return true;
        }
        if ($docs < self::TERM_MIN) {
            return false;
        }
        if ((int)$s['term_at'] === 0 || $docs > 1.05 * $at) {
            return true;
        }
        return time() - (int)$s['term_at'] > 7 * 86400;
    }

    /** True when the planner must treat words without statistics as common (stale rule, se-R7b, sc-S2c). */
    public static function termsStale(array $s = []): bool
    {
        $s = $s ?: self::state();
        $docs = (int)($s['docs'] ?? 0);
        if ($docs > 1.1 * (int)($s['docs_at_terms'] ?? 0)) {
            return true;
        }
        if ($docs > 20000 && (int)($s['term_at'] ?? 0) === 0) {
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------ bulk window (D-I5, sc-S5a)

    /**
     * Open a bulk window: drain skips vod ids >= $minVodId until bulkEnd(). The window start is stored in
     * vod_ft_state.bulk_job (the drain needs the id range, the job id is the importer's business; see CONTRACT note).
     */
    public static function bulkBegin(int $jobId, int $minVodId): void
    {
        if (!self::installed()) {
            return;
        }
        $min = max(1, $minVodId);
        self::x('UPDATE ' . self::t(self::T_STATE) . ' SET bulk_job = CASE WHEN bulk_job = 0 OR bulk_job > :m THEN :m2 ELSE bulk_job END WHERE id = 1', [':m' => $min, ':m2' => $min]);
        self::$state = null;
    }

    /**
     * Close the bulk window. When the rows were placed "as new arrivals" and the published rows of [min, max] are <= 10 %
     * of the docs, their docs are appended in ascending vod_time, vod_id order and 'appended' is returned; otherwise
     * rebuild_due='bulk' and 'rebuild_due'. Queue entries of the range are folded in (appended) or kept (rebuild).
     */
    public static function bulkEnd(int $jobId, int $minVodId, int $maxVodId, bool $asNewArrivals): string
    {
        if (!self::installed()) {
            return 'appended';
        }
        $min = max(1, $minVodId);
        $max = max($min, $maxVodId);
        self::x('UPDATE ' . self::t(self::T_STATE) . ' SET bulk_job = 0 WHERE id = 1');
        self::$state = null;
        $s = self::state(true);
        $n = (int)self::v('SELECT COUNT(*) FROM ' . self::t('vod') . ' WHERE vod_id BETWEEN ' . $min . ' AND ' . $max . ' AND vod_status = 1 AND vod_recycle_time = 0');
        if ($maxVodId < $minVodId || $n === 0) {
            return 'appended';
        }
        if (!$asNewArrivals || $n > 0.10 * (int)$s['docs']) {
            self::markRebuild('bulk');
            return 'rebuild_due';
        }
        if (self::storageFull()) {
            self::markRebuild('bulk');
            return 'rebuild_due';
        }
        // Round-2 verify fix R2-7a: the window's (vod_time, vod_id) pairs are read ONCE along the primary key (vod_id ranges
        // of BULK_SCAN ids, FORCE INDEX (PRIMARY)) and sorted in PHP as one integer each (vod_time << 32 | vod_id; 700 k
        // pairs ~ 11 MB). The per-batch "ORDER BY vod_time, vod_id LIMIT 1000" let MySQL 8.0 scan the whole
        // (vod_recycle_time, vod_time) index from vod_time 0 for every batch (~100 s per 1,000 docs at 8 M). The position of
        // the last committed batch is kept in runtime/ft/bulkend.json (job, range, cursor): a restarted runner resumes there
        // instead of re-appending the window from its start.
        $keys = [];
        for ($a = $min; $a <= $max; $a += self::BULK_SCAN) {
            $z = min($max, $a + self::BULK_SCAN - 1);
            foreach (self::q('SELECT vod_id, vod_time FROM ' . self::t('vod') . ' FORCE INDEX (PRIMARY) WHERE vod_id >= ' . $a . ' AND vod_id <= ' . $z
                . ' AND vod_status = 1 AND vod_recycle_time = 0') as $r) {
                $keys[] = (max(0, min(2147483647, (int)$r['vod_time'])) << 32) | ((int)$r['vod_id'] & 0xFFFFFFFF);
            }
        }
        sort($keys, SORT_NUMERIC);
        $resume = self::readJson('bulkend.json');
        $from = 0;
        if ((int)($resume['job'] ?? -1) === $jobId && (int)($resume['min'] ?? -1) === $min && (int)($resume['max'] ?? -1) === $max && isset($resume['key'])) {
            $done = (int)$resume['key'];
            // skip every pair at or below the last committed one (binary search)
            $lo = 0;
            $hi = count($keys);
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($keys[$mid] <= $done) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            $from = $lo;
        }
        for ($pos = $from; $pos < count($keys); $pos += self::BATCH) {
            $batch = array_slice($keys, $pos, self::BATCH);
            $ids = [];
            foreach ($batch as $k) {
                $ids[] = $k & 0xFFFFFFFF;
            }
            $lastKey = (int)end($batch);
            if (!self::getLock('vod_ft', 30)) {
                self::markRebuild('bulk');
                return 'rebuild_due';
            }
            try {
                $s = self::state(true);
                $next = max(2, (int)$s['next_doc_id']);
                $own = self::begin();
                try {
                    self::x('DELETE FROM ' . self::t(self::T_QUEUE) . ' WHERE vod_id IN (' . implode(',', $ids) . ')');
                    $r = self::applyDocs(self::T_FT, $ids, $next);
                    if ((int)$s['rebuild_started_at'] > 0) {
                        $now = time();
                        self::x('INSERT IGNORE INTO ' . self::t(self::T_REPLAY) . ' (vod_id, queued_at) VALUES ' . implode(',', array_map(function ($id) use ($now) { return '(' . $id . ',' . $now . ')'; }, $ids)));
                    }
                    self::x('UPDATE ' . self::t(self::T_STATE) . ' SET docs = GREATEST(0, CAST(docs AS SIGNED) + :d), stale_ops = stale_ops + :s, out_of_order = out_of_order + :o, next_doc_id = :n WHERE id = 1',
                        [':d' => $r['inserted'] - $r['deleted'], ':s' => $r['deleted'], ':o' => $r['ooo'], ':n' => $next]);
                    self::commit($own);
                } catch (\Throwable $e) {
                    self::rollback($own);
                    throw $e;
                }
            } finally {
                self::releaseLock('vod_ft');
                self::$state = null;
            }
            self::writeJson('bulkend.json', ['job' => $jobId, 'min' => $min, 'max' => $max, 'key' => $lastKey, 'at' => time()]);
        }
        self::removeJson('bulkend.json');
        self::deadPassAfterWrite();
        return 'appended';
    }

    /** bulkEnd: vod ids per primary-key range read of the window. */
    const BULK_SCAN = 50000;
    /** Import job states that keep a bulk window open (AvdbImport::RUN_STATES + PAUSE_STATES). */
    const BULK_OWNER_STATES = ['running', 'x_running', 'paused', 'x_paused'];
    /** An orphan window (no owning job) is closed when it was seen orphaned this long ago already (a starting job keeps it). */
    const BULK_ORPHAN_SEC = 120;
    /** An orphan window with more published rows than this (or above 10 % of the docs) is left to a rebuild. */
    const BULK_QUEUE_MAX = 200000;

    /**
     * Bulk-window safety net (import-r2 finding 8, HO-P-F2 (2)): vod_ft_state.bulk_job is set but no import job in
     * BULK_OWNER_STATES has options bulk:1 without _bulk_closed (a crashed runner, a job deleted by hand). Seen orphaned
     * twice, BULK_ORPHAN_SEC apart (runtime/ft/bulkorphan.json), the window is closed: bulk_job = 0 (only this value) and its
     * published ids are queued, so drain() makes them searchable; above BULK_QUEUE_MAX rows or 10 % of the docs:
     * rebuild_due = 'bulk' instead. Called by drain(), status(), housekeeping() and backfillStep().
     * Returns '' (no window) | 'owned' | 'orphan_seen' | 'closed' | 'rebuild_due'.
     */
    public static function closeOrphanBulkWindow(): string
    {
        try {
            $s = self::state(true);
            $min = (int)($s['bulk_job'] ?? 0);
            if ($min <= 0) {
                return '';
            }
            if (self::bulkWindowOwned()) {
                self::removeJson('bulkorphan.json');
                return 'owned';
            }
            $seen = self::readJson('bulkorphan.json');
            if ((int)($seen['bulk_job'] ?? -1) !== $min) {
                self::writeJson('bulkorphan.json', ['bulk_job' => $min, 'seen_at' => time()]);
                return 'orphan_seen';
            }
            if (time() - (int)($seen['seen_at'] ?? time()) < self::BULK_ORPHAN_SEC) {
                return 'orphan_seen';
            }
            $closed = self::x('UPDATE ' . self::t(self::T_STATE) . ' SET bulk_job = 0 WHERE id = 1 AND bulk_job = ' . $min);
            self::$state = null;
            self::removeJson('bulkorphan.json');
            if ($closed < 1) {
                return '';
            }
            $rows = (int)self::v('SELECT COUNT(*) FROM (SELECT vod_id FROM ' . self::t('vod') . ' WHERE vod_id >= ' . $min
                . ' AND vod_status = 1 AND vod_recycle_time = 0 LIMIT ' . (self::BULK_QUEUE_MAX + 1) . ') x');
            if ($rows > self::BULK_QUEUE_MAX || $rows > 0.10 * max(1, (int)$s['docs'])) {
                self::markRebuild('bulk');
                error_log('[FtIndex] orphan bulk window from vod id ' . $min . ' closed: rebuild due (' . $rows . ' rows)');
                return 'rebuild_due';
            }
            if ($rows > 0) {
                self::x('INSERT IGNORE INTO ' . self::t(self::T_QUEUE) . ' (vod_id, queued_at) SELECT vod_id, ' . time() . ' FROM ' . self::t('vod')
                    . ' WHERE vod_id >= ' . $min . ' AND vod_status = 1 AND vod_recycle_time = 0');
            }
            error_log('[FtIndex] orphan bulk window from vod id ' . $min . ' closed: ' . $rows . ' videos queued');
            return 'closed';
        } catch (\Throwable $e) {
            error_log('[FtIndex] orphan bulk window check failed: ' . substr($e->getMessage(), 0, 200));
            return '';
        }
    }

    /** True when an import job in BULK_OWNER_STATES runs in bulk mode with its window open (options bulk:1, no _bulk_closed). */
    private static function bulkWindowOwned(): bool
    {
        $in = "'" . implode("','", self::BULK_OWNER_STATES) . "'";
        try {
            $rows = self::q('SELECT options FROM ' . self::t('avdb_import_job') . ' WHERE state IN (' . $in . ')');
        } catch (\Throwable $e) {
            return false; // no importer table: nobody can own the window
        }
        foreach ($rows as $r) {
            $o = json_decode((string)$r['options'], true);
            if (is_array($o) && !empty($o['bulk']) && empty($o['_bulk_closed'])) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ self-heal and verify (D-S6)

    private static function runtimeDir(): string
    {
        if (self::$runtime === null) {
            $base = defined('RUNTIME_PATH') ? rtrim(RUNTIME_PATH, '/\\') : rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'javcms-ft-' . substr(md5(self::$dbName . '|' . self::$prefix), 0, 10);
            self::$runtime = $base;
        }
        $dir = self::$runtime . DIRECTORY_SEPARATOR . 'ft';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function readJson(string $name): array
    {
        $f = self::runtimeDir() . DIRECTORY_SEPARATOR . $name;
        $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        return is_array($d) ? $d : [];
    }

    private static function writeJson(string $name, array $data): void
    {
        $f = self::runtimeDir() . DIRECTORY_SEPARATOR . $name;
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data)) !== false) {
            @rename($tmp, $f);
        }
    }

    private static function removeJson(string $name): void
    {
        $f = self::runtimeDir() . DIRECTORY_SEPARATOR . $name;
        if (is_file($f)) {
            @unlink($f);
        }
    }

    /** Bucket numbers (vod_id div BUCKET) from MIN to MAX vod id of javcms_vod (two primary-key reads). */
    private static function bucketRange(): array
    {
        $r = self::q('SELECT MIN(vod_id) a, MAX(vod_id) b FROM ' . self::t('vod'));
        $f = self::q('SELECT MIN(vod_id) a, MAX(vod_id) b FROM ' . self::t(self::T_FT));
        $lo = min((int)($r[0]['a'] ?? 0) ?: PHP_INT_MAX, (int)($f[0]['a'] ?? 0) ?: PHP_INT_MAX);
        $hi = max((int)($r[0]['b'] ?? 0), (int)($f[0]['b'] ?? 0));
        if ($lo === PHP_INT_MAX || $hi < 1) {
            return [0, -1];
        }
        return [intdiv($lo, self::BUCKET), intdiv($hi, self::BUCKET)];
    }

    /**
     * Summary of bucket $b on javcms_vod (published) and on vod_ft: equal summaries = bucket in sync. $limitSec > 0: each of
     * the two statements runs under that statement limit (round-2 verify fix N17: a bucket at 7.1 M can need 5,000 random
     * reads per side; under a flood one run passed PHP's 50 s limit); a timeout is thrown (DbTimeout::is) to the caller.
     */
    private static function bucketDiffers(int $b, float $limitSec = 0.0): bool
    {
        $a = $b * self::BUCKET;
        $z = $a + self::BUCKET;
        $agg = 'COUNT(*) c, COALESCE(MAX(vod_time),0) m, COALESCE(SUM(vod_time),0) s, COALESCE(SUM(type_id),0) t';
        $v = self::q(self::limited('SELECT ' . $agg . ' FROM ' . self::t('vod') . ' WHERE vod_id >= ' . $a . ' AND vod_id < ' . $z . ' AND vod_status = 1 AND vod_recycle_time = 0', $limitSec));
        $f = self::q(self::limited('SELECT ' . $agg . ' FROM ' . self::t(self::T_FT) . ' WHERE vod_id >= ' . $a . ' AND vod_id < ' . $z, $limitSec));
        return array_map('strval', $v[0]) !== array_map('strval', $f[0]);
    }

    /** ['kind' => 'mariadb'|'mysql'|'', 'id' => 110405] of the search connection (memoized per connection). */
    private static $engineMemo = null;

    private static function engine(): array
    {
        if (self::$engineMemo === null) {
            try {
                $v = (string)self::v('SELECT VERSION()');
            } catch (\Throwable $e) {
                $v = '';
            }
            self::$engineMemo = class_exists(__NAMESPACE__ . '\\DbTimeout') ? DbTimeout::parseVersion($v)
                : (stripos($v, 'mariadb') !== false ? ['kind' => 'mariadb', 'id' => 100000] : ['kind' => '', 'id' => 0]);
        }
        return self::$engineMemo;
    }

    /**
     * $select (one SELECT statement) under a statement limit of $sec seconds (MariaDB >= 10.1.2 SET STATEMENT
     * max_statement_time, MySQL >= 5.7.8 MAX_EXECUTION_TIME hint); unchanged for $sec <= 0 or engines without a limit.
     * Round 8 (flood-r8 R8-1, orchestrator O-12): in a request with a database deadline (DbTimeout, the front end) the limit
     * is capped by the time this request has left (capSec()), so the statement ends by that deadline like every statement
     * under the session limit DbTimeout keeps: a statement's own limit overrides the session limit (with 0.3 s left,
     * Vod::textMatchIds() ran 0.35-1.25 s past the deadline; under the O-F1c flood 3 verified-crawler landings ended at
     * 4.01-4.39 s, over the statement limit + 1 s).
     */
    public static function limited(string $select, float $sec): string
    {
        if ($sec <= 0 || strncasecmp(ltrim($select), 'SELECT', 6) !== 0) {
            return $select;
        }
        $sec = self::capSec($sec);
        $eng = self::engine();
        if ($eng['kind'] === 'mariadb' && $eng['id'] >= 100102) {
            return 'SET STATEMENT max_statement_time=' . sprintf('%.2F', max(0.05, $sec)) . ' FOR ' . $select;
        }
        if ($eng['kind'] === 'mysql' && $eng['id'] >= 50708) {
            $select = ltrim($select);
            return 'SELECT /*+ MAX_EXECUTION_TIME(' . max(50, (int)round($sec * 1000)) . ') */' . substr($select, 6);
        }
        return $select;
    }

    /**
     * Round 8 (flood-r8 R8-1, orchestrator O-12): the statement limit for a statement that sets its own limit ($sec seconds:
     * limited(), FtPlan's recall MATCH), capped so that the statement, started now, ends by this request's database deadline
     * (DbTimeout::remaining(): the statement limit + DEADLINE_GRACE - RENDER_RESERVE from the request start, so the page or
     * its lighter page is still answered within the statement limit + 1 s): at most the time left - CAP_STEP, at least
     * MIN_CAP, the rule DbTimeout::beforeStatement() applies to the session limit. With the time spent the statement gets
     * MIN_CAP (DbTimeout::beforeStatement() refuses every framework statement then; this never throws: callers build the SQL
     * outside their try blocks). No deadline (command line, admin) or $sec <= 0: $sec as given. FtIndex on a connection of its
     * own (useConnection(): housekeeping's scale run, which may follow a visitor's page in the same request, the cron URL's
     * run, tools): $sec as given too, as before round 8; that connection works to its own budget and session limit
     * (ScaleSchema::capSession), never to the visitor's deadline (a self-heal slice capped by a spent visitor deadline would
     * time out bucket after bucket and skip them).
     */
    public static function capSec(float $sec): float
    {
        if ($sec <= 0 || !class_exists(__NAMESPACE__ . '\\DbTimeout') || !method_exists(__NAMESPACE__ . '\\DbTimeout', 'remaining')) {
            return $sec;
        }
        $left = DbTimeout::remaining();
        if ($left === null || (self::$givenPdo && !self::frameworkPdo(self::$pdo))) {
            return $sec;
        }
        $step = defined(__NAMESPACE__ . '\\DbTimeout::CAP_STEP') ? (float)DbTimeout::CAP_STEP : 0.25;
        $min = defined(__NAMESPACE__ . '\\DbTimeout::MIN_CAP') ? (float)DbTimeout::MIN_CAP : 0.1;
        return min($sec, max($min, floor(((float)$left - $step) * 100) / 100));
    }

    /**
     * Round 8 (capSec): true when $pdo is the framework's own open connection (the one DbTimeout governs). Only asked while a
     * request deadline exists and FtIndex was given a connection; never connects (getPdo() of a closed link is false), never
     * throws.
     */
    private static function frameworkPdo($pdo): bool
    {
        if (!$pdo instanceof \PDO || !class_exists('\think\Db', false)) {
            return false;
        }
        try {
            return \think\Db::connect()->getPdo() === $pdo;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * True for a statement stopped by its time limit (MariaDB 1969, MySQL 3024). Matched on the message only, so a
     * housekeeping slice (or Vod's time-bounded visitor count, isTimeout) that stops early never sets DbTimeout's
     * per-request flag (which turns a visitor page LITE).
     */
    public static function isTimeout(\Throwable $e): bool
    {
        return self::timedOut($e);
    }

    private static function timedOut(\Throwable $e): bool
    {
        for ($depth = 0; $e !== null && $depth < 4; $depth++, $e = $e->getPrevious()) {
            $msg = $e->getMessage();
            if (preg_match('/\b(1969|3024)\b/', $msg) || stripos($msg, 'max_statement_time') !== false
                || stripos($msg, 'maximum statement execution time') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function queueBucket(int $b): int
    {
        $a = $b * self::BUCKET;
        $z = $a + self::BUCKET;
        $now = time();
        $n = self::x('INSERT IGNORE INTO ' . self::t(self::T_QUEUE) . ' (vod_id, queued_at) SELECT vod_id, ' . $now . ' FROM ' . self::t('vod') . ' WHERE vod_id >= ' . $a . ' AND vod_id < ' . $z);
        $n += self::x('INSERT IGNORE INTO ' . self::t(self::T_QUEUE) . ' (vod_id, queued_at) SELECT vod_id, ' . $now . ' FROM ' . self::t(self::T_FT) . ' WHERE vod_id >= ' . $a . ' AND vod_id < ' . $z);
        return $n;
    }

    /**
     * Self-healing slice for housekeeping: compare $buckets buckets of 5,000 ids (rotating cursor) between javcms_vod and
     * vod_ft and queue every id of a bucket that differs. Buckets inside an open bulk window are skipped. Returns
     * ['checked' => n, 'differing' => k, 'queued' => q, 'next' => cursor, 'stopped' => '' | 'deadline' | 'statement_limit'].
     * Round-2 verify fix N17: $deadline (microtime, 0 = none) bounds the slice: no bucket starts with less than 0.5 s left,
     * each statement runs under min(HEAL_STMT_SEC, time left) and a bucket that times out stays the cursor (retried next
     * run; after three timeouts in a row it is skipped and counted in heal.json), so the caller's run always ends in time.
     */
    public static function selfHeal(int $buckets = self::HEAL_BUCKETS, float $deadline = 0.0): array
    {
        $out = ['checked' => 0, 'differing' => 0, 'queued' => 0, 'next' => 0, 'stopped' => ''];
        if (!self::installed() || !self::ready()) {
            return $out;
        }
        $s = self::state(true);
        if ((int)$s['rebuild_started_at'] > 0) {
            return $out; // the shadow build replaces the table; nothing to heal now
        }
        list($lo, $hi) = self::bucketRange();
        if ($hi < $lo) {
            return $out;
        }
        $bulkMin = (int)$s['bulk_job'];
        $cur = self::readJson('heal.json');
        $b = isset($cur['next']) ? (int)$cur['next'] : $lo;
        if ($b < $lo || $b > $hi) {
            $b = $lo;
        }
        $slow = isset($cur['slow_bucket'], $cur['slow_n']) && (int)$cur['slow_bucket'] === $b ? (int)$cur['slow_n'] : 0;
        $skipped = (int)($cur['skipped'] ?? 0);
        for ($i = 0; $i < max(1, $buckets) && $i <= $hi - $lo; $i++) {
            if (!($bulkMin > 0 && ($b + 1) * self::BUCKET > $bulkMin)) {
                $limit = 0.0;
                if ($deadline > 0) {
                    $left = $deadline - microtime(true);
                    if ($left < 0.5) {
                        $out['stopped'] = 'deadline';
                        break;
                    }
                    $limit = min(self::HEAL_STMT_SEC, max(0.2, $left - 0.2));
                }
                try {
                    $diff = self::bucketDiffers($b, $limit);
                } catch (\Throwable $e) {
                    if (!self::timedOut($e)) {
                        throw $e;
                    }
                    $out['stopped'] = 'statement_limit';
                    if (++$slow >= 3) {
                        $skipped++;
                        $slow = 0;
                        $b = $b >= $hi ? $lo : $b + 1; // a bucket that never fits the slice: skipped (verify --deep covers it)
                    }
                    break;
                }
                $slow = 0;
                $out['checked']++;
                if ($diff) {
                    $out['differing']++;
                    $out['queued'] += self::queueBucket($b);
                }
            }
            $b = $b >= $hi ? $lo : $b + 1;
        }
        $out['next'] = $b;
        self::writeJson('heal.json', ['next' => $b, 'at' => time(), 'slow_bucket' => $b, 'slow_n' => $slow, 'skipped' => $skipped]);
        if ($out['differing'] > 0) {
            // a doc changed behind the queue's back, so the docs counter drifted: recount now, under the write lock; the
            // drain of the queued buckets then applies exact deltas (each re-doc deletes the old docs and inserts the new).
            // With a deadline the recount runs under the time left (a timeout leaves the counter for the next slice)
            $left = $deadline > 0 ? $deadline - microtime(true) : 0.0;
            if ($deadline <= 0) {
                self::recountDocs();
            } elseif ($left >= 0.5) {
                try {
                    self::recountDocs($left - 0.2);
                } catch (\Throwable $e) {
                    if (!self::timedOut($e)) {
                        throw $e;
                    }
                    $out['recount'] = 'later';
                }
            } else {
                $out['recount'] = 'later';
            }
        }
        return $out;
    }

    /** docs = COUNT(*) of vod_ft, taken under the write lock (no drain in between); $limitSec > 0: under that statement limit. */
    public static function recountDocs(float $limitSec = 0.0): int
    {
        if (!self::getLock('vod_ft', $limitSec > 0 ? 1 : 5)) {
            return -1;
        }
        try {
            $n = (int)self::v(self::limited('SELECT COUNT(*) FROM ' . self::t(self::T_FT), $limitSec));
            self::setState(['docs' => $n]);
            return $n;
        } finally {
            self::releaseLock('vod_ft');
        }
    }

    /**
     * Verify every bucket. Plain: summary per bucket (count, newest time, time and category sums). $deep: also compares
     * every document with the document the current row would get (missing, extra, stale). $fix queues the differing ids.
     */
    public static function verify(bool $deep = false, bool $fix = false): array
    {
        $out = ['buckets' => 0, 'differing' => 0, 'missing' => 0, 'extra' => 0, 'stale' => 0, 'queued' => 0, 'docs' => 0, 'published' => 0, 'differing_buckets' => []];
        if (!self::installed()) {
            return $out + ['error' => 'not_installed'];
        }
        list($lo, $hi) = self::bucketRange();
        for ($b = $lo; $b <= $hi; $b++) {
            $out['buckets']++;
            $diff = self::bucketDiffers($b);
            if ($deep) {
                $a = $b * self::BUCKET;
                $z = $a + self::BUCKET;
                $rows = self::q('SELECT vod_id, vod_name, vod_en, vod_sub, vod_remarks, vod_director, vod_tag, vod_class, vod_actor, vod_time, type_id, type_id_1 FROM '
                    . self::t('vod') . ' WHERE vod_id >= ' . $a . ' AND vod_id < ' . $z . ' AND vod_status = 1 AND vod_recycle_time = 0');
                $docs = [];
                foreach (self::q('SELECT vod_id, vod_time, type_id, type_id_1, ft_text FROM ' . self::t(self::T_FT) . ' WHERE vod_id >= ' . $a . ' AND vod_id < ' . $z) as $d) {
                    $docs[(int)$d['vod_id']] = $d;
                }
                $out['docs'] += count($docs);
                $out['published'] += count($rows);
                $titles = self::titles(array_map('intval', array_column($rows, 'vod_id')));
                $bad = [];
                foreach ($rows as $r) {
                    $id = (int)$r['vod_id'];
                    if (!isset($docs[$id])) {
                        $out['missing']++;
                        $bad[] = $id;
                        continue;
                    }
                    $d = $docs[$id];
                    unset($docs[$id]);
                    if ((string)$d['ft_text'] !== FtText::docFromRow($r, $titles[$id] ?? []) || (int)$d['vod_time'] !== (int)$r['vod_time']
                        || (int)$d['type_id'] !== (int)$r['type_id'] || (int)$d['type_id_1'] !== (int)$r['type_id_1']) {
                        $out['stale']++;
                        $bad[] = $id;
                    }
                }
                $out['extra'] += count($docs);
                $bad = array_merge($bad, array_keys($docs));
                if ($bad) {
                    $diff = true;
                    if ($fix) {
                        self::touch($bad);
                        $out['queued'] += count($bad);
                    }
                }
            } elseif ($diff && $fix) {
                $out['queued'] += self::queueBucket($b);
            }
            if ($diff) {
                $out['differing']++;
                if (count($out['differing_buckets']) < 50) {
                    $out['differing_buckets'][] = $b;
                }
            }
        }
        if ($fix && $out['differing'] > 0) {
            self::recountDocs();
        }
        return $out;
    }

    // ------------------------------------------------------------------ term statistics (D-S7 --terms)

    /**
     * Refresh vod_ft_term: exact df of every token below TERM_EXACT_MAX docs, else from a 1-in-20 sample (vod_id % 20 = 7);
     * tokens with df >= TERM_MIN are stored; sets term_at and docs_at_terms; writes the table's text bytes per page read
     * (runtime/ft/ftsize.json, round-2 verify fix R2-2). Single flight with the other jobs unless the caller already holds
     * the job lock ($locked).
     * Round 5: field tokens (tg_ / ac_) are counted exactly over every document and kept from FIELD_TERM_MIN docs, every tg_
     * token whatever its df (FIELD_TERM_MIN); the same pass checks the document order: inv_doc = the first doc whose
     * (vod_time, vod_id) sorts below an earlier doc (0 = none), term_doc = the last doc read when the tg_ dictionary is
     * complete (exact field counts), else 0. A mark the write path adds while the scan runs (inv_n moved) is kept.
     */
    public static function terms(bool $locked = false): array
    {
        if (!self::installed()) {
            return ['error' => 'not_installed'];
        }
        if (!$locked && !self::getLock('ft_job', 0)) {
            return ['error' => 'busy'];
        }
        $t0 = microtime(true);
        try {
            $docs = (int)self::v('SELECT COUNT(*) FROM ' . self::t(self::T_FT));
            $exact = $docs <= (self::$termsExactMax !== null ? (int)self::$termsExactMax : self::TERM_EXACT_MAX);
            $s0 = self::state(true);
            $hasOrder = array_key_exists('inv_doc', $s0) && array_key_exists('inv_n', $s0) && array_key_exists('term_doc', $s0);
            // round 6 (D-9): the route tracking columns and tables (an older state table / no tables: tracking stays off)
            $canTrack = $hasOrder && array_key_exists('inv_ok', $s0) && array_key_exists('tok_doc', $s0) && self::has(self::T_INV) && self::has(self::T_NEWTOK);
            $invN0 = (int)($s0['inv_n'] ?? 0);
            $df = [];
            $fdf = [];
            // exact mode counts every token of every document already; above TERM_EXACT_MAX the field tokens get their own
            // exact count (memory guard: FIELD_EXACT_CAP tokens or 60 % of memory_limit, then the sample as before)
            $fieldExact = !$exact;
            $memCap = self::memoryCap();
            $seen = 0;
            $textBytes = 0;
            $last = 0;
            $prevKey = null;
            $inv = 0;
            $cutDocs = 0;
            $invList = [];   // round 6: every inverted document [doc, vod_time, vod_id, tg tokens] (at most INV_MAX + 1)
            while (true) {
                $rows = self::q('SELECT FTS_DOC_ID, vod_id, vod_time, ft_text FROM ' . self::t(self::T_FT) . ' WHERE FTS_DOC_ID > ' . $last . ' ORDER BY FTS_DOC_ID LIMIT 5000');
                if (!$rows) {
                    break;
                }
                foreach ($rows as $r) {
                    $key = [(int)$r['vod_time'], (int)$r['vod_id']];
                    $txt = (string)$r['ft_text'];
                    if ($prevKey !== null && $key < $prevKey) {
                        if ($inv === 0) {
                            $inv = (int)$r['FTS_DOC_ID'];
                        }
                        if ($canTrack && count($invList) <= self::INV_MAX) {
                            $invList[] = [(int)$r['FTS_DOC_ID'], $key[0], $key[1], self::tgTokens($txt)];
                        }
                    } else {
                        $prevKey = $key;
                    }
                    if (strlen($txt) >= FtText::DOC_MAX - 200) {
                        $cutDocs++; // cut at DOC_MAX (FtText::doc): its field tokens, written last, may be missing
                    }
                    if ($fieldExact && preg_match_all('/ ((?:tg|ac)_[a-z0-9][a-z0-9_]*)(?= )/', $txt, $fm)) {
                        foreach ($fm[1] as $ftok) {
                            $fdf[$ftok] = ($fdf[$ftok] ?? 0) + 1;
                        }
                    }
                    if (!$exact && ((int)$r['vod_id'] % self::TERM_SAMPLE) !== 7) {
                        continue;
                    }
                    $seen++;
                    $textBytes += strlen($txt);
                    foreach (explode(' ', trim($txt)) as $tok) {
                        if ($tok !== '') {
                            $df[$tok] = ($df[$tok] ?? 0) + 1;
                        }
                    }
                }
                if ($fieldExact && (count($fdf) > self::FIELD_EXACT_CAP || ($memCap > 0 && memory_get_usage() > $memCap))) {
                    $fieldExact = false; // too many distinct field tokens for this process: the sample decides for them too
                    $fdf = [];
                }
                $last = (int)end($rows)['FTS_DOC_ID'];
                if (count($rows) < 5000) {
                    break;
                }
            }
            $mult = $exact ? 1 : self::TERM_SAMPLE;
            $keep = []; // token => [df, df_sample]
            foreach ($df as $tok => $c) {
                if (strlen($tok) > 84) {
                    continue;
                }
                $field = preg_match('/^(?:tg|ac)_[a-z0-9]/', (string)$tok) === 1;
                if ($field && $fieldExact) {
                    continue; // exact count below
                }
                $d = $c * $mult;
                if ($d >= ($field ? self::FIELD_TERM_MIN : self::TERM_MIN) || ($field && $exact && strncmp((string)$tok, 'tg_', 3) === 0)) {
                    $keep[$tok] = [$d, $exact ? 0 : $c];
                }
            }
            unset($df);
            foreach ($fdf as $tok => $n) {
                if (strlen((string)$tok) <= 84 && ($n >= self::FIELD_TERM_MIN || strncmp((string)$tok, 'tg_', 3) === 0)) {
                    $keep[$tok] = [$n, 0];
                }
            }
            unset($fdf);
            // the tg_ dictionary is complete for every doc read when every field token was counted exactly and no document
            // was cut before its field tokens
            $termDoc = ($exact || $fieldExact) && $cutDocs === 0 ? $last : 0;
            // round 6 (D-9): the last phase holds the write lock (drains wait a moment), so the documents written while the scan
            // ran are read here with the scan's own high-water key: their inversions and the tg_ tokens the new term table
            // lacks, then the term table, vod_ft_inv, vod_ft_newtok and the state change together (one transaction)
            $writeLock = $canTrack && self::getLock('vod_ft', 30);
            $track = null;
            try {
                if ($writeLock) {
                    $hw = $prevKey ?? [0, 0];
                    $newTok = [];
                    $cutAfter = 0;
                    $from = $last;
                    while (true) {
                        $rows = self::q('SELECT FTS_DOC_ID, vod_id, vod_time, ft_text FROM ' . self::t(self::T_FT) . ' WHERE FTS_DOC_ID > ' . $from . ' ORDER BY FTS_DOC_ID LIMIT 5000');
                        foreach ($rows as $r) {
                            $key = [(int)$r['vod_time'], (int)$r['vod_id']];
                            $txt = (string)$r['ft_text'];
                            $tg = self::tgTokens($txt);
                            if ($key < $hw) {
                                if ($inv === 0) {
                                    $inv = (int)$r['FTS_DOC_ID'];
                                }
                                if (count($invList) <= self::INV_MAX) {
                                    $invList[] = [(int)$r['FTS_DOC_ID'], $key[0], $key[1], $tg];
                                }
                            } else {
                                $hw = $key;
                            }
                            foreach ($tg as $tok) {
                                if (!isset($keep[$tok]) && !isset($newTok[$tok]) && strlen($tok) <= 84) {
                                    $newTok[$tok] = (int)$r['FTS_DOC_ID'];
                                }
                            }
                            if (strlen($txt) >= FtText::DOC_MAX - 200 && $cutAfter === 0) {
                                $cutAfter = (int)$r['FTS_DOC_ID'];
                            }
                        }
                        if (count($rows) < 5000) {
                            break;
                        }
                        $from = (int)end($rows)['FTS_DOC_ID'];
                    }
                    $over = count($invList) > self::INV_MAX;
                    $track = ['inv_ok' => $over ? 2 : 1, 'hw' => $hw, 'inv' => $over ? [] : $invList, 'tok' => $newTok,
                        'tok_doc' => $termDoc > 0 ? $termDoc + 1 : 0, 'cut_doc' => $cutAfter];
                }
                $own = self::begin();
                try {
                    self::x('DELETE FROM ' . self::t(self::T_TERM));
                    foreach (array_chunk($keep, 500, true) as $chunk) {
                        $values = [];
                        $bind = [];
                        $i = 0;
                        foreach ($chunk as $tok => $c) {
                            $values[] = '(:t' . $i . ',' . (int)$c[0] . ',' . (int)$c[1] . ')';
                            $bind[':t' . $i] = (string)$tok;
                            $i++;
                        }
                        self::x('INSERT INTO ' . self::t(self::T_TERM) . ' (term, df, df_sample) VALUES ' . implode(',', $values), $bind);
                    }
                    self::x('UPDATE ' . self::t(self::T_STATE) . ' SET term_at = :a, docs_at_terms = :d, docs = :d2 WHERE id = 1', [':a' => time(), ':d' => $docs, ':d2' => $docs]);
                    if ($track !== null) {
                        // the write lock is held: this answer is complete (round 5's "a mark written meanwhile" cannot happen)
                        self::x('DELETE FROM ' . self::t(self::T_INV));
                        self::insertInvRows($track['inv']);
                        self::x('DELETE FROM ' . self::t(self::T_NEWTOK));
                        foreach (array_chunk($track['tok'], 200, true) as $chunk) {
                            $values = [];
                            $bind = [];
                            $k = 0;
                            foreach ($chunk as $tok => $doc) {
                                $values[] = '(:t' . $k . ',' . (int)$doc . ')';
                                $bind[':t' . $k] = (string)$tok;
                                $k++;
                            }
                            self::x('INSERT INTO ' . self::t(self::T_NEWTOK) . ' (term, doc) VALUES ' . implode(',', $values), $bind);
                        }
                        self::x('UPDATE ' . self::t(self::T_STATE) . ' SET inv_doc = :v, term_doc = :td, inv_ok = :ok, hw_time = :ht, hw_id = :hi, tok_doc = :tk,'
                            . ' tok_n = tok_n + 1, cut_doc = :cd WHERE id = 1',
                            [':v' => $inv, ':td' => $termDoc, ':ok' => $track['inv_ok'], ':ht' => $track['hw'][0], ':hi' => $track['hw'][1],
                             ':tk' => $track['tok_doc'], ':cd' => $track['cut_doc']]);
                    } elseif ($hasOrder) {
                        // inv_n unchanged since the scan started: this scan's answer; otherwise a mark written meanwhile stays
                        // (the lower doc id wins; 1 = "not known" stays until the next scan). Round 6: without the write lock the
                        // dictionary tracking is turned off (tokens of documents written during the scan may be in neither
                        // table); the order tracking keeps what the write path recorded meanwhile
                        self::x('UPDATE ' . self::t(self::T_STATE) . ' SET inv_doc = CASE WHEN inv_n = :n0 THEN :v WHEN :v2 = 0 THEN inv_doc WHEN inv_doc = 0 THEN :v3'
                            . ' ELSE LEAST(inv_doc, :v4) END, term_doc = :td WHERE id = 1',
                            [':n0' => $invN0, ':v' => $inv, ':v2' => $inv, ':v3' => $inv, ':v4' => $inv, ':td' => $termDoc]);
                        if (array_key_exists('tok_doc', $s0)) {
                            self::x('UPDATE ' . self::t(self::T_STATE) . ' SET tok_doc = 0 WHERE id = 1');
                        }
                    }
                    self::commit($own);
                } catch (\Throwable $e) {
                    self::rollback($own);
                    throw $e;
                }
            } finally {
                if ($writeLock) {
                    self::releaseLock('vod_ft');
                }
            }
            self::$state = null;
            $size = self::writeSizeStats($docs, $seen > 0 ? $textBytes / $seen : 0.0);
            return ['docs' => $docs, 'sampled' => $seen, 'exact' => $exact, 'field_exact' => $exact || $fieldExact, 'terms' => count($keep),
                'inv_doc' => $inv, 'term_doc' => $termDoc, 'cut_docs' => $cutDocs, 'seconds' => round(microtime(true) - $t0, 2), 'page_text_bytes' => (int)($size['page_text_bytes'] ?? 0),
                'route_tracking' => $track === null ? 'off' : ['inv_ok' => $track['inv_ok'], 'inverted' => count($track['inv']), 'new_tokens' => count($track['tok']), 'tok_doc' => $track['tok_doc']]];
        } finally {
            if (!$locked) {
                self::releaseLock('ft_job');
            }
        }
    }

    /** 60 % of this process's memory_limit in bytes (0 = no limit or unreadable): the exact field-count guard of terms(). */
    private static function memoryCap(): int
    {
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int)$v;
        $u = strtolower(substr($v, -1));
        $n *= $u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1));
        return $n > 0 ? (int)($n * 0.6) : 0;
    }

    private static $ptbMemo = null;

    /**
     * Round-2 verify fix R2-2: document text bytes per page read of vod_ft as this server stores it, from the table's
     * clustered size (DATA_LENGTH / docs), its page size (KEY_BLOCK_SIZE x 1 KB when COMPRESSED, innodb_page_size
     * otherwise: the uncompressed DYNAMIC shadow of MariaDB 10.6-10.10 among them) and the average document text of the
     * term scan. The planner prices its walk windows with it (FtPlan::pageTextBytes). Written to runtime/ft/ftsize.json.
     */
    public static function writeSizeStats(int $docs, float $avgText): array
    {
        try {
            if ($docs < 1000 || $avgText <= 0) {
                return [];
            }
            $r = self::q('SELECT ROW_FORMAT f, DATA_LENGTH d, CREATE_OPTIONS o FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                [':t' => self::prefix() . self::T_FT]);
            $data = (int)($r[0]['d'] ?? 0);
            if ($data <= 0) {
                return [];
            }
            $fmt = (string)($r[0]['f'] ?? '');
            if (strcasecmp($fmt, 'Compressed') === 0) {
                $page = 1024 * (preg_match('/key_block_size=(\d+)/i', (string)($r[0]['o'] ?? ''), $m) ? max(1, (int)$m[1]) : 8);
            } else {
                $page = (int)self::v('SELECT @@innodb_page_size');
                $page = $page >= 4096 ? $page : 16384;
            }
            $perDoc = $data / $docs;
            $out = ['page_text_bytes' => (int)round($page * $avgText / max(1.0, $perDoc)), 'page' => $page, 'disk_per_doc' => round($perDoc, 1),
                'avg_text' => round($avgText, 1), 'row_format' => $fmt, 'docs' => $docs, 'at' => time()];
            self::writeJson('ftsize.json', $out);
            self::$ptbMemo = null;
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Round-3 R3-1: search documents per page of the table as stored (ftsize.json of the last term build: page size / disk
     * bytes per doc); 0.0 when unknown.
     */
    public static function docsPerPage(): float
    {
        $d = self::readJson('ftsize.json');
        $page = (float)($d['page'] ?? 0);
        $per = (float)($d['disk_per_doc'] ?? 0);
        // a table measured before its statistics caught up (DATA_LENGTH of a fresh table) reads as thousands of docs per page:
        // no page holds more than 400 search documents (40-byte rows)
        return $page >= 1024 && $per > 0 ? max(1.0, min(400.0, $page / $per)) : 0.0;
    }

    /** Text bytes per page read of the last term build (ftsize.json); 0.0 when unknown (the planner keeps its constant). */
    public static function pageTextBytes(): float
    {
        if (self::$ptbMemo === null) {
            $d = self::readJson('ftsize.json');
            $v = (float)($d['page_text_bytes'] ?? 0);
            self::$ptbMemo = $v >= 2000 && $v <= 60000 ? $v : 0.0;
        }
        return self::$ptbMemo;
    }

    /**
     * Daily disk probe (sc-S11): 200 random primary-key reads in the older half of vod_ft; stores ms_per_read. Skipped
     * below 1,000 docs or when the last probe is younger than 20 h (unless $force). Returns the stored value or null.
     */
    public static function probeReadSpeed(bool $force = false): ?float
    {
        if (!self::installed()) {
            return null;
        }
        $last = self::readJson('probe.json');
        if (!$force && isset($last['at']) && time() - (int)$last['at'] < 72000) {
            return isset($last['ms']) ? (float)$last['ms'] : null;
        }
        $r = self::q('SELECT MIN(FTS_DOC_ID) a, MAX(FTS_DOC_ID) b, COUNT(*) c FROM (SELECT FTS_DOC_ID FROM ' . self::t(self::T_FT) . ' ORDER BY FTS_DOC_ID LIMIT 1000) x');
        $docs = (int)(self::state(true)['docs'] ?? 0);
        if ((int)($r[0]['c'] ?? 0) < 1000 || $docs < 1000) {
            return null;
        }
        $lo = (int)self::v('SELECT MIN(FTS_DOC_ID) FROM ' . self::t(self::T_FT));
        $hi = (int)self::v('SELECT MAX(FTS_DOC_ID) FROM ' . self::t(self::T_FT));
        $mid = $lo + intdiv(max(0, $hi - $lo), 2);
        $st = self::pdo()->prepare('SELECT FTS_DOC_ID, vod_time FROM ' . self::t(self::T_FT) . ' WHERE FTS_DOC_ID >= :id ORDER BY FTS_DOC_ID LIMIT 1');
        $t0 = microtime(true);
        for ($i = 0; $i < 200; $i++) {
            $st->execute([':id' => mt_rand($lo, max($lo, $mid))]);
            $st->fetchAll();
        }
        $ms = (microtime(true) - $t0) * 1000 / 200;
        $ms = round(max(0.01, min(99.999, $ms)), 3);
        self::setState(['ms_per_read' => $ms]);
        self::writeJson('probe.json', ['at' => time(), 'ms' => $ms]);
        return $ms;
    }

    // ------------------------------------------------------------------ rebuild protocol (D-S7, sc-S5b)

    /**
     * Free space for a rebuild (sc-S6): needs free disk >= 1.2 x the table size plus the FULLTEXT sort files (about the
     * data size) in @@tmpdir / @@innodb_tmpdir. Free disk is read where the database server is local (datadir visible
     * from PHP), else from StorageGuard; unknown free space does not block.
     */
    public static function spaceCheck(): array
    {
        $size = self::q('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH),0) total, COALESCE(SUM(DATA_LENGTH),0) data FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            [':t' => self::prefix() . self::T_FT]);
        $total = (int)$size[0]['total'];
        $data = (int)$size[0]['data'];
        $published = (int)self::v('SELECT COUNT(*) FROM ' . self::t('vod') . ' WHERE vod_status = 1 AND vod_recycle_time = 0');
        $estimate = (int)($published * 600 * (1 + 0.5 * (count(self::langs()) - 1)));
        if (self::zipFtAlterUnsafe()) {
            // MariaDB 10.6-10.10: the shadow table is built uncompressed (ROW_FORMAT=DYNAMIC, F7): ~1.6x the compressed size
            $estimate = (int)($estimate * 1.6);
        }
        $total = max($total, $estimate);
        $data = max($data, (int)($estimate * 0.7));
        $need = (int)ceil((1.2 * $total + $data) / 1048576);
        $free = null;
        $tmpFree = null;
        try {
            $vars = self::q("SELECT @@datadir d, @@tmpdir t");
            $dd = (string)$vars[0]['d'];
            $td = (string)$vars[0]['t'];
            if ($dd !== '' && @is_dir($dd)) {
                $f = @disk_free_space($dd);
                $free = $f === false ? null : (int)floor($f / 1048576);
            }
            if ($td !== '' && @is_dir($td)) {
                $f = @disk_free_space($td);
                $tmpFree = $f === false ? null : (int)floor($f / 1048576);
            }
        } catch (\Throwable $e) {
        }
        if ($free === null && class_exists(__NAMESPACE__ . '\\StorageGuard')) {
            try {
                $sg = StorageGuard::status();
                $free = $sg['free_disk_mb'] ?? null;
                $tmpFree = $tmpFree ?? ($sg['tmp_free_mb'] ?? null);
            } catch (\Throwable $e) {
            }
        }
        $ok = ($free === null || $free >= $need) && ($tmpFree === null || $tmpFree >= (int)ceil($data / 1048576));
        return ['ok' => $ok, 'need_mb' => $need, 'free_mb' => $free, 'tmp_free_mb' => $tmpFree, 'table_mb' => (int)ceil($total / 1048576)];
    }

    /** Running rebuild / backfill job (from the job file), or [] when none. */
    public static function job(): array
    {
        return self::readJson('job.json');
    }

    /**
     * Backfill (empty table) or rebuild: shadow table {pre}vod_ft_new built in vod_time, vod_id order, replay of the ids
     * written meanwhile, atomic RENAME swap, replay of the last ids under the write lock, old table dropped, terms
     * refreshed, ready=1. Options: 'web' (true = one step of WEB_STEP_SEC; table created with its FULLTEXT key; tiers
     * T1/T2 only), 'budget' (seconds per web step), 'kind' ('backfill' | 'rebuild'), 'restart' (drop an abandoned job),
     * 'progress' (callable(array) for the CLI), 'skip_space' (tests only).
     * Returns ['status' => 'done'|'running'|'refused', 'reason' => ..., 'phase' => ..., 'done' => n, 'total' => n, ...].
     */
    public static function rebuild(array $o = []): array
    {
        $web = !empty($o['web']);
        if (!self::installed()) {
            return ['status' => 'refused', 'reason' => 'not_installed'];
        }
        // S7 (XVID-NOLIMIT-BRIEF L10): web steps at every tier; T3 sends a site with cron / command-line runs to the CLI
        // (faster), a site without one builds in resumable web steps (the admin page, and housekeeping continues them)
        if ($web && self::tier() >= 3 && self::cliFresh()) {
            return ['status' => 'refused', 'reason' => 'cli_only', 'command' => 'php tools/search-index.php --rebuild'];
        }
        if (!self::getLock('ft_job', 0)) {
            return ['status' => 'refused', 'reason' => 'busy'];
        }
        $t0 = microtime(true);
        $budget = $web ? max(1, (int)($o['budget'] ?? self::WEB_STEP_SEC)) : PHP_INT_MAX;
        $progress = isset($o['progress']) && is_callable($o['progress']) ? $o['progress'] : null;
        $throttle = $web ? 0.0 : max(0.0, min(4.0, (float)($o['throttle'] ?? self::THROTTLE)));
        $job = [];
        try {
            $job = self::job();
            $s = self::state(true);
            if ($job && ((int)($s['rebuild_started_at'] ?? 0) > 0 || ($job['phase'] ?? '') === 'terms')) {
                $stale = time() - (int)($job['step_at'] ?? 0) > self::JOB_STALE_SEC;
                if (!empty($o['restart'])) {
                    $job = [];
                } elseif (empty($job['web']) && ($job['phase'] ?? '') === 'swap' && self::has(self::T_NEW)) {
                    // a CLI job that stopped at the swap (lock / rename error after its retries): the shadow table is complete,
                    // resume at the swap (replay under the lock, RENAME) instead of building it again
                    $job['resumed'] = (int)($job['resumed'] ?? 0) + 1;
                } elseif (empty($job['web'])) {
                    // a CLI job holds the job lock for its whole run; we hold it now, so its process is gone: start over
                    $job = [];
                } elseif (!$web && !$stale) {
                    return ['status' => 'refused', 'reason' => 'busy', 'phase' => $job['phase'] ?? ''];
                } elseif (!$web && $stale) {
                    $job = [];
                }
            } else {
                $job = [];
            }
            if (!$job && !empty($o['continue'])) {
                // a web progress loop asks for the next step of a job that has finished (or never started): nothing to do
                return ['status' => 'idle'];
            }
            if (!$job) {
                if (empty($o['skip_space'])) {
                    $space = self::spaceCheck();
                    if (!$space['ok']) {
                        return ['status' => 'refused', 'reason' => 'space'] + $space;
                    }
                }
                $mode = (string)($s['mode'] ?? 'ft');
                self::x('DELETE FROM ' . self::t(self::T_REPLAY));
                self::setState(['rebuild_started_at' => time()]);
                // MariaDB 10.6-10.10: ALTER TABLE ... ADD FULLTEXT on a ROW_FORMAT=COMPRESSED table kills the server (InnoDB
                // assertion page0zip.cc:761, MDEV-29976 family; measured on 10.6.9, 10.7.5, 10.8.4; not on 10.3, 10.5, 10.11,
                // 11.4, MySQL). There the shadow table is built WITHOUT compression (ROW_FORMAT=DYNAMIC, ladder variant 2) in
                // the normal order: fill first, ADD FULLTEXT after (verify fix F7: the FULLTEXT-first order left 6.0 GB of
                // fragmented FTS tables at 7.1 M and took 11,204 s). Only when that table cannot be made: FULLTEXT first.
                $ftFirst = false;
                $info = null;
                if (!$web && self::zipFtAlterUnsafe()) {
                    try {
                        $info = self::createDocTable(self::T_NEW, false, 2);
                    } catch (\Throwable $e) {
                        $info = null;
                        $ftFirst = true;
                    }
                }
                if ($info === null) {
                    $info = self::createDocTable(self::T_NEW, $web || $ftFirst);
                }
                if ($info['mode'] === 'walk') {
                    $mode = 'walk';
                }
                $docs0 = (int)$s['docs'];
                $total = (int)self::v('SELECT COUNT(*) FROM ' . self::t('vod') . ' WHERE vod_status = 1 AND vod_recycle_time = 0');
                $job = ['kind' => (string)($o['kind'] ?? ($docs0 > 0 ? 'rebuild' : 'backfill')), 'started' => time(), 'step_at' => time(),
                    'phase' => 'fill', 'cursor_t' => -1, 'cursor_id' => 0, 'next' => 2, 'done' => 0, 'total' => $total,
                    'web' => $web, 'mode' => $mode, 'variant' => $info['variant'], 'langs' => implode(',', self::langs()), 'ft_first' => $ftFirst];
                self::writeJson('job.json', $job);
            }
            if (!empty($job['langs'])) {
                self::$langsFixed = explode(',', (string)$job['langs']);
            }
            while (true) {
                if ($job['phase'] === 'fill') {
                    $finished = self::fillStep($job, $t0, $budget, $progress, $throttle);
                    $job['step_at'] = time();
                    self::writeJson('job.json', $job);
                    if (!$finished) {
                        return ['status' => 'running'] + self::jobSummary($job);
                    }
                    $job['phase'] = (!$job['web'] && $job['mode'] === 'ft' && empty($job['ft_first'])) ? 'fulltext' : 'replay';
                    self::writeJson('job.json', $job);
                }
                if ($job['phase'] === 'fulltext') {
                    if ($progress) {
                        $progress(['phase' => 'fulltext'] + self::jobSummary($job));
                    }
                    self::x('ALTER TABLE ' . self::t(self::T_NEW) . ' ADD FULLTEXT KEY `ft` (`ft_text`)');
                    $job['phase'] = 'replay';
                    $job['step_at'] = time();
                    self::writeJson('job.json', $job);
                    if ($web && microtime(true) - $t0 > $budget) {
                        return ['status' => 'running'] + self::jobSummary($job);
                    }
                }
                if ($job['phase'] === 'replay') {
                    while (true) {
                        $tb = microtime(true);
                        $n = self::replayBatch($job);
                        if ($n < self::BATCH) {
                            break;
                        }
                        self::throttle($throttle, microtime(true) - $tb);
                        if ($web && microtime(true) - $t0 > $budget) {
                            $job['step_at'] = time();
                            self::writeJson('job.json', $job);
                            return ['status' => 'running'] + self::jobSummary($job);
                        }
                    }
                    $job['phase'] = 'swap';
                    self::writeJson('job.json', $job);
                }
                if ($job['phase'] === 'swap') {
                    if (!self::getLock('vod_ft', 30)) {
                        return ['status' => 'running', 'reason' => 'lock_wait'] + self::jobSummary($job);
                    }
                    try {
                        while (self::replayBatch($job) > 0) {
                        }
                        self::x('DROP TABLE IF EXISTS ' . self::t(self::T_OLD));
                        self::swapTables($job, $web);
                        self::$tables = null;
                        $docs = (int)self::v('SELECT COUNT(*) FROM ' . self::t(self::T_FT));
                        // round 5 (D-1): the new table's order is checked by the terms phase below (replayed rows may sort
                        // below the shadow's newest); until then "not known"
                        $orderMark = array_key_exists('inv_doc', self::state(true)) ? ['inv_doc' => 1, 'term_doc' => 0] : [];
                        // round 6 (D-9): the route tracking of the old table is meaningless for the new one (other doc ids): off
                        // until the terms phase turns it on again
                        if (array_key_exists('inv_ok', self::state())) {
                            $orderMark += ['inv_ok' => 0, 'hw_time' => 0, 'hw_id' => 0, 'tok_doc' => 0, 'cut_doc' => 0];
                            foreach ([self::T_INV, self::T_NEWTOK] as $tt) {
                                if (self::has($tt)) {
                                    self::x('DELETE FROM ' . self::t($tt));
                                }
                            }
                        }
                        self::setState(['rebuild_started_at' => 0, 'docs' => $docs, 'stale_ops' => 0, 'out_of_order' => 0, 'built_at' => time(),
                            'rebuild_due' => '', 'langs' => $job['langs'], 'mode' => $job['mode'], 'next_doc_id' => (int)$job['next']] + $orderMark);
                        self::x('DELETE FROM ' . self::t(self::T_REPLAY));
                        if (self::has(self::T_MAPD)) {
                            // round-3 F-r3-1: a new search table makes every recorded doc id meaningless (the map is rebuilt)
                            self::x('DELETE FROM ' . self::t(self::T_MAPD));
                        }
                    } finally {
                        self::releaseLock('vod_ft');
                    }
                    self::x('DROP TABLE IF EXISTS ' . self::t(self::T_OLD));
                    $job['phase'] = 'terms';
                    $job['step_at'] = time();
                    self::writeJson('job.json', $job);
                    if ($web && microtime(true) - $t0 > $budget) {
                        return ['status' => 'running'] + self::jobSummary($job);
                    }
                }
                if ($job['phase'] === 'terms') {
                    $terms = self::terms(true);
                    self::setState(['ready' => 1]);
                    self::removeJson('job.json');
                    self::$tables = null;
                    return ['status' => 'done', 'kind' => $job['kind'], 'docs' => (int)(self::state(true)['docs'] ?? 0), 'terms' => $terms,
                        'seconds' => round(microtime(true) - $t0, 1), 'mode' => $job['mode']] + self::jobSummary($job);
                }
                return ['status' => 'refused', 'reason' => 'bad_job'];
            }
        } catch (\Throwable $e) {
            if (!$web && ($job['phase'] ?? '') !== 'swap') {
                // CLI: leave nothing half-built (the live table was never touched before the swap)
                self::abandonJob();
            } elseif (!$web) {
                // swap-phase error after the retries: the shadow table is complete and job.json stays at phase swap, so the
                // next --rebuild / --auto resumes at the swap in seconds (verify fix F10: 612 s of fill had been thrown away)
                $job['step_at'] = time();
                $job['swap_error'] = substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
                self::writeJson('job.json', $job);
            }
            throw $e;
        } finally {
            self::$langsFixed = null;
            self::releaseLock('ft_job');
            self::$state = null;
            self::$mapMemo = null;
        }
    }

    /**
     * Drop a rebuild that will not finish: shadow table, replay rows, rebuild_started_at, job file. The live table is
     * untouched (the swap is the last step). Called on a CLI failure and by housekeeping for an abandoned web job.
     */
    public static function abandonJob(): void
    {
        try {
            self::x('DROP TABLE IF EXISTS ' . self::t(self::T_NEW));
            self::x('DELETE FROM ' . self::t(self::T_REPLAY));
            self::setState(['rebuild_started_at' => 0]);
        } catch (\Throwable $e) {
            error_log('[FtIndex] abandonJob: ' . substr($e->getMessage(), 0, 200));
        }
        self::removeJson('job.json');
        self::$tables = null;
    }

    /** Sleep $share x $seconds (CLI rebuild throttle, at most 2 s per batch). */
    private static function throttle(float $share, float $seconds): void
    {
        if ($share > 0 && $seconds > 0) {
            usleep((int)min(2000000, $seconds * $share * 1000000));
        }
    }

    /** True for the errors a RENAME of the swap may meet under load: lock wait, deadlock, rename / engine errors. */
    private static function swapRetryable(\Throwable $e): bool
    {
        $code = 0;
        if ($e instanceof \PDOException && is_array($e->errorInfo)) {
            $code = (int)($e->errorInfo[1] ?? 0);
        }
        return in_array($code, [1205, 1213, 1025, 1030], true) || preg_match('/\b(1205|1213|1025)\b|errno: ?168|Lock wait|Deadlock/i', $e->getMessage()) === 1;
    }

    /**
     * RENAME vod_ft -> vod_ft_old, vod_ft_new -> vod_ft (atomic), with SWAP_LOCK_WAIT as the metadata-lock wait of each
     * attempt and SWAP_RETRY waits between attempts on a retryable error (CLI). The last error is re-thrown (the caller
     * keeps the job at phase swap).
     */
    private static function swapTables(array &$job, bool $web): void
    {
        $sql = 'RENAME TABLE ' . self::t(self::T_FT) . ' TO ' . self::t(self::T_OLD) . ', ' . self::t(self::T_NEW) . ' TO ' . self::t(self::T_FT);
        $prev = null;
        try {
            $prev = self::v('SELECT @@SESSION.lock_wait_timeout');
            self::x('SET SESSION lock_wait_timeout = ' . self::SWAP_LOCK_WAIT);
        } catch (\Throwable $e) {
            $prev = null;
        }
        $waits = $web ? [] : self::SWAP_RETRY;
        try {
            for ($attempt = 0; ; $attempt++) {
                try {
                    self::x($sql);
                    return;
                } catch (\Throwable $e) {
                    if ($attempt >= count($waits) || !self::swapRetryable($e)) {
                        throw $e;
                    }
                    $job['swap_retries'] = (int)($job['swap_retries'] ?? 0) + 1;
                    error_log('[FtIndex] swap: RENAME failed (' . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 120) . '), retry in ' . $waits[$attempt] . ' s');
                    sleep($waits[$attempt]);
                    // RENAME is atomic: a done swap shows vod_ft_old and no vod_ft_new
                    self::$tables = null;
                    if (!self::has(self::T_NEW) && self::has(self::T_FT) && self::has(self::T_OLD)) {
                        return;
                    }
                }
            }
        } finally {
            if ($prev !== null && (int)$prev > 0) {
                try {
                    self::x('SET SESSION lock_wait_timeout = ' . (int)$prev);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    /** Housekeeping: a web rebuild whose last step is older than 2 x JOB_STALE_SEC and whose lock is free is dropped. */
    private static function abandonStaleJob(): bool
    {
        $s = self::state(true);
        if ((int)($s['rebuild_started_at'] ?? 0) === 0) {
            return false;
        }
        $job = self::job();
        $last = (int)($job['step_at'] ?? $s['rebuild_started_at']);
        if (time() - $last <= 2 * self::JOB_STALE_SEC || !self::getLock('ft_job', 0)) {
            return false;
        }
        try {
            self::abandonJob();
        } finally {
            self::releaseLock('ft_job');
        }
        return true;
    }

    private static function jobSummary(array $job): array
    {
        $total = max(1, (int)$job['total']);
        return ['kind' => $job['kind'] ?? '', 'phase' => $job['phase'], 'done' => (int)$job['done'], 'total' => (int)$job['total'],
            'pct' => min(100, round(100 * (int)$job['done'] / $total, 1))];
    }

    /** Fill the shadow table from javcms_vod in vod_time, vod_id order (keyset). Returns true when no row is left. */
    private static function fillStep(array &$job, float $t0, int $budget, $progress, float $throttle = 0.0): bool
    {
        $t = self::t(self::T_NEW);
        $batches = 0;
        while (true) {
            $tb = microtime(true);
            $ct = (int)$job['cursor_t'];
            $ci = (int)$job['cursor_id'];
            $rows = self::q('SELECT vod_id, vod_name, vod_en, vod_sub, vod_remarks, vod_director, vod_tag, vod_class, vod_actor, vod_time, type_id, type_id_1 FROM '
                . self::t('vod') . ' WHERE vod_status = 1 AND vod_recycle_time = 0 AND vod_time >= ' . max(0, $ct)
                . ' AND NOT (vod_time = ' . $ct . ' AND vod_id <= ' . $ci . ') ORDER BY vod_time, vod_id LIMIT ' . self::BATCH);
            if (!$rows) {
                return true;
            }
            // a row edited during the build (vod_time moved past the cursor) comes back here a second time: its doc is
            // already in the shadow table and the edit is in vod_ft_replay, which re-docs it; skip it here
            $have = [];
            foreach (self::q('SELECT vod_id FROM ' . $t . ' WHERE vod_id IN (' . implode(',', array_map('intval', array_column($rows, 'vod_id'))) . ')') as $h) {
                $have[(int)$h['vod_id']] = true;
            }
            $titles = self::titles(array_map('intval', array_column($rows, 'vod_id')));
            $values = [];
            $bind = [];
            $i = 0;
            foreach ($rows as $r) {
                if (isset($have[(int)$r['vod_id']])) {
                    continue;
                }
                $values[] = '(' . (int)$job['next'] . ',' . (int)$r['vod_id'] . ',' . max(0, min(4294967295, (int)$r['vod_time'])) . ','
                    . max(0, min(65535, (int)$r['type_id'])) . ',' . max(0, min(65535, (int)$r['type_id_1'])) . ',:d' . $i . ')';
                $bind[':d' . $i] = FtText::docFromRow($r, $titles[(int)$r['vod_id']] ?? []);
                $job['next']++;
                $i++;
            }
            if ($values) {
                self::x('INSERT INTO ' . $t . ' (FTS_DOC_ID, vod_id, vod_time, type_id, type_id_1, ft_text) VALUES ' . implode(',', $values), $bind);
            }
            $last = end($rows);
            $job['cursor_t'] = (int)$last['vod_time'];
            $job['cursor_id'] = (int)$last['vod_id'];
            $job['done'] += count($values);
            $batches++;
            if ($batches % 20 === 0) {
                $job['step_at'] = time();
                self::writeJson('job.json', $job);
                if ($progress) {
                    $progress(self::jobSummary($job));
                }
            }
            if (count($rows) < self::BATCH) {
                return true;
            }
            if (microtime(true) - $t0 > $budget) {
                return false;
            }
            self::throttle($throttle, microtime(true) - $tb);
        }
    }

    /** Replay up to BATCH ids of vod_ft_replay into the shadow table. Returns the number of ids taken. */
    private static function replayBatch(array &$job): int
    {
        $ids = array_map('intval', array_column(self::q('SELECT vod_id FROM ' . self::t(self::T_REPLAY) . ' ORDER BY queued_at, vod_id LIMIT ' . self::BATCH), 'vod_id'));
        if (!$ids) {
            return 0;
        }
        $next = (int)$job['next'];
        $own = self::begin();
        try {
            self::x('DELETE FROM ' . self::t(self::T_REPLAY) . ' WHERE vod_id IN (' . implode(',', $ids) . ')');
            self::applyDocs(self::T_NEW, $ids, $next);
            self::commit($own);
        } catch (\Throwable $e) {
            self::rollback($own);
            throw $e;
        }
        $job['next'] = $next;
        $job['replayed'] = (int)($job['replayed'] ?? 0) + count($ids);
        return count($ids);
    }

    /**
     * Opt-in FULLTEXT optimize (sc-S4b, CLI only): only when the database user may set innodb_optimize_fulltext_only
     * (global, root). OPTIMIZE TABLE in $passes bounded passes of innodb_ft_num_word_optimize words each.
     */
    public static function optimizeFt(int $passes = 10): array
    {
        if (PHP_SAPI !== 'cli') {
            return ['status' => 'refused', 'reason' => 'cli_only'];
        }
        if (!self::installed() || (self::state(true)['mode'] ?? '') !== 'ft') {
            return ['status' => 'refused', 'reason' => 'no_fulltext'];
        }
        if (!self::getLock('ft_job', 0)) {
            return ['status' => 'refused', 'reason' => 'busy'];
        }
        $t0 = microtime(true);
        try {
            $before = (int)self::v('SELECT @@GLOBAL.innodb_optimize_fulltext_only');
            try {
                self::x('SET GLOBAL innodb_optimize_fulltext_only = ON');
            } catch (\Throwable $e) {
                return ['status' => 'refused', 'reason' => 'no_privilege', 'error' => substr($e->getMessage(), 0, 160)];
            }
            try {
                for ($i = 0; $i < max(1, $passes); $i++) {
                    self::q('OPTIMIZE TABLE ' . self::t(self::T_FT));
                }
            } finally {
                self::x('SET GLOBAL innodb_optimize_fulltext_only = ' . ($before ? 'ON' : 'OFF'));
            }
            self::setState(['stale_ops' => 0]);
            return ['status' => 'done', 'passes' => max(1, $passes), 'seconds' => round(microtime(true) - $t0, 1)];
        } finally {
            self::releaseLock('ft_job');
        }
    }


    // ------------------------------------------------------------------ term map (XVID-NOLIMIT-BRIEF C2, C16)

    /**
     * Rows a term-map page reads at most around its ranks (R_MAX); a term below D_FULL = ceil(20 x N / R_MAX) documents keeps
     * its full list of FTS_DOC_IDs (kind 1), a more common one every step-th match (kind 2, step = floor(R_MAX x df / 2N)).
     */
    const MAP_R_MAX = 5000;
    /** packed postings per build partition (bytes; the PHP peak stays near 256 MB) */
    const MAP_PART_BYTES = 134217728;
    /** documents read per query of the build / append scan */
    const MAP_SCAN = 5000;
    /** a page counts the matches above a term's last_doc by LIKE while at most this many docs were added since; more = behind */
    const MAP_GAP_MAX = 20000;
    /** a build partition left this long is started over */
    const MAP_BUILD_STALE = 21600;
    /**
     * round 7 (engines-r7 NEW-3): a 'building' meta whose last partition ended at least this many seconds ago counts as a
     * running build only while the single-flight lock 'ft_map' is held (mapState()); the grace covers a budgeted builder
     * between two partitions (tools/search-index.php --map: mapBuild(60) calls in a row)
     */
    const MAP_LOCK_GRACE = 15;

    private static $mapMemo = null;
    private static $mapRMax = self::MAP_R_MAX;
    private static $tailChunk = self::TAIL_CHUNK;
    private static $tailMerge = self::TAIL_MERGE;
    private static $mapHead = self::MAP_HEAD;
    /** tests: smaller build partitions (several in a small fixture) and a smaller pending-delete limit */
    private static $mapPartBytes = self::MAP_PART_BYTES;
    private static $pendingMax = self::PENDING_MAX;

    /**
     * Tests only: a smaller R_MAX, so a small fixture holds both kinds of terms (D_FULL = ceil(20 x N / R_MAX)); smaller
     * append chunks / fold threshold / head, so a small fixture reaches them.
     */
    public static function mapTuning(int $rMax = self::MAP_R_MAX, int $tailChunk = self::TAIL_CHUNK, int $tailMerge = self::TAIL_MERGE, int $head = self::MAP_HEAD,
        int $partBytes = self::MAP_PART_BYTES, int $pendingMax = self::PENDING_MAX): void
    {
        self::$mapPartBytes = max(1, $partBytes);
        self::$pendingMax = max(0, $pendingMax);
        self::$mapRMax = max(1, $rMax);
        self::$tailChunk = max(1, $tailChunk);
        self::$tailMerge = max(1, $tailMerge);
        self::$mapHead = max(1, $head);
    }

    /** round-3 F-r3-1: dead chunks of a term in vod_ft_mapt start at this chunk number (appended chunks stay far below) */
    const DEAD_BASE = 40000;
    /** round-3 F-r3-1: pages subtract deletes not folded yet (LIKE over the pending rows) while at most this many wait */
    const PENDING_MAX = 20000;
    /** round-3 F-r3-1: delete-record rows folded per statement */
    const DEAD_SCAN = 5000;
    /** round-3 F-r3-1: a build partition waits at most this long (s) for the write lock before it takes its snapshot */
    const MAP_LOCK_WAIT = 30;
    /** chunk 0 of a kind-2 term in vod_ft_mapt: the exact doc ids of its newest MAP_HEAD ranks (round-2 verify fix F4 / G8) */
    const MAP_HEAD = 1000;
    /** exact doc ids per append chunk of vod_ft_mapt (4 KB rows: an append rewrites one small row, never the id list; N11) */
    const TAIL_CHUNK = 1024;
    /** a term with more append chunks than this is folded into its main row by mapAppend (one rewrite per ~49 k new docs) */
    const TAIL_MERGE = 48;

    /** append watermark of the map (runtime/ft/ftmap.json last_doc), memoized per request */
    private static $coverMemo = null;

    /**
     * Create {pre}vod_ft_map (compressed when the engine allows writes to compressed tables, else plain) and
     * {pre}vod_ft_mapt (head and append chunks) when missing. An install whose map predates vod_ft_mapt gets the new table
     * here (ensureSchema, mapBuild and mapAppend call it).
     */
    public static function ensureMap(): void
    {
        if (!self::has(self::T_MAP)) {
            $sql = self::schemaStatements()[self::T_MAP] ?? '';
            if ($sql === '') {
                throw new \RuntimeException('FtIndex: vod_ft_map DDL missing');
            }
            $roc = null;
            try {
                $roc = self::v('SELECT @@innodb_read_only_compressed');
            } catch (\Throwable $e) {
                $roc = null;
            }
            $ladder = [];
            if (!($roc !== null && in_array(strtoupper((string)$roc), ['1', 'ON'], true))) {
                $ladder[] = $sql;
            }
            $ladder[] = str_replace(' ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8', '', $sql);
            $t = self::t(self::T_MAP);
            $last = null;
            $made = false;
            foreach ($ladder as $ddl) {
                try {
                    self::x('DROP TABLE IF EXISTS ' . $t);
                    self::x($ddl);
                    self::x('INSERT INTO ' . $t . " (term, kind, df, step, last_doc, data) VALUES ('zzprobe', 1, 0, 0, 0, '')");
                    self::x("DELETE FROM " . $t . " WHERE term = 'zzprobe'");
                    $made = true;
                    break;
                } catch (\Throwable $e) {
                    $last = $e;
                }
            }
            self::$tables = null;
            if (!$made) {
                throw new \RuntimeException('FtIndex: vod_ft_map could not be created: ' . ($last ? substr($last->getMessage(), 0, 160) : ''));
            }
        }
        if (!self::has(self::T_MAPT)) {
            $sql = self::schemaStatements()[self::T_MAPT] ?? '';
            if ($sql === '') {
                throw new \RuntimeException('FtIndex: vod_ft_mapt DDL missing');
            }
            $t = self::t(self::T_MAPT);
            self::x($sql);
            self::x('INSERT INTO ' . $t . " (term, chunk, n, last_doc, data) VALUES ('zzprobe', 0, 0, 0, '')");
            self::x("DELETE FROM " . $t . " WHERE term = 'zzprobe'");
            self::$tables = null;
        }
        if (!self::has(self::T_MAPD)) {
            // round-3 F-r3-1: the write path records deleted documents here from now on (a map built before this table
            // existed has no record of earlier deletes: mapUsable() refuses it after the first delete, the next build fixes it)
            $sql = self::schemaStatements()[self::T_MAPD] ?? '';
            if ($sql === '') {
                throw new \RuntimeException('FtIndex: vod_ft_mapd DDL missing');
            }
            self::x($sql);
            self::$tables = null;
        }
    }

    /** Pure: D_FULL and the kind-2 step for a term of $df documents among $n. */
    public static function mapShape(int $df, int $n): array
    {
        $n = max(1, $n);
        $dFull = (int)max(1, ceil(20 * $n / self::$mapRMax));
        $kind = $df < $dFull ? 1 : 2;
        return ['d_full' => $dFull, 'kind' => $kind, 'step' => $kind === 2 ? (int)max(1, floor(self::$mapRMax * $df / (2 * $n))) : 0];
    }

    /** Pure: every step-th doc id of an ascending list (ranks 0, step, 2 x step, ...). */
    public static function mapSample(array $docs, int $step): array
    {
        $out = [];
        $step = max(1, $step);
        for ($i = 0, $c = count($docs); $i < $c; $i += $step) {
            $out[] = $docs[$i];
        }
        return $out;
    }

    private static function mapMeta(): array
    {
        return self::readJson('ftmap.json');
    }

    /**
     * True when the term map may serve pages: built against the current search table (its FTS_DOC_IDs; a rebuild of
     * vod_ft makes every stored doc id meaningless) and not being rebuilt after such a swap. Round-3 F-r3-1: a map built
     * before the dead-id record (meta dead_v) or whose record stopped (vod_ft_mapd gone) is exact only until the first
     * delete since its build: after it, false (the walk / candidates serve the pages and housekeeping rebuilds the map).
     * Memoized per request.
     */
    public static function mapUsable(): bool
    {
        if (self::$mapMemo !== null) {
            return self::$mapMemo;
        }
        try {
            $m = self::mapMeta();
            $s = self::state();
            $ft = (int)($s['built_at'] ?? -1);
            $ok = !empty($m['built_at']) && (int)($m['ft_built_at'] ?? -2) === $ft && self::has(self::T_MAP);
            if ($ok && !empty($m['building']) && (int)($m['building']['ft_built_at'] ?? $ft) !== $ft) {
                $ok = false;
            }
            if ($ok && !(!empty($m['dead_v']) && self::has(self::T_MAPD) && self::has(self::T_MAPT))
                && (int)($s['stale_ops'] ?? 0) > (int)($m['dead_stale'] ?? ($m['stale_at_build'] ?? 0))) {
                $ok = false;
            }
            return self::$mapMemo = $ok;
        } catch (\Throwable $e) {
            return self::$mapMemo = false;
        }
    }

    /**
     * The map's append watermark: every doc at or below it is in the map row (main row + append chunks) of every term that
     * has a row (the build's last doc, raised by each finished mapAppend). 0 when unknown. Memoized per request.
     */
    public static function mapCover(): int
    {
        if (self::$coverMemo === null) {
            try {
                $m = self::mapMeta();
                self::$coverMemo = max(0, (int)($m['last_doc'] ?? 0));
            } catch (\Throwable $e) {
                self::$coverMemo = 0;
            }
        }
        return self::$coverMemo;
    }

    /**
     * One term's map row or null: kind, df, step, last_doc, data (the main row: kind 1 every doc id up to last_doc, kind 2
     * every step-th); head (kind 2: packed exact doc ids of the newest MAP_HEAD of those df ranks, '' when not stored);
     * tail / tail_n (packed exact doc ids appended above last_doc, ascending; ranks df .. df + tail_n - 1); cover (every
     * matching doc at or below it is in df + tail: max of last_doc, the chunks' last_doc and the map's append watermark).
     * Round-3 F-r3-1: dead (packed, ascending, unique) = the documents of this rank space (main row, head, appended
     * chunks) the write path deleted since: the term's dead chunks plus the deletes not folded yet (a LIKE over the pending
     * vod_ft_mapd rows, only while some wait); dead_n; live_n = df + tail_n - dead_n (the exact number of live matches at
     * or below the term's own cover). Null also while more than PENDING_MAX deletes wait to be folded (pages fall back).
     * Two primary-key reads (main row, the term's chunk rows) and, while deletes wait, one range read of the pending rows.
     */
    public static function mapRow(string $term): ?array
    {
        if (!preg_match('/^[a-z0-9_]{1,84}$/D', $term)) {
            return null;
        }
        $r = self::q('SELECT kind, df, step, last_doc, data FROM ' . self::t(self::T_MAP) . ' WHERE term = :t', [':t' => $term]);
        if (!$r) {
            return null;
        }
        $row = ['kind' => (int)$r[0]['kind'], 'df' => (int)$r[0]['df'], 'step' => (int)$r[0]['step'], 'last_doc' => (int)$r[0]['last_doc'],
            'data' => (string)$r[0]['data'], 'head' => '', 'tail' => '', 'tail_n' => 0];
        $c = self::termChunks($term, $row['kind'], $row['last_doc']);
        $row['head'] = $c['head'];
        $row['tail'] = $c['tail'];
        $row['tail_n'] = intdiv(strlen($c['tail']), 4);
        $own = $c['cover'];
        $dead = $c['dead'];
        $m = self::mapMeta();
        if (!empty($m['dead_v']) && self::has(self::T_MAPD)) {
            $s = self::state();
            $floor = self::deadFloor($m, $term, $building);
            $pending = max(0, (int)($s['stale_ops'] ?? 0) - (int)($m['dead_stale'] ?? 0));
            if (!$building && $pending > self::$pendingMax) {
                return null; // a large write waits to be folded: the planner's other paths answer until mapAppend catches up
            }
            if ($building || $pending > 0) {
                // deletes recorded after the term's floor: each was alive when the row / chunks at or above it were written
                // (snapshot rule, mapAppend), so it belongs to this rank space exactly when it matches and lies at or below
                // the term's own cover
                $like = "ft_text LIKE '% " . str_replace('_', '!_', $term) . " %' ESCAPE '!'";
                foreach (self::q('SELECT doc FROM ' . self::t(self::T_MAPD) . ' WHERE seq > ' . (int)$floor . ' AND ' . $like) as $p) {
                    if ((int)$p['doc'] <= $own) {
                        $dead[] = (int)$p['doc'];
                    }
                }
            }
        }
        if ($dead) {
            $dead = array_values(array_unique($dead));
            sort($dead, SORT_NUMERIC);
        }
        $row['dead'] = $dead ? pack('V*', ...$dead) : '';
        $row['dead_n'] = count($dead);
        $row['live_n'] = max(0, $row['df'] + $row['tail_n'] - $row['dead_n']);
        $row['cover'] = max($own, self::mapCover());
        return $row;
    }

    /**
     * The chunk rows of a term: head (kind 2, of this main row only), tail (packed appended ids above $lastDoc, ascending),
     * cover (the term's own: max of $lastDoc and the appended chunks' last_doc), dead (int[]: its dead chunks, unordered,
     * may repeat after a crash-retry), deadRows (int: dead chunk rows), lastDead / lastDeadN (the highest dead chunk and its
     * size), tailChunks (int: appended chunk rows).
     */
    private static function termChunks(string $term, int $kind, int $lastDoc): array
    {
        $out = ['head' => '', 'tail' => '', 'cover' => $lastDoc, 'dead' => [], 'deadRows' => 0, 'lastDead' => 0, 'lastDeadN' => 0, 'tailChunks' => 0];
        if (!self::has(self::T_MAPT)) {
            return $out;
        }
        foreach (self::q('SELECT chunk, n, last_doc, data FROM ' . self::t(self::T_MAPT) . ' WHERE term = :t ORDER BY chunk', [':t' => $term]) as $c) {
            $data = (string)$c['data'];
            $ch = (int)$c['chunk'];
            if ($ch === 0) {
                // the head of this build only (a fold or build that rewrote the main row rewrote its head with the same last_doc)
                if ($kind === 2 && (int)$c['last_doc'] === $lastDoc && strlen($data) % 4 === 0) {
                    $out['head'] = $data;
                }
                continue;
            }
            if ($data === '' || strlen($data) % 4 !== 0) {
                continue;
            }
            if ($ch >= self::DEAD_BASE) {
                foreach (unpack('V*', $data) as $d) {
                    $out['dead'][] = (int)$d;
                }
                $out['deadRows']++;
                if ($ch > $out['lastDead']) {
                    $out['lastDead'] = $ch;
                    $out['lastDeadN'] = (int)$c['n'];
                }
                continue;
            }
            $out['tailChunks']++;
            $first = unpack('V', substr($data, 0, 4))[1];
            if ($first > $lastDoc) {
                $out['tail'] .= $data;
            } else {
                // a chunk written before the main row was rebuilt: only its ids above last_doc count
                foreach (array_values(unpack('V*', $data)) as $d) {
                    if ($d > $lastDoc) {
                        $out['tail'] .= pack('V', $d);
                    }
                }
            }
            $out['cover'] = max($out['cover'], (int)$c['last_doc']);
        }
        return $out;
    }

    /**
     * Round-3 F-r3-1: the vod_ft_mapd seq at or below which a term's deletes are already folded into its dead chunks (or were
     * never in its rank space): the map's dead_seq; while a build runs, the snapshot seq of the build partition that has
     * rewritten the term's row ($building = true then: the pending count of stale_ops does not apply).
     */
    private static function deadFloor(array $m, string $term, &$building = false): int
    {
        $building = false;
        $floor = (int)($m['dead_seq'] ?? 0);
        $b = isset($m['building']) && is_array($m['building']) ? $m['building'] : null;
        if ($b === null || empty($b['dead_v'])) {
            return $floor;
        }
        $building = true;
        $parts = isset($b['parts']) && is_array($b['parts']) ? $b['parts'] : [];
        $done = (int)($b['part'] ?? 0);
        $seqs = isset($b['seqs']) && is_array($b['seqs']) ? $b['seqs'] : [];
        // the partitions are consecutive ranges of the sorted term space: binary search on the first term of each
        $lo = 0;
        $hi = count($parts) - 1;
        $idx = -1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if (strcmp((string)$parts[$mid][0], $term) <= 0) {
                $idx = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        if ($idx >= 0 && $idx < $done && strcmp($term, (string)$parts[$idx][1]) <= 0 && isset($seqs[$idx])) {
            return (int)$seqs[$idx];
        }
        return $floor;
    }

    /**
     * C16 state: ready (built for the current search table, no rebuild running, not stale), building, pct, built_at, terms,
     * mb, stale (new term statistics since the build, or deletes above 1 % of the docs), usable (pages may use it).
     */
    public static function mapState(): array
    {
        try {
            if (!self::installed()) {
                return ['ready' => false, 'building' => false, 'state' => 'missing', 'pct' => 0.0];
            }
            self::$mapMemo = null;
            self::$coverMemo = null;
            $m = self::mapMeta();
            $s = self::state(true);
            $ft = (int)($s['built_at'] ?? 0);
            $b = isset($m['building']) && is_array($m['building']) ? $m['building'] : null;
            $building = $b !== null && (int)($b['ft_built_at'] ?? -1) === $ft && time() - (int)($b['step_at'] ?? 0) < self::MAP_BUILD_STALE;
            // round 7 (engines-r7 NEW-3): a build counts as running only while a builder holds the single-flight lock (or its
            // last partition ended a moment ago: a budgeted builder between two partitions); a 'building' meta without one is
            // a dead build (fatal error, kill, server restart): 'stale', so housekeeping and tools/upgrade.php resume it
            // (mapBuild() goes on from the meta) instead of waiting MAP_BUILD_STALE for it
            $dead = false;
            if ($building && time() - (int)($b['step_at'] ?? 0) >= self::MAP_LOCK_GRACE && self::lockHeld('ft_map') === false) {
                $building = false;
                $dead = true;
            }
            $usable = self::mapUsable();
            $docs = max(1, (int)($s['docs'] ?? 0));
            $stale = $usable && ($dead || (int)($s['term_at'] ?? 0) > (int)($m['term_at'] ?? 0) + 60
                || max(0, (int)($s['stale_ops'] ?? 0) - (int)($m['stale_at_build'] ?? 0)) > 0.01 * $docs);
            $ready = $usable && !$building && !$stale && (int)($s['ready'] ?? 0) === 1;
            $pct = $building ? round(100 * (int)$b['part'] / max(1, count((array)$b['parts'])), 1) : ($ready ? 100.0 : 0.0);
            // round-3 R3-5: 'mb' = what the build measured on disk (tablespace files of vod_ft_map + vod_ft_mapt, else the
            // larger of information_schema and the bytes it wrote: DATA_LENGTH alone leaves out off-page BLOB pages on MariaDB)
            return ['ready' => $ready, 'building' => $building, 'state' => $ready ? 'ready' : ($building ? 'building' : ($usable ? 'stale' : 'missing')),
                'pct' => $pct, 'built_at' => (int)($m['built_at'] ?? 0), 'terms' => (int)($m['terms'] ?? 0), 'mb' => round((int)($m['bytes'] ?? 0) / 1048576, 2),
                'mb_source' => (string)($m['bytes_source'] ?? ''),
                'stale' => $stale, 'usable' => $usable, 'last_doc' => (int)($m['last_doc'] ?? 0), 'docs' => (int)($m['docs'] ?? 0),
                'appended_at' => (int)($m['appended_at'] ?? 0),
                'dead' => ['tracked' => !empty($m['dead_v']), 'seq' => (int)($m['dead_seq'] ?? 0),
                    'pending' => !empty($m['dead_v']) ? max(0, (int)($s['stale_ops'] ?? 0) - (int)($m['dead_stale'] ?? 0)) : null,
                    'folded_at' => (int)($m['dead_at'] ?? 0)]];
        } catch (\Throwable $e) {
            return ['ready' => false, 'building' => false, 'state' => 'error', 'pct' => 0.0, 'error' => get_class($e)];
        }
    }

    /**
     * C16 build: every term of vod_ft_term (df >= TERM_MIN) gets its map row from ordered passes over vod_ft (FTS_DOC_ID up
     * to the newest doc at the start), the term space cut into partitions of MAP_PART_BYTES of postings (one pass each, so
     * PHP stays near 256 MB); rows written with REPLACE (each row is complete: the old map serves meanwhile when the search
     * table did not change), the partition's append chunks folded in (dropped) and the kind-2 heads written, terms that
     * left vod_ft_term removed at the end. Resumable between partitions ($budgetSec > 0: stop after the partition that
     * crosses it; the next call continues). Single flight. Needs ready = 1.
     * Returns state 'done' | 'building' (more partitions left) | 'busy' | 'not_ready', terms, seconds, mb.
     */
    public static function mapBuild(float $budgetSec = 0.0): array
    {
        if (!self::installed() || !self::ready()) {
            return ['state' => 'not_ready'];
        }
        self::ensureMap();
        if (!self::getLock('ft_map', 0)) {
            return ['state' => 'busy'];
        }
        $t0 = microtime(true);
        try {
            $s = self::state(true);
            $ft = (int)$s['built_at'];
            $m = self::mapMeta();
            $b = isset($m['building']) && is_array($m['building']) ? $m['building'] : null;
            // a partition list written by a build without snapshots (no dead_v) starts over: every partition needs its seq
            if ($b !== null && ((int)($b['ft_built_at'] ?? -1) !== $ft || time() - (int)($b['step_at'] ?? 0) > self::MAP_BUILD_STALE || empty($b['dead_v']))) {
                $b = null;
            }
            if ($b === null) {
                $n = max(1, (int)self::v('SELECT COUNT(*) FROM ' . self::t(self::T_FT)));
                $lastDoc = (int)self::v('SELECT COALESCE(MAX(FTS_DOC_ID), 0) FROM ' . self::t(self::T_FT));
                $dFull = self::mapShape(0, $n)['d_full'];
                // partitions over the sorted term space by the expected bytes of full lists (big terms keep a sample + a head)
                $parts = [];
                $cur = null;
                $bytes = 0;
                foreach (self::q('SELECT term, df FROM ' . self::t(self::T_TERM) . ' ORDER BY term') as $r) {
                    $term = (string)$r['term'];
                    $w = (int)$r['df'] < $dFull * 1.2 ? (int)$r['df'] * 4 + 64 : 256 + 8 * self::$mapHead;
                    if ($cur === null || $bytes + $w > self::$mapPartBytes) {
                        if ($cur !== null) {
                            $parts[] = $cur;
                        }
                        $cur = [$term, $term];
                        $bytes = 0;
                    }
                    $cur[1] = $term;
                    $bytes += $w;
                }
                if ($cur !== null) {
                    $parts[] = $cur;
                }
                // round-3 F-r3-1: dead_v = every partition reads vod_ft in a snapshot taken with the seq of the delete record
                // (seqs[part]): a delete recorded at or below it is not in that partition's rows, a later one is
                $b = ['ft_built_at' => $ft, 'parts' => $parts, 'part' => 0, 'n' => $n, 'last_doc' => $lastDoc, 'd_full' => $dFull,
                    'started' => time(), 'step_at' => time(), 'terms' => 0, 'bytes' => 0, 'stale_at_build' => (int)$s['stale_ops'], 'term_at' => (int)$s['term_at'],
                    'dead_v' => 1, 'seqs' => [], 'head_bytes' => 0];
                $m['building'] = $b;
                self::writeJson('ftmap.json', $m);
            }
            $ran = 0;
            while ($b['part'] < count($b['parts'])) {
                if ($budgetSec > 0 && $ran > 0 && microtime(true) - $t0 > $budgetSec) {
                    break; // the partition that crossed the budget is done; the next call continues (at least one per call)
                }
                $ran++;
                list($lo, $hi) = $b['parts'][$b['part']];
                $r = self::mapPartition((string)$lo, (string)$hi, (int)$b['n'], (int)$b['last_doc'], (int)$b['d_full']);
                $b['terms'] += $r['terms'];
                $b['bytes'] += $r['bytes'];
                $b['head_bytes'] = (int)($b['head_bytes'] ?? 0) + (int)($r['head_bytes'] ?? 0);
                $b['seqs'][$b['part']] = (int)$r['seq'];
                $b['part']++;
                $b['step_at'] = time();
                $m = self::mapMeta();
                $m['building'] = $b;
                self::writeJson('ftmap.json', $m);
            }
            if ($b['part'] < count($b['parts'])) {
                return ['state' => 'building', 'part' => $b['part'], 'parts' => count($b['parts']), 'pct' => round(100 * $b['part'] / max(1, count($b['parts'])), 1),
                    'seconds' => round(microtime(true) - $t0, 1)];
            }
            // terms that left the statistics leave the map (main rows and their chunks)
            self::x('DELETE m FROM ' . self::t(self::T_MAP) . ' m LEFT JOIN ' . self::t(self::T_TERM) . ' t ON t.term = m.term WHERE t.term IS NULL');
            if (self::has(self::T_MAPT)) {
                self::x('DELETE c FROM ' . self::t(self::T_MAPT) . ' c LEFT JOIN ' . self::t(self::T_MAP) . ' m ON m.term = c.term WHERE m.term IS NULL');
            }
            // round-3 F-r3-1: the deletes recorded while the partitions were read (above a partition's seq, at or below its
            // last doc) are documents of that partition's rows: folded into their terms' dead chunks; the record restarts
            $dead = self::deadAfterBuild($b);
            $size = self::mapBytes((int)$b['bytes'] + (int)($b['head_bytes'] ?? 0));
            $m = ['built_at' => time(), 'ft_built_at' => $ft, 'docs' => (int)$b['n'], 'last_doc' => (int)$b['last_doc'], 'terms' => (int)$b['terms'],
                'd_full' => (int)$b['d_full'], 'bytes' => $size['bytes'], 'bytes_source' => $size['source'], 'postings_bytes' => (int)$b['bytes'],
                'stale_at_build' => (int)$b['stale_at_build'], 'term_at' => (int)$b['term_at'], 'seconds' => time() - (int)$b['started'],
                'dead_v' => 1, 'dead_seq' => $dead['seq'], 'dead_stale' => $dead['stale'], 'dead_at' => time(), 'dead_build' => $dead['pairs']];
            self::writeJson('ftmap.json', $m);
            self::$mapMemo = null;
            self::$coverMemo = null;
            return ['state' => 'done', 'terms' => (int)$b['terms'], 'parts' => count($b['parts']), 'seconds' => round(microtime(true) - $t0, 1),
                'mb' => round($m['bytes'] / 1048576, 2), 'mb_source' => $size['source'], 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'dead_during_build' => $dead['pairs']];
        } finally {
            self::releaseLock('ft_map');
        }
    }

    /**
     * One build partition: terms lo..hi of vod_ft_term, one ordered pass over vod_ft (FTS_DOC_ID <= $lastDoc). Terms
     * expected below D_FULL collect every doc id (packed), the others every step-th (step from the expected df) and the
     * newest MAP_HEAD ids (head); a list that reaches D_FULL becomes kind 2. Written in this order, so a page between two
     * statements never misses a doc: the main rows (REPLACE, last_doc = $lastDoc: older append chunks fall below it), then
     * the partition's old chunks are dropped and the new heads inserted. Returns terms written, bytes of data.
     */
    private static function mapPartition(string $lo, string $hi, int $n, int $lastDoc, int $dFull): array
    {
        $set = [];
        $headMax = self::$mapHead * 4;
        foreach (self::q('SELECT term, df FROM ' . self::t(self::T_TERM) . ' WHERE term >= :a AND term <= :b', [':a' => $lo, ':b' => $hi]) as $r) {
            $df = (int)$r['df'];
            $big = $df >= $dFull * 1.2;
            $set[(string)$r['term']] = $big ? ['k' => 2, 'step' => self::mapShape($df, $n)['step'], 'c' => 0, 'buf' => '', 'head' => ''] : ['k' => 1, 'c' => 0, 'buf' => ''];
        }
        // round-3 F-r3-1: one consistent read of the partition, its seq taken under the write lock (snapshotBegin; a long
        // drain may hold that lock for its whole budget: up to 3 waits)
        $snap = null;
        for ($try = 0; $try < 3 && $snap === null; $try++) {
            $snap = self::snapshotBegin(self::MAP_LOCK_WAIT);
        }
        if ($snap === null) {
            throw new \RuntimeException('FtIndex: term map build: the search write lock stayed busy');
        }
        if (!$set) {
            self::snapshotEnd();
            return ['terms' => 0, 'bytes' => 0, 'seq' => $snap['seq'], 'head_bytes' => 0];
        }
        try {
            $from = 0;
            while ($from < $lastDoc) {
                $rows = self::q('SELECT FTS_DOC_ID, ft_text FROM ' . self::t(self::T_FT) . ' WHERE FTS_DOC_ID > ' . $from . ' AND FTS_DOC_ID <= ' . $lastDoc
                    . ' ORDER BY FTS_DOC_ID LIMIT ' . self::MAP_SCAN);
                if (!$rows) {
                    break;
                }
                foreach ($rows as $r) {
                    $doc = (int)$r['FTS_DOC_ID'];
                    $seen = [];
                    foreach (explode(' ', (string)$r['ft_text']) as $tok) {
                        if ($tok === '' || isset($seen[$tok]) || !isset($set[$tok])) {
                            continue;
                        }
                        $seen[$tok] = true;
                        $e = &$set[$tok];
                        $p = pack('V', $doc);
                        if ($e['k'] === 1) {
                            $e['buf'] .= $p;
                        } else {
                            if ($e['c'] % $e['step'] === 0) {
                                $e['buf'] .= $p;
                            }
                            $e['head'] .= $p;
                            if (strlen($e['head']) > 2 * $headMax) {
                                $e['head'] = substr($e['head'], -$headMax);
                            }
                        }
                        $e['c']++;
                        unset($e);
                    }
                }
                $from = (int)$rows[count($rows) - 1]['FTS_DOC_ID'];
                unset($rows);
            }
        } finally {
            self::snapshotEnd();
        }
        $batch = [];
        $batchBytes = 0;
        $heads = [];
        $terms = 0;
        $bytes = 0;
        $flush = function () use (&$batch, &$batchBytes) {
            if (!$batch) {
                return;
            }
            $values = [];
            $bind = [];
            foreach ($batch as $i => $row) {
                $values[] = '(:t' . $i . ',' . (int)$row[1] . ',' . (int)$row[2] . ',' . (int)$row[3] . ',' . (int)$row[4] . ',:d' . $i . ')';
                $bind[':t' . $i] = $row[0];
                $bind[':d' . $i] = $row[5];
            }
            self::x('REPLACE INTO ' . self::t(self::T_MAP) . ' (term, kind, df, step, last_doc, data) VALUES ' . implode(',', $values), $bind);
            $batch = [];
            $batchBytes = 0;
        };
        foreach ($set as $term => $e) {
            if ($e['c'] === 0) {
                continue;
            }
            $kind = $e['k'];
            $step = $e['step'] ?? 0;
            $data = $e['buf'];
            $head = $kind === 2 ? substr($e['head'], -$headMax) : '';
            if ($kind === 1 && $e['c'] >= $dFull) {
                $sh = self::mapShape($e['c'], $n);
                $step = $sh['step'];
                $all = array_values(unpack('V*', $data));
                $data = pack('V*', ...self::mapSample($all, $step));
                $head = pack('V*', ...array_slice($all, -self::$mapHead));
                $kind = 2;
                unset($all);
            }
            $batch[] = [(string)$term, $kind, $e['c'], $step, $lastDoc, $data];
            if ($kind === 2 && $head !== '') {
                $heads[] = [(string)$term, $head];
            }
            $batchBytes += strlen($data) + 100;
            $terms++;
            $bytes += strlen($data);
            unset($set[$term]);
            if ($batchBytes > 1048576 || count($batch) >= 200) {
                $flush();
            }
        }
        $flush();
        $headBytes = 0;
        if (self::has(self::T_MAPT)) {
            // the partition's old append, head and dead chunks go (its rows were just rewritten from the snapshot)
            self::x('DELETE FROM ' . self::t(self::T_MAPT) . ' WHERE term >= :a AND term <= :b', [':a' => $lo, ':b' => $hi]);
            foreach (array_chunk($heads, 200) as $chunk) {
                $values = [];
                $bind = [];
                foreach ($chunk as $i => $h) {
                    $values[] = '(:t' . $i . ',0,' . intdiv(strlen($h[1]), 4) . ',' . (int)$lastDoc . ',:d' . $i . ')';
                    $bind[':t' . $i] = $h[0];
                    $bind[':d' . $i] = $h[1];
                    $headBytes += strlen($h[1]);
                }
                self::x('INSERT INTO ' . self::t(self::T_MAPT) . ' (term, chunk, n, last_doc, data) VALUES ' . implode(',', $values), $bind);
            }
        }
        return ['terms' => $terms, 'bytes' => $bytes, 'seq' => $snap['seq'], 'head_bytes' => $headBytes];
    }

    // ------------------------------------------------------------------ round-3 F-r3-1: deletes inside the term map

    /**
     * A consistent read view that matches the delete record exactly: under the write lock (no drain / bulkEnd transaction
     * in flight) a REPEATABLE READ transaction starts and its first read (MAX(seq) of vod_ft_mapd, stale_ops) creates the
     * view. Every delete recorded at or below that seq is invisible to the view; every later delete is visible (its
     * document reads as alive). The lock is released at once; snapshotEnd() closes the view. Null when the lock stayed
     * busy for $wait seconds or a transaction is already open (no view then; the caller retries later).
     * Returns ['seq' => int, 'stale' => int].
     */
    private static function snapshotBegin(int $wait): ?array
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction() || !self::getLock('vod_ft', $wait)) {
            return null;
        }
        try {
            self::x('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $seq = self::has(self::T_MAPD) ? (int)self::v('SELECT COALESCE(MAX(seq), 0) FROM ' . self::t(self::T_MAPD)) : 0;
            $stale = (int)self::v('SELECT stale_ops FROM ' . self::t(self::T_STATE) . ' WHERE id = 1');
            return ['seq' => $seq, 'stale' => $stale];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            self::releaseLock('vod_ft');
        }
    }

    private static function snapshotEnd(): void
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    /**
     * Fold deleted documents (vod_ft_mapd rows: seq, doc, ft_text) into the dead chunks of the terms whose rank space holds
     * them: a term with a map row whose own cover (main last_doc, appended chunks' last_doc, read now: before any append
     * of the caller) is at or above the doc; with $floor (term => seq) only rows above the term's floor (a build
     * partition's snapshot seq). Appends to the term's last dead chunk (TAIL_CHUNK ids per row); a repeated fold after a
     * crash writes the ids again (readers take the set). Returns the (term, doc) pairs written.
     */
    private static function deadFold(array $rows, ?callable $floor = null): int
    {
        $byTerm = [];
        foreach ($rows as $r) {
            $doc = (int)$r['doc'];
            $seq = (int)$r['seq'];
            foreach (array_unique(explode(' ', (string)$r['ft_text'])) as $tok) {
                if ($tok !== '' && strlen($tok) <= 84) {
                    $byTerm[$tok][] = [$doc, $seq];
                }
            }
        }
        if (!$byTerm) {
            return 0;
        }
        $writes = [];
        $pairs = 0;
        foreach (array_chunk(array_keys($byTerm), 500) as $chunk) {
            $in = [];
            $bind = [];
            foreach ($chunk as $i => $tok) {
                $in[] = ':t' . $i;
                $bind[':t' . $i] = (string)$tok;
            }
            $cover = [];
            foreach (self::q('SELECT term, last_doc FROM ' . self::t(self::T_MAP) . ' WHERE term IN (' . implode(',', $in) . ')', $bind) as $row) {
                $cover[(string)$row['term']] = (int)$row['last_doc'];
            }
            if (!$cover) {
                continue;
            }
            $last = [];
            foreach (self::q('SELECT term, chunk, n, last_doc FROM ' . self::t(self::T_MAPT) . ' WHERE term IN (' . implode(',', $in) . ') AND chunk > 0', $bind) as $c) {
                $term = (string)$c['term'];
                if (!isset($cover[$term])) {
                    continue;
                }
                if ((int)$c['chunk'] < self::DEAD_BASE) {
                    $cover[$term] = max($cover[$term], (int)$c['last_doc']);
                } elseif ((int)$c['chunk'] > ($last[$term][0] ?? 0)) {
                    $last[$term] = [(int)$c['chunk'], (int)$c['n']];
                }
            }
            foreach ($cover as $term => $own) {
                $f = $floor !== null ? (int)$floor((string)$term) : -1;
                $docs = [];
                foreach ($byTerm[$term] as $p) {
                    if ($p[1] > $f && $p[0] <= $own) {
                        $docs[] = $p[0];
                    }
                }
                if (!$docs) {
                    continue;
                }
                sort($docs, SORT_NUMERIC);
                $pairs += count($docs);
                list($ch, $used) = $last[$term] ?? [self::DEAD_BASE, 0];
                while ($docs) {
                    if ($used >= self::$tailChunk) {
                        $ch++;
                        $used = 0;
                    }
                    $part = array_splice($docs, 0, self::$tailChunk - $used);
                    $writes[] = [(string)$term, $ch, count($part), (int)max($part), pack('V*', ...$part)];
                    $used += count($part);
                }
            }
        }
        self::writeChunks($writes, true);
        return $pairs;
    }

    /**
     * Upsert chunk rows of vod_ft_mapt ([term, chunk, n, last_doc, data], 200 per statement). Appended-id chunks ($dead
     * false) take the ids only when the row's last_doc is below them (a repeated append never adds the same docs twice);
     * dead chunks ($dead true) always take them (dead ids are not ascending across folds; readers take the set).
     */
    private static function writeChunks(array $writes, bool $dead): void
    {
        foreach (array_chunk($writes, 200) as $batch) {
            $values = [];
            $bind = [];
            foreach ($batch as $i => $w) {
                $values[] = '(:t' . $i . ',' . (int)$w[1] . ',' . (int)$w[2] . ',' . (int)$w[3] . ',:d' . $i . ')';
                $bind[':t' . $i] = $w[0];
                $bind[':d' . $i] = $w[4];
            }
            if (!$values) {
                continue;
            }
            $upd = $dead
                ? ' ON DUPLICATE KEY UPDATE data = CONCAT(data, VALUES(data)), n = n + VALUES(n), last_doc = GREATEST(last_doc, VALUES(last_doc))'
                : ' ON DUPLICATE KEY UPDATE data = IF(last_doc < VALUES(last_doc), CONCAT(data, VALUES(data)), data),'
                    . ' n = IF(last_doc < VALUES(last_doc), n + VALUES(n), n), last_doc = GREATEST(last_doc, VALUES(last_doc))';
            self::x('INSERT INTO ' . self::t(self::T_MAPT) . ' (term, chunk, n, last_doc, data) VALUES ' . implode(',', $values) . $upd, $bind);
        }
    }

    /**
     * Under the map lock: fold the recorded deletes above the map's dead_seq (at most up to $upTo) in batches of DEAD_SCAN
     * rows within $budget seconds; after each batch the meta moves on (dead_seq, dead_stale += rows) and the batch's rows
     * are removed (meta first: a crash in between leaves rows that are never read again). $complete = every row up to
     * $upTo is folded. Returns the rows folded.
     */
    private static function deadPassLocked(array &$m, float $budget, int $upTo, &$complete = null): int
    {
        $complete = false;
        if (!self::has(self::T_MAPD)) {
            $complete = true;
            return 0;
        }
        $t0 = microtime(true);
        $done = 0;
        while (true) {
            $from = (int)($m['dead_seq'] ?? 0);
            if ($from >= $upTo) {
                $complete = true;
                break;
            }
            $rows = self::q('SELECT seq, doc, ft_text FROM ' . self::t(self::T_MAPD) . ' WHERE seq > ' . $from . ($upTo < PHP_INT_MAX ? ' AND seq <= ' . $upTo : '')
                . ' ORDER BY seq LIMIT ' . self::DEAD_SCAN);
            if (!$rows) {
                $complete = true;
                break;
            }
            self::deadFold($rows);
            $last = (int)$rows[count($rows) - 1]['seq'];
            $fresh = self::mapMeta();
            $fresh['dead_seq'] = $last;
            $fresh['dead_stale'] = (int)($fresh['dead_stale'] ?? 0) + count($rows);
            $fresh['dead_at'] = time();
            self::writeJson('ftmap.json', $fresh);
            $m = $fresh;
            self::x('DELETE FROM ' . self::t(self::T_MAPD) . ' WHERE seq <= ' . $last);
            $done += count($rows);
            if (count($rows) < self::DEAD_SCAN) {
                $complete = true; // every row up to $upTo was in this batch
                break;
            }
            if (microtime(true) - $t0 >= max(0.2, $budget)) {
                break;
            }
        }
        return $done;
    }

    /**
     * Fold the recorded deletes into the term map's dead chunks now (drain / bulkEnd call it after a write that deleted
     * documents; tests): only for a map that keeps the record (dead_v), while no build runs and the map lock is free.
     * Returns the rows folded (0 when skipped).
     */
    public static function mapDeadPass(float $budget = 1.0): int
    {
        if (!self::installed() || !self::has(self::T_MAPD) || !self::has(self::T_MAPT) || !self::mapUsable()) {
            return 0;
        }
        $m = self::mapMeta();
        if (empty($m['dead_v']) || !empty($m['building']) || !self::getLock('ft_map', 0)) {
            return 0;
        }
        try {
            $m = self::mapMeta();
            if (empty($m['dead_v']) || !empty($m['building'])) {
                return 0;
            }
            return self::deadPassLocked($m, $budget, PHP_INT_MAX);
        } finally {
            self::releaseLock('ft_map');
        }
    }

    /**
     * End of a build (under the map lock): the deletes recorded while partitions were read. A delete above a partition's
     * snapshot seq was alive in that partition's read: when it matches one of the partition's terms (and lies at or below
     * the build's last doc, the rows' cover) it is folded into that term's dead chunk; deletes at or below the seq were
     * never in its rows. Then the record restarts: rows up to the end seq are removed. Returns ['seq' => end seq (the new
     * dead_seq), 'stale' => stale_ops at that seq (the new dead_stale), 'pairs' => dead ids written].
     */
    private static function deadAfterBuild(array $b): array
    {
        $out = ['seq' => 0, 'stale' => 0, 'pairs' => 0];
        $snap = null;
        for ($try = 0; $try < 3 && $snap === null; $try++) {
            $snap = self::snapshotBegin(self::MAP_LOCK_WAIT);
        }
        if ($snap === null) {
            throw new \RuntimeException('FtIndex: term map build: the search write lock stayed busy at the end');
        }
        self::snapshotEnd();
        $out['seq'] = $snap['seq'];
        $out['stale'] = $snap['stale'];
        if (!self::has(self::T_MAPD)) {
            return $out;
        }
        $seqs = isset($b['seqs']) && is_array($b['seqs']) ? array_map('intval', $b['seqs']) : [];
        $min = $seqs ? min($seqs) : $snap['seq'];
        $parts = isset($b['parts']) && is_array($b['parts']) ? $b['parts'] : [];
        $floor = function (string $term) use ($parts, $seqs) {
            $lo = 0;
            $hi = count($parts) - 1;
            $idx = -1;
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                if (strcmp((string)$parts[$mid][0], $term) <= 0) {
                    $idx = $mid;
                    $lo = $mid + 1;
                } else {
                    $hi = $mid - 1;
                }
            }
            // a term outside every partition (it left the statistics: its row was removed) gets nothing
            return $idx >= 0 && strcmp($term, (string)$parts[$idx][1]) <= 0 && isset($seqs[$idx]) ? $seqs[$idx] : PHP_INT_MAX;
        };
        $from = $min;
        while ($from < $snap['seq']) {
            $rows = self::q('SELECT seq, doc, ft_text FROM ' . self::t(self::T_MAPD) . ' WHERE seq > ' . $from . ' AND seq <= ' . (int)$snap['seq']
                . ' AND doc <= ' . (int)$b['last_doc'] . ' ORDER BY seq LIMIT ' . self::DEAD_SCAN);
            if (!$rows) {
                break;
            }
            $out['pairs'] += self::deadFold($rows, $floor);
            $from = (int)$rows[count($rows) - 1]['seq'];
            if (count($rows) < self::DEAD_SCAN) {
                break;
            }
        }
        self::x('DELETE FROM ' . self::t(self::T_MAPD) . ' WHERE seq <= ' . (int)$snap['seq']);
        return $out;
    }

    /**
     * Round-3 R3-5: size of the term map on disk (bytes) and where it came from: the tablespace files of vod_ft_map and
     * vod_ft_mapt (information_schema INNODB_SYS_TABLESPACES on MariaDB / MySQL 5.7, INNODB_TABLESPACES on MySQL 8.0;
     * needs the PROCESS privilege), else the larger of DATA_LENGTH + INDEX_LENGTH (MariaDB leaves out off-page BLOB pages
     * there: 0.76 MB for a 281 MB map was measured) and the bytes the build wrote ($written).
     */
    private static function mapBytes(int $written): array
    {
        $names = [self::$dbName . '/' . self::prefix() . self::T_MAP, self::$dbName . '/' . self::prefix() . self::T_MAPT];
        foreach (['INNODB_SYS_TABLESPACES', 'INNODB_TABLESPACES'] as $view) {
            try {
                $r = self::q('SELECT COALESCE(SUM(FILE_SIZE), 0) b, COUNT(*) n FROM information_schema.' . $view . ' WHERE NAME IN (:a, :b)', [':a' => $names[0], ':b' => $names[1]]);
                if ((int)($r[0]['n'] ?? 0) > 0 && (int)($r[0]['b'] ?? 0) > 0) {
                    return ['bytes' => (int)$r[0]['b'], 'source' => 'tablespace'];
                }
            } catch (\Throwable $e) {
                // view missing or no PROCESS privilege
            }
        }
        $is = 0;
        try {
            $is = (int)self::v('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (:a, :b)',
                [':a' => self::prefix() . self::T_MAP, ':b' => self::prefix() . self::T_MAPT]);
        } catch (\Throwable $e) {
            $is = 0;
        }
        return $is >= $written ? ['bytes' => $is, 'source' => 'tables'] : ['bytes' => $written, 'source' => 'written'];
    }

    /**
     * C16 append (housekeeping): documents above the map's last_doc, at most $limit, within $budget seconds. Round-2 verify
     * fix N11: the new doc ids of every term that has a map row go to small append chunks of vod_ft_mapt (TAIL_CHUNK ids per
     * row; each touched term rewrites one small row) instead of rewriting the term's whole id list (9,888 docs cost 13 s and
     * 357 MB of writes at 7.1 M before); exact ids for both kinds, so the newest pages read them directly. A term with more
     * than TAIL_MERGE chunks is folded into its main row (mapFold). Docs at or below a term's own coverage are never added
     * twice (a second run after a crash re-reads the same docs and skips them). Returns the docs processed.
     * Round-3 F-r3-1 (map keeps the delete record, dead_v): first the recorded deletes are folded into the dead chunks of
     * their terms (against the covers before this run's appends); then the new docs are read in one snapshot whose seq is
     * taken under the write lock and the deletes recorded up to that seq are folded too. So every delete recorded later
     * was alive in this run's read: it is in a term's rank space exactly when it matches and lies at or below the term's
     * own cover (the rule the next fold and the pages' pending check use). A backlog of deletes that does not fit the
     * budget is folded first; the new docs then wait for the next run (their pages read them by LIKE meanwhile).
     */
    public static function mapAppend(int $limit = 20000, float $budget = 5.0): int
    {
        if (!self::installed() || !self::mapUsable()) {
            return 0;
        }
        $m = self::mapMeta();
        if (!empty($m['building'])) {
            return 0;
        }
        try {
            self::ensureMap(); // an install whose map predates vod_ft_mapt
        } catch (\Throwable $e) {
            return 0;
        }
        if (!self::has(self::T_MAPT) || !self::getLock('ft_map', 0)) {
            return 0;
        }
        $t0 = microtime(true);
        $done = 0;
        try {
            $m = self::mapMeta();
            if (!empty($m['building'])) {
                return 0;
            }
            $tracked = !empty($m['dead_v']) && self::has(self::T_MAPD);
            if ($tracked) {
                $complete = false;
                self::deadPassLocked($m, max(0.2, $budget / 2), PHP_INT_MAX, $complete);
                if (!$complete) {
                    return 0; // the backlog of deletes takes this run; the next run continues
                }
            }
            $snap = null;
            if ($tracked) {
                $snap = self::snapshotBegin(2);
                if ($snap === null) {
                    return 0; // the write lock is busy now: the next run appends
                }
            }
            $late = [];
            $from = (int)($m['last_doc'] ?? 0);
            $n = max(1, (int)($m['docs'] ?? 1));
            $new = [];
            $last = $from;
            try {
                if ($snap !== null && $snap['seq'] > (int)($m['dead_seq'] ?? 0)) {
                    // deletes recorded between the fold above and the snapshot (few): folded below, before the appends
                    $late = self::q('SELECT seq, doc, ft_text FROM ' . self::t(self::T_MAPD) . ' WHERE seq > ' . (int)($m['dead_seq'] ?? 0)
                        . ' AND seq <= ' . (int)$snap['seq'] . ' ORDER BY seq');
                }
                while ($done < $limit && microtime(true) - $t0 < max(0.2, $budget)) {
                    $rows = self::q('SELECT FTS_DOC_ID, ft_text FROM ' . self::t(self::T_FT) . ' WHERE FTS_DOC_ID > ' . $last . ' ORDER BY FTS_DOC_ID LIMIT ' . min(self::MAP_SCAN, $limit - $done));
                    if (!$rows) {
                        break;
                    }
                    foreach ($rows as $r) {
                        $doc = (int)$r['FTS_DOC_ID'];
                        foreach (array_unique(explode(' ', (string)$r['ft_text'])) as $tok) {
                            if ($tok !== '' && strlen($tok) <= 84) {
                                $new[$tok][] = $doc;
                            }
                        }
                    }
                    $done += count($rows);
                    $last = (int)$rows[count($rows) - 1]['FTS_DOC_ID'];
                }
            } finally {
                if ($snap !== null) {
                    self::snapshotEnd();
                }
            }
            if ($snap !== null) {
                if ($late) {
                    self::deadFold($late);
                }
                // every delete recorded up to the snapshot is folded now
                $m = self::mapMeta();
                $m['dead_seq'] = max((int)($m['dead_seq'] ?? 0), (int)$snap['seq']);
                $m['dead_stale'] = (int)$snap['stale'];
                $m['dead_at'] = time();
                self::writeJson('ftmap.json', $m);
                if ($late) {
                    self::x('DELETE FROM ' . self::t(self::T_MAPD) . ' WHERE seq <= ' . (int)$snap['seq']);
                }
            }
            if ($done === 0) {
                return 0;
            }
            // only terms that have a map row; per term: its coverage (main last_doc, the chunks' last_doc), its last chunk
            $writes = [];
            $fold = [];
            foreach (array_chunk(array_keys($new), 500) as $chunk) {
                $in = [];
                $bind = [];
                foreach ($chunk as $i => $tok) {
                    $in[] = ':t' . $i;
                    $bind[':t' . $i] = (string)$tok;
                }
                $mains = [];
                foreach (self::q('SELECT term, last_doc FROM ' . self::t(self::T_MAP) . ' WHERE term IN (' . implode(',', $in) . ')', $bind) as $row) {
                    $mains[(string)$row['term']] = (int)$row['last_doc'];
                }
                if (!$mains) {
                    continue;
                }
                $tails = [];
                foreach (self::q('SELECT term, chunk, n, last_doc FROM ' . self::t(self::T_MAPT) . ' WHERE term IN (' . implode(',', $in) . ') AND chunk > 0 AND chunk < '
                    . self::DEAD_BASE, $bind) as $c) {
                    $term = (string)$c['term'];
                    $t = $tails[$term] ?? ['chunk' => 0, 'n' => 0, 'cover' => 0];
                    if ((int)$c['chunk'] > $t['chunk']) {
                        $t['chunk'] = (int)$c['chunk'];
                        $t['n'] = (int)$c['n'];
                    }
                    $t['cover'] = max($t['cover'], (int)$c['last_doc']);
                    $tails[$term] = $t;
                }
                foreach ($mains as $term => $lastDoc) {
                    $cover = max($lastDoc, $tails[$term]['cover'] ?? 0);
                    $docs = [];
                    foreach ($new[$term] as $d) {
                        if ($d > $cover) {
                            $docs[] = $d;
                        }
                    }
                    if (!$docs) {
                        continue;
                    }
                    $ch = $tails[$term]['chunk'] ?? 0;
                    $used = $tails[$term]['n'] ?? 0;
                    if ($ch === 0) {
                        $ch = 1;
                        $used = 0;
                    }
                    while ($docs) {
                        if ($used >= self::$tailChunk) {
                            $ch++;
                            $used = 0;
                        }
                        $part = array_splice($docs, 0, self::$tailChunk - $used);
                        $writes[] = [$term, $ch, count($part), (int)end($part), pack('V*', ...$part)];
                        $used += count($part);
                    }
                    if ($ch > self::$tailMerge) {
                        $fold[$term] = true;
                    }
                }
            }
            // an existing chunk takes the ids only when its last_doc is below them (last_doc is assigned last, so the IFs see
            // the old value): a repeated run never appends the same docs twice
            self::writeChunks($writes, false);
            foreach (array_keys($fold) as $term) {
                self::mapFold((string)$term, $n);
            }
            $m = self::mapMeta();
            if (empty($m['building'])) {
                $m['last_doc'] = $last;
                $m['appended_at'] = time();
                $m['append_terms'] = count($writes);
                self::writeJson('ftmap.json', $m);
            }
            self::$coverMemo = null;
            return $done;
        } finally {
            self::releaseLock('ft_map');
        }
    }

    /**
     * Fold a term's append chunks into its main row (one rewrite of the id list): kind 1 keeps every id (kind 2 once the
     * list reaches D_FULL), kind 2 extends its every-step-th sample and keeps the newest MAP_HEAD ids as its head.
     * Round-3 F-r3-1: the term's folded dead ids leave the exact lists here: a kind-1 list (and a list that becomes kind 2)
     * is rewritten from its live ids only (its dead chunks go); kind 2 drops the dead appended ids and keeps the dead ids of
     * its sampled main row (their ranks stay counted in df, the pages subtract them). Deletes recorded after the last fold
     * stay in the rewritten row (they were alive: the next fold and the pages' pending check count them).
     * Under the map lock (mapAppend, after its fold of the recorded deletes); one transaction.
     */
    private static function mapFold(string $term, int $n): void
    {
        $r = self::q('SELECT kind, df, step, last_doc, data FROM ' . self::t(self::T_MAP) . ' WHERE term = :t', [':t' => $term]);
        if (!$r) {
            return;
        }
        $kind = (int)$r[0]['kind'];
        $df = (int)$r[0]['df'];
        $step = (int)$r[0]['step'];
        $oldLast = (int)$r[0]['last_doc'];
        $main = (string)$r[0]['data'];
        $c = self::termChunks($term, $kind, $oldLast);
        if ($c['tail'] === '') {
            return;
        }
        $tailAll = array_values(unpack('V*', $c['tail']));
        $dead = $c['dead'] ? array_flip($c['dead']) : [];
        $tail = $dead ? array_values(array_filter($tailAll, function ($d) use ($dead) { return !isset($dead[$d]); })) : $tailAll;
        // the main row now covers everything the appended chunks covered (also when their newest ids were deleted)
        $lastDoc = max((int)end($tailAll), $c['cover']);
        $keepDead = [];
        $head = '';
        if ($kind === 1) {
            $all = array_merge($main === '' ? [] : array_values(unpack('V*', $main)), $tail);
            if ($dead) {
                $all = array_values(array_filter($all, function ($d) use ($dead) { return !isset($dead[$d]); }));
            }
            $total = count($all);
            $sh = self::mapShape($total, $n);
            if ($sh['kind'] === 2 && $all) {
                $kind = 2;
                $step = $sh['step'];
                $data = pack('V*', ...self::mapSample($all, $step));
                $head = pack('V*', ...array_slice($all, -self::$mapHead));
            } else {
                $data = $all ? pack('V*', ...$all) : '';
            }
        } else {
            $step = max(1, $step);
            $add = '';
            foreach ($tail as $j => $d) {
                if (($df + $j) % $step === 0) {
                    $add .= pack('V', $d);
                }
            }
            $data = $main . $add;
            $total = $df + count($tail);
            $head = substr($c['head'] . ($tail ? pack('V*', ...$tail) : ''), -self::$mapHead * 4);
            foreach (array_keys($dead) as $d) {
                if ($d <= $oldLast) {
                    $keepDead[] = (int)$d; // a dead id of the sampled main row: its rank stays in df
                }
            }
            sort($keepDead, SORT_NUMERIC);
        }
        $own = self::begin();
        try {
            self::x('UPDATE ' . self::t(self::T_MAP) . ' SET kind = :k, df = :df, step = :s, last_doc = :ld, data = :d WHERE term = :t',
                [':k' => $kind, ':df' => $total, ':s' => $step, ':ld' => $lastDoc, ':d' => $data, ':t' => $term]);
            self::x('DELETE FROM ' . self::t(self::T_MAPT) . ' WHERE term = :t', [':t' => $term]);
            if ($kind === 2 && $head !== '') {
                self::x('INSERT INTO ' . self::t(self::T_MAPT) . ' (term, chunk, n, last_doc, data) VALUES (:t, 0, :n, :ld, :d)',
                    [':t' => $term, ':n' => intdiv(strlen($head), 4), ':ld' => $lastDoc, ':d' => $head]);
            }
            if ($keepDead) {
                $writes = [];
                foreach (array_chunk($keepDead, self::$tailChunk) as $i => $part) {
                    $writes[] = [$term, self::DEAD_BASE + $i, count($part), (int)max($part), pack('V*', ...$part)];
                }
                self::writeChunks($writes, true);
            }
            self::commit($own);
        } catch (\Throwable $e) {
            self::rollback($own);
            throw $e;
        }
    }
    /** S7: a cron / command-line run seen in the last 24 h (housekeeping cron.seen, the importer's CLI heartbeat). */
    private static function cliFresh(): bool
    {
        $seen = 0;
        try {
            if (class_exists(__NAMESPACE__ . '\\Housekeeping') && method_exists(__NAMESPACE__ . '\\Housekeeping', 'cronSeenAt')) {
                $seen = (int)Housekeeping::cronSeenAt();
            }
        } catch (\Throwable $e) {
        }
        try {
            $seen = max($seen, (int)self::v('SELECT COALESCE(MAX(cli_seen_at), 0) FROM ' . self::t('avdb_provider_state')));
        } catch (\Throwable $e) {
        }
        return $seen > time() - 86400;
    }

    // ------------------------------------------------------------------ housekeeping and status

    /**
     * Housekeeping hook (lane H calls it from Housekeeping / tools/housekeeping.php): drain, self-heal slice, daily
     * ms_per_read probe, rebuild triggers. Never runs the rebuild or the term refresh itself (they may take minutes):
     * with a cron heartbeat, tools/search-index.php --auto does that from the CLI.
     */
    public static function housekeeping(int $budgetSec = 5): array
    {
        $out = ['drained' => 0, 'heal' => [], 'ms_per_read' => null, 'rebuild_due' => '', 'terms_due' => false];
        if (!self::installed()) {
            return $out + ['installed' => false];
        }
        $t0 = microtime(true);
        $out['abandoned_job'] = self::abandonStaleJob();
        $out['bulk_window'] = self::closeOrphanBulkWindow();
        // S7: a web backfill / rebuild (started from the Speed page on a site without cron) continues in the housekeeping
        // runs too, one resumable step within this budget, so it finishes with the admin tab closed
        $job = self::job();
        if ($job && !empty($job['web']) && (int)(self::state(true)['rebuild_started_at'] ?? 0) > 0) {
            try {
                $r = self::rebuild(['web' => true, 'budget' => max(1, $budgetSec - 1), 'continue' => true]);
                $out['web_job'] = ['status' => (string)($r['status'] ?? ''), 'phase' => (string)($r['phase'] ?? ''), 'pct' => $r['pct'] ?? null];
            } catch (\Throwable $e) {
                $out['web_job'] = ['status' => 'error', 'error' => get_class($e)];
            }
        }
        $out['drained'] = self::drain(500, max(1, $budgetSec));
        if (microtime(true) - $t0 < $budgetSec) {
            // round-2 verify fix N17: the slice ends with this run's budget (statement limits inside)
            $out['heal'] = self::selfHeal(self::HEAL_BUCKETS, $t0 + $budgetSec);
        }
        if (microtime(true) - $t0 < $budgetSec) {
            $out['ms_per_read'] = self::probeReadSpeed(false);
        }
        $out['rebuild_due'] = self::checkTriggers();
        $out['terms_due'] = self::termsDue();
        return $out;
    }

    /**
     * Status for the admin page ("Speed & protection", lane E, HO-E-4) and the CLI. Keys E reads: 'mode' = what visitor
     * search uses now: 'ft' (search table with FULLTEXT), 'walk' (search table without FULLTEXT), 'old' (table not ready:
     * today's search path), 'building' (backfill / rebuild running); 'docs'; 'pending' (videos not yet searchable: queue
     * + published videos without a document while no rebuild runs, bounded); 'rebuild_due'; 'terms_at'; 'ms_per_read';
     * 'langs'. 'table_mode' is the stored table mode ('ft' | 'walk'). The other keys are details for the CLI.
     */
    public static function status(): array
    {
        if (!self::installed()) {
            return ['installed' => false, 'mode' => 'old', 'docs' => null, 'pending' => null, 'rebuild_due' => '', 'terms_at' => 0, 'ms_per_read' => null, 'langs' => ''];
        }
        $orphan = self::closeOrphanBulkWindow();
        $s = self::state(true);
        $queue = (int)self::v('SELECT COUNT(*) FROM ' . self::t(self::T_QUEUE));
        $replay = (int)self::v('SELECT COUNT(*) FROM ' . self::t(self::T_REPLAY));
        $terms = (int)self::v('SELECT COUNT(*) FROM ' . self::t(self::T_TERM));
        $size = self::q('SELECT ROW_FORMAT f, COALESCE(DATA_LENGTH + INDEX_LENGTH, 0) b, COALESCE(TABLE_ROWS, 0) r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', [':t' => self::prefix() . self::T_FT]);
        $vodRows = self::q('SELECT COALESCE(TABLE_ROWS, 0) r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', [':t' => self::prefix() . 'vod']);
        $msr = (float)($s['ms_per_read'] ?? 0.1);
        $job = self::job();
        $building = (int)$s['rebuild_started_at'] > 0;
        $mode = $building ? 'building' : ((int)$s['ready'] !== 1 ? 'old' : ((string)$s['mode'] === 'walk' ? 'walk' : 'ft'));
        // pending: queued ids, plus the bulk window's videos (inserted, not drained) estimated from table statistics
        $pending = $queue;
        if ((int)$s['bulk_job'] > 0) {
            $pending += (int)self::v('SELECT COUNT(*) FROM (SELECT vod_id FROM ' . self::t('vod') . ' WHERE vod_id >= ' . (int)$s['bulk_job'] . ' LIMIT 1000000) x');
        }
        return ['installed' => true, 'ready' => (int)$s['ready'] === 1, 'mode' => $mode, 'table_mode' => (string)$s['mode'], 'docs' => (int)$s['docs'],
            'pending' => $pending, 'queue' => $queue, 'replay' => $replay, 'terms' => $terms, 'stale_ops' => (int)$s['stale_ops'], 'out_of_order' => (int)$s['out_of_order'],
            'built_at' => (int)$s['built_at'], 'langs' => (string)$s['langs'], 'langs_configured' => implode(',', self::langs()),
            'terms_at' => (int)$s['term_at'], 'docs_at_terms' => (int)$s['docs_at_terms'], 'terms_stale' => self::termsStale($s), 'terms_due' => self::termsDue(),
            'ms_per_read' => $msr, 'ft_max' => FtPlan::ftMax($msr), 'walk_cap' => self::walkCap(), 'tier' => self::tier(), 'rebuild_started_at' => (int)$s['rebuild_started_at'],
            'rebuild_due' => (string)$s['rebuild_due'], 'bulk_from' => (int)$s['bulk_job'], 'bulk_window' => $orphan, 'next_doc_id' => (int)$s['next_doc_id'],
            'row_format' => (string)($size[0]['f'] ?? ''), 'table_mb' => round((int)($size[0]['b'] ?? 0) / 1048576, 1), 'vod_rows_estimate' => (int)($vodRows[0]['r'] ?? 0),
            'job' => $job ? self::jobSummary($job) + ['web' => !empty($job['web']), 'started' => (int)($job['started'] ?? 0)] : []];
    }

    /**
     * Admin button step (HO-E-4; every tier since S7, T3 only without cron): one web step of a backfill / rebuild. Starts one when the table is not
     * ready or a rebuild is due, else continues the running one. Returns ['done', 'total', 'finished', 'msg'] with msg ''
     * or a reason key: 'busy' | 'cli_only' | 'space' | 'not_installed' | 'nothing_to_do' | 'lock_wait'.
     */
    public static function backfillStep(int $budgetSec = 9): array
    {
        if (!self::installed()) {
            self::ensureSchema();
        }
        self::closeOrphanBulkWindow(); // import-r2 finding 8: "Build search index" also closes a window no job owns
        $s = self::state(true);
        $start = (int)$s['ready'] !== 1 || (string)$s['rebuild_due'] !== '';
        $running = self::job() && (int)$s['rebuild_started_at'] > 0;
        if (!$start && !$running && !self::job()) {
            return ['done' => (int)$s['docs'], 'total' => (int)$s['docs'], 'finished' => true, 'msg' => 'nothing_to_do'];
        }
        $r = self::rebuild(['web' => true, 'budget' => max(1, min(25, $budgetSec)), 'continue' => !$start]);
        $finished = in_array($r['status'], ['done', 'idle'], true);
        return ['done' => (int)($r['done'] ?? ($finished ? (int)(self::state(true)['docs'] ?? 0) : 0)), 'total' => (int)($r['total'] ?? 0),
            'finished' => $finished, 'msg' => $r['status'] === 'refused' || isset($r['reason']) ? (string)($r['reason'] ?? '') : ''] + ($r['status'] === 'refused' ? ['command' => $r['command'] ?? ''] : []);
    }
}
