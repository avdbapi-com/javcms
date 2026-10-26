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
if (PHP_SAPI !== 'cli') { exit(1); }
// Optional database tuning for big sites (XVID-SCALE-BRIEF D-L2, 2.12 step 6). Never runs by itself.
// Usage: php tools/db-tune.php --slim-indexes [--dry-run] [--yes] [--root=<site folder>] [--json]
//        php tools/db-tune.php --reclaim [--dry-run] [--yes] [--table=<name without prefix>] [--root=<site folder>] [--json]
//   --slim-indexes  keeps the 14 indexes of the video table that lists, search fallbacks and imports use (PRIMARY,
//                   idx_vod_recycle_time_vod_time, vod_sub(32), vod_remarks(32), vod_name(50), vod_hits,
//                   vod_hits_day/week/month, vod_level, vod_time_add, vod_time, vod_lang, vod_year) plus the list
//                   indexes of 1.2.0 (idx_type_list, idx_type1_list, idx_status_list for the "all videos" list) and the
//                   four sort indexes, and drops the 24 others
//                   of the 1.1.0 schema (indexes you added yourself are kept). FULLTEXT indexes are never dropped: the five
//                   of 1.2.0 (ft_vod_name, ft_vod_actor, ft_vod_tag, ft_vod_class, ft_vod_director; filters and title
//                   search) are part of every install and tools/upgrade.php adds them when missing; the translation table's covering
//                   index idx_tr_lastmod (sitemap language dates) is never dropped either. Recommended above 1 million videos,
//                   best before a big import. One ALTER TABLE, online where the database supports it.
//   --reclaim       rebuilds big tables one at a time (OPTIMIZE TABLE) so the files shrink after big deletions.
//   Dry run is the default: the first call prints the plan and the free disk space it needs; the same command again
//   within 24 hours (same plan) applies it. --dry-run only prints; --yes applies without the earlier dry run.
//   Single flight with the search index jobs (tools/search-index.php) and the upgrade steps. Make a backup first.
// Only the site's own database (application/database.php) is used. Credentials are never printed.
if (PHP_VERSION_ID < 70400) {
    fwrite(STDERR, "PHP 7.4 or newer is required (this is " . PHP_VERSION . ")\n");
    exit(2);
}
$opt = ['root' => dirname(__DIR__), 'slim-indexes' => false, 'reclaim' => false, 'dry-run' => false, 'yes' => false, 'table' => '', 'json' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $arg, $m) || !array_key_exists($m[1], $opt)) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
    $opt[$m[1]] = isset($m[2]) ? $m[2] : true;
}
if ((bool)$opt['slim-indexes'] === (bool)$opt['reclaim']) {
    fwrite(STDERR, "Usage: php tools/db-tune.php --slim-indexes|--reclaim [--dry-run] [--yes]\n");
    exit(2);
}
$root = realpath((string)$opt['root']);
if ($root === false || !is_file($root . '/application/common/util/ScaleSchema.php') || !is_file($root . '/application/database.php')) {
    fwrite(STDERR, "Not a JavCMS 1.2.0 site folder: {$opt['root']}\n");
    exit(2);
}
$root = str_replace('\\', '/', $root) . '/';
define('ROOT_PATH', $root);
foreach (['ScaleTier', 'DbTimeout', 'ScaleSchema', 'FtIndex'] as $c) {
    if (is_file($root . 'application/common/util/' . $c . '.php')) { require_once $root . 'application/common/util/' . $c . '.php'; }
}
use app\common\util\ScaleSchema;
use app\common\util\ScaleTier;

@set_time_limit(0);
@ignore_user_abort(true);
$say = function ($line) use ($opt) { if (!$opt['json']) { echo $line, PHP_EOL; } };
$pdo = ScaleSchema::connect(true);
$pre = ScaleSchema::configPrefix();
if ($pdo === null) {
    fwrite(STDERR, "The database in application/database.php is not reachable.\n");
    exit(2);
}
$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$mb = function ($bytes) { return number_format($bytes / 1048576, 0) . ' MB'; };

