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
 * Lane H schema glue. XVID-SCALE-BRIEF 2.12 steps 2a/2b, D-L1, D-L3, D-L9, D-L11.
 *
 *   ensureTables(?PDO, ?prefix): array   scale_count, sitemap_bucket, hit_buffer, list_pagemap, list_pagemap_state (VERSION 2,
 *                                        XVID-NOLIMIT-BRIEF B1 / H2) from install/sql/scale.sql. Automatic
 *                                        (installer, first admin visit, tools/upgrade.php); checks information_schema
 *                                        first, creates only what is missing; idempotent; a cached flag makes the next
 *                                        call a file read.
 *   ensureIndexes(?PDO, ?prefix, ?progress): array
 *                                        the two D-L1 list indexes, the four SORT_INDEXES (by=hits / by=up per category,
 *                                        G5b) and STATUS_INDEXES (idx_status_list, the "all" list seek: verify r2 R2-4a)
 *                                        on {pre}vod, TRANSLATION_INDEXES (idx_tr_lastmod on {pre}avdb_translation, the
 *                                        index-only per-language sitemap lastmod: verify r3 R3-8; ensureTables() adds it
 *                                        while that table is small), then the five FT_INDEXES (FULLTEXT, owner decision 2026-10-17;
 *                                        one at a time, never dropped). ONLY from tools/upgrade.php or the admin
 *                                        button "Finish upgrade" (never from a page view): GET_LOCK('<db>_scale_schema', 0)
 *                                        (skip when held), skip when the processlist shows an ALTER TABLE on {pre}vod,
 *                                        lock_wait_timeout=5, ignore_user_abort, no time limit, own connection without
 *                                        statement limit. An existing index with the same columns counts as present (its
 *                                        name is recorded). Writes the confirmed flag.
 *   indexesConfirmed(): bool             cached flag (runtime/scale/schema.json); false = missing or unverified -> callers
 *                                        keep today's plans and never FORCE INDEX (error 1176 on a missing key).
 *   indexName(string $which): string     'type_list' | 'type1_list' -> the confirmed index name ('' when not confirmed).
 *   tablesReady(): bool                  cached flag of ensureTables().
 *   firstAdminVisit(): void              admin entrance hook (HP-H-3): ensureTables() once per schema version, plus the
 *                                        importer and search schemas when their owners' methods exist.
 *   connect(bool $unlimited = true): ?PDO  own connection from application/database.php (housekeeping, tools, index builds),
 *                                        with the statement limit removed.
 *   ftSizes(PDO, prefix): ?array         bytes of each FULLTEXT index (its InnoDB auxiliary tables, which TABLES.DATA_LENGTH /
 *                                        INDEX_LENGTH do not include) + '_common'; null when the server does not show them
 *                                        (no PROCESS privilege); the total is cached for ftBytes().
 *   ftBytes(): ?int                      last measured total (runtime/scale/schema.json), else the estimate for the row count.
 *   ftEstimateBytes(int $rows): int      FT_BYTES_PER_ROW x rows (import estimate, StorageGuard figures, upgrade disk check).
 */
final class ScaleSchema
{
    /**
     * 2: + list_pagemap, list_pagemap_state (an install with the version-1 flag creates the two at the next ensureTables()).
     * 3: + the translation covering index while the translation table is small (verify r3 R3-8: ensureTables() runs once more
     *    on every install, a few information_schema reads).
     * 4: lane S round 6 (D-9, CONTRACT 2026-10-20): the search tables' new tables / columns (vod_ft_inv, vod_ft_newtok, the
     *    order-tracking columns of vod_ft_state): a site updated only through the web runs FtIndex::ensureSchema() once more at
     *    the first admin visit (firstAdminVisit()) or the next housekeeping run (Housekeeping::scale(), whichever comes first).
     */
    public const VERSION = 4;
    public const TABLES = ['scale_count', 'sitemap_bucket', 'hit_buffer', 'list_pagemap', 'list_pagemap_state'];
    public const INDEXES = [
        'type_list' => ['name' => 'idx_type_list', 'cols' => ['type_id', 'vod_status', 'vod_recycle_time', 'vod_time']],
        'type1_list' => ['name' => 'idx_type1_list', 'cols' => ['type_id_1', 'vod_status', 'vod_recycle_time', 'vod_time']],
    ];
    /**
     * Sort indexes for category lists ordered by hits / up (G5b, HO-S-8, verify fix 2026-10-17): without them a by=up page
     * at the by cap walks a single-column index past millions of other categories' rows (3.1 s cold, shed 503 at 7.1 M);
     * with them 0.15-0.21 s. About 139 MB each at 7.1 M (555 MB for the four; owner decision against D7). Added by the same
     * upgrade step as INDEXES (ensureIndexes), kept by tools/db-tune.php --slim-indexes; the optimizer picks them itself
     * (no FORCE INDEX), so a missing one only costs speed.
     */
    public const SORT_INDEXES = [
        'type_hits' => ['name' => 'idx_type_hits', 'cols' => ['type_id', 'vod_status', 'vod_recycle_time', 'vod_hits']],
        'type1_hits' => ['name' => 'idx_type1_hits', 'cols' => ['type_id_1', 'vod_status', 'vod_recycle_time', 'vod_hits']],
        'type_up' => ['name' => 'idx_type_up', 'cols' => ['type_id', 'vod_status', 'vod_recycle_time', 'vod_up']],
        'type1_up' => ['name' => 'idx_type1_up', 'cols' => ['type_id_1', 'vod_status', 'vod_recycle_time', 'vod_up']],
    ];
    /**
     * The "all videos" list of published rows (verify r2 R2-4a, N13): the page-map seek of key 'a' (WHERE vod_status = 1 AND
     * vod_recycle_time = 0 ORDER BY vod_time, vod_id) on idx_vod_recycle_time_vod_time checks vod_status in the rows (602
     * primary-key lookups, 711-889 reads, DB 0.16-0.56 s at 7.1 M); with this index the seek reads the index only (InnoDB
     * appends vod_id). About 140 MB at 7.1 M. Added by ensureIndexes() like the other list indexes, kept by the slim set
     * (tools/db-tune.php); indexName('status_list') names it for a FORCE INDEX (lane S, ListPageMap) once it exists.
     */
    public const STATUS_INDEXES = [
        'status_list' => ['name' => 'idx_status_list', 'cols' => ['vod_status', 'vod_recycle_time', 'vod_time']],
    ];
    /**
     * verify r3 R3-8: the covering index of {pre}avdb_translation for the per-language sitemap lastmod (Sitemap::langQueue()):
     * its windows (SELECT locale, MAX(updated_at) ... WHERE vod_id BETWEEN ...) read this index only (InnoDB appends the rest
     * of the primary key, locale): about 25-30 bytes an entry instead of the ~1.2 KB rows (8.38 M translations: about 250 MB
     * instead of 8.2 GB per pass; G23 on MySQL 5.7 with a 128 MB pool and 1 core had not finished upgrade step 5 after 95
     * minutes). Added online by ensureIndexes() (tools/upgrade.php, "Finish upgrade"), by ensureTables() at once while the
     * table holds at most TRANSLATION_AUTO_ROWS rows (fresh installs), never dropped (tools/db-tune.php touches only the video
     * table's stock indexes). Without it the windows read the rows along the primary key, as before.
     */
    public const TRANSLATION_INDEXES = [
        'tr_lastmod' => ['name' => 'idx_tr_lastmod', 'cols' => ['vod_id', 'updated_at']],
    ];
    public const TRANSLATION_TABLE = 'avdb_translation';
    public const TRANSLATION_AUTO_ROWS = 20000;
    /** Bytes per translation row of the covering index (estimate for the free-disk check: entry + page fill + sort files). */
    public const TRANSLATION_INDEX_BYTES = 40;

