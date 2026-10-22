<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
if (PHP_SAPI !== 'cli') { exit(1); }
// Search table tool (XVID-SCALE-BRIEF D-S7). Uses only the site's own database (application/database.php); credentials
// are never printed. Every command prints one JSON line on stdout; progress goes to stderr.
// Usage: php tools/search-index.php <command> [--root=<site folder>] [options]
//   --status                      state, queue, sizes, triggers, running job
//   --ensure                      create the search tables when missing (same as the first admin visit after an upgrade)
//   --backfill                    fill the empty table in vod_time, vod_id order (shadow table + swap), then --terms, ready=1
//   --rebuild [--restart]         shadow rebuild with replay and atomic swap; --restart drops an abandoned job first; a job that
//                                 stopped at the swap resumes there; --throttle=0..4 sleeps that share of each batch's time
//                                 (default 0.25: visitors keep the cores; 0 = full speed)
//   --terms                       refresh the term statistics (1-in-20 sample above 200,000 docs)
//   --verify [--deep] [--fix]     compare every 5,000-id bucket (deep: every document); --fix queues the differences
//   --drain [--limit=N]           drain the write queue (default: until empty)
//   --heal [--buckets=N]          one self-heal slice (rotating buckets)
//   --probe                       measure ms_per_read now (the daily housekeeping probe)
//   --auto                        drain, then run a due rebuild (space permitting) or a due term refresh (cron / housekeeping)
//   --optimize-ft [--passes=N]    opt-in OPTIMIZE TABLE with innodb_optimize_fulltext_only (needs a privileged database user)
//   --map                         build the term map of the deep single-token pages (XVID-NOLIMIT-BRIEF C2; partitions of the
//                                 term space, PHP near 256 MB); --backfill / --rebuild / --auto build it after a done rebuild
//                                 (--no-map skips that); --map-append [--limit=N] adds the documents written since
$cmds = ['status', 'ensure', 'backfill', 'rebuild', 'terms', 'verify', 'drain', 'heal', 'probe', 'auto', 'optimize-ft', 'map', 'map-append'];
$opt = ['root' => dirname(__DIR__), 'restart' => false, 'deep' => false, 'fix' => false, 'limit' => 0, 'buckets' => 20, 'passes' => 10, 'quiet' => false, 'throttle' => null,
    'no-map' => false];
$cmd = '';
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $arg, $m)) {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
    if (in_array($m[1], $cmds, true) && !isset($m[2])) {
        if ($cmd !== '') {
            fwrite(STDERR, "Only one command at a time\n");
            exit(2);
        }
        $cmd = $m[1];
    } elseif (array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = isset($m[2]) ? $m[2] : true;
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
}
if ($cmd === '') {
    fwrite(STDERR, "Usage: php tools/search-index.php --status|--ensure|--backfill|--rebuild|--terms|--verify|--drain|--heal|--probe|--auto|--optimize-ft|--map|--map-append\n");
    exit(2);
}
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    fwrite(STDERR, "PHP 7.4 or newer is required (this is " . PHP_VERSION . ")\n");
    exit(2);
}
$throttleOpt = $opt['throttle'] !== null && is_numeric($opt['throttle']) ? ['throttle' => max(0.0, min(4.0, (float)$opt['throttle']))] : [];
$root = realpath((string)$opt['root']);
if ($root === false || !is_file($root . '/application/common/util/FtIndex.php') || !is_file($root . '/application/database.php')) {
    fwrite(STDERR, "Not a JavCMS site folder: {$opt['root']}\n");
    exit(2);
}
if (function_exists('proc_nice')) {
    @proc_nice(19);
}
@set_time_limit(0);
ini_set('memory_limit', '1024M');

$GLOBALS['config'] = [];
$maccms = is_file($root . '/application/extra/maccms.php') ? include $root . '/application/extra/maccms.php' : [];
if (is_array($maccms)) {
    $GLOBALS['config'] = $maccms;
}
$util = $root . '/application/common/util/';
foreach (['FtText', 'FtIndex', 'FtPlan', 'ScaleTier', 'ScaleCounts', 'StorageGuard', 'DbTimeout', 'SeoSettings', 'AvdbLanguage'] as $class) {
    if (is_file($util . $class . '.php')) {
        require_once $util . $class . '.php';
    }
}

use app\common\util\FtIndex;

$c = include $root . '/application/database.php';
if (!is_array($c) || empty($c['database'])) {
    fwrite(STDERR, "No database configured\n");
    exit(2);
}
try {
    $dsn = 'mysql:host=' . $c['hostname'] . ';port=' . (isset($c['hostport']) && $c['hostport'] !== '' ? (int)$c['hostport'] : 3306)
        . ';dbname=' . $c['database'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)$c['username'], (string)$c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 30');
    // the CLI has no statement limit (L12); a visitor-side limit set by a global init_connect is lifted where allowed
    try { $pdo->exec('/*M!100108 SET SESSION max_statement_time = 0 */'); } catch (Throwable $e) {}
    FtIndex::useConnection($pdo, isset($c['prefix']) ? (string)$c['prefix'] : '', (string)$c['database']);
} catch (Throwable $e) {
    fwrite(STDERR, "Database not reachable (" . get_class($e) . ")\n");
    exit(3);
}
unset($c);
FtIndex::useRuntime($root . DIRECTORY_SEPARATOR . 'runtime');