// ---- free disk next to the database files (only when the database runs on this machine)
$freeDisk = function () use ($pdo) {
    try {
        $dir = (string)$pdo->query('SELECT @@datadir')->fetchColumn();
        if ($dir !== '' && @is_dir($dir)) { $f = @disk_free_space($dir); return $f === false ? null : (float)$f; }
    } catch (\Throwable $e) {
    }
    return null;
};

// ---- single flight: the search index job lock (D-S7) and the schema lock of the upgrade steps
$locks = [];
$lockNames = [substr($db . '_scale_schema', 0, 64)];
if (class_exists('\app\common\util\FtIndex') && method_exists('\app\common\util\FtIndex', 'jobLockName')) {
    try {
        if (method_exists('\app\common\util\FtIndex', 'useConnection')) { \app\common\util\FtIndex::useConnection($pdo, $pre, $db); }
        $lockNames[] = (string)\app\common\util\FtIndex::jobLockName();
    } catch (\Throwable $e) {
    }
} else {
    $lockNames[] = substr($db . '_ft_job', 0, 64);
}
$takeLocks = function () use ($pdo, $lockNames, &$locks) {
    foreach ($lockNames as $name) {
        $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $st->execute([$name]);
        if ((int)$st->fetchColumn() !== 1) { return false; }
        $locks[] = $name;
    }
    return true;
};

// ---- the dry-run memory: the same plan applied within 24 hours
$memoFile = ScaleTier::runtime('scale') . 'dbtune.json';
$memo = ScaleTier::readJson($memoFile);
$memo = is_array($memo) ? $memo : [];
$decide = function ($what, $hash) use ($opt, $memo, $memoFile, $say) {
    if ($opt['dry-run']) { return false; }
    if ($opt['yes']) { return true; }
    if (($memo[$what]['hash'] ?? '') === $hash && time() - (int)($memo[$what]['at'] ?? 0) < 86400) { return true; }
    $memo[$what] = ['hash' => $hash, 'at' => time()];
    ScaleTier::writeAtomic($memoFile, json_encode($memo));
    $say('This was a dry run. Run the same command again within 24 hours to apply this plan (or add --yes).');
    return false;
};