    /**
     * FULLTEXT indexes of every install (owner decision 2026-10-17): the actor / tag / category / director filters (MATCH
     * pre-filter + exact LIKE, lane S FilterMatch) and the title search. Fresh installs create them (install.sql); upgrades
     * get them from ensureIndexes() (tools/upgrade.php, "Finish upgrade"), smallest first, one ALTER each (InnoDB builds one
     * FULLTEXT index per statement; the first one on a table without FTS_DOC_ID rebuilds the table); the slim index set of
     * tools/db-tune.php keeps them. An existing one-column FULLTEXT index on the same column counts as present.
     */
    public const FT_INDEXES = [
        'ft_vod_director' => 'vod_director',
        'ft_vod_actor' => 'vod_actor',
        'ft_vod_tag' => 'vod_tag',
        'ft_vod_class' => 'vod_class',
        'ft_vod_name' => 'vod_name',
    ];
    /**
     * Bytes per video row of each FULLTEXT index (auxiliary tables, file-per-table allocation), used only where the server
     * does not report the sizes (ftSizes() needs the PROCESS privilege) and for estimates before the first measurement.
     * Measured (owner item xv-owner-H 2026-10-17): 7.12 M clustered xvid + AVDB rows, MariaDB 11.4: director 3.7, actor 12.2,
     * tag 37.9, class 7.2, name 54.2 B/row (783 MB in all); the real AVDB catalog (524 k rows, FT-FILTERS-BUILD.md): director
     * 5.4, actor 27, tag 29, class 45, name about 800 B/row. The constants take the larger figure per index and a middle
     * value for titles (estimates err on the safe side; ftBytes() scales the measured total once there is one).
     */
    public const FT_BYTES_PER_ROW = ['ft_vod_director' => 6, 'ft_vod_actor' => 28, 'ft_vod_tag' => 40, 'ft_vod_class' => 45, 'ft_vod_name' => 300, '_common' => 1];

    private static $flag = null;

    public static function ensureTables(?\PDO $pdo = null, ?string $prefix = null): array
    {
        $q = ScaleTier::queryFn($pdo, $prefix, true);
        if ($q === null) {
            return ['ok' => false, 'created' => [], 'error' => 'no database'];
        }
        [$select, $pre, $exec] = $q;
        $created = [];
        try {
            $have = [];
            $statements = self::statements($pre);
            $names = array_map(function ($t) use ($pre) { return $pre . $t; }, array_keys($statements));
            if ($names) {
                foreach ($select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('
                    . implode(', ', array_fill(0, count($names), '?')) . ')', $names) as $row) {
                    $have[strtolower((string)$row['t'])] = true;
                }
            }
            foreach ($statements as $table => $sql) {
                if (!isset($have[strtolower($pre . $table)])) {
                    $exec($sql);
                    $created[] = $table;
                }
            }
            // verify r3 R3-8: the translation covering index at once while the table is small (a fresh install: milliseconds);
            // a larger table gets it from ensureIndexes() (tools/upgrade.php, "Finish upgrade"), never from a page view
            $tr = self::ensureTranslationIndex($select, $exec, $pre, self::TRANSLATION_AUTO_ROWS);
            // the flag only claims what really exists: a scale.sql without one of the TABLES (old file next to new code)
            // leaves the version unconfirmed, so the next admin visit or upgrade run tries again
            $missing = array_diff(self::TABLES, array_keys($statements));
            if (!$missing) {
                self::writeFlag(['tables' => self::VERSION, 'tables_at' => time()]);
            }
            return ['ok' => !$missing, 'created' => $created, 'error' => $missing ? 'scale.sql lacks ' . implode(', ', $missing) : '',
                'translation' => $tr['state'], 'translation_added' => $tr['added']];
        } catch (\Throwable $e) {
            return ['ok' => false, 'created' => $created, 'error' => self::safeMessage($e)];
        }
    }