$out = function (array $data, int $code = 0) {
    echo json_encode($data, JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($code);
};
$progress = $opt['quiet'] ? null : function (array $p) {
    fwrite(STDERR, sprintf("%s %s: %d / %d (%.1f %%)\n", date('H:i:s'), $p['phase'], $p['done'], $p['total'], $p['pct']));
};
// C2: the term map after a done backfill / rebuild (its doc ids changed with the swap); progress per partition
$afterRebuild = function (array $r) use ($opt) {
    if (($r['status'] ?? '') !== 'done' || $opt['no-map'] || !method_exists(FtIndex::class, 'mapBuild')) {
        return $r;
    }
    if (!$opt['quiet']) {
        fwrite(STDERR, date('H:i:s') . " term map: building\n");
    }
    $r['map'] = FtIndex::mapBuild();
    return $r;
};

try {
    if ($cmd !== 'status' && $cmd !== 'ensure' && !FtIndex::installed()) {
        FtIndex::ensureSchema();
    }
    switch ($cmd) {
        case 'status':
            $out(FtIndex::status());
        case 'ensure':
            FtIndex::ensureSchema();
            $out(['status' => 'done', 'schema' => FtIndex::schemaInfo()] + FtIndex::status());
        case 'backfill':
            $s = FtIndex::state(true);
            if ((int)$s['docs'] > 0 && (int)$s['rebuild_started_at'] === 0) {
                $out(['status' => 'refused', 'reason' => 'not_empty', 'hint' => 'php tools/search-index.php --rebuild'], 1);
            }
            $r = $afterRebuild(FtIndex::rebuild(['kind' => 'backfill', 'restart' => (bool)$opt['restart'], 'progress' => $progress] + $throttleOpt));
            $out($r, $r['status'] === 'done' ? 0 : 1);
        case 'rebuild':
            $r = $afterRebuild(FtIndex::rebuild(['kind' => 'rebuild', 'restart' => (bool)$opt['restart'], 'progress' => $progress] + $throttleOpt));
            $out($r, $r['status'] === 'done' ? 0 : 1);
        case 'map':
            $t0 = microtime(true);
            do {
                $r = FtIndex::mapBuild(60.0);
                if (!$opt['quiet'] && ($r['state'] ?? '') === 'building') {
                    fwrite(STDERR, sprintf("%s term map: part %d / %d (%.1f %%)\n", date('H:i:s'), $r['part'], $r['parts'], $r['pct']));
                }
            } while (($r['state'] ?? '') === 'building');
            $r['total_seconds'] = round(microtime(true) - $t0, 1);
            $r['peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
            $out($r + ['map_state' => FtIndex::mapState()], ($r['state'] ?? '') === 'done' ? 0 : 1);
        case 'map-append':
            $t0 = microtime(true);
            $n = FtIndex::mapAppend((int)$opt['limit'] > 0 ? (int)$opt['limit'] : 20000, 600.0);
            $out(['status' => 'done', 'docs' => $n, 'seconds' => round(microtime(true) - $t0, 2), 'map_state' => FtIndex::mapState()]);
        case 'terms':
            $r = FtIndex::terms();
            $out($r, isset($r['error']) ? 1 : 0);
        case 'verify':
            $out(FtIndex::verify((bool)$opt['deep'], (bool)$opt['fix']));
        case 'drain':
            $limit = (int)$opt['limit'];
            $total = 0;
            $t0 = microtime(true);
            do {
                $n = FtIndex::drain($limit > 0 ? min(5000, $limit - $total) : 5000, 30);
                $total += $n;
            } while ($n > 0 && ($limit <= 0 || $total < $limit));
            $out(['status' => 'done', 'drained' => $total, 'seconds' => round(microtime(true) - $t0, 2)]);
        case 'heal':
            $out(FtIndex::selfHeal(max(1, (int)$opt['buckets'])));
        case 'probe':
            $out(['ms_per_read' => FtIndex::probeReadSpeed(true)]);
        case 'auto':
            $drained = 0;
            do {
                $n = FtIndex::drain(5000, 30);
                $drained += $n;
            } while ($n > 0);
            FtIndex::checkTriggers();
            $s = FtIndex::state(true);
            $res = ['drained' => $drained, 'rebuild_due' => (string)$s['rebuild_due'], 'ran' => ''];
            if ((int)$s['ready'] !== 1 && (int)$s['rebuild_started_at'] === 0) {
                $res['ran'] = 'backfill';
                $res['result'] = $afterRebuild(FtIndex::rebuild(['kind' => 'backfill', 'progress' => $progress] + $throttleOpt));
            } elseif ((string)$s['rebuild_due'] !== '' || (int)$s['rebuild_started_at'] > 0) {
                $res['ran'] = 'rebuild';
                $res['result'] = $afterRebuild(FtIndex::rebuild(['kind' => 'rebuild', 'progress' => $progress] + $throttleOpt));
            } elseif (FtIndex::termsDue()) {
                $res['ran'] = 'terms';
                $res['result'] = FtIndex::terms();
            } elseif (method_exists(FtIndex::class, 'mapState') && !$opt['no-map'] && empty(FtIndex::mapState()['ready']) && empty(FtIndex::mapState()['building'])) {
                $res['ran'] = 'map';
                $res['result'] = FtIndex::mapBuild();
            }
            $out($res);
        case 'optimize-ft':
            $r = FtIndex::optimizeFt(max(1, (int)$opt['passes']));
            $out($r, $r['status'] === 'done' ? 0 : 1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . substr($e->getMessage(), 0, 300) . "\n");
    exit(4);
}