$out = [];
$exit = 0;
if ($opt['slim-indexes']) {
    $table = $pre . 'vod';
    // keep (14 of the 1.1.0 schema) and drop (the other 24 stock names); indexes the owner added are never touched.
    // idx_vod_remarks stays (HO-P-10): the AVDB importer's code match (vod_sub IN (...) OR vod_remarks IN (...)) scans the
    // whole table without it (45 s per item at 7 M, measured by lane P)
    $keep = ['PRIMARY', 'idx_vod_recycle_time_vod_time', 'idx_vod_sub', 'idx_vod_remarks', 'vod_name', 'vod_hits', 'vod_hits_day', 'vod_hits_week', 'vod_hits_month',
        'vod_level', 'vod_time_add', 'vod_time', 'vod_lang', 'vod_year'];
    $stockDrop = ['type_id', 'type_id_1', 'group_id', 'vod_letter', 'vod_area', 'vod_tag', 'vod_class', 'vod_lock', 'vod_up', 'vod_down', 'vod_en',
        'vod_plot', 'vod_points_play', 'vod_points_down', 'vod_time_make', 'vod_actor', 'vod_director', 'vod_score_all',
        'vod_score_num', 'vod_total', 'vod_score', 'vod_version', 'vod_state', 'vod_isend'];
    $shrink = ['idx_vod_sub' => ['vod_sub', 32], 'idx_vod_remarks' => ['vod_remarks', 32], 'vod_name' => ['vod_name', 50]];
    $st = $pdo->prepare('SELECT INDEX_NAME AS n, COLUMN_NAME AS c, SUB_PART AS s, SEQ_IN_INDEX AS q, INDEX_TYPE AS t FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
    $st->execute([$table]);
    $idx = [];
    $fulltext = [];
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $idx[$r['n']][] = ['c' => $r['c'], 's' => $r['s'] === null ? null : (int)$r['s']];
        if (strtoupper((string)$r['t']) === 'FULLTEXT') { $fulltext[$r['n']] = true; }
    }
    if (!$idx) { fwrite(STDERR, "The video table {$table} was not found.\n"); exit(2); }
    // D-L1 list indexes (and idx_status_list, verify r2 R2-4a) must exist first (they replace type_id / type_id_1 for lists)
    $listDefs = ScaleSchema::INDEXES + (defined(ScaleSchema::class . '::STATUS_INDEXES') ? ScaleSchema::STATUS_INDEXES : []);
    $dl1 = [];
    foreach ($listDefs as $which => $def) {
        foreach ($idx as $name => $cols) {
            if (array_column($cols, 'c') === $def['cols']) { $dl1[$which] = $name; break; }
        }
    }
    $drop = [];
    foreach ($stockDrop as $name) {
        // never a FULLTEXT index (owner decision 2026-10-17), whatever its name
        if (isset($idx[$name]) && !in_array($name, $dl1, true) && !isset($fulltext[$name])) { $drop[] = $name; }
    }
    $ftMissing = [];
    foreach (ScaleSchema::FT_INDEXES as $ftName => $ftCol) {
        $found = false;
        foreach ($idx as $name => $cols) {
            if (isset($fulltext[$name]) && array_column($cols, 'c') === [$ftCol]) { $found = true; break; }
        }
        if (!$found) { $ftMissing[] = $ftName; }
    }
    $rebuild = [];
    foreach ($shrink as $name => $def) {
        if (isset($idx[$name]) && count($idx[$name]) === 1 && $idx[$name][0]['c'] === $def[0] && ($idx[$name][0]['s'] === null || $idx[$name][0]['s'] > $def[1])) {
            $rebuild[$name] = $def;
        }
    }
    // sizes: innodb_index_stats when readable, else a share of INDEX_LENGTH
    $sizes = [];
    try {
        $st = $pdo->prepare("SELECT index_name, stat_value * @@innodb_page_size AS b FROM mysql.innodb_index_stats WHERE database_name = ? AND table_name = ? AND stat_name = 'size'");
        $st->execute([$db, $table]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) { $sizes[$r['index_name']] = (float)$r['b']; }
    } catch (\Throwable $e) {
    }
    $st = $pdo->prepare('SELECT TABLE_ROWS AS r, DATA_LENGTH AS d, INDEX_LENGTH AS i FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    $t = $st->fetch(\PDO::FETCH_ASSOC) ?: ['r' => 0, 'd' => 0, 'i' => 0];
    $secondary = max(1, count($idx) - 1);
    $sizeOf = function ($name) use ($sizes, $t, $secondary) { return $sizes[$name] ?? (float)$t['i'] / $secondary; };
    $saved = 0.0;
    foreach ($drop as $name) { $saved += $sizeOf($name); }
    $needed = 0.0;
    foreach ($rebuild as $name => $def) { $saved += $sizeOf($name) * 0.4; $needed += $sizeOf($name) * 1.5; }
    $needed = max($needed, 64 * 1048576);
    $free = $freeDisk();
    $plan = ['table' => $table, 'rows' => (int)$t['r'], 'drop' => $drop, 'shorten' => array_map(function ($d) { return $d[0] . '(' . $d[1] . ')'; }, $rebuild),
        'list_indexes' => $dl1 ? array_values($dl1) : [], 'list_indexes_missing' => count($dl1) < count($listDefs),
        'saves_mb' => (int)round($saved / 1048576), 'free_needed_mb' => (int)round($needed / 1048576), 'free_disk_mb' => $free === null ? null : (int)round($free / 1048576),
        'fulltext_kept' => array_keys($fulltext), 'fulltext_missing' => $ftMissing];
    // verify r3 R3-8: the translation table's covering index (per-language sitemap lastmod) is never dropped here
    $trIndex = 'no translation table';
    if (defined(ScaleSchema::class . '::TRANSLATION_INDEXES')) {
        try {
            $st = $pdo->prepare('SELECT INDEX_NAME AS n, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
            $st->execute([$pre . ScaleSchema::TRANSLATION_TABLE]);
            $trHave = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) { $trHave[(string)$r['n']][] = strtolower((string)$r['c']); }
            if ($trHave) {
                $trIndex = 'missing (php tools/upgrade.php adds it)';
                foreach ($trHave as $name => $cols) {
                    if ($cols === ScaleSchema::TRANSLATION_INDEXES['tr_lastmod']['cols']) { $trIndex = $name; break; }
                }
            }
        } catch (\Throwable $e) {
            $trIndex = 'unknown';
        }
    }
    $plan['translation_index'] = $trIndex;
    $out['plan'] = $plan;
    $say('Video table ' . $table . ': ' . number_format((int)$t['r']) . ' rows, ' . count($idx) . ' indexes, data ' . $mb((float)$t['d']) . ', indexes ' . $mb((float)$t['i']));
    $say('FULLTEXT indexes (always kept): ' . ($fulltext ? implode(', ', array_keys($fulltext)) : 'none')
        . ($ftMissing ? '; missing ' . implode(', ', $ftMissing) . ' (php tools/upgrade.php adds them)' : ''));
    $say('Translation lastmod index (always kept): ' . $trIndex);
    if (!$drop && !$rebuild && !$plan['list_indexes_missing']) {
        $say('Nothing to do: the slim index set is already in place.');
        $out['result'] = 'nothing';
    } else {
        if ($plan['list_indexes_missing']) { $say('First: the list indexes of 1.2.0 (as tools/upgrade.php adds them).'); }
        $say('Drop ' . count($drop) . ' indexes: ' . ($drop ? implode(', ', $drop) : '-'));
        $say('Shorten: ' . ($rebuild ? implode(', ', $plan['shorten']) : '-'));
        $say('Saves about ' . $mb($saved) . '. Free disk needed: about ' . $mb($needed) . ($free === null ? ' (free disk of the database server: unknown here, check it yourself)' : '; free now ' . $mb($free) . '.'));
        if ($free !== null && $free < $needed) {
            $say('NOT ENOUGH FREE DISK: nothing was changed.');
            $out['result'] = 'no_space';
            $exit = 4;
        } elseif ($decide('slim', sha1(json_encode([$table, $drop, array_keys($rebuild), $plan['list_indexes_missing']])))) {
            if (!$takeLocks()) {
                $say('Another search index job or upgrade step is running; try again later. Nothing was changed.');
                $out['result'] = 'busy';
                $exit = 3;
            } else {
                $t0 = microtime(true);
                if ($plan['list_indexes_missing']) {
                    // ensureIndexes takes the schema lock itself: release ours for it
                    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockNames[0]]);
                    $ix = ScaleSchema::ensureIndexes($pdo, $pre, function ($m) use ($say) { $say('  ' . $m); });
                    $out['list_indexes'] = $ix['state'] ?? '';
                    $pdo->prepare('SELECT GET_LOCK(?, 0)')->execute([$lockNames[0]]);
                    if (($ix['state'] ?? '') !== 'confirmed') {
                        $say('The list indexes could not be added (' . ($ix['state'] ?? '?') . '); nothing was dropped.');
                        $out['result'] = 'list_indexes_failed';
                        $exit = 1;
                    }
                }
                if (empty($out['result'])) {
                    $parts = [];
                    foreach ($drop as $name) { $parts[] = 'DROP INDEX `' . $name . '`'; }
                    foreach ($rebuild as $name => $def) { $parts[] = 'DROP INDEX `' . $name . '`'; $parts[] = 'ADD INDEX `' . $name . '` (`' . $def[0] . '`(' . (int)$def[1] . '))'; }
                    $sql = 'ALTER TABLE `' . $table . '` ' . implode(', ', $parts);
                    try { $pdo->exec('SET SESSION lock_wait_timeout=5'); } catch (\Throwable $e) {}
                    try {
                        try {
                            $pdo->exec($sql . ', ALGORITHM=INPLACE, LOCK=NONE');
                            $out['algorithm'] = 'inplace';
                        } catch (\Throwable $e) {
                            if (preg_match('/\b(1091|1176)\b|check that .* exists/i', $e->getMessage())) { throw $e; }
                            $pdo->exec($sql);
                            $out['algorithm'] = 'default';
                        }
                        ScaleSchema::verifyIndexes($pdo, $pre);
                        $out['result'] = 'applied';
                        $out['seconds'] = round(microtime(true) - $t0, 1);
                        $say('Done in ' . $out['seconds'] . ' s (' . $out['algorithm'] . ').');
                    } catch (\Throwable $e) {
                        $out['result'] = 'error';
                        $out['error'] = substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 300);
                        $say('ERROR: ' . $out['error']);
                        $exit = 1;
                    }
                }
            }
        } else {
            $out['result'] = 'dry_run';
        }
    }
} else {
    // ---- reclaim: OPTIMIZE one table at a time (InnoDB rebuild; online on MySQL 5.6+ / MariaDB 10.0+)
    $candidates = ['vod', 'avdb_translation', 'avdb_import_map', 'avdb_import_item', 'vod_search', 'art', 'comment'];
    if ($opt['table'] !== '') {
        if (!preg_match('/^[a-z0-9_]{1,48}$/D', (string)$opt['table'])) { fwrite(STDERR, "Invalid table name.\n"); exit(2); }
        $candidates = [(string)$opt['table']];
    }
    $rows = [];
    foreach ($candidates as $name) {
        $st = $pdo->prepare('SELECT TABLE_NAME AS t, ENGINE AS e, DATA_LENGTH + INDEX_LENGTH AS size, DATA_FREE AS free FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute([$pre . $name]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if ($r && strtolower((string)$r['e']) === 'innodb') { $rows[] = ['table' => $r['t'], 'size' => (float)$r['size'], 'free' => (float)$r['free']]; }
    }
    $work = array_values(array_filter($rows, function ($r) use ($opt) { return $opt['table'] !== '' || ($r['free'] >= 100 * 1048576 && $r['free'] >= 0.1 * $r['size']); }));
    $needed = 0.0;
    foreach ($work as $r) { $needed = max($needed, $r['size'] * 1.1); }
    $free = $freeDisk();
    $out['plan'] = ['tables' => $work, 'free_needed_mb' => (int)round($needed / 1048576), 'free_disk_mb' => $free === null ? null : (int)round($free / 1048576)];
    foreach ($rows as $r) {
        $say(sprintf('%-28s %10s used, %10s free inside%s', $r['table'], $mb($r['size']), $mb($r['free']), in_array($r, $work, true) ? '  <- rebuild' : ''));
    }
    if (!$work) {
        $say('Nothing to do: no table has much free space inside.');
        $out['result'] = 'nothing';
    } else {
        $say('Free disk needed (the biggest table once more): about ' . $mb($needed) . ($free === null ? ' (unknown here: check the database server yourself)' : '; free now ' . $mb($free) . '.'));
        if ($free !== null && $free < $needed) {
            $say('NOT ENOUGH FREE DISK: nothing was changed.');
            $out['result'] = 'no_space';
            $exit = 4;
        } elseif ($decide('reclaim', sha1(json_encode(array_column($work, 'table'))))) {
            if (!$takeLocks()) {
                $say('Another search index job or upgrade step is running; try again later.');
                $out['result'] = 'busy';
                $exit = 3;
            } else {
                foreach ($work as $r) {
                    $f = $freeDisk();
                    if ($f !== null && $f < $r['size'] * 1.1) { $say('Stopped before ' . $r['table'] . ': not enough free disk.'); $exit = 4; break; }
                    $t0 = microtime(true);
                    try {
                        $res = $pdo->query('OPTIMIZE TABLE `' . str_replace('`', '', $r['table']) . '`')->fetchAll(\PDO::FETCH_ASSOC);
                        $say('Rebuilt ' . $r['table'] . ' in ' . round(microtime(true) - $t0, 1) . ' s');
                        $out['done'][] = $r['table'];
                    } catch (\Throwable $e) {
                        $say('ERROR on ' . $r['table'] . ': ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200));
                        $exit = 1;
                        break;
                    }
                }
                $out['result'] = $exit === 0 ? 'applied' : 'stopped';
            }
        } else {
            $out['result'] = 'dry_run';
        }
    }
}
foreach ($locks as $name) {
    try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); } catch (\Throwable $e) {}
}
if ($opt['json']) { echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL; }
exit($exit);