    /**
     * verify r3 R3-8: add TRANSLATION_INDEXES to {pre}avdb_translation when the table exists and lacks them (an index with
     * the same columns, any name, counts as present). Online: ALGORITHM=INPLACE, LOCK=NONE (reads and writes go on); a plain
     * ALTER only where the engine refuses that form, never after a lock wait / timeout error. Skipped while an ALTER TABLE on
     * the table runs, while StorageGuard says the storage is full, with too little free disk next to a local server, and
     * (automatic call from ensureTables()) when the table holds more than $maxRows rows. $select / $exec: ScaleTier::queryFn()
     * closures. Returns ['state' => 'present' | 'added' | 'no_table' | 'too_big' | 'busy' | 'no_space' | 'error', 'added' => [],
     * 'present' => [], 'name' => index name or '', 'seconds', 'error'] and records the name in the flag (tr_names). Never throws.
     */
    public static function ensureTranslationIndex(callable $select, callable $exec, string $pre, ?int $maxRows = null, ?callable $say = null): array
    {
        $out = ['state' => 'error', 'added' => [], 'present' => [], 'name' => '', 'seconds' => 0.0, 'error' => ''];
        $say = $say ?: function ($m) {};
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $pre)) {
            $out['error'] = 'invalid prefix';
            return $out;
        }
        $table = $pre . self::TRANSLATION_TABLE;
        try {
            $t = $select('SELECT TABLE_ROWS AS r, DATA_LENGTH AS d FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (!$t) {
                $out['state'] = 'no_table';
                return $out;
            }
            $rows = (int)($t[0]['r'] ?? 0);
            $have = [];
            foreach ($select('SELECT INDEX_NAME AS n, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table]) as $row) {
                $have[(string)$row['n']][] = strtolower((string)$row['c']);
            }
            $names = [];
            $todo = [];
            foreach (self::TRANSLATION_INDEXES as $which => $def) {
                $found = '';
                foreach ($have as $name => $cols) {
                    if ($cols === $def['cols']) { $found = $name; break; }
                }
                if ($found !== '') {
                    $names[$which] = $found;
                    $out['present'][] = $found;
                } else {
                    $todo[$which] = $def;
                }
            }
            if (!$todo) {
                $out['state'] = 'present';
                $out['name'] = (string)($names['tr_lastmod'] ?? '');
                self::recordTranslationNames($names);
                return $out;
            }
            if ($maxRows !== null && $rows > $maxRows) {
                $out['state'] = 'too_big';
                self::recordTranslationNames($names);
                return $out;
            }
            // search-r6 R6-7: only this site's database (DB = DATABASE()): another site on the same server with the same
            // table prefix runs its own index builds, which never touch this site's tables
            $alter = $select("SELECT COUNT(*) AS c FROM information_schema.PROCESSLIST WHERE INFO LIKE ? AND ID <> CONNECTION_ID() AND DB = DATABASE()", ['%ALTER TABLE%' . $table . '%']);
            if ((int)($alter[0]['c'] ?? 0) > 0) {
                $out['state'] = 'busy';
                $out['error'] = 'an ALTER TABLE on the translation table is running';
                return $out;
            }
            if (class_exists(__NAMESPACE__ . '\StorageGuard') && method_exists(__NAMESPACE__ . '\StorageGuard', 'isFull')) {
                try {
                    if (StorageGuard::isFull()) {
                        $out['state'] = 'no_space';
                        $out['error'] = 'storage limit reached';
                        return $out;
                    }
                } catch (\Throwable $e) {
                }
            }
            $need = (int)($rows * self::TRANSLATION_INDEX_BYTES * 2) + 67108864;
            $free = null;
            try {
                $dir = $select('SELECT @@datadir AS d');
                $dir = (string)($dir[0]['d'] ?? '');
                if ($dir !== '' && @is_dir($dir)) {
                    $f = @disk_free_space($dir);
                    $free = $f === false ? null : (float)$f;
                }
            } catch (\Throwable $e) {
            }
            if ($free !== null && $free < $need) {
                $out['state'] = 'no_space';
                $out['error'] = 'not enough free disk for the translation index (needs about ' . (int)ceil($need / 1048576) . ' MB, free ' . (int)floor($free / 1048576) . ' MB)';
                $say($out['error']);
                return $out;
            }
            // a short metadata-lock wait for the ALTER only; the caller's session gets the server default back afterwards (the
            // framework connection of an installer or admin request goes on with other statements)
            $lockSet = false;
            try { $exec('SET SESSION lock_wait_timeout=5'); $lockSet = true; } catch (\Throwable $e) {}
            try {
                foreach ($todo as $which => $def) {
                    $base = 'ALTER TABLE `' . $table . '` ADD INDEX `' . $def['name'] . '` (`' . implode('`,`', $def['cols']) . '`)';
                    $say('adding ' . $def['name'] . ' (translations: ' . number_format($rows) . ' rows; reads and writes go on while it builds)');
                    $t0 = microtime(true);
                    try {
                        try {
                            $exec($base . ', ALGORITHM=INPLACE, LOCK=NONE');
                        } catch (\Throwable $e) {
                            if (stripos($e->getMessage(), 'Duplicate key name') !== false
                                || preg_match('/\b(1205|1213|3024|1969|1317)\b|lock wait timeout|deadlock|interrupted/i', $e->getMessage())) {
                                throw $e;
                            }
                            $exec($base);
                        }
                        $names[$which] = $def['name'];
                        $out['added'][] = $def['name'];
                        $out['seconds'] = round(microtime(true) - $t0, 1);
                        $say('   ' . $def['name'] . ': ' . $out['seconds'] . ' s');
                    } catch (\Throwable $e) {
                        if (stripos($e->getMessage(), 'Duplicate key name') !== false) {
                            $names[$which] = $def['name'];
                            $out['present'][] = $def['name'];
                            continue;
                        }
                        $out['state'] = preg_match('/\b1205\b|lock wait timeout/i', $e->getMessage()) ? 'busy' : 'error';
                        $out['error'] = $def['name'] . ': ' . self::safeMessage($e);
                        $say('   ' . $out['error']);
                        self::recordTranslationNames($names);
                        return $out;
                    }
                }
            } finally {
                if ($lockSet) {
                    try { $exec('SET SESSION lock_wait_timeout=@@GLOBAL.lock_wait_timeout'); } catch (\Throwable $e) {}
                }
            }
            $out['state'] = $out['added'] ? 'added' : 'present';
            $out['name'] = (string)($names['tr_lastmod'] ?? '');
            self::recordTranslationNames($names);
            return $out;
        } catch (\Throwable $e) {
            $out['state'] = 'error';
            $out['error'] = self::safeMessage($e);
            return $out;
        }
    }

    /** The flag's tr_names (index names of TRANSLATION_INDEXES that exist), written only when they change. */
    private static function recordTranslationNames(array $names): void
    {
        $clean = [];
        foreach ($names as $which => $name) {
            if (isset(self::TRANSLATION_INDEXES[$which]) && preg_match('/^[A-Za-z0-9_]{1,64}$/D', (string)$name)) { $clean[$which] = (string)$name; }
        }
        if ((self::flag()['tr_names'] ?? null) !== $clean) {
            self::writeFlag(['tr_names' => $clean]);
        }
    }

    /** CREATE statements of scale.sql keyed by table name (without prefix). */
    public static function statements(string $prefix): array
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
            throw new \RuntimeException('Invalid database prefix.');
        }
        $file = ScaleTier::root() . 'application/install/sql/scale.sql';
        $sql = is_file($file) ? (string)file_get_contents($file) : '';
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $out = [];
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            if (!preg_match('/CREATE TABLE IF NOT EXISTS `javcms_([a-z_]+)`/', $statement, $m)) {
                continue;
            }
            $out[$m[1]] = str_replace('`javcms_', '`' . $prefix, $statement);
        }
        return $out;
    }

    public static function ensureIndexes(?\PDO $pdo = null, ?string $prefix = null, ?callable $progress = null): array
    {
        $own = false;
        if ($pdo === null) {
            $pdo = self::connect(true);
            $own = true;
            if ($pdo === null) {
                return ['ok' => false, 'state' => 'no_database', 'added' => [], 'present' => [], 'error' => 'database not reachable'];
            }
            $prefix = self::configPrefix();
        }
        $pre = (string)$prefix;
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $pre)) {
            return ['ok' => false, 'state' => 'error', 'added' => [], 'present' => [], 'error' => 'invalid prefix'];
        }
        $say = function ($msg) use ($progress) { if ($progress) { $progress($msg); } };
        @ignore_user_abort(true);
        @set_time_limit(0);
        $lockName = '';
        $heavy = false;
        $result = ['ok' => false, 'state' => '', 'added' => [], 'present' => [], 'names' => [], 'error' => ''];
        try {
            $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            $lockName = substr($db . '_scale_schema', 0, 64);
            $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $st->execute([$lockName]);
            if ((int)$st->fetchColumn() !== 1) {
                $lockName = '';
                return ['state' => 'busy', 'error' => 'another upgrade step is running'] + $result;
            }
            // search-r6 R6-7: ALTERs of this site's database only (two JavCMS sites with the same prefix on one server: the
            // other site's index builds made this upgrade wait up to an hour)
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE INFO LIKE ? AND ID <> CONNECTION_ID() AND DB = DATABASE()");
            $st->execute(['%ALTER TABLE%' . $pre . 'vod%']);
            if ((int)$st->fetchColumn() > 0) {
                return ['state' => 'busy', 'error' => 'an ALTER TABLE on the video table is running'] + $result;
            }
            // engines-r3 R3-8: index builds keep the database busy: expired cached pages are answered with their stale copy
            // meanwhile (ScaleTier::heavyActive in EdgeCache::serve); a pass with nothing to add ends the marker at once
            ScaleTier::heavyMark('index');
            $heavy = true;
            $existing = self::existingIndexes($pdo, $pre . 'vod');
            $names = [];
            $add = [];
            foreach (self::INDEXES + self::SORT_INDEXES + self::STATUS_INDEXES as $which => $def) {
                $found = '';
                foreach ($existing as $name => $cols) {
                    if ($cols === $def['cols']) { $found = $name; break; }
                }
                if ($found !== '') {
                    $names[$which] = $found;
                    $result['present'][] = $found;
                } else {
                    $add[$which] = $def;
                }
            }
            try { $pdo->exec('SET SESSION lock_wait_timeout=5'); } catch (\Throwable $e) {}
            foreach ($add as $which => $def) {
                $cols = '`' . implode('`,`', $def['cols']) . '`';
                $base = 'ALTER TABLE `' . $pre . 'vod` ADD INDEX `' . $def['name'] . '` (' . $cols . ')';
                $say('adding ' . $def['name']);
                try {
                    $pdo->exec($base . ', ALGORITHM=INPLACE, LOCK=NONE');
                } catch (\Throwable $e) {
                    if (stripos($e->getMessage(), 'Duplicate key name') !== false) {
                        // someone else added it in between
                    } else {
                        // engines without online DDL for this case: plain ALTER (still under lock_wait_timeout=5)
                        $pdo->exec($base);
                    }
                }
                $names[$which] = $def['name'];
                $result['added'][] = $def['name'];
            }
            // verify r3 R3-8: the translation covering index (per-language sitemap lastmod read from the index only); the heavy
            // marker is renewed before each phase (one marker lives HEAVY_TTL)
            ScaleTier::heavyMark('index');
            $q = ScaleTier::queryFn($pdo, $pre, true);
            $tr = $q === null ? ['state' => 'error', 'added' => [], 'present' => [], 'error' => 'no connection']
                : self::ensureTranslationIndex($q[0], $q[2], $pre, null, $say);
            $result['translation'] = $tr;
            foreach ($tr['added'] as $n) { $result['added'][] = $n; }
            foreach ($tr['present'] as $n) { $result['present'][] = $n; }
            // ensureTranslationIndex() gives the session the server default back: the FULLTEXT builds below keep the 5 s wait
            try { $pdo->exec('SET SESSION lock_wait_timeout=5'); } catch (\Throwable $e) {}
            // FULLTEXT indexes (owner decision 2026-10-17), one at a time; a busy table or too little disk stops the pass
            ScaleTier::heavyMark('index');
            $ft = self::ensureFulltext($pdo, $pre, $say);
            $result['ft'] = $ft;
            foreach ($ft['added'] as $n) { $result['added'][] = $n; }
            foreach ($ft['present'] as $n) { $result['present'][] = $n; }
            // read back from information_schema: confirmed only when both exist with the right columns
            $existing = self::existingIndexes($pdo, $pre . 'vod');
            $ok = $ft['missing'] === [] && in_array($tr['state'], ['present', 'added', 'no_table'], true);
            foreach (self::INDEXES + self::SORT_INDEXES + self::STATUS_INDEXES as $which => $def) {
                if (!isset($names[$which]) || ($existing[$names[$which]] ?? null) !== $def['cols']) { $ok = false; }
            }
            $listNames = array_intersect_key($names, self::INDEXES);
            $listOk = count($listNames) === count(self::INDEXES);
            foreach (self::INDEXES as $which => $def) {
                if (!isset($names[$which]) || ($existing[$names[$which]] ?? null) !== $def['cols']) { $listOk = false; }
            }
            // the confirmed flag (FORCE INDEX of the time-sorted lists) depends on the two list indexes only
            self::writeFlag(['indexes' => $listOk ? self::VERSION : 0, 'names' => $listOk ? $listNames : [], 'indexes_at' => time(),
                'sort_names' => array_intersect_key($names, self::SORT_INDEXES), 'status_names' => array_intersect_key($names, self::STATUS_INDEXES)]);
            $result['names'] = $names;
            $result['ok'] = $ok;
            $result['state'] = $ok ? 'confirmed' : (($ft['state'] === 'busy' || $tr['state'] === 'busy') ? 'busy' : 'incomplete');
            if (!$ok) {
                if ($ft['error'] !== '') { $result['error'] = $ft['error']; }
                elseif (($tr['error'] ?? '') !== '') { $result['error'] = (string)$tr['error']; }
            }
            return $result;
        } catch (\Throwable $e) {
            $result['state'] = 'error';
            $result['error'] = self::safeMessage($e);
            return $result;
        } finally {
            if ($heavy) {
                ScaleTier::heavyClear('index');
            }
            if ($lockName !== '') {
                try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (\Throwable $e) {}
            }
            if ($own) { $pdo = null; }
        }
    }

    /**
     * Shape asked by lane E (HO-E-6) for "Finish upgrade": indexes_ok, missing[] (index names still to add), est_minutes
     * (about 1 minute per 2 M videos, at least 1), running (another index build holds the lock or an ALTER is running).
     */
    public static function status(?\PDO $pdo = null, ?string $prefix = null): array
    {
        $out = ['indexes_ok' => self::indexesConfirmed(), 'missing' => [], 'est_minutes' => 1, 'running' => false, 'tables_ok' => self::tablesReady()];
        $q = ScaleTier::queryFn($pdo, $prefix);
        if ($q === null) {
            return $out;
        }
        [$select, $pre] = $q;
        try {
            $existing = [];
            foreach ($select('SELECT INDEX_NAME AS n, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$pre . 'vod']) as $row) {
                $existing[(string)$row['n']][] = strtolower((string)$row['c']);
            }
            foreach (self::INDEXES + self::SORT_INDEXES + self::STATUS_INDEXES as $which => $def) {
                if (!in_array($def['cols'], $existing, true)) { $out['missing'][] = $def['name']; }
            }
            $ftHave = [];
            foreach ($select("SELECT INDEX_NAME AS n, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_TYPE = 'FULLTEXT' ORDER BY INDEX_NAME, SEQ_IN_INDEX", [$pre . 'vod']) as $row) {
                $ftHave[(string)$row['n']][] = strtolower((string)$row['c']);
            }
            $ftMissing = 0;
            foreach (self::FT_INDEXES as $name => $col) {
                if (!in_array([$col], $ftHave, true)) { $out['missing'][] = $name; $ftMissing++; }
            }
            // verify r3 R3-8: the translation covering index (only when the importer's translation table exists)
            $trRows = 0;
            $trTable = $select('SELECT TABLE_ROWS AS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$pre . self::TRANSLATION_TABLE]);
            if ($trTable) {
                $trRows = (int)($trTable[0]['r'] ?? 0);
                $trHave = [];
                foreach ($select('SELECT INDEX_NAME AS n, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$pre . self::TRANSLATION_TABLE]) as $row) {
                    $trHave[(string)$row['n']][] = strtolower((string)$row['c']);
                }
                foreach (self::TRANSLATION_INDEXES as $which => $def) {
                    if (!in_array($def['cols'], $trHave, true)) { $out['missing'][] = $def['name']; } else { $trRows = 0; }
                }
            }
            $out['indexes_ok'] = !$out['missing'] && self::indexesConfirmed();
            $rows = (int)(ScaleTier::get()['rows'] ?? 0);
            // B-tree indexes about 1 minute per 2 M videos; FULLTEXT about 1 minute per 1 M videos each (measured 7.1 M); the
            // translation index reads the whole translation table once (about 1 minute per 3 M translations, estimate)
            $out['est_minutes'] = max(1, (int)ceil($rows / 2000000) + (int)ceil($ftMissing * $rows / 1000000) + (int)ceil($trRows / 3000000));
            $out['ft_bytes'] = self::ftBytes();
            $db = $select('SELECT DATABASE() AS d');
            $lock = substr((string)($db[0]['d'] ?? '') . '_scale_schema', 0, 64);
            $used = $select('SELECT IS_USED_LOCK(?) AS u', [$lock]);
            // this query's own row matches the pattern too (hence > 1); search-r6 R6-7: this site's database only
            $alter = $select("SELECT COUNT(*) AS c FROM information_schema.PROCESSLIST WHERE INFO LIKE ? AND DB = DATABASE()", ['%ALTER TABLE%' . $pre . 'vod%']);
            $out['running'] = !empty($used[0]['u']) || (int)($alter[0]['c'] ?? 0) > 1;
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /**
     * Add the missing FT_INDEXES one at a time: ALTER TABLE ... ADD FULLTEXT INDEX, ALGORITHM=INPLACE, LOCK=SHARED (reads go
     * on, writes to the video table wait while it builds), plain ALTER where the engine refuses that form (MyISAM, old
     * servers); lock_wait_timeout=5 (set by the caller) makes a build that cannot get the table lock give up ('busy', the
     * next run tries again). Before each build: StorageGuard full (lane P) or less free disk than the estimate (local server
     * only) skips it ('no_space'). Returns ['added', 'present', 'missing', 'state', 'error', 'steps' => [{name, seconds,
     * algorithm}]] and caches the measured sizes.
     */
    public static function ensureFulltext(\PDO $pdo, string $pre, ?callable $say = null): array
    {
        $out = ['added' => [], 'present' => [], 'missing' => [], 'state' => 'done', 'error' => '', 'steps' => []];
        $say = $say ?: function ($m) {};
        $table = $pre . 'vod';
        $have = self::existingFulltext($pdo, $table);
        $todo = [];
        foreach (self::FT_INDEXES as $name => $col) {
            $found = '';
            foreach ($have as $n => $cols) {
                if ($cols === [$col]) { $found = $n; break; }
            }
            if ($found !== '') { $out['present'][] = $found; } else { $todo[$name] = $col; }
        }
        if ($todo) {
            $rows = 0;
            try {
                $st = $pdo->prepare('SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $st->execute([$table]);
                $rows = (int)$st->fetchColumn();
            } catch (\Throwable $e) {
            }
            $rows = max($rows, (int)(ScaleTier::get()['rows'] ?? 0));
            $first = !$have;
            foreach ($todo as $name => $col) {
                if (class_exists('\\app\\common\\util\\StorageGuard') && method_exists('\\app\\common\\util\\StorageGuard', 'isFull')) {
                    try {
                        if (\app\common\util\StorageGuard::isFull()) {
                            $out['state'] = 'no_space';
                            $out['error'] = 'storage limit reached';
                            break;
                        }
                    } catch (\Throwable $e) {
                    }
                }
                // free disk while it builds: the index twice (sort files + result); the first FULLTEXT index of a table
                // without FTS_DOC_ID also rebuilds the table (a full copy until the swap: data + indexes + 10 %)
                $need = (int)($rows * 2 * (self::FT_BYTES_PER_ROW[$name] ?? 100)) + 268435456 + ($first ? (int)(self::tableBytes($pdo, $table) * 1.1) : 0);
                $free = self::serverFreeDisk($pdo);
                if ($free !== null && $free < $need) {
                    $out['state'] = 'no_space';
                    $out['error'] = 'not enough free disk for ' . $name . ' (needs about ' . (int)ceil($need / 1048576) . ' MB, free ' . (int)floor($free / 1048576) . ' MB)';
                    $say($out['error']);
                    break;
                }
                $base = 'ALTER TABLE `' . $table . '` ADD FULLTEXT INDEX `' . $name . '` (`' . $col . '`)';
                $say('adding ' . $name . ' (FULLTEXT, one at a time; writes to the video table wait while it builds)');
                $t0 = microtime(true);
                $algorithm = 'inplace';
                try {
                    try {
                        $pdo->exec($base . ', ALGORITHM=INPLACE, LOCK=SHARED');
                    } catch (\Throwable $e) {
                        if (stripos($e->getMessage(), 'Duplicate key name') !== false) {
                            throw $e;
                        }
                        if (preg_match('/\b(1205|1213|3024|1969|1317)\b|lock wait timeout|deadlock|interrupted/i', $e->getMessage())) {
                            throw $e;
                        }
                        $algorithm = 'default';
                        $pdo->exec($base);
                    }
                    $out['added'][] = $name;
                    $out['steps'][] = ['name' => $name, 'seconds' => round(microtime(true) - $t0, 1), 'algorithm' => $algorithm];
                    $say('   ' . $name . ': ' . round(microtime(true) - $t0, 1) . ' s (' . $algorithm . ')');
                    $first = false;
                } catch (\Throwable $e) {
                    if (stripos($e->getMessage(), 'Duplicate key name') !== false) {
                        $out['present'][] = $name;
                        continue;
                    }
                    $out['state'] = preg_match('/\b1205\b|lock wait timeout/i', $e->getMessage()) ? 'busy' : 'error';
                    $out['error'] = $name . ': ' . self::safeMessage($e);
                    $say('   ' . $out['error']);
                    break;
                }
            }
        }
        $have = self::existingFulltext($pdo, $table);
        foreach (self::FT_INDEXES as $name => $col) {
            if (!in_array([$col], $have, true)) { $out['missing'][] = $name; }
        }
        if ($out['missing'] === [] && $out['state'] !== 'done') { $out['state'] = 'done'; }
        $sizes = self::ftSizes($pdo, $pre);
        if ($sizes !== null) { $out['sizes'] = $sizes; }
        return $out;
    }

    /** One-column-or-more FULLTEXT indexes of a table: name => ordered column list. */
    public static function existingFulltext(\PDO $pdo, string $table): array
    {
        $st = $pdo->prepare("SELECT INDEX_NAME AS n, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_TYPE = 'FULLTEXT' ORDER BY INDEX_NAME, SEQ_IN_INDEX");
        $st->execute([$table]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['n']][] = strtolower((string)$row['c']);
        }
        return $out;
    }

    /**
     * Bytes of each FULLTEXT index of {pre}vod: the InnoDB auxiliary tables fts_<table id>_<index id>_index_1..6, plus
     * '_common' (fts_<table id>_deleted / being_deleted / config ...), from INNODB_SYS_TABLES / INDEXES / TABLESPACES
     * (MariaDB, MySQL 5.7) or INNODB_TABLES / INDEXES / TABLESPACES (MySQL 8). null when not readable (PROCESS privilege
     * missing, other engine). The total is cached (schema.json ft_bytes, ft_rows) for ftBytes().
     */
    public static function ftSizes(\PDO $pdo, string $pre): ?array
    {
        $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        foreach ([['INNODB_SYS_TABLES', 'INNODB_SYS_INDEXES', 'INNODB_SYS_TABLESPACES'], ['INNODB_TABLES', 'INNODB_INDEXES', 'INNODB_TABLESPACES']] as $v) {
            try {
                $st = $pdo->prepare('SELECT TABLE_ID FROM information_schema.' . $v[0] . ' WHERE NAME = ?');
                $st->execute([$db . '/' . $pre . 'vod']);
                $tid = $st->fetchColumn();
                if ($tid === false) {
                    continue;
                }
                $tid = (int)$tid;
                $st = $pdo->prepare('SELECT NAME, INDEX_ID FROM information_schema.' . $v[1] . ' WHERE TABLE_ID = ?');
                $st->execute([$tid]);
                $ids = [];
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) { $ids[(string)$r['NAME']] = (int)$r['INDEX_ID']; }
                $st = $pdo->prepare('SELECT NAME, FILE_SIZE, ALLOCATED_SIZE FROM information_schema.' . $v[2] . ' WHERE NAME LIKE ?');
                $st->execute([$db . '/fts\\_' . sprintf('%016x', $tid) . '\\_%']);
                $files = $st->fetchAll(\PDO::FETCH_ASSOC);
                if (!$files) {
                    $st->execute([$db . '/FTS\\_' . sprintf('%016X', $tid) . '\\_%']);
                    $files = $st->fetchAll(\PDO::FETCH_ASSOC);
                }
                $out = ['_common' => 0];
                foreach ($have = array_intersect_key($ids, self::FT_INDEXES + self::existingFulltext($pdo, $pre . 'vod')) as $name => $iid) { $out[$name] = 0; }
                foreach ($files as $f) {
                    $bytes = (int)($f['ALLOCATED_SIZE'] ?? 0) ?: (int)($f['FILE_SIZE'] ?? 0);
                    $hit = '_common';
                    foreach ($have as $name => $iid) {
                        if (stripos((string)$f['NAME'], '_' . sprintf('%016x', $iid) . '_index_') !== false) { $hit = $name; break; }
                    }
                    $out[$hit] += $bytes;
                }
                $total = array_sum($out);
                $rows = (int)(ScaleTier::get()['rows'] ?? 0);
                self::writeFlag(['ft_bytes' => $total, 'ft_rows' => $rows, 'ft_sizes' => $out, 'ft_at' => time()]);
                return $out;
            } catch (\Throwable $e) {
                continue;
            }
        }
        return null;
    }

    /** Last measured FULLTEXT bytes scaled to today's row count, else the estimate; null only without any row count. */
    public static function ftBytes(): ?int
    {
        $f = self::flag();
        $rows = (int)(ScaleTier::get()['rows'] ?? 0);
        if (isset($f['ft_bytes']) && (int)($f['ft_rows'] ?? 0) > 0) {
            return (int)round((int)$f['ft_bytes'] * max(1, $rows) / (int)$f['ft_rows']);
        }
        return $rows > 0 ? self::ftEstimateBytes($rows) : null;
    }

    /** Estimate of the five FULLTEXT indexes for $rows videos (FT_BYTES_PER_ROW). */
    public static function ftEstimateBytes(int $rows): int
    {
        return (int)(max(0, $rows) * array_sum(self::FT_BYTES_PER_ROW));
    }

    /** DATA_LENGTH + INDEX_LENGTH of a table (0 when unknown). */
    private static function tableBytes(\PDO $pdo, string $table): int
    {
        try {
            $st = $pdo->prepare('SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Free bytes next to the database files when the server runs on this machine, else null (unknown). */
    private static function serverFreeDisk(\PDO $pdo): ?float
    {
        try {
            $dir = (string)$pdo->query('SELECT @@datadir')->fetchColumn();
            if ($dir !== '' && @is_dir($dir)) {
                $f = @disk_free_space($dir);
                return $f === false ? null : (float)$f;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /** Re-check the confirmed flag against information_schema (housekeeping, daily); clears it when an index vanished. */
    public static function verifyIndexes(\PDO $pdo, string $prefix): bool
    {
        // Re-derived from information_schema each time: a lost flag file (runtime folder wiped) is confirmed again when both
        // indexes exist; a dropped index clears the flag. Never adds an index (that is ensureIndexes, upgrade only).
        $flag = self::flag();
        $existing = self::existingIndexes($pdo, $prefix . 'vod');
        $names = [];
        foreach (self::INDEXES as $which => $def) {
            foreach ($existing as $name => $cols) {
                if ($cols === $def['cols']) { $names[$which] = $name; break; }
            }
        }
        $ok = count($names) === count(self::INDEXES);
        if ($ok && (empty($flag['indexes']) || ($flag['names'] ?? []) !== $names)) {
            self::writeFlag(['indexes' => self::VERSION, 'names' => $names, 'indexes_at' => time()]);
        } elseif (!$ok && !empty($flag['indexes'])) {
            self::writeFlag(['indexes' => 0, 'names' => []]);
        }
        // the status list index (R2-4a): named only while it exists, so a FORCE INDEX never meets a dropped key (error 1176)
        $status = [];
        foreach (self::STATUS_INDEXES as $which => $def) {
            foreach ($existing as $name => $cols) {
                if ($cols === $def['cols']) { $status[$which] = $name; break; }
            }
        }
        if ((self::flag()['status_names'] ?? []) !== $status) {
            self::writeFlag(['status_names' => $status]);
        }
        // the translation covering index (verify r3 R3-8): named only while it exists
        $tr = [];
        foreach (self::existingIndexes($pdo, $prefix . self::TRANSLATION_TABLE) as $name => $cols) {
            foreach (self::TRANSLATION_INDEXES as $which => $def) {
                if ($cols === $def['cols'] && !isset($tr[$which])) { $tr[$which] = $name; }
            }
        }
        self::recordTranslationNames($tr);
        return $ok;
    }

    /** index name => ordered column list for a table. */
    public static function existingIndexes(\PDO $pdo, string $table): array
    {
        $st = $pdo->prepare('SELECT INDEX_NAME AS n, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $st->execute([$table]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['n']][] = strtolower((string)$row['c']);
        }
        return $out;
    }

    public static function indexesConfirmed(): bool
    {
        $f = self::flag();
        return !empty($f['indexes']) && !empty($f['names']['type_list']) && !empty($f['names']['type1_list']);
    }

    public static function indexName(string $which): string
    {
        // the status list index (R2-4a) is named when ensureIndexes() / verifyIndexes() found it, independent of the
        // confirmed flag of the two type list indexes
        if (isset(self::STATUS_INDEXES[$which])) {
            $name = (string)(self::flag()['status_names'][$which] ?? '');
            return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) ? $name : '';
        }
        if (isset(self::TRANSLATION_INDEXES[$which])) {
            $name = (string)(self::flag()['tr_names'][$which] ?? '');
            return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) ? $name : '';
        }
        if (!self::indexesConfirmed()) {
            return '';
        }
        $name = (string)(self::flag()['names'][$which] ?? '');
        return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) ? $name : '';
    }

    public static function tablesReady(): bool
    {
        return (int)(self::flag()['tables'] ?? 0) >= self::VERSION;
    }

    /** Admin entrance hook (HP-H-3): additive schema once per version; a stat + small read afterwards. */
    public static function firstAdminVisit(): void
    {
        if (self::tablesReady()) {
            return;
        }
        $lock = @fopen(ScaleTier::runtime('scale') . 'schema.lock', 'c');
        if (!$lock || ScaleTier::lock($lock, LOCK_EX, 0) !== true) {
            if ($lock) { @fclose($lock); }
            return;
        }
        try {
            self::ensureTables();
            if (class_exists('\app\common\util\FtIndex') && method_exists('\app\common\util\FtIndex', 'ensureSchema')) {
                try { \app\common\util\FtIndex::ensureSchema(); } catch (\Throwable $e) {}
            }
            if (class_exists('\app\common\model\AvdbImport') && function_exists('model')) {
                try { model('AvdbImport')->ensureSchema(); } catch (\Throwable $e) {}
            }
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * Web housekeeping slices (verify r2 R2-8 / R2-9): every statement of the own connection ends by $until (microtime):
     * the session statement limit is set to the time left (DbTimeout::capCommand: MariaDB max_statement_time, MySQL
     * max_execution_time for SELECTs) and row-lock / metadata-lock waits to the same whole seconds, so no statement of a
     * page-view or cron-URL run can outlive PHP's time limit (the 50 s fatals of HitBuffer::fold and Sitemap::rebuildRange).
     * At least $min seconds. Never throws.
     */
    public static function capSession(\PDO $pdo, float $until, float $min = 0.5): void
    {
        $left = max($min, $until - microtime(true));
        try {
            $v = DbTimeout::cachedVersion();
            $pdo->exec(DbTimeout::capCommand($left, $v !== '' ? $v : null) . ', SESSION innodb_lock_wait_timeout=' . max(1, (int)ceil($left))
                . ', SESSION lock_wait_timeout=' . max(1, (int)ceil($left)));
        } catch (\Throwable $e) {
        }
    }

    /** Own connection from application/database.php, statement limit removed when $unlimited. Never prints credentials. */
    public static function connect(bool $unlimited = true): ?\PDO
    {
        $file = ScaleTier::root() . 'application/database.php';
        if (!is_file($file)) {
            return null;
        }
        $c = include $file;
        if (!is_array($c) || empty($c['database'])) {
            return null;
        }
        try {
            $dsn = 'mysql:host=' . $c['hostname'] . ';port=' . (isset($c['hostport']) && $c['hostport'] !== '' ? (int)$c['hostport'] : 3306)
                . ';dbname=' . $c['database'] . ';charset=' . (!empty($c['charset']) ? $c['charset'] : 'utf8mb4');
            $pdo = new \PDO($dsn, (string)$c['username'], (string)$c['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        } catch (\Throwable $e) {
            return null;
        }
        if ($unlimited) {
            try {
                $v = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
                DbTimeout::rememberVersion($v);
                $pdo->exec(DbTimeout::unlimitedCommand($v));
            } catch (\Throwable $e) {
            }
        }
        return $pdo;
    }

    public static function configPrefix(): string
    {
        $file = ScaleTier::root() . 'application/database.php';
        $c = is_file($file) ? include $file : [];
        $p = is_array($c) ? (string)($c['prefix'] ?? '') : '';
        return preg_match('/^[A-Za-z0-9_]*$/D', $p) ? $p : '';
    }

    public static function flag(): array
    {
        if (self::$flag === null) {
            $f = ScaleTier::readJson(ScaleTier::runtime('scale') . 'schema.json');
            self::$flag = is_array($f) ? $f : [];
        }
        return self::$flag;
    }

    /** Test hook. */
    public static function reset(): void
    {
        self::$flag = null;
    }

    private static function writeFlag(array $changes): void
    {
        self::$flag = null;
        $f = array_replace(self::flag(), $changes);
        ScaleTier::writeAtomic(ScaleTier::runtime('scale') . 'schema.json', json_encode($f, JSON_UNESCAPED_SLASHES));
        self::$flag = $f;
    }

    private static function safeMessage(\Throwable $e): string
    {
        // no credentials or DSN in messages: keep the SQLSTATE/driver part only
        $m = preg_replace('/\s+/', ' ', (string)$e->getMessage());
        return substr((string)$m, 0, 300);
    }
}
